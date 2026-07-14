<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Drivers;

use Illuminate\Contracts\Events\Dispatcher;
use Throwable;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\PaymentDriverContract;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\SupportsCapabilities;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Logging\PaymentLoggerContract;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Services\RetryServiceContract;
use Mifatoyeh\LaravelPaymentFramework\DTO\CaptureRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentLinkRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\RefundRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\SaveCardRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\SubscriptionRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\TokenChargeRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\TransactionLookupRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\VoidRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\WebhookRequest;
use Mifatoyeh\LaravelPaymentFramework\Enums\Environment;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\IdempotencyException;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\PaymentException;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\UnsupportedOperationException;
use Mifatoyeh\LaravelPaymentFramework\Responses\CaptureResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentLinkResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\RefundResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\StatusResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\SubscriptionResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\VerificationResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\VoidResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\WebhookResponse;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;

/**
 * Abstract base class for all payment provider drivers.
 *
 * Every payment provider driver MUST extend this class. The goal is to
 * centralise all shared infrastructure so that a concrete driver (e.g., StripeDriver)
 * only needs to implement the 15 abstract provider-specific methods.
 *
 * ## What AbstractDriver handles for every driver:
 *
 * **1. Configuration & Credentials**
 *   - Stores the driver's config array (credentials, sandbox flag, timeout, etc.).
 *   - Provides `getConfig()`, `getConfigValue()`, and `getCredential()` helpers.
 *   - Resolves `Environment` from the `sandbox` config flag.
 *
 * **2. Environment Detection**
 *   - `getEnvironment()` returns the `Environment` enum (Sandbox or Production).
 *   - `isSandbox()` / `isProduction()` guards prevent accidental live charges.
 *
 * **3. Logging**
 *   - `log($level, $message, $context)` proxies to `PaymentLoggerContract`.
 *   - Convenience shorthands: `logInfo()`, `logError()`, `logDebug()`, `logWarning()`.
 *   - All log entries are prefixed with the driver name for easy filtering.
 *
 * **4. Event Dispatching**
 *   - `dispatchEvent($event)` fires any event via the injected Dispatcher.
 *   - Concrete drivers call `dispatchEvent(new PaymentInitiated($request))` etc.
 *     at the correct lifecycle points.
 *
 * **5. Retry**
 *   - `withRetry($operation)` delegates to `RetryService` for automatic backoff
 *     on transient failures (HTTP 429, 5xx).
 *
 * **6. Idempotency**
 *   - `validateIdempotencyKey($key)` throws `IdempotencyException` on empty keys.
 *   - Must be called first in every mutating driver method.
 *
 * **7. Capability Helpers**
 *   - `supportsOperation($capability)` checks `SupportsCapabilities` if implemented.
 *   - `assertSupports($capability)` throws `UnsupportedOperationException` if missing.
 *
 * **8. Exception Mapping**
 *   - `wrapException($e, $context)` normalises provider-specific exceptions into
 *     framework `PaymentException` subclasses so the host application can catch
 *     a single exception hierarchy.
 *
 * ## Implementing a concrete driver
 *
 * ```php
 * class StripeDriver extends AbstractDriver
 * {
 *     public function charge(PaymentRequest $request): PaymentResponse
 *     {
 *         $this->validateIdempotencyKey($request->idempotencyKey);
 *         $this->logInfo('Initiating charge', ['amount' => $request->amount->amount]);
 *         $this->dispatchEvent(new PaymentInitiated($request));
 *
 *         try {
 *             $response = $this->withRetry(fn() => $this->performCharge($request));
 *             $this->dispatchEvent(new PaymentSucceeded($request, $response));
 *             $this->logInfo('Charge succeeded', ['txn' => $response->getTransactionId()->toString()]);
 *             return $response;
 *         } catch (Throwable $e) {
 *             $this->dispatchEvent(new PaymentFailed($request, null, $e));
 *             $this->logError('Charge failed', ['error' => $e->getMessage()]);
 *             throw $this->wrapException($e, ['operation' => 'charge']);
 *         }
 *     }
 *
 *     // Only provider-specific HTTP code goes here:
 *     private function performCharge(PaymentRequest $request): PaymentResponse { ... }
 * }
 * ```
 */
