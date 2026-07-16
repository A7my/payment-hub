<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Drivers;

use Illuminate\Contracts\Events\Dispatcher;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\PaymentDriverContract;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\SupportsCapabilities;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Logging\PaymentLoggerContract;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Services\RetryServiceContract;
use Mifatoyeh\LaravelPaymentFramework\DTO\CaptureRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\CustomerData;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentLinkRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\RefundRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\SaveCardRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\SubscriptionRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\TokenChargeRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\TransactionLookupRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\VoidRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\WebhookRequest;
use Mifatoyeh\LaravelPaymentFramework\Drivers\AbstractDriver;
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
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
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\CustomerId;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Token;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\WebhookSignature;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AbstractDriver.
 *
 * Uses a minimal concrete stub that extends AbstractDriver to allow
 * testing of the shared infrastructure without requiring a real provider.
 *
 * Covers: configuration loading, logger usage, retry delegation,
 * event dispatching, exception mapping, capability helpers, idempotency helpers.
 */
class AbstractDriverTest extends TestCase
{
    // =========================================================================
    // Test infrastructure
    // =========================================================================

    /** @var PaymentLoggerContract&MockObject */
    private PaymentLoggerContract $logger;

    /** @var Dispatcher&MockObject */
    private Dispatcher $events;

    /** @var RetryServiceContract&MockObject */
    private RetryServiceContract $retry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->createMock(PaymentLoggerContract::class);
        $this->events = $this->createMock(Dispatcher::class);
        $this->retry  = $this->createMock(RetryServiceContract::class);

