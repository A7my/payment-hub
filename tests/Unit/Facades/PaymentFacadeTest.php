<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Facades;

use Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\PaymentDriverContract;
use Mifatoyeh\LaravelPaymentFramework\DTO\CustomerData;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentRequest;
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\DriverNotFoundException;
use Mifatoyeh\LaravelPaymentFramework\Facades\Payment;
use Mifatoyeh\LaravelPaymentFramework\Managers\PaymentManager;
use Mifatoyeh\LaravelPaymentFramework\Providers\PaymentServiceProvider;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentResponse;
use Mifatoyeh\LaravelPaymentFramework\Testing\FakePaymentDriver;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;
use Orchestra\Testbench\TestCase;

/**
 * Unit tests for the Payment facade.
 *
 * Covers: facade accessor, driver() delegation, fake(), extend() via facade,
 * getAvailableDrivers(), and that static calls proxy to the correct driver method.
 */
class PaymentFacadeTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PaymentServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Payment' => Payment::class];
    }

    protected function tearDown(): void
    {
        // Clear cached facade instance so fake() state does not bleed between tests.
        Payment::clearResolvedInstance(PaymentManager::class);
        parent::tearDown();
    }

    // =========================================================================
    // Facade accessor
    // =========================================================================

    /** @test */
    public function test_facade_resolves_payment_manager_from_container(): void
    {
        $resolved = Payment::getFacadeRoot();

        $this->assertInstanceOf(PaymentManager::class, $resolved);
    }

    /** @test */
    public function test_facade_accessor_returns_payment_manager_class_string(): void
    {
        // Access protected getFacadeAccessor() via reflection for white-box testing
        $reflection = new \ReflectionClass(Payment::class);
        $method     = $reflection->getMethod('getFacadeAccessor');
        $method->setAccessible(true);

        $accessor = $method->invoke(null);

        $this->assertSame(PaymentManager::class, $accessor);
    }

    // =========================================================================
    // driver() delegation
    // =========================================================================

    /** @test */
    public function test_driver_returns_payment_driver_contract_instance(): void
    {
        $this->app['config']->set('payment.default', 'fake');

        Payment::extend('fake', FakePaymentDriver::class);

        $driver = Payment::driver('fake');

        $this->assertInstanceOf(PaymentDriverContract::class, $driver);
    }

    /** @test */
    public function test_driver_without_argument_returns_default_driver(): void
    {
        $this->app['config']->set('payment.default', 'fake');
        Payment::extend('fake', FakePaymentDriver::class);

        $driver = Payment::driver();

        $this->assertInstanceOf(PaymentDriverContract::class, $driver);
    }

    /** @test */
    public function test_driver_returns_same_instance_on_second_call(): void
    {
        Payment::extend('fake', FakePaymentDriver::class);

        $first  = Payment::driver('fake');
        $second = Payment::driver('fake');

        $this->assertSame($first, $second);
    }

    /** @test */
    public function test_driver_throws_for_unknown_driver(): void
    {
        $this->expectException(DriverNotFoundException::class);

        Payment::driver('nonexistent_xyz_driver');
    }

    // =========================================================================
    // extend()
    // =========================================================================

    /** @test */
    public function test_extend_registers_custom_driver(): void
    {
        Payment::extend('custom_fake', FakePaymentDriver::class);

        $driver = Payment::driver('custom_fake');

        $this->assertInstanceOf(FakePaymentDriver::class, $driver);
    }

    /** @test */
    public function test_extend_with_closure_registers_driver(): void
    {
        Payment::extend('closure_fake', fn () => new FakePaymentDriver());

        $driver = Payment::driver('closure_fake');

        $this->assertInstanceOf(FakePaymentDriver::class, $driver);
    }

    // =========================================================================
    // getAvailableDrivers()
    // =========================================================================

    /** @test */
    public function test_get_available_drivers_returns_configured_keys(): void
    {
        $this->app['config']->set('payment.drivers', [
            'stripe'     => ['class' => 'StripeDriver'],
            'paypal'     => ['class' => 'PayPalDriver'],
        ]);

        $drivers = Payment::getAvailableDrivers();

        $this->assertContains('stripe', $drivers);
        $this->assertContains('paypal', $drivers);
    }

    // =========================================================================
    // fake()
    // =========================================================================

    /** @test */
    public function test_fake_returns_fake_payment_driver_instance(): void
    {
        $fake = Payment::fake();

        $this->assertInstanceOf(FakePaymentDriver::class, $fake);
    }

    /** @test */
    public function test_fake_swaps_facade_root_to_stub_manager(): void
    {
        Payment::fake();

        // After fake(), driver() should still return a PaymentDriverContract
        $driver = Payment::driver();

        $this->assertInstanceOf(PaymentDriverContract::class, $driver);
    }

    /** @test */
    public function test_fake_driver_is_returned_for_all_driver_names(): void
    {
        $fake = Payment::fake();

        $this->assertSame($fake, Payment::driver('stripe'));
        $this->assertSame($fake, Payment::driver('paypal'));
        $this->assertSame($fake, Payment::driver('myfatoorah'));
        $this->assertSame($fake, Payment::driver());
    }

    /** @test */
    public function test_charge_via_fake_records_call(): void
    {
        $fake = Payment::fake();

        $request = new PaymentRequest(
            amount: Money::ofMinor(1000, Currency::USD),
            currency: Currency::USD,
            idempotencyKey: 'idem-facade-test-001',
            customer: new CustomerData('Test User', 'test@example.com'),
        );

        $response = Payment::charge($request);

        $this->assertInstanceOf(PaymentResponse::class, $response);
        $fake->assertCharged(Money::ofMinor(1000, Currency::USD));
    }

    /** @test */
    public function test_assert_not_charged_passes_before_any_charge(): void
    {
        $fake = Payment::fake();

        // No charges made yet
        $fake->assertNotCharged();

        $this->addToAssertionCount(1); // assertNotCharged did not throw
    }

    /** @test */
    public function test_assert_not_charged_fails_after_charge(): void
    {
        $fake    = Payment::fake();
        $request = new PaymentRequest(
            Money::ofMinor(500, Currency::USD),
            Currency::USD,
            'idem-not-charged-002',
            new CustomerData('Alice', 'alice@example.com'),
        );

        Payment::charge($request);

        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $fake->assertNotCharged();
    }

    /** @test */
    public function test_fake_proxies_method_calls_to_fake_driver(): void
    {
        $fake = Payment::fake();

        // Payment::refund() should route through the fake manager's __call()
        // which delegates to FakePaymentDriver::refund()
        $request = new \Mifatoyeh\LaravelPaymentFramework\DTO\RefundRequest(
            transactionId: \Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId::fromString('txn_facade_001'),
            amount: Money::ofMinor(300, Currency::USD),
            reason: 'Test refund via facade',
            idempotencyKey: 'idem-refund-facade-001',
        );

        $response = Payment::refund($request);

        $this->assertInstanceOf(
            \Mifatoyeh\LaravelPaymentFramework\Responses\RefundResponse::class,
            $response,
        );

        $fake->assertRefunded(
            \Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId::fromString('txn_facade_001'),
        );
    }

    // =========================================================================
    // Static method forwarding
    // =========================================================================

    /** @test */
    public function test_static_call_is_proxied_to_default_driver(): void
    {
        Payment::fake();

        // Payment::lookup() should hit the default driver via __call
        $lookupRequest = new \Mifatoyeh\LaravelPaymentFramework\DTO\TransactionLookupRequest(
            \Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId::fromString('txn_lookup_facade'),
        );

        $response = Payment::lookup($lookupRequest);

        $this->assertInstanceOf(
            \Mifatoyeh\LaravelPaymentFramework\Responses\StatusResponse::class,
            $response,
        );
    }
}