abstract class AbstractDriver implements PaymentDriverContract
{
    /**
     * The driver name — set by the PaymentManager at resolution time.
     * Concrete drivers may override this in their constructor if needed.
     *
     * @var string
     */
    protected string $driverName = 'unknown';

    /**
     * The resolved driver configuration array from config/payment.php.
     *
     * @var array<string, mixed>
     */
    protected array $config = [];

    /**
     * @param PaymentLoggerContract $logger  The bound logger implementation.
     * @param Dispatcher            $events  Laravel's event dispatcher.
     * @param RetryServiceContract  $retry   The retry service for transient failure handling.
     * @param array<string, mixed>  $config  The driver's config block from payment.drivers.{name}.
     */
    public function __construct(
        protected readonly PaymentLoggerContract $logger,
        protected readonly Dispatcher $events,
        protected readonly RetryServiceContract $retry,
        array $config = [],
    ) {
        $this->config = $config;

        if (isset($config['driver_name']) && is_string($config['driver_name'])) {
            $this->driverName = $config['driver_name'];
        }
    }

    // =========================================================================
    // Abstract methods — concrete drivers MUST implement all 15
    // =========================================================================

    /** {@inheritDoc} */
    abstract public function authorize(PaymentRequest $request): PaymentResponse;

    /** {@inheritDoc} */
    abstract public function capture(CaptureRequest $request): CaptureResponse;

    /** {@inheritDoc} */
    abstract public function charge(PaymentRequest $request): PaymentResponse;

    /** {@inheritDoc} */
    abstract public function void(VoidRequest $request): VoidResponse;

    /** {@inheritDoc} */
    abstract public function refund(RefundRequest $request): RefundResponse;

    /** {@inheritDoc} */
    abstract public function partialRefund(RefundRequest $request): RefundResponse;

    /** {@inheritDoc} */
    abstract public function verify(TransactionLookupRequest $request): VerificationResponse;

    /** {@inheritDoc} */
    abstract public function lookup(TransactionLookupRequest $request): StatusResponse;

    /** {@inheritDoc} */
    abstract public function createPaymentLink(PaymentLinkRequest $request): PaymentLinkResponse;

    /** {@inheritDoc} */
    abstract public function saveCard(SaveCardRequest $request): PaymentResponse;

    /** {@inheritDoc} */
    abstract public function chargeToken(TokenChargeRequest $request): PaymentResponse;

    /** {@inheritDoc} */
    abstract public function createSubscription(SubscriptionRequest $request): SubscriptionResponse;

    /** {@inheritDoc} */
    abstract public function cancelSubscription(TransactionId $subscriptionId): SubscriptionResponse;

    /** {@inheritDoc} */
    abstract public function processWebhook(WebhookRequest $request): WebhookResponse;

    /** {@inheritDoc} */
    abstract public function verifyWebhookSignature(WebhookRequest $request): bool;

    // =========================================================================
    // Configuration & Credentials
    // =========================================================================

    /**
     * Return the full driver configuration array.
     *
     * @return array<string, mixed>
     */
    protected function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Retrieve a configuration value by dot-notation key.
     *
     * @param string $key     The config key (e.g. 'key', 'secret', 'timeout').
     * @param mixed  $default Default value when the key is absent.
     *
     * @return mixed
     */
    protected function getConfigValue(string $key, mixed $default = null): mixed
    {
        // Support simple dot notation (one level deep within the driver config block).
        if (str_contains($key, '.')) {
            $segments = explode('.', $key, 2);
            $top      = $this->config[$segments[0]] ?? null;

            if (is_array($top)) {
                return $top[$segments[1]] ?? $default;
            }

            return $default;
        }

        return $this->config[$key] ?? $default;
    }

    /**
     * Retrieve a credential value (API key, secret, etc.) safely.
     *
     * Identical to getConfigValue() but semantically marks the intent —
     * the value is a sensitive credential. The logger will never receive
     * the value of a credential call.
     *
     * @param string $key     The credential key (e.g. 'key', 'secret', 'webhook_secret').
     * @param string $default Default value (usually empty string).
     *
     * @return string
     */
    protected function getCredential(string $key, string $default = ''): string
    {
        return (string) ($this->config[$key] ?? $default);
    }