        // Default: retry passes through the callable
        $this->retry
            ->method('execute')
            ->willReturnCallback(fn (callable $op) => $op());
    }

    /**
     * Build a minimal concrete driver stub with optional config.
     *
     * @param array<string, mixed> $config
     *
     * @return ConcreteTestDriver
     */
    private function makeDriver(array $config = []): ConcreteTestDriver
    {
        return new ConcreteTestDriver($this->logger, $this->events, $this->retry, $config);
    }

    private function makePaymentRequest(string $idempotencyKey = 'idem-test-001'): PaymentRequest
    {
        return new PaymentRequest(
            amount: Money::ofMinor(1000, Currency::USD),
            currency: Currency::USD,
            idempotencyKey: $idempotencyKey,
            customer: new CustomerData('Test User', 'test@example.com'),
        );
    }

    // =========================================================================
    // Configuration loading
    // =========================================================================

    /** @test */
    public function test_get_config_returns_full_config_array(): void
    {
        $config = ['key' => 'pk_test', 'secret' => 'sk_test', 'sandbox' => true];
        $driver = $this->makeDriver($config);

        $this->assertSame($config, $driver->exposeGetConfig());
    }

    /** @test */
    public function test_get_config_value_returns_value_by_key(): void
    {
        $driver = $this->makeDriver(['key' => 'pk_live_abc']);

        $this->assertSame('pk_live_abc', $driver->exposeGetConfigValue('key'));
    }

    /** @test */
    public function test_get_config_value_returns_default_for_missing_key(): void
    {
        $driver = $this->makeDriver([]);

        $this->assertSame('default_val', $driver->exposeGetConfigValue('missing', 'default_val'));
        $this->assertNull($driver->exposeGetConfigValue('also_missing'));
    }

    /** @test */
    public function test_get_credential_returns_string_value(): void
    {
        $driver = $this->makeDriver(['secret' => 'sk_test_123']);

        $this->assertSame('sk_test_123', $driver->exposeGetCredential('secret'));
    }

    /** @test */
    public function test_get_credential_returns_empty_string_for_missing(): void
    {
        $driver = $this->makeDriver([]);

        $this->assertSame('', $driver->exposeGetCredential('nonexistent'));
    }

    /** @test */
    public function test_get_timeout_returns_configured_value(): void
    {
        $driver = $this->makeDriver(['timeout' => 60]);

        $this->assertSame(60, $driver->exposeGetTimeout());
    }

    /** @test */
    public function test_get_timeout_defaults_to_30(): void
    {
        $driver = $this->makeDriver([]);

        $this->assertSame(30, $driver->exposeGetTimeout());
    }

    // =========================================================================
    // Environment detection
    // =========================================================================

    /** @test */
    public function test_get_environment_returns_sandbox_when_sandbox_true(): void
    {
        $driver = $this->makeDriver(['sandbox' => true]);

        $this->assertSame(Environment::Sandbox, $driver->exposeGetEnvironment());
        $this->assertTrue($driver->exposeIsSandbox());
        $this->assertFalse($driver->exposeIsProduction());
    }

    /** @test */
    public function test_get_environment_returns_production_when_sandbox_false(): void
    {
        $driver = $this->makeDriver(['sandbox' => false]);

        $this->assertSame(Environment::Production, $driver->exposeGetEnvironment());
        $this->assertFalse($driver->exposeIsSandbox());
        $this->assertTrue($driver->exposeIsProduction());
    }

    /** @test */
    public function test_get_environment_defaults_to_sandbox_when_not_configured(): void
    {
        $driver = $this->makeDriver([]);

        $this->assertTrue($driver->exposeIsSandbox());
    }

    // =========================================================================
    // Logger usage
    // =========================================================================

    /** @test */
    public function test_log_calls_logger_info(): void
    {
        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with('test message', $this->arrayHasKey('driver'));

        $driver = $this->makeDriver();
        $driver->exposeLog('info', 'test message', []);
    }

    /** @test */
    public function test_log_calls_logger_error(): void
    {
        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with('error occurred', $this->arrayHasKey('driver'));

        $driver = $this->makeDriver();
        $driver->exposeLog('error', 'error occurred', []);
    }

    /** @test */
    public function test_log_calls_logger_debug(): void
    {
        $this->logger->expects($this->once())->method('debug');

        $driver = $this->makeDriver();
        $driver->exposeLog('debug', 'debug info', []);
    }

    /** @test */
    public function test_log_calls_logger_warning(): void
    {
        $this->logger->expects($this->once())->method('warning');

        $driver = $this->makeDriver();
        $driver->exposeLog('warning', 'warning message', []);
    }

    /** @test */
    public function test_log_injects_driver_name_into_context(): void
    {
        $this->logger
            ->expects($this->once())
            ->method('info')
            ->with(
                $this->anything(),
                $this->callback(fn (array $ctx) => ($ctx['driver'] ?? null) === 'test_driver'),
            );

        $driver = $this->makeDriver(['driver_name' => 'test_driver']);
        $driver->exposeLog('info', 'message', []);
    }

    /** @test */
    public function test_log_info_shorthand(): void
    {
        $this->logger->expects($this->once())->method('info');

        $this->makeDriver()->exposeLogInfo('info message');
    }

    /** @test */
    public function test_log_error_shorthand(): void
    {
        $this->logger->expects($this->once())->method('error');

        $this->makeDriver()->exposeLogError('error message');
    }

    // =========================================================================
    // Event dispatching
    // =========================================================================

    /** @test */
    public function test_dispatch_event_calls_dispatcher(): void
    {
        $event = new \stdClass();

        $this->events
            ->expects($this->once())
            ->method('dispatch')
            ->with($event);

        $this->makeDriver()->exposeDispatchEvent($event);
    }

    /** @test */
    public function test_dispatch_event_can_be_called_multiple_times(): void
    {
        $this->events->expects($this->exactly(3))->method('dispatch');

        $driver = $this->makeDriver();
        $driver->exposeDispatchEvent(new \stdClass());
        $driver->exposeDispatchEvent(new \stdClass());
        $driver->exposeDispatchEvent(new \stdClass());
    }

    // =========================================================================
    // Retry delegation
    // =========================================================================

    /** @test */
    public function test_with_retry_delegates_to_retry_service(): void
    {
        $called = false;

        $this->retry
            ->expects($this->once())
            ->method('execute')
            ->willReturnCallback(function (callable $op) use (&$called) {
                $called = true;
                return $op();
            });

        $result = $this->makeDriver()->exposeWithRetry(fn () => 'result');

        $this->assertSame('result', $result);
        $this->assertTrue($called);
    }

    /** @test */
    public function test_with_retry_propagates_exception_from_retry_service(): void
    {
        $this->retry
            ->method('execute')
            ->willThrowException(new \RuntimeException('All retries exhausted'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('All retries exhausted');

        $this->makeDriver()->exposeWithRetry(fn () => 'unreachable');
    }

    // =========================================================================
    // Idempotency helpers
    // =========================================================================

    /** @test */
    public function test_validate_idempotency_key_passes_for_non_empty(): void
    {
        // Should not throw
        $this->makeDriver()->exposeValidateIdempotencyKey('uuid-v4-key');

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function test_validate_idempotency_key_throws_for_empty_string(): void
    {
        $this->expectException(IdempotencyException::class);

        $this->makeDriver()->exposeValidateIdempotencyKey('');
    }

    /** @test */
    public function test_validate_idempotency_key_throws_for_whitespace_only(): void
    {
        $this->expectException(IdempotencyException::class);

        $this->makeDriver()->exposeValidateIdempotencyKey('   ');
    }

    /** @test */
    public function test_validate_idempotency_key_throws_for_tab(): void
    {
        $this->expectException(IdempotencyException::class);

        $this->makeDriver()->exposeValidateIdempotencyKey("\t");
    }

    // =========================================================================
    // Capability helpers
    // =========================================================================

    /** @test */
    public function test_supports_operation_returns_true_when_not_implementing_interface(): void
    {
        // ConcreteTestDriver does NOT implement SupportsCapabilities
        $driver = $this->makeDriver();

        $this->assertTrue($driver->exposeSupportsOperation('charge'));
        $this->assertTrue($driver->exposeSupportsOperation('any_capability'));
    }

    /** @test */
    public function test_supports_operation_delegates_to_interface_when_implemented(): void
    {
        $driver = new CapableTestDriver($this->logger, $this->events, $this->retry, []);

        $this->assertTrue($driver->exposeSupportsOperation('charge'));
        $this->assertFalse($driver->exposeSupportsOperation('subscription'));
    }

    /** @test */
    public function test_assert_supports_does_not_throw_when_supported(): void
    {
        $driver = new CapableTestDriver($this->logger, $this->events, $this->retry, []);

        // 'charge' is supported — should not throw
        $driver->exposeAssertSupports('charge');

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function test_assert_supports_throws_when_not_supported(): void
    {
        $driver = new CapableTestDriver($this->logger, $this->events, $this->retry, []);

        $this->expectException(UnsupportedOperationException::class);

        $driver->exposeAssertSupports('subscription');
    }

    /** @test */
    public function test_unsupported_operation_exception_contains_driver_and_operation(): void
    {
        $driver = new CapableTestDriver(
            $this->logger,
            $this->events,
            $this->retry,
            ['driver_name' => 'qr_pay'],
        );

        try {
            $driver->exposeAssertSupports('createSubscription');
            $this->fail('Expected UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertStringContainsString('createSubscription', $e->getMessage());
            $this->assertStringContainsString('qr_pay', $e->getMessage());
        }
    }

    // =========================================================================
    // Exception mapping
    // =========================================================================

    /** @test */
    public function test_wrap_exception_returns_payment_exception_unchanged(): void
    {
        $original = new PaymentException('Already a payment exception');
        $wrapped  = $this->makeDriver()->exposeWrapException($original);

        $this->assertSame($original, $wrapped);
    }

    /** @test */
    public function test_wrap_exception_wraps_generic_exception(): void
    {
        $original = new \RuntimeException('Provider error');
        $wrapped  = $this->makeDriver()->exposeWrapException($original, ['operation' => 'charge']);

        $this->assertInstanceOf(PaymentException::class, $wrapped);
        $this->assertNotSame($original, $wrapped);
        $this->assertSame($original, $wrapped->getPrevious());
        $this->assertStringContainsString('charge', $wrapped->getMessage());
        $this->assertStringContainsString('Provider error', $wrapped->getMessage());
    }

    /** @test */
    public function test_wrap_exception_includes_driver_name_in_message(): void
    {
        $driver  = $this->makeDriver(['driver_name' => 'stripe']);
        $wrapped = $driver->exposeWrapException(new \RuntimeException('Err'), ['operation' => 'refund']);

        $this->assertStringContainsString('stripe', $wrapped->getMessage());
    }

    // =========================================================================
    // build_log_context
    // =========================================================================

    /** @test */
    public function test_build_log_context_contains_driver_operation_and_environment(): void
    {
        $driver  = $this->makeDriver(['driver_name' => 'paymob', 'sandbox' => true]);
        $context = $driver->exposeBuildLogContext('charge', ['extra' => 'value']);

        $this->assertSame('paymob', $context['driver']);
        $this->assertSame('charge', $context['operation']);
        $this->assertSame('sandbox', $context['environment']);
        $this->assertSame('value', $context['extra']);
    }

    // =========================================================================
    // assert_sandbox
    // =========================================================================

    /** @test */
    public function test_assert_sandbox_passes_in_sandbox_mode(): void
    {
        $driver = $this->makeDriver(['sandbox' => true]);
        $driver->exposeAssertSandbox(); // should not throw

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function test_assert_sandbox_throws_in_production_mode(): void
    {
        $driver = $this->makeDriver(['sandbox' => false]);

        $this->expectException(\RuntimeException::class);

        $driver->exposeAssertSandbox();
    }

    // =========================================================================
    // Property 11: Driver Methods Return Correct Response Contract
    // Feature: laravel-payment-framework, Property 11: Driver methods return correct response contract
    // =========================================================================
    /** @test */
    public function test_property_11_driver_methods_return_correct_response_contract(): void
    {
        // Feature: laravel-payment-framework, Property 11: Driver methods return correct response contract
        // FakePaymentDriver is a concrete AbstractDriver implementation — verify each
        // of its 15 methods returns an object implementing the correct response contract.
        $driver = new \Mifatoyeh\LaravelPaymentFramework\Testing\FakePaymentDriver();
        $money  = Money::ofMinor(1000, Currency::USD);
        $txnId  = TransactionId::fromString('txn_test');

        $paymentReq = new PaymentRequest(
            $money, Currency::USD, 'idem-001', new CustomerData('T', 't@t.com'),
        );

        $this->assertInstanceOf(
            \Mifatoyeh\LaravelPaymentFramework\Contracts\Responses\PaymentResponseContract::class,
            $driver->charge($paymentReq),
        );

        $this->assertInstanceOf(
            \Mifatoyeh\LaravelPaymentFramework\Contracts\Responses\PaymentResponseContract::class,
            $driver->authorize($paymentReq),
        );

        $captureReq = new CaptureRequest($txnId, $money, 'idem-002');
        $this->assertInstanceOf(
            \Mifatoyeh\LaravelPaymentFramework\Contracts\Responses\CaptureResponseContract::class,
            $driver->capture($captureReq),
        );

        $refundReq = new RefundRequest($txnId, $money, 'reason', 'idem-003');
        $this->assertInstanceOf(
            \Mifatoyeh\LaravelPaymentFramework\Contracts\Responses\RefundResponseContract::class,
            $driver->refund($refundReq),
        );

        $lookupReq = new TransactionLookupRequest($txnId);
        $this->assertInstanceOf(
            \Mifatoyeh\LaravelPaymentFramework\Contracts\Responses\StatusResponseContract::class,
            $driver->lookup($lookupReq),
        );

        $this->assertInstanceOf(
            \Mifatoyeh\LaravelPaymentFramework\Contracts\Responses\VerificationResponseContract::class,
            $driver->verify($lookupReq),
        );

        $webhookReq = new WebhookRequest('stripe', '{}', [], WebhookSignature::fromString(''));
        $this->assertInstanceOf(
            \Mifatoyeh\LaravelPaymentFramework\Contracts\Responses\WebhookResponseContract::class,
            $driver->processWebhook($webhookReq),
        );
    }

    /** @test */
    public function test_empty_idempotency_key_throws_before_driver_call(): void
    {
        // The FakePaymentDriver does NOT call validateIdempotencyKey — that's AbstractDriver's
        // responsibility in the wiring pattern. Here we test AbstractDriver directly.
        $driver = $this->makeDriver();

        $this->expectException(IdempotencyException::class);

        $driver->exposeValidateIdempotencyKey('');
    }
}

// =============================================================================
// Test stub: minimal concrete driver for testing AbstractDriver infrastructure
// =============================================================================

/**
 * Minimal concrete driver that exposes all protected AbstractDriver methods
 * for white-box unit testing. All 15 abstract methods are stubbed as
 * UnsupportedOperationException so tests never accidentally call them.
 *
 * @internal Only used in unit tests.
 */
class ConcreteTestDriver extends AbstractDriver
{
    // Expose protected methods for testing via public wrappers:

    public function exposeGetConfig(): array
    {
        return $this->getConfig();
    }

    public function exposeGetConfigValue(string $key, mixed $default = null): mixed
    {
        return $this->getConfigValue($key, $default);
    }

    public function exposeGetCredential(string $key, string $default = ''): string
    {
        return $this->getCredential($key, $default);
    }

    public function exposeGetTimeout(): int
    {
        return $this->getTimeout();
    }

    public function exposeGetEnvironment(): Environment
    {
        return $this->getEnvironment();
    }

    public function exposeIsSandbox(): bool
    {
        return $this->isSandbox();
    }

    public function exposeIsProduction(): bool
    {
        return $this->isProduction();
    }

    public function exposeLog(string $level, string $message, array $context = []): void
    {
        $this->log($level, $message, $context);
    }

    public function exposeLogInfo(string $message, array $context = []): void
    {
        $this->logInfo($message, $context);
    }

    public function exposeLogError(string $message, array $context = []): void
    {
        $this->logError($message, $context);
    }

    public function exposeDispatchEvent(object $event): void
    {
        $this->dispatchEvent($event);
    }

    public function exposeWithRetry(callable $operation): mixed
    {
        return $this->withRetry($operation);
    }

    public function exposeValidateIdempotencyKey(string $key): void
    {
        $this->validateIdempotencyKey($key);
    }

    public function exposeSupportsOperation(string $capability): bool
    {
        return $this->supportsOperation($capability);
    }

    public function exposeAssertSupports(string $capability): void
    {
        $this->assertSupports($capability);
    }

    public function exposeWrapException(\Throwable $e, array $context = []): PaymentException
    {
        return $this->wrapException($e, $context);
    }

    public function exposeBuildLogContext(string $operation, array $extra = []): array
    {
        return $this->buildLogContext($operation, $extra);
    }

    public function exposeAssertSandbox(): void
    {
        $this->assertSandbox();
    }

    // ── Abstract stubs (all throw — concrete test logic doesn't call them) ──

    public function authorize(\Mifatoyeh\LaravelPaymentFramework\DTO\PaymentRequest $request): PaymentResponse
    {
        throw new \LogicException('Not implemented in test stub.');
    }

    public function capture(\Mifatoyeh\LaravelPaymentFramework\DTO\CaptureRequest $request): CaptureResponse
    {
        throw new \LogicException('Not implemented in test stub.');
    }

    public function charge(\Mifatoyeh\LaravelPaymentFramework\DTO\PaymentRequest $request): PaymentResponse
    {
        throw new \LogicException('Not implemented in test stub.');
    }

    public function void(\Mifatoyeh\LaravelPaymentFramework\DTO\VoidRequest $request): VoidResponse
    {
        throw new \LogicException('Not implemented in test stub.');
    }

    public function refund(\Mifatoyeh\LaravelPaymentFramework\DTO\RefundRequest $request): RefundResponse
    {
        throw new \LogicException('Not implemented in test stub.');
    }

    public function partialRefund(\Mifatoyeh\LaravelPaymentFramework\DTO\RefundRequest $request): RefundResponse
    {
        throw new \LogicException('Not implemented in test stub.');
    }

    public function verify(\Mifatoyeh\LaravelPaymentFramework\DTO\TransactionLookupRequest $request): VerificationResponse
    {
        throw new \LogicException('Not implemented in test stub.');
    }

    public function lookup(\Mifatoyeh\LaravelPaymentFramework\DTO\TransactionLookupRequest $request): StatusResponse
    {
        throw new \LogicException('Not implemented in test stub.');
    }

    public function createPaymentLink(\Mifatoyeh\LaravelPaymentFramework\DTO\PaymentLinkRequest $request): PaymentLinkResponse
    {
        throw new \LogicException('Not implemented in test stub.');
    }

    public function saveCard(\Mifatoyeh\LaravelPaymentFramework\DTO\SaveCardRequest $request): PaymentResponse
    {
        throw new \LogicException('Not implemented in test stub.');
    }

    public function chargeToken(\Mifatoyeh\LaravelPaymentFramework\DTO\TokenChargeRequest $request): PaymentResponse
    {
        throw new \LogicException('Not implemented in test stub.');
    }

    public function createSubscription(\Mifatoyeh\LaravelPaymentFramework\DTO\SubscriptionRequest $request): SubscriptionResponse
    {
        throw new \LogicException('Not implemented in test stub.');
    }

    public function cancelSubscription(\Mifatoyeh\LaravelPaymentFramework\DTO\CancelSubscriptionRequest $request): SubscriptionResponse
    {
        throw new \LogicException('Not implemented in test stub.');
    }

    public function processWebhook(\Mifatoyeh\LaravelPaymentFramework\DTO\WebhookRequest $request): WebhookResponse
    {
        throw new \LogicException('Not implemented in test stub.');
    }

    public function verifyWebhookSignature(\Mifatoyeh\LaravelPaymentFramework\DTO\WebhookRequest $request): bool
    {
        throw new \LogicException('Not implemented in test stub.');
    }
}

/**
 * Concrete driver stub that also implements SupportsCapabilities.
 * Only 'charge' is supported; everything else returns false.
 *
 * @internal Only used in unit tests.
 */
class CapableTestDriver extends ConcreteTestDriver implements SupportsCapabilities
{
    public function supports(string $capability): bool
    {
        return $capability === 'charge';
    }
}
