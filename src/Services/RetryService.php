<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Services;

use Closure;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Services\RetryServiceContract;
use Throwable;

/**
 * Encapsulates retry-with-backoff logic for payment driver operations.
 *
 * Used by AbstractDriver::withRetry() to wrap provider HTTP calls. Reads its
 * configuration from constructor parameters (typically sourced from
 * payment.retry.* config keys by the service provider):
 *   - maxAttempts — maximum total attempts (1 = no retry)
 *   - delayMs     — base delay in milliseconds before the first retry
 *   - enabled     — master switch; when false, the operation runs exactly once
 *
 * Only transient errors (HTTP 429, HTTP 5xx) are retried. All other
 * errors are treated as permanent and propagated immediately.
 *
 * Delay between attempts grows exponentially: delayMs, delayMs * multiplier,
 * delayMs * multiplier^2, ... This is pure PHP with no framework or
 * provider-specific dependencies, so it is reusable as-is by every driver
 * (Stripe, PayPal, Paymob, MyFatoorah, ...).
 */
final class RetryService implements RetryServiceContract
{
    /**
     * @param int       $maxAttempts       Maximum number of attempts (1 = no retry).
     * @param int       $delayMs           Base delay in milliseconds before the first retry.
     * @param bool      $enabled           Whether retry is enabled at all.
     * @param float     $backoffMultiplier Multiplier applied to the delay after each retry (exponential backoff).
     * @param Closure|null $onRetry        Optional callback invoked before each retry:
     *                                     `function (int $attempt, Throwable $e, int $delayMs): void`.
     * @param Closure|null $onLog          Optional logging hook invoked at key points:
     *                                     `function (string $level, string $message, array $context): void`.
     */
    public function __construct(
        private readonly int $maxAttempts,
        private readonly int $delayMs,
        private readonly bool $enabled,
        private readonly float $backoffMultiplier = 2.0,
        private readonly ?Closure $onRetry = null,
        private readonly ?Closure $onLog = null,
    ) {
    }

    /**
     * Execute a callable with automatic retry on transient exceptions.
     *
     * @param callable $operation The operation to execute (wraps a provider API call).
     *
     * @return mixed The return value of the callable on success.
     *
     * @throws Throwable The original exception, unwrapped, once no more attempts remain
     *                    or the exception is classified as non-transient.
     */
    public function execute(callable $operation): mixed
    {
        if (! $this->enabled) {
            return $operation();
        }

        $attempt = 1;
        $delay   = max(0, $this->delayMs);

        while (true) {
            try {
                return $operation();
            } catch (Throwable $e) {
                $context = [
                    'attempt'      => $attempt,
                    'max_attempts' => $this->maxAttempts,
                    'exception'    => $e->getMessage(),
                ];

                if (! $this->isTransient($e)) {
                    $this->log('debug', 'Non-transient exception — not retrying.', $context);

                    throw $e;
                }

                if ($attempt >= $this->maxAttempts) {
                    $this->log('error', 'Retry attempts exhausted — rethrowing last exception.', $context);

                    throw $e;
                }

                $this->log('warning', 'Transient failure — retrying.', $context + ['delay_ms' => $delay]);
                $this->onRetry?->__invoke($attempt, $e, $delay);

                if ($delay > 0) {
                    usleep($delay * 1000);
                }

                $delay   = (int) round($delay * $this->backoffMultiplier);
                $attempt++;
            }
        }
    }

    /**
     * Determine whether a thrown exception represents a transient (retryable) error.
     *
     * HTTP 429 (rate limit) and HTTP 5xx (server error) are transient.
     * All other HTTP status codes, and exceptions with no discernible status
     * code, are treated as permanent and not retried.
     *
     * @param Throwable $e The exception to classify.
     *
     * @return bool True if the error is transient and the operation should be retried.
     */
    public function isTransient(Throwable $e): bool
    {
        $statusCode = $this->extractStatusCode($e);

        if ($statusCode === null) {
            return false;
        }

        return $statusCode === 429 || ($statusCode >= 500 && $statusCode <= 599);
    }

    /**
     * Extract an HTTP status code from an exception, if one is discernible.
     *
     * Provider SDKs typically expose the status via a `getStatusCode()` method
     * (common on HTTP client exceptions) or via the standard `getCode()`.
     * No provider-specific exception classes are referenced here — only
     * duck-typing against `getStatusCode()` and the built-in `getCode()`.
     *
     * @param Throwable $e The exception to inspect.
     *
     * @return int|null The HTTP status code, or null when none can be determined.
     */
    private function extractStatusCode(Throwable $e): ?int
    {
        if (method_exists($e, 'getStatusCode')) {
            $code = $e->getStatusCode();

            if (is_int($code)) {
                return $code;
            }
        }

        $code = $e->getCode();

        return is_int($code) && $code > 0 ? $code : null;
    }

    /**
     * Invoke the optional logging hook, if one was configured.
     *
     * @param string               $level   A PSR-3-style log level (e.g. 'warning', 'error', 'debug').
     * @param string               $message The log message.
     * @param array<string, mixed> $context Additional structured context.
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $this->onLog?->__invoke($level, $message, $context);
    }
}
