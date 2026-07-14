<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\RefundRequest;
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\Testing\PaymentFactory;

/**
 * Unit tests for PaymentFactory.
 *
 * Also contains property-based test P20.
 */
class PaymentFactoryTest extends TestCase
{
    /** @test */
    public function test_payment_request_factory_produces_valid_dto(): void
    {
        // TODO: $request = PaymentFactory::paymentRequest()->make();
        // TODO: $this->assertInstanceOf(PaymentRequest::class, $request);
        $this->markTestIncomplete('TODO: Assert PaymentFactory produces a valid PaymentRequest.');
    }

    /** @test */
    public function test_refund_request_factory_produces_valid_dto(): void
    {
        // TODO: $request = PaymentFactory::refundRequest()->make();
        // TODO: $this->assertInstanceOf(RefundRequest::class, $request);
        $this->markTestIncomplete('TODO: Assert PaymentFactory produces a valid RefundRequest.');
    }

    /** @test */
    public function test_fluent_builder_overrides_defaults(): void
    {
        // TODO: $request = PaymentFactory::paymentRequest()
        //     ->withAmount(5000, Currency::EUR)
        //     ->withCustomer('Alice', 'alice@example.com')
        //     ->withIdempotencyKey('custom-key-123')
        //     ->make();
        // TODO: $this->assertSame(5000, $request->amount->amount);
        // TODO: $this->assertSame(Currency::EUR, $request->amount->currency);
        // TODO: $this->assertSame('alice@example.com', $request->customer->email);
        $this->markTestIncomplete('TODO: Assert fluent builder overrides apply correctly.');
    }

    // -------------------------------------------------------------------------
    // Property 20: PaymentFactory Produces Valid DTOs
    // Feature: laravel-payment-framework, Property 20: PaymentFactory produces valid DTOs
    // -------------------------------------------------------------------------
    /** @test */
    public function test_property_20_payment_factory_produces_valid_dtos(): void
    {
        // Feature: laravel-payment-framework, Property 20: PaymentFactory produces valid DTOs
        // TODO: Generate random valid amounts, customer names/emails, idempotency keys.
        // TODO: Assert produced DTO is non-null, instanceof expected class, and valid.
        $this->markTestIncomplete('TODO: Implement property test P20 with innmind/black-box.');
    }
}
