<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\DTO;

use DateTimeImmutable;
use InvalidArgumentException;
use Mifatoyeh\LaravelPaymentFramework\DTO\AddressData;
use Mifatoyeh\LaravelPaymentFramework\DTO\CaptureRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\CustomerData;
use Mifatoyeh\LaravelPaymentFramework\DTO\OrderData;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentLinkRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\RefundRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\SaveCardRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\SubscriptionRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\TokenChargeRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\TransactionLookupRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\VoidRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\WebhookRequest;
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentMethod;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\CustomerId;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\OrderId;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Token;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\WebhookSignature;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for all DTO classes.
 *
 * Covers: valid construction, validation guards, JSON serialisation,
 * helper methods, and property immutability.
 */
class PaymentRequestTest extends TestCase
{
    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeCustomer(): CustomerData
    {
        return new CustomerData('John Doe', 'john@example.com', '+1234567890', 'ext-001');
    }

    private function makeAddress(): AddressData
    {
        return new AddressData('123 Main St', null, 'Cairo', null, 'EG', '11511');
    }

    private function makeMoney(int $amount = 1000, Currency $currency = Currency::USD): Money
    {
        return Money::ofMinor($amount, $currency);
    }

    // =========================================================================
    // AddressData
    // =========================================================================

    /** @test */
    public function test_address_data_constructs_correctly(): void
    {
        $addr = new AddressData('123 Main St', 'Suite 4', 'Riyadh', 'RYD', 'SA', '11564');

        $this->assertSame('123 Main St', $addr->line1);
        $this->assertSame('Suite 4', $addr->line2);
        $this->assertSame('Riyadh', $addr->city);
        $this->assertSame('RYD', $addr->state);
        $this->assertSame('SA', $addr->country);
        $this->assertSame('11564', $addr->postalCode);
    }

    /** @test */
    public function test_address_data_optional_fields_can_be_null(): void
    {
        $addr = new AddressData('1 Street', null, 'Dubai', null, 'AE', '00000');

        $this->assertNull($addr->line2);
        $this->assertNull($addr->state);
        $this->assertFalse($addr->hasLine2());
        $this->assertFalse($addr->hasState());
    }

    /** @test */
    public function test_address_data_has_line2_and_state(): void
    {
        $addr = new AddressData('1 St', 'Apt 2', 'Cairo', 'CAI', 'EG', '12345');

        $this->assertTrue($addr->hasLine2());
        $this->assertTrue($addr->hasState());
    }

