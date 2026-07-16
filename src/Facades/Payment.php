<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Facades;

use Illuminate\Support\Facades\Facade;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\PaymentDriverContract;
use Mifatoyeh\LaravelPaymentFramework\DTO\CancelSubscriptionRequest;
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
use Mifatoyeh\LaravelPaymentFramework\Managers\PaymentManager;
use Mifatoyeh\LaravelPaymentFramework\Responses\CaptureResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentLinkResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\RefundResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\StatusResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\SubscriptionResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\VerificationResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\VoidResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\WebhookResponse;
use Mifatoyeh\LaravelPaymentFramework\Testing\FakePaymentDriver;

/**
 * Laravel Facade providing a clean, expressive API for the payment framework.
 *
 * All static calls are proxied to the resolved {@see PaymentManager} instance
 * from the IoC container. The active driver is determined by:
 *   1. The `PAYMENT_DRIVER` environment variable.
 *   2. The `payment.default` config key.
 *   3. An explicit `Payment::driver('name')` call.
 *
 * Switching providers requires only a config/env change — zero application
 * code changes.
 *
 * Usage:
 * ```php
 * // Charge using the default configured driver
 * $response = Payment::charge($paymentRequest);
 *
 * // Explicitly select a driver
 * $response = Payment::driver('paypal')->charge($paymentRequest);
 *
 * // In tests — swap for a fake driver with no real API calls
 * $fake = Payment::fake();
 * Payment::charge($paymentRequest);
 * $fake->assertCharged(Money::ofMinor(1000, Currency::USD));
 * ```
 *
 * PHPDoc @method stubs below enable IDE autocompletion for all proxied calls.
 * They are documentation only — the actual dispatch is handled by
 * {@see \Illuminate\Support\Manager::__call()} on the resolved PaymentManager.
 *
 * ── Driver operations ──────────────────────────────────────────────────────
 * @method static PaymentResponse      charge(PaymentRequest $request)
 * @method static PaymentResponse      authorize(PaymentRequest $request)
 * @method static CaptureResponse      capture(CaptureRequest $request)
 * @method static VoidResponse         void(VoidRequest $request)
 * @method static RefundResponse       refund(RefundRequest $request)
 * @method static RefundResponse       partialRefund(RefundRequest $request)
 * @method static VerificationResponse verify(TransactionLookupRequest $request)
 * @method static StatusResponse       lookup(TransactionLookupRequest $request)
 * @method static PaymentLinkResponse  createPaymentLink(PaymentLinkRequest $request)
 * @method static PaymentResponse      saveCard(SaveCardRequest $request)
 * @method static PaymentResponse      chargeToken(TokenChargeRequest $request)
 * @method static SubscriptionResponse createSubscription(SubscriptionRequest $request)
 * @method static SubscriptionResponse cancelSubscription(CancelSubscriptionRequest $request)
 * @method static WebhookResponse      processWebhook(WebhookRequest $request)
 * @method static bool                 verifyWebhookSignature(WebhookRequest $request)
 *
 * ── Manager operations ─────────────────────────────────────────────────────
 * @method static PaymentDriverContract driver(string|null $name = null)
 * @method static PaymentManager        extend(string $driver, \Closure|string $driverOrClosure)
 * @method static array<int,string>     getAvailableDrivers()
 * @method static void                  forgetDrivers()
 *
 * @see PaymentManager
 */
class Payment extends Facade
{
    /**
     * Get the registered binding key for the PaymentManager in the IoC container.
     *
     * Laravel's Facade base class uses this to resolve the underlying instance
     * when a static method call arrives.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return PaymentManager::class;
    }

    /**
     * Swap the active driver for a FakePaymentDriver for the duration of a test.
     *
     * Replaces the resolved PaymentManager binding with a minimal stub that
     * always returns the FakePaymentDriver instance for every `driver()` call.
     * This means all facade method calls (`Payment::charge()`, `Payment::refund()`,
     * etc.) will be recorded by the fake without hitting any real provider API.
     *
     * The original binding is NOT restored automatically — call
     * `Payment::clearResolvedInstance(PaymentManager::class)` or use Testbench's
     * `tearDown()` to reset state between tests.
     *
     * @return FakePaymentDriver The fake driver instance for assertion chaining.
     *
     * @example
     *   $fake = Payment::fake();
     *   Payment::charge($request);
     *   $fake->assertCharged(Money::ofMinor(1000, Currency::USD));
     */
    public static function fake(): FakePaymentDriver
    {
        $fake = new FakePaymentDriver();

        // Build a minimal PaymentManager substitute that always returns the fake
        // driver for every driver() call. We do NOT call parent::__construct()
        // because it requires an Application instance; instead we override all
        // relevant methods to be self-contained.
        $stub = new class ($fake) extends PaymentManager {
            private FakePaymentDriver $fakeDriver;

            public function __construct(FakePaymentDriver $fakeDriver)
            {
                // Intentionally skip parent constructor (requires App container).
                // This stub only needs to intercept driver() and getDefaultDriver().
                $this->fakeDriver = $fakeDriver;
            }

            /**
             * Always return the fake driver regardless of the requested name.
             *
             * @param mixed $driver Ignored — fake is always returned.
             *
             * @return FakePaymentDriver
             */
            public function driver(mixed $driver = null): FakePaymentDriver
            {
                return $this->fakeDriver;
            }

            /**
             * Report 'fake' as the resolved default driver name.
             *
             * @return string
             */
            public function getDefaultDriver(): string
            {
                return 'fake';
            }

            /**
             * Expose the underlying FakePaymentDriver for direct assertions.
             *
             * @return FakePaymentDriver
             */
            public function getFake(): FakePaymentDriver
            {
                return $this->fakeDriver;
            }

            /**
             * Proxy all driver-level method calls to the fake driver.
             *
             * Laravel's Manager::__call() normally does this via the resolved
             * driver instance. We replicate it here so calls like
             * Payment::charge($request) still reach the fake even when the
             * facade is resolved against this stub.
             *
             * @param string       $method     The method name.
             * @param array<mixed> $parameters The method parameters.
             *
             * @return mixed
             */
            public function __call($method, $parameters): mixed
            {
                return $this->fakeDriver->{$method}(...$parameters);
            }
        };

        static::swap($stub);

        return $fake;
    }
}