    /**
     * Return the timeout in seconds configured for this driver.
     *
     * Defaults to 30 seconds if not configured.
     *
     * @return int
     */
    protected function getTimeout(): int
    {
        return (int) ($this->config['timeout'] ?? 30);
    }

    // =========================================================================
    // Environment Detection
    // =========================================================================

    /**
     * Resolve the current execution environment for this driver.
     *
     * Reads the `sandbox` boolean from the driver config and maps it to
     * the Environment enum. Defaults to Sandbox if not configured, preventing
     * accidental live charges in unconfigured environments.
     *
     * @return Environment
     */
    protected function getEnvironment(): Environment
    {
        $sandbox = (bool) ($this->config['sandbox'] ?? true);

        return Environment::fromSandboxFlag($sandbox);
    }

    /**
     * Whether this driver is running in sandbox (test) mode.
     *
     * @return bool
     */
    protected function isSandbox(): bool
    {
        return $this->getEnvironment()->isSandbox();
    }

    /**
     * Whether this driver is running in production (live) mode.
     *
     * @return bool
     */
    protected function isProduction(): bool
    {
        return $this->getEnvironment()->isProduction();
    }

    // =========================================================================
    // Logging
    // =========================================================================

    /**
     * Log a message at the given PSR-3 level via the injected logger.
     *
     * All log entries are automatically prefixed with the driver name so
     * operators can filter payment logs by provider.
     *
     * @param string               $level   PSR-3 level: 'info', 'error', 'debug', 'warning'.
     * @param string               $message The log message.
     * @param array<string, mixed> $context Additional context data.
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        $context['driver'] ??= $this->driverName;

        match ($level) {
            'error'   => $this->logger->error($message, $context),
            'debug'   => $this->logger->debug($message, $context),
            'warning' => $this->logger->warning($message, $context),
            default   => $this->logger->info($message, $context),
        };
    }

    /**
     * Log an informational message.
     *
     * @param string               $message
     * @param array<string, mixed> $context
     */
    protected function logInfo(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * Log an error message.
     *
     * @param string               $message
     * @param array<string, mixed> $context
     */
    protected function logError(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * Log a debug message (raw payloads, verbose internal state).
     *
     * @param string               $message
     * @param array<string, mixed> $context
     */
    protected function logDebug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * Log a warning message.
     *
     * @param string               $message
     * @param array<string, mixed> $context
     */
    protected function logWarning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    // =========================================================================
    // Event Dispatching
    // =========================================================================

    /**
     * Dispatch a payment lifecycle event via the injected event dispatcher.
     *
     * Call this at the appropriate lifecycle point in each concrete method:
     *   - BEFORE the provider call: `PaymentInitiated`, `WebhookReceived`
     *   - AFTER success:            `PaymentSucceeded`, `PaymentCaptured`, etc.
     *   - ON any failure:           `PaymentFailed` (always, even on exception)
     *
     * @param object $event Any framework event object.
     */
    protected function dispatchEvent(object $event): void
    {
        $this->events->dispatch($event);
    }

    // =========================================================================
    // Retry
    // =========================================================================

    /**
     * Execute a callable with automatic retry on transient provider errors.
     *
     * Wraps the inner provider HTTP call. Uses `RetryService::execute()` which
     * handles the retry loop, backoff delay, and transient error classification
     * (HTTP 429, HTTP 5xx).
     *
     * Usage in a concrete driver method:
     * ```php
     * $response = $this->withRetry(fn() => $this->callStripeApi($request));
     * ```
     *
     * @param callable $operation The provider HTTP call to wrap.
     *
     * @return mixed The return value of $operation on success.
     *
     * @throws Throwable The last exception after all retry attempts are exhausted.
     */
    protected function withRetry(callable $operation): mixed
    {
        return $this->retry->execute($operation);
    }

    // =========================================================================
    // Idempotency
    // =========================================================================

    /**
     * Validate that an idempotency key is present and non-whitespace.
     *
     * MUST be called first in every mutating method (charge, authorize, refund,
     * capture, void, chargeToken, saveCard, createSubscription, cancelSubscription,
     * createPaymentLink) before any provider interaction.
     *
     * The DTO constructors enforce non-empty at the PHP level but whitespace-only
     * keys (e.g., "   ") would pass that check — this guard catches them.
     *
     * @param string $key The idempotency key from the request DTO.
     *
     * @throws IdempotencyException When the key is empty or whitespace-only.
     */
    protected function validateIdempotencyKey(string $key): void
    {
        if (trim($key) === '') {
            throw IdempotencyException::forEmptyKey();
        }
    }

    // =========================================================================
    // Capability Helpers
    // =========================================================================

    /**
     * Whether this driver supports the given capability.
     *
     * If the driver implements `SupportsCapabilities`, delegates to it.
     * Otherwise returns true — by default all operations are assumed supported,
     * and `UnsupportedOperationException` will be thrown if the abstract method
     * is not overridden.
     *
     * @param string $capability A capability identifier (e.g., 'subscription', 'qr_code').
     *
     * @return bool
     */
    protected function supportsOperation(string $capability): bool
    {
        if ($this instanceof SupportsCapabilities) {
            return $this->supports($capability);
        }

        return true;
    }

    /**
     * Assert that this driver supports the given operation or throw.
     *
     * Call this at the top of abstract method implementations that are
     * optional (e.g., createSubscription for a QR-only provider):
     * ```php
     * public function createSubscription(...): SubscriptionResponse
     * {
     *     $this->assertSupports('subscription');
     *     // provider-specific code
     * }
     * ```
     *
     * @param string $capability The capability name to check.
     *
     * @throws UnsupportedOperationException When the driver does not support the capability.
     */
    protected function assertSupports(string $capability): void
    {
        if (! $this->supportsOperation($capability)) {
            throw UnsupportedOperationException::forOperation($capability, $this->driverName);
        }
    }

    // =========================================================================
    // Exception Mapping
    // =========================================================================

    /**
     * Wrap any thrown exception in an appropriate framework PaymentException.
     *
     * Provider SDKs throw their own exception classes. This method normalises
     * them so the host application only needs to catch `PaymentException` (or
     * a more specific subclass).
     *
     * Rules:
     * - If $e is already a `PaymentException`, return it unchanged.
     * - Otherwise, wrap it in `PaymentException` with the original as `$previous`.
     *
     * Concrete drivers may override this to map specific provider exceptions
     * to more precise subclasses (e.g., `RefundFailedException`).
     *
     * @param Throwable            $e       The original exception to wrap.
     * @param array<string, mixed> $context Additional context for the error message.
     *
     * @return PaymentException
     */
    protected function wrapException(Throwable $e, array $context = []): PaymentException
    {
        if ($e instanceof PaymentException) {
            return $e;
        }

        $operation = $context['operation'] ?? 'operation';
        $message   = sprintf(
            '[%s] %s failed: %s',
            $this->driverName,
            $operation,
            $e->getMessage(),
        );

        return new PaymentException($message, (int) $e->getCode(), $e);
    }

    // =========================================================================
    // Common Validation Helpers
    // =========================================================================

    /**
     * Assert that the driver is running in sandbox mode.
     *
     * Use in tests or safety checks to prevent accidentally calling live APIs.
     *
     * @throws \RuntimeException When in production mode.
     */
    protected function assertSandbox(): void
    {
        if (! $this->isSandbox()) {
            throw new \RuntimeException(
                "[{$this->driverName}] This operation is only permitted in sandbox mode.",
            );
        }
    }

    /**
     * Build a standard log context array for a request DTO.
     *
     * Provides a consistent structure for all log entries across drivers.
     *
     * @param string               $operation The operation name (e.g., 'charge', 'refund').
     * @param array<string, mixed> $extra     Additional context to merge.
     *
     * @return array<string, mixed>
     */
    protected function buildLogContext(string $operation, array $extra = []): array
    {
        return array_merge([
            'driver'      => $this->driverName,
            'operation'   => $operation,
            'environment' => $this->getEnvironment()->value,
        ], $extra);
    }
}