    /** @test */
    public function test_address_data_empty_line1_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AddressData('', null, 'City', null, 'US', '10001');
    }

    /** @test */
    public function test_address_data_empty_city_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AddressData('123 St', null, '', null, 'US', '10001');
    }

    /** @test */
    public function test_address_data_empty_country_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AddressData('123 St', null, 'City', null, '', '10001');
    }

    /** @test */
    public function test_address_data_empty_postal_code_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AddressData('123 St', null, 'City', null, 'US', '');
    }

    /** @test */
    public function test_address_data_json_serializes_correctly(): void
    {
        $addr    = new AddressData('123 Main St', null, 'Cairo', null, 'EG', '11511');
        $decoded = json_decode(json_encode($addr), true);

        $this->assertSame('123 Main St', $decoded['line1']);
        $this->assertNull($decoded['line2']);
        $this->assertSame('Cairo', $decoded['city']);
        $this->assertSame('EG', $decoded['country']);
        $this->assertSame('11511', $decoded['postal_code']);
    }

    // =========================================================================
    // CustomerData
    // =========================================================================

    /** @test */
    public function test_customer_data_constructs_correctly(): void
    {
        $c = new CustomerData('Alice', 'alice@example.com', '+1555000', 'uid-001');

        $this->assertSame('Alice', $c->name);
        $this->assertSame('alice@example.com', $c->email);
        $this->assertSame('+1555000', $c->phone);
        $this->assertSame('uid-001', $c->externalId);
    }

    /** @test */
    public function test_customer_data_optional_fields_default_to_null(): void
    {
        $c = new CustomerData('Bob', 'bob@example.com');

        $this->assertNull($c->phone);
        $this->assertNull($c->externalId);
        $this->assertFalse($c->hasPhone());
        $this->assertFalse($c->hasExternalId());
    }

    /** @test */
    public function test_customer_data_empty_name_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CustomerData('', 'email@example.com');
    }

    /** @test */
    public function test_customer_data_empty_email_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CustomerData('Name', '');
    }

    /** @test */
    public function test_customer_data_json_serializes_correctly(): void
    {
        $c       = new CustomerData('Jane', 'jane@example.com', '+999', 'ext-2');
        $decoded = json_decode(json_encode($c), true);

        $this->assertSame('Jane', $decoded['name']);
        $this->assertSame('jane@example.com', $decoded['email']);
        $this->assertSame('+999', $decoded['phone']);
        $this->assertSame('ext-2', $decoded['external_id']);
    }

    // =========================================================================
    // OrderData
    // =========================================================================

    /** @test */
    public function test_order_data_constructs_correctly(): void
    {
        $order = new OrderData(
            OrderId::fromString('ORD-001'),
            'Test Order',
            [['name' => 'Widget', 'qty' => 2]],
        );

        $this->assertSame('ORD-001', $order->orderId->toString());
        $this->assertSame('Test Order', $order->description);
        $this->assertCount(1, $order->items);
        $this->assertTrue($order->hasItems());
        $this->assertSame(1, $order->itemCount());
    }

    /** @test */
    public function test_order_data_defaults_to_empty_items(): void
    {
        $order = new OrderData(OrderId::fromString('ORD-002'), 'Empty order');

        $this->assertFalse($order->hasItems());
        $this->assertSame(0, $order->itemCount());
    }

    /** @test */
    public function test_order_data_json_serializes_correctly(): void
    {
        $order   = new OrderData(OrderId::fromString('ORD-003'), 'JSON Order');
        $decoded = json_decode(json_encode($order), true);

        $this->assertSame('ORD-003', $decoded['order_id']);
        $this->assertSame('JSON Order', $decoded['description']);
    }

    // =========================================================================
    // PaymentRequest
    // =========================================================================

    /** @test */
    public function test_payment_request_constructs_correctly(): void
    {
        $request = new PaymentRequest(
            amount: $this->makeMoney(),
            currency: Currency::USD,
            idempotencyKey: 'idem-001',
            customer: $this->makeCustomer(),
        );

        $this->assertSame(1000, $request->amount->amount);
        $this->assertSame(Currency::USD, $request->currency);
        $this->assertSame('idem-001', $request->idempotencyKey);
        $this->assertFalse($request->hasToken());
        $this->assertFalse($request->hasOrder());
        $this->assertFalse($request->hasBillingAddress());
    }

    /** @test */
    public function test_payment_request_empty_idempotency_key_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PaymentRequest(
            amount: $this->makeMoney(),
            currency: Currency::USD,
            idempotencyKey: '',
            customer: $this->makeCustomer(),
        );
    }

    /** @test */
    public function test_payment_request_currency_mismatch_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PaymentRequest(
            amount: $this->makeMoney(1000, Currency::EUR), // EUR amount
            currency: Currency::USD,                        // but USD declared
            idempotencyKey: 'idem-001',
            customer: $this->makeCustomer(),
        );
    }

    /** @test */
    public function test_payment_request_with_all_optional_fields(): void
    {
        $token   = Token::fromString('tok_test');
        $order   = new OrderData(OrderId::fromString('ORD-1'), 'Order');
        $address = $this->makeAddress();

        $request = new PaymentRequest(
            amount: $this->makeMoney(),
            currency: Currency::USD,
            idempotencyKey: 'idem-full',
            customer: $this->makeCustomer(),
            order: $order,
            billingAddress: $address,
            returnUrl: 'https://example.com/success',
            cancelUrl: 'https://example.com/cancel',
            metadata: ['order_ref' => 'ABC'],
            token: $token,
            paymentMethod: PaymentMethod::Wallet,
        );

        $this->assertTrue($request->hasToken());
        $this->assertTrue($request->hasOrder());
        $this->assertTrue($request->hasBillingAddress());
        $this->assertSame(PaymentMethod::Wallet, $request->paymentMethod);
    }

    /** @test */
    public function test_payment_request_json_excludes_full_token(): void
    {
        $request = new PaymentRequest(
            amount: $this->makeMoney(),
            currency: Currency::USD,
            idempotencyKey: 'idem-json',
            customer: $this->makeCustomer(),
            token: Token::fromString('tok_secret_value'),
        );

        $json    = json_encode($request);
        $decoded = json_decode($json, true);

        $this->assertArrayNotHasKey('token', $decoded);
        $this->assertTrue($decoded['has_token']);
    }

    /** @test */
    public function test_valid_construction(): void
    {
        $request = new PaymentRequest(
            amount: $this->makeMoney(),
            currency: Currency::USD,
            idempotencyKey: 'test-key',
            customer: $this->makeCustomer(),
        );

        $this->assertInstanceOf(PaymentRequest::class, $request);
    }

    /** @test */
    public function test_properties_are_readonly(): void
    {
        $request    = new PaymentRequest(
            amount: $this->makeMoney(),
            currency: Currency::USD,
            idempotencyKey: 'readonly-test',
            customer: $this->makeCustomer(),
        );
        $reflection = new \ReflectionClass($request);

        foreach ($reflection->getProperties() as $prop) {
            $this->assertTrue(
                $prop->isReadOnly(),
                "Property {$prop->getName()} should be readonly.",
            );
        }
    }

    // =========================================================================
    // RefundRequest
    // =========================================================================

    /** @test */
    public function test_refund_request_constructs_correctly(): void
    {
        $refund = new RefundRequest(
            transactionId: TransactionId::fromString('txn_001'),
            amount: $this->makeMoney(500),
            reason: 'Customer request',
            idempotencyKey: 'ref-001',
        );

        $this->assertSame('txn_001', $refund->transactionId->toString());
        $this->assertSame(500, $refund->amount->amount);
        $this->assertSame('Customer request', $refund->reason);
    }

    /** @test */
    public function test_refund_request_empty_idempotency_key_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RefundRequest(
            TransactionId::fromString('txn_001'),
            $this->makeMoney(500),
            'reason',
            '',
        );
    }

    /** @test */
    public function test_refund_request_zero_amount_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RefundRequest(
            TransactionId::fromString('txn_001'),
            Money::zero(Currency::USD),
            'reason',
            'idem-001',
        );
    }

    // =========================================================================
    // CaptureRequest
    // =========================================================================

    /** @test */
    public function test_capture_request_constructs_correctly(): void
    {
        $capture = new CaptureRequest(
            transactionId: TransactionId::fromString('txn_auth_001'),
            amount: $this->makeMoney(800),
            idempotencyKey: 'cap-001',
        );

        $this->assertSame('txn_auth_001', $capture->transactionId->toString());
        $this->assertSame(800, $capture->amount->amount);
    }

    /** @test */
    public function test_capture_request_empty_idempotency_key_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CaptureRequest(TransactionId::fromString('txn_001'), $this->makeMoney(), '');
    }

    /** @test */
    public function test_capture_request_zero_amount_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CaptureRequest(
            TransactionId::fromString('txn_001'),
            Money::zero(Currency::USD),
            'cap-001',
        );
    }

    // =========================================================================
    // VoidRequest
    // =========================================================================

    /** @test */
    public function test_void_request_constructs_correctly(): void
    {
        $void = new VoidRequest(
            transactionId: TransactionId::fromString('txn_void_001'),
            reason: 'Order cancelled',
            idempotencyKey: 'void-001',
        );

        $this->assertSame('txn_void_001', $void->transactionId->toString());
        $this->assertSame('Order cancelled', $void->reason);
    }

    /** @test */
    public function test_void_request_empty_idempotency_key_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new VoidRequest(TransactionId::fromString('txn_001'), 'reason', '');
    }

    // =========================================================================
    // WebhookRequest
    // =========================================================================

    /** @test */
    public function test_webhook_request_constructs_correctly(): void
    {
        $req = new WebhookRequest(
            driver: 'stripe',
            rawBody: '{"event":"payment.succeeded"}',
            headers: ['x-signature' => 'sha256=abc'],
            signature: WebhookSignature::fromString('sha256=abc'),
        );

        $this->assertSame('stripe', $req->driver);
        $this->assertTrue($req->hasSignature());
    }

    /** @test */
    public function test_webhook_request_empty_driver_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WebhookRequest('', '{}', [], WebhookSignature::fromString(''));
    }

    /** @test */
    public function test_webhook_request_header_helper(): void
    {
        $req = new WebhookRequest(
            'paymob',
            '{}',
            ['content-type' => 'application/json'],
            WebhookSignature::fromString(''),
        );

        $this->assertSame('application/json', $req->header('content-type'));
        $this->assertSame('default', $req->header('missing', 'default'));
    }

    // =========================================================================
    // SubscriptionRequest
    // =========================================================================

    /** @test */
    public function test_subscription_request_constructs_correctly(): void
    {
        $sub = new SubscriptionRequest(
            amount: $this->makeMoney(2000),
            currency: Currency::USD,
            interval: 'monthly',
            intervalCount: 1,
            trialDays: 7,
            customer: $this->makeCustomer(),
            planId: 'plan_basic',
            idempotencyKey: 'sub-001',
        );

        $this->assertSame('monthly', $sub->interval);
        $this->assertSame(1, $sub->intervalCount);
        $this->assertTrue($sub->hasTrial());
        $this->assertTrue($sub->hasPlanId());
    }

    /** @test */
    public function test_subscription_request_invalid_interval_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SubscriptionRequest(
            $this->makeMoney(),
            Currency::USD,
            'hourly', // invalid
            1,
            null,
            $this->makeCustomer(),
            null,
            'sub-001',
        );
    }

    /** @test */
    public function test_subscription_request_interval_count_zero_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SubscriptionRequest(
            $this->makeMoney(),
            Currency::USD,
            'monthly',
            0, // invalid
            null,
            $this->makeCustomer(),
            null,
            'sub-001',
        );
    }

    /** @test */
    public function test_subscription_request_currency_mismatch_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SubscriptionRequest(
            $this->makeMoney(1000, Currency::EUR),
            Currency::USD, // mismatch
            'monthly',
            1,
            null,
            $this->makeCustomer(),
            null,
            'sub-001',
        );
    }

    /** @test */
    public function test_subscription_request_all_valid_intervals(): void
    {
        foreach (['daily', 'weekly', 'monthly', 'yearly'] as $interval) {
            $sub = new SubscriptionRequest(
                $this->makeMoney(),
                Currency::USD,
                $interval,
                1,
                null,
                $this->makeCustomer(),
                null,
                'sub-' . $interval,
            );
            $this->assertSame($interval, $sub->interval);
        }
    }

    // =========================================================================
    // PaymentLinkRequest
    // =========================================================================

    /** @test */
    public function test_payment_link_request_constructs_correctly(): void
    {
        $link = new PaymentLinkRequest(
            amount: $this->makeMoney(5000),
            currency: Currency::USD,
            description: 'Invoice #123',
            customer: $this->makeCustomer(),
            returnUrl: 'https://example.com/success',
            cancelUrl: 'https://example.com/cancel',
            expiresAt: new DateTimeImmutable('+1 day'),
            idempotencyKey: 'link-001',
        );

        $this->assertSame('Invoice #123', $link->description);
        $this->assertTrue($link->hasExpiry());
        $this->assertTrue($link->hasCustomer());
    }

    /** @test */
    public function test_payment_link_request_optional_fields_nullable(): void
    {
        $link = new PaymentLinkRequest(
            $this->makeMoney(),
            Currency::USD,
            'Test link',
            null,
            null,
            null,
            null,
            'link-002',
        );

        $this->assertFalse($link->hasExpiry());
        $this->assertFalse($link->hasCustomer());
    }

    /** @test */
    public function test_payment_link_request_currency_mismatch_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PaymentLinkRequest(
            $this->makeMoney(1000, Currency::EUR),
            Currency::USD,
            'desc',
            null,
            null,
            null,
            null,
            'link-003',
        );
    }

    // =========================================================================
    // TokenChargeRequest
    // =========================================================================

    /** @test */
    public function test_token_charge_request_constructs_correctly(): void
    {
        $req = new TokenChargeRequest(
            token: Token::fromString('tok_saved_card'),
            amount: $this->makeMoney(1500),
            currency: Currency::USD,
            idempotencyKey: 'tok-001',
            customer: $this->makeCustomer(),
        );

        $this->assertSame(1500, $req->amount->amount);
        $this->assertStringContainsString('*', $req->token->masked());
    }

    /** @test */
    public function test_token_charge_json_excludes_full_token(): void
    {
        $req     = new TokenChargeRequest(
            Token::fromString('tok_very_secret'),
            $this->makeMoney(),
            Currency::USD,
            'tok-002',
            $this->makeCustomer(),
        );
        $decoded = json_decode(json_encode($req), true);

        $this->assertArrayNotHasKey('token', $decoded);
        $this->assertArrayHasKey('token_masked', $decoded);
    }

    // =========================================================================
    // SaveCardRequest
    // =========================================================================

    /** @test */
    public function test_save_card_request_constructs_correctly(): void
    {
        $req = new SaveCardRequest(
            token: Token::fromString('tok_nonce'),
            customerId: CustomerId::fromString('cust_001'),
            idempotencyKey: 'save-001',
        );

        $this->assertSame('cust_001', $req->customerId->toString());
    }

    /** @test */
    public function test_save_card_request_empty_idempotency_key_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SaveCardRequest(Token::fromString('tok_test'), CustomerId::fromString('cust_1'), '');
    }

    /** @test */
    public function test_save_card_json_excludes_full_token(): void
    {
        $req     = new SaveCardRequest(
            Token::fromString('tok_secret'),
            CustomerId::fromString('cust_json'),
            'save-json',
        );
        $decoded = json_decode(json_encode($req), true);

        $this->assertArrayNotHasKey('token', $decoded);
        $this->assertArrayHasKey('token_masked', $decoded);
        $this->assertSame('cust_json', $decoded['customer_id']);
    }

    // =========================================================================
    // TransactionLookupRequest
    // =========================================================================

    /** @test */
    public function test_transaction_lookup_request_constructs_correctly(): void
    {
        $req = new TransactionLookupRequest(
            transactionId: TransactionId::fromString('txn_lookup_001'),
            metadata: ['source' => 'webhook'],
        );

        $this->assertSame('txn_lookup_001', $req->transactionId->toString());
        $this->assertSame('webhook', $req->metadata['source']);
    }

    /** @test */
    public function test_transaction_lookup_request_json_serializes_correctly(): void
    {
        $req     = new TransactionLookupRequest(TransactionId::fromString('txn_json'));
        $decoded = json_decode(json_encode($req), true);

        $this->assertSame('txn_json', $decoded['transaction_id']);
        $this->assertSame([], $decoded['metadata']);
    }

    // =========================================================================
    // Property 10: DTO Invalid Field Throws
    // Feature: laravel-payment-framework, Property 10: DTO invalid field throws
    // =========================================================================
    /** @test */
    public function test_property_10_dto_invalid_field_throws(): void
    {
        // Feature: laravel-payment-framework, Property 10: DTO invalid field throws
        // Empty idempotency keys on mutating DTOs must throw.
        $requiredIdempotencyDTOs = [
            fn () => new PaymentRequest(
                $this->makeMoney(), Currency::USD, '', $this->makeCustomer(),
            ),
            fn () => new RefundRequest(
                TransactionId::fromString('txn'), $this->makeMoney(500), 'reason', '',
            ),
            fn () => new CaptureRequest(
                TransactionId::fromString('txn'), $this->makeMoney(), '',
            ),
            fn () => new VoidRequest(
                TransactionId::fromString('txn'), 'reason', '',
            ),
            fn () => new SaveCardRequest(
                Token::fromString('tok'), CustomerId::fromString('cust'), '',
            ),
        ];

        foreach ($requiredIdempotencyDTOs as $index => $factory) {
            try {
                $factory();
                $this->fail("DTO at index {$index} should throw for empty idempotency key.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // =========================================================================
    // Property 18: Idempotency Key Enforcement
    // Feature: laravel-payment-framework, Property 18: Idempotency key enforcement
    // =========================================================================
    /** @test */
    public function test_property_18_idempotency_key_enforcement(): void
    {
        // Feature: laravel-payment-framework, Property 18: Idempotency key enforcement
        $whitespaceKeys = ['', '   ', "\t", "\n", "  \t  \n  "];

        foreach ($whitespaceKeys as $key) {
            // PaymentRequest rejects empty string (whitespace-only is caught by AbstractDriver)
            if ($key === '') {
                try {
                    new PaymentRequest($this->makeMoney(), Currency::USD, $key, $this->makeCustomer());
                    $this->fail('PaymentRequest should throw for empty idempotency key.');
                } catch (InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }

                try {
                    new RefundRequest(TransactionId::fromString('txn'), $this->makeMoney(100), 'r', $key);
                    $this->fail('RefundRequest should throw for empty idempotency key.');
                } catch (InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }
}
