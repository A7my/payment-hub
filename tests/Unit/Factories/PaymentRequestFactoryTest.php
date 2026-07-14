<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Factories;

use Mifatoyeh\LaravelPaymentFramework\DTO\CustomerData;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentRequest;
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentMethod;
use Mifatoyeh\LaravelPaymentFramework\Factories\PaymentRequestFactory;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PaymentRequestFactory's array → PaymentRequest translation,
 * with a focus on the provider-options pipeline: unknown array keys must be
 * collected into PaymentRequest::$options rather than silently discarded,
 * while known framework keys must keep populating their dedicated DTO
 * properties (never swept into options).
 */
final class PaymentRequestFactoryTest extends TestCase
{
    private PaymentRequestFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new PaymentRequestFactory();
    }

    /**
     * @return array<string, mixed>
     */
    private function baseArray(array $overrides = []): array
    {
        return array_merge([
            'amount'   => 1000,
            'currency' => 'USD',
            'customer' => [
                'name'  => 'Mohamed',
                'email' => 'azmy@example.com',
            ],
        ], $overrides);
    }

    // =========================================================================
    // Unknown keys become PaymentRequest->options
    // =========================================================================

    /** @test */
    public function test_unknown_array_keys_are_collected_into_options(): void
    {
        $request = $this->factory->toPaymentRequest($this->baseArray([
            'automatic_payment_methods' => [
                'enabled'         => true,
                'allow_redirects' => 'never',
            ],
            'capture_method'     => 'manual',
            'setup_future_usage' => 'off_session',
        ]));

        $this->assertSame(
            [
                'automatic_payment_methods' => [
                    'enabled'         => true,
                    'allow_redirects' => 'never',
                ],
                'capture_method'     => 'manual',
                'setup_future_usage' => 'off_session',
            ],
            $request->options,
        );
    }

    /** @test */
    public function test_no_unknown_keys_results_in_empty_options(): void
    {
        $request = $this->factory->toPaymentRequest($this->baseArray());

        $this->assertSame([], $request->options);
    }

    /** @test */
    public function test_exact_example_from_the_stripe_integration_bug_report(): void
    {
        // The literal scenario reported: options must reach the DTO instead
        // of being silently dropped before ever reaching the driver.
        $request = $this->factory->toPaymentRequest([
            'amount'   => 100,
            'currency' => 'USD',
            'customer' => [
                'name'  => 'Mohamed',
                'email' => 'azmy@example.com',
            ],
            'automatic_payment_methods' => [
                'enabled'         => true,
                'allow_redirects' => 'never',
            ],
            'confirm'  => true,
            'metadata' => ['order_id' => 123],
        ]);

        $this->assertSame(['order_id' => 123], $request->metadata);
        $this->assertSame(
            [
                'automatic_payment_methods' => [
                    'enabled'         => true,
                    'allow_redirects' => 'never',
                ],
                'confirm' => true,
            ],
            $request->options,
        );
    }

    // =========================================================================
    // Known framework fields still populate DTO properties (never swept into options)
    // =========================================================================

    /** @test */
    public function test_known_framework_keys_populate_dedicated_properties_not_options(): void
    {
        $request = $this->factory->toPaymentRequest($this->baseArray([
            'idempotency_key' => 'idem-known-001',
            'return_url'      => 'https://example.com/return',
            'cancel_url'      => 'https://example.com/cancel',
            'metadata'        => ['order_id' => 15],
            'payment_method'  => 'card',
            'unknown_option'  => 'should-be-in-options',
        ]));

        $this->assertSame(1000, $request->amount->amount);
        $this->assertSame(Currency::USD, $request->currency);
        $this->assertSame('idem-known-001', $request->idempotencyKey);
        $this->assertSame('https://example.com/return', $request->returnUrl);
        $this->assertSame('https://example.com/cancel', $request->cancelUrl);
        $this->assertSame(['order_id' => 15], $request->metadata);
        $this->assertSame(PaymentMethod::Card, $request->paymentMethod);

        // None of the known keys leak into options ...
        $this->assertArrayNotHasKey('amount', $request->options);
        $this->assertArrayNotHasKey('currency', $request->options);
        $this->assertArrayNotHasKey('idempotency_key', $request->options);
        $this->assertArrayNotHasKey('return_url', $request->options);
        $this->assertArrayNotHasKey('cancel_url', $request->options);
        $this->assertArrayNotHasKey('metadata', $request->options);
        $this->assertArrayNotHasKey('payment_method', $request->options);

        // ... but the genuinely unknown one does.
        $this->assertSame(['unknown_option' => 'should-be-in-options'], $request->options);
    }

    /** @test */
    public function test_order_and_billing_address_keys_are_not_swept_into_options(): void
    {
        $request = $this->factory->toPaymentRequest($this->baseArray([
            'order' => [
                'order_id'    => 'order-001',
                'description' => 'Test order',
            ],
            'billing_address' => [
                'line1'       => '123 Main St',
                'city'        => 'Cairo',
                'country'     => 'EG',
                'postal_code' => '12345',
            ],
        ]));

        $this->assertTrue($request->hasOrder());
        $this->assertTrue($request->hasBillingAddress());
        $this->assertSame([], $request->options);
    }

    // =========================================================================
    // Existing API remains unchanged
    // =========================================================================

    /** @test */
    public function test_passing_an_already_built_payment_request_is_returned_unchanged(): void
    {
        $original = new PaymentRequest(
            amount: Money::ofMinor(500, Currency::USD),
            currency: Currency::USD,
            idempotencyKey: 'dto-passthrough',
            customer: new CustomerData('Jane', 'jane@example.com'),
            options: ['capture_method' => 'manual'],
        );

        $result = $this->factory->toPaymentRequest($original);

        $this->assertSame($original, $result);
        $this->assertSame(['capture_method' => 'manual'], $result->options);
    }

    /** @test */
    public function test_array_construction_without_any_provider_options_still_works_exactly_as_before(): void
    {
        $request = $this->factory->toPaymentRequest($this->baseArray());

        $this->assertInstanceOf(PaymentRequest::class, $request);
        $this->assertTrue($request->amount->currency === Currency::USD);
        $this->assertSame('Mohamed', $request->customer->name);
        $this->assertSame('azmy@example.com', $request->customer->email);
    }
}
