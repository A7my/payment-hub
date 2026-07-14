<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Feature;

use Orchestra\Testbench\TestCase;
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\Facades\Payment;
use Mifatoyeh\LaravelPaymentFramework\Providers\PaymentServiceProvider;
use Mifatoyeh\LaravelPaymentFramework\Testing\FakePaymentDriver;
use Mifatoyeh\LaravelPaymentFramework\Testing\PaymentFactory;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;

/**
 * Feature tests for FakePaymentDriver assertion helpers.
 *
 * Verifies that assertCharged(), assertNotCharged(), and assertRefunded()
 * behave correctly relative to recorded calls.
 *
 * Also contains property-based test P19.
 */
class FakePaymentDriverTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PaymentServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Payment' => Payment::class];
    }

    /** @test */
    public function test_assert_charged_passes_after_charge(): void
    {
        // TODO: $fake = Payment::fake();
        // TODO: $request = PaymentFactory::paymentRequest()->withAmount(1000, Currency::USD)->make();
        // TODO: Payment::charge($request);
        // TODO: $fake->assertCharged(Money::of(1000, Currency::USD)); // should not throw
        $this->markTestIncomplete('TODO: Assert assertCharged() passes after a charge.');
    }

    /** @test */
    public function test_assert_not_charged_passes_before_any_charge(): void
    {
        // TODO: $fake = Payment::fake();
        // TODO: $fake->assertNotCharged(); // should not throw — no charges recorded yet
        $this->markTestIncomplete('TODO: Assert assertNotCharged() passes when no charge has been made.');
    }

    /** @test */
    public function test_assert_not_charged_fails_after_charge(): void
    {
        // TODO: $fake = Payment::fake();
        // TODO: Payment::charge(PaymentFactory::paymentRequest()->make());
        // TODO: $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        // TODO: $fake->assertNotCharged();
        $this->markTestIncomplete('TODO: Assert assertNotCharged() fails after a charge has been made.');
    }

    /** @test */
    public function test_assert_refunded_passes_after_refund(): void
    {
        // TODO: $fake = Payment::fake();
        // TODO: $refundRequest = PaymentFactory::refundRequest()->withTransactionId('txn_123')->make();
        // TODO: Payment::refund($refundRequest);
        // TODO: $fake->assertRefunded(TransactionId::fromString('txn_123')); // should not throw
        $this->markTestIncomplete('TODO: Assert assertRefunded() passes after a refund.');
    }

    // -------------------------------------------------------------------------
    // Property 19: FakePaymentDriver Assertion Round-Trip
    // Feature: laravel-payment-framework, Property 19: FakePaymentDriver assertion round-trip
    // -------------------------------------------------------------------------
    /** @test */
    public function test_property_19_fake_payment_driver_assertion_round_trip(): void
    {
        // Feature: laravel-payment-framework, Property 19: FakePaymentDriver assertion round-trip
        // TODO: Use Set::integers()->between(1, 1_000_000) for amounts.
        // TODO: Use Set::elements(...Currency::cases()) for currency.
        // TODO: Assert assertCharged(amount) does not throw after charge.
        // TODO: Assert assertNotCharged() throws after charge.
        // TODO: Assert assertNotCharged() does not throw before any charge.
        $this->markTestIncomplete('TODO: Implement property test P19 with innmind/black-box.');
    }
}
