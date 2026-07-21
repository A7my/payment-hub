<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Checkout;

use Mifatoyeh\LaravelPaymentFramework\Checkout\CheckoutContext;
use Mifatoyeh\LaravelPaymentFramework\Checkout\CheckoutTransaction;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see CheckoutContext} — no database needed, just building
 * an in-memory (unsaved) {@see CheckoutTransaction} and reading its
 * attributes back out.
 */
final class CheckoutContextTest extends TestCase
{
    /** @test */
    public function test_from_transaction_reads_payer_id_and_os_out_of_metadata(): void
    {
        $transaction = new CheckoutTransaction([
            'driver'             => 'stripe',
            'driver_type'        => 'webview',
            'merchant_order_id'  => 'idem-001',
            'metadata'           => [
                'payer_id'   => '42',
                'os'         => 'web',
                'return_url' => 'https://example.com/thanks',
            ],
        ]);

        $context = CheckoutContext::fromTransaction($transaction);

        $this->assertSame('42', $context->payerId);
        $this->assertSame('stripe', $context->driver);
        $this->assertSame('webview', $context->driverType);
        $this->assertSame('web', $context->os);
        $this->assertSame('idem-001', $context->merchantOrderId);
    }

    /** @test */
    public function test_from_transaction_handles_a_missing_payer_id_gracefully(): void
    {
        // Unauthenticated checkout, or a row written before this feature —
        // must not fatal on a missing metadata key.
        $transaction = new CheckoutTransaction([
            'driver'             => 'paymob',
            'driver_type'        => 'sdk',
            'merchant_order_id'  => 'idem-002',
            'metadata'           => ['os' => 'mobile'],
        ]);

        $context = CheckoutContext::fromTransaction($transaction);

        $this->assertNull($context->payerId);
        $this->assertSame('mobile', $context->os);
    }

    /** @test */
    public function test_from_transaction_handles_entirely_null_metadata(): void
    {
        $transaction = new CheckoutTransaction([
            'driver'             => 'paymob',
            'driver_type'        => null,
            'merchant_order_id'  => 'idem-003',
            'metadata'           => null,
        ]);

        $context = CheckoutContext::fromTransaction($transaction);

        $this->assertNull($context->payerId);
        $this->assertNull($context->os);
        $this->assertNull($context->driverType);
    }

    /** @test */
    public function test_without_transaction_returns_a_context_with_only_driver_fields_set(): void
    {
        $context = CheckoutContext::withoutTransaction('stripe', 'webview');

        $this->assertNull($context->payerId);
        $this->assertSame('stripe', $context->driver);
        $this->assertSame('webview', $context->driverType);
        $this->assertNull($context->os);
        $this->assertNull($context->merchantOrderId);
    }

    /** @test */
    public function test_json_serialize_uses_snake_case_keys(): void
    {
        $context = new CheckoutContext(
            payerId: '7',
            driver: 'stripe',
            driverType: 'webview',
            os: 'web',
            merchantOrderId: 'idem-004',
            custom: ['discount_code' => 'WELCOME10'],
        );

        $this->assertSame([
            'payer_id'          => '7',
            'driver'            => 'stripe',
            'driver_type'       => 'webview',
            'os'                => 'web',
            'merchant_order_id' => 'idem-004',
            'custom'            => ['discount_code' => 'WELCOME10'],
        ], $context->jsonSerialize());
    }

    /** @test */
    public function test_from_transaction_reads_custom_data_captured_via_capturescheckoutcontext(): void
    {
        $transaction = new CheckoutTransaction([
            'driver'             => 'stripe',
            'driver_type'        => 'webview',
            'merchant_order_id'  => 'idem-005',
            'metadata'           => [
                'os'     => 'web',
                'custom' => ['discount_code' => 'WELCOME10', 'referrer' => 'affiliate-42'],
            ],
        ]);

        $context = CheckoutContext::fromTransaction($transaction);

        $this->assertSame(['discount_code' => 'WELCOME10', 'referrer' => 'affiliate-42'], $context->custom);
        $this->assertSame('WELCOME10', $context->get('discount_code'));
        $this->assertNull($context->get('missing_key'));
        $this->assertSame('fallback', $context->get('missing_key', 'fallback'));
    }

    /** @test */
    public function test_from_transaction_defaults_custom_to_an_empty_array_when_absent(): void
    {
        $transaction = new CheckoutTransaction([
            'driver'             => 'stripe',
            'driver_type'        => 'webview',
            'merchant_order_id'  => 'idem-006',
            'metadata'           => ['os' => 'web'],
        ]);

        $context = CheckoutContext::fromTransaction($transaction);

        $this->assertSame([], $context->custom);
    }
}
