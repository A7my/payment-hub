<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Feature;

use Orchestra\Testbench\TestCase;
use Illuminate\Support\Facades\Event;
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentFailed;
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentInitiated;
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentSucceeded;
use Mifatoyeh\LaravelPaymentFramework\Facades\Payment;
use Mifatoyeh\LaravelPaymentFramework\Providers\PaymentServiceProvider;
use Mifatoyeh\LaravelPaymentFramework\Testing\FakePaymentDriver;
use Mifatoyeh\LaravelPaymentFramework\Testing\PaymentFactory;

/**
 * Feature tests for the full payment charge flow.
 *
 * Uses FakePaymentDriver via Payment::fake() — no real provider calls.
 * Also contains property-based tests P12, P13, P21.
 */
class PaymentChargeTest extends TestCase
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
    public function test_charge_returns_payment_response(): void
    {
        // TODO: $fake = Payment::fake();
        // TODO: $request = PaymentFactory::paymentRequest()->make();
        // TODO: $response = Payment::charge($request);
        // TODO: $this->assertTrue($response->isSuccessful());
        $this->markTestIncomplete('TODO: Assert Payment::charge() returns successful PaymentResponse.');
    }

    /** @test */
    public function test_payment_initiated_event_dispatched(): void
    {
        // TODO: Event::fake();
        // TODO: Payment::fake();
        // TODO: Payment::charge(PaymentFactory::paymentRequest()->make());
        // TODO: Event::assertDispatched(PaymentInitiated::class);
        $this->markTestIncomplete('TODO: Assert PaymentInitiated event is dispatched before driver call.');
    }

    /** @test */
    public function test_payment_succeeded_event_dispatched_on_success(): void
    {
        // TODO: Event::fake();
        // TODO: Payment::fake();
        // TODO: Payment::charge(PaymentFactory::paymentRequest()->make());
        // TODO: Event::assertDispatched(PaymentSucceeded::class);
        $this->markTestIncomplete('TODO: Assert PaymentSucceeded event is dispatched on success.');
    }

    /** @test */
    public function test_payment_failed_event_dispatched_on_failure(): void
    {
        // TODO: Event::fake();
        // TODO: Payment::fake()->failing();
        // TODO: Payment::charge(PaymentFactory::paymentRequest()->make());
        // TODO: Event::assertDispatched(PaymentFailed::class);
        $this->markTestIncomplete('TODO: Assert PaymentFailed event is dispatched on failure.');
    }

    /** @test */
    public function test_logger_receives_info_call_for_charge(): void
    {
        // TODO: Bind a MockPaymentLogger that captures calls.
        // TODO: Payment::fake();
        // TODO: Payment::charge(PaymentFactory::paymentRequest()->make());
        // TODO: Assert MockPaymentLogger received at least one info() call.
        $this->markTestIncomplete('TODO: Assert logger receives info() call for each driver operation.');
    }

    // -------------------------------------------------------------------------
    // Property 12: Event Payload Completeness
    // Feature: laravel-payment-framework, Property 12: Event payload completeness
    // -------------------------------------------------------------------------
    /** @test */
    public function test_property_12_event_payload_completeness(): void
    {
        // Feature: laravel-payment-framework, Property 12: Event payload completeness
        // TODO: Use PaymentFactory to generate random PaymentRequest instances.
        // TODO: Use Event::fake(); assert PaymentSucceeded carries original request + PaymentResponse.
        $this->markTestIncomplete('TODO: Implement property test P12 with innmind/black-box.');
    }

    // -------------------------------------------------------------------------
    // Property 13: PaymentFailed Always Dispatched on Failure
    // Feature: laravel-payment-framework, Property 13: PaymentFailed always dispatched on failure
    // -------------------------------------------------------------------------
    /** @test */
    public function test_property_13_payment_failed_always_dispatched_on_failure(): void
    {
        // Feature: laravel-payment-framework, Property 13: PaymentFailed always dispatched on failure
        // TODO: Configure FakePaymentDriver to return failure; verify PaymentFailed dispatched once.
        $this->markTestIncomplete('TODO: Implement property test P13 with innmind/black-box.');
    }

    // -------------------------------------------------------------------------
    // Property 21: Logger Receives Call for Every Driver Operation
    // Feature: laravel-payment-framework, Property 21: Logger receives call for every driver operation
    // -------------------------------------------------------------------------
    /** @test */
    public function test_property_21_logger_receives_call_for_every_driver_operation(): void
    {
        // Feature: laravel-payment-framework, Property 21: Logger receives call for every driver operation
        // TODO: Use Set::elements() over 15 driver method names.
        // TODO: Assert bound MockPaymentLogger receives >= 1 info() call per operation.
        $this->markTestIncomplete('TODO: Implement property test P21 with innmind/black-box.');
    }
}
