<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\ValueObjects;

use InvalidArgumentException;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\CustomerId;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\OrderId;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Token;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\WebhookSignature;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for all string-wrapping value objects.
 *
 * Covers: construction, round-trip, equality, JSON serialisation,
 * string casting, and empty-string guards.
 */
class ValueObjectTest extends TestCase
{
    // =========================================================================
    // TransactionId
    // =========================================================================

    /** @test */
    public function test_transaction_id_round_trip(): void
    {
        $id = TransactionId::fromString('txn_abc123');

        $this->assertSame('txn_abc123', $id->toString());
        $this->assertSame('txn_abc123', (string) $id);
    }

    /** @test */
    public function test_transaction_id_equals_same_value(): void
    {
        $a = TransactionId::fromString('txn_123');
        $b = TransactionId::fromString('txn_123');

        $this->assertTrue($a->equals($b));
    }

    /** @test */
    public function test_transaction_id_not_equal_different_value(): void
    {
        $a = TransactionId::fromString('txn_123');
        $b = TransactionId::fromString('txn_456');

        $this->assertFalse($a->equals($b));
    }

    /** @test */
    public function test_transaction_id_is_case_sensitive(): void
    {
        $a = TransactionId::fromString('TXN_ABC');
        $b = TransactionId::fromString('txn_abc');

        $this->assertFalse($a->equals($b));
    }

    /** @test */
    public function test_transaction_id_json_serialize_returns_string(): void
    {
        $id      = TransactionId::fromString('txn_xyz');
        $encoded = json_encode($id);

        $this->assertSame('"txn_xyz"', $encoded);
    }

    /** @test */
    public function test_empty_transaction_id_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TransactionId::fromString('');
    }

    // =========================================================================
    // CustomerId
    // =========================================================================

    /** @test */
    public function test_customer_id_round_trip(): void
    {
        $id = CustomerId::fromString('cust_456');

        $this->assertSame('cust_456', $id->toString());
        $this->assertSame('cust_456', (string) $id);
    }

    /** @test */
    public function test_customer_id_equals_same_value(): void
    {
        $a = CustomerId::fromString('cust_001');
        $b = CustomerId::fromString('cust_001');

        $this->assertTrue($a->equals($b));
    }

    /** @test */
    public function test_customer_id_not_equal_different_value(): void
    {
        $a = CustomerId::fromString('cust_001');
        $b = CustomerId::fromString('cust_002');

        $this->assertFalse($a->equals($b));
    }

    /** @test */
    public function test_customer_id_json_serialize_returns_string(): void
    {
        $id      = CustomerId::fromString('cust_json');
        $encoded = json_encode($id);

        $this->assertSame('"cust_json"', $encoded);
    }

    /** @test */
    public function test_empty_customer_id_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CustomerId::fromString('');
    }

    // =========================================================================
    // OrderId
    // =========================================================================

    /** @test */
    public function test_order_id_round_trip(): void
    {
        $id = OrderId::fromString('order_789');

        $this->assertSame('order_789', $id->toString());
        $this->assertSame('order_789', (string) $id);
    }

    /** @test */
    public function test_order_id_equals_same_value(): void
    {
        $a = OrderId::fromString('ORD-001');
        $b = OrderId::fromString('ORD-001');

        $this->assertTrue($a->equals($b));
    }

    /** @test */
    public function test_order_id_not_equal_different_value(): void
    {
        $a = OrderId::fromString('ORD-001');
        $b = OrderId::fromString('ORD-002');

        $this->assertFalse($a->equals($b));
    }

    /** @test */
    public function test_order_id_json_serialize_returns_string(): void
    {
        $id      = OrderId::fromString('ORD-JSON');
        $encoded = json_encode($id);

        $this->assertSame('"ORD-JSON"', $encoded);
    }

    /** @test */
    public function test_empty_order_id_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OrderId::fromString('');
    }

    // =========================================================================
    // Token
    // =========================================================================

    /** @test */
    public function test_token_round_trip(): void
    {
        $token = Token::fromString('tok_live_abc123');

        $this->assertSame('tok_live_abc123', $token->toString());
    }

    /** @test */
    public function test_token_equals_same_value(): void
    {
        $a = Token::fromString('tok_live_abc');
        $b = Token::fromString('tok_live_abc');

        $this->assertTrue($a->equals($b));
    }

    /** @test */
    public function test_token_not_equal_different_value(): void
    {
        $a = Token::fromString('tok_live_aaa');
        $b = Token::fromString('tok_live_bbb');

        $this->assertFalse($a->equals($b));
    }

    /** @test */
    public function test_token_masked_hides_most_of_value(): void
    {
        $token  = Token::fromString('tok_live_abc123def456');
        $masked = $token->masked();

        $this->assertStringStartsWith('tok_', $masked);
        $this->assertStringContainsString('*', $masked);
        $this->assertSame(mb_strlen('tok_live_abc123def456'), mb_strlen($masked));
    }

    /** @test */
    public function test_token_to_string_returns_full_value(): void
    {
        $token = Token::fromString('pm_1234567890');

        $this->assertSame('pm_1234567890', (string) $token);
    }

    /** @test */
    public function test_token_json_serialize_returns_string(): void
    {
        $token   = Token::fromString('tok_test');
        $encoded = json_encode($token);

        $this->assertSame('"tok_test"', $encoded);
    }

    /** @test */
    public function test_empty_token_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Token::fromString('');
    }

    // =========================================================================
    // WebhookSignature
    // =========================================================================

    /** @test */
    public function test_webhook_signature_round_trip(): void
    {
        $sig = WebhookSignature::fromString('sha256=abc123def456');

        $this->assertSame('sha256=abc123def456', $sig->toString());
    }

    /** @test */
    public function test_webhook_signature_allows_empty_string(): void
    {
        $sig = WebhookSignature::fromString('');

        $this->assertSame('', $sig->toString());
        $this->assertTrue($sig->isEmpty());
        $this->assertFalse($sig->isPresent());
    }

    /** @test */
    public function test_webhook_signature_is_present_for_non_empty(): void
    {
        $sig = WebhookSignature::fromString('some-signature');

        $this->assertTrue($sig->isPresent());
        $this->assertFalse($sig->isEmpty());
    }

    /** @test */
    public function test_webhook_signature_equals_same_value(): void
    {
        $a = WebhookSignature::fromString('sig_abc');
        $b = WebhookSignature::fromString('sig_abc');

        $this->assertTrue($a->equals($b));
    }

    /** @test */
    public function test_webhook_signature_not_equal_different_value(): void
    {
        $a = WebhookSignature::fromString('sig_aaa');
        $b = WebhookSignature::fromString('sig_bbb');

        $this->assertFalse($a->equals($b));
    }

    /** @test */
    public function test_webhook_signature_secure_equals_against_raw_string(): void
    {
        $sig = WebhookSignature::fromString('expected-hmac-value');

        $this->assertTrue($sig->secureEquals('expected-hmac-value'));
        $this->assertFalse($sig->secureEquals('wrong-hmac-value'));
    }

    /** @test */
    public function test_webhook_signature_truncated_returns_at_most_32_chars(): void
    {
        $longSig   = WebhookSignature::fromString(str_repeat('a', 100));
        $truncated = $longSig->truncated();

        $this->assertSame(32, mb_strlen($truncated));
    }

    /** @test */
    public function test_webhook_signature_truncated_short_value_unchanged(): void
    {
        $sig       = WebhookSignature::fromString('short');
        $truncated = $sig->truncated();

        $this->assertSame('short', $truncated);
    }

    /** @test */
    public function test_webhook_signature_to_string_returns_truncated(): void
    {
        $longSig = WebhookSignature::fromString(str_repeat('x', 100));

        $this->assertSame(32, mb_strlen((string) $longSig));
    }

    /** @test */
    public function test_webhook_signature_json_serialize_returns_truncated(): void
    {
        $longSig = WebhookSignature::fromString(str_repeat('z', 100));
        $decoded = json_decode(json_encode($longSig), true);

        $this->assertSame(32, mb_strlen($decoded));
    }

    // =========================================================================
    // Property 8: String Value Object Round-Trip
    // Feature: laravel-payment-framework, Property 8: String value object round-trip
    // =========================================================================
    /** @test */
    public function test_property_8_string_value_object_round_trip(): void
    {
        // Feature: laravel-payment-framework, Property 8: String value object round-trip
        $ids = ['abc', 'ABC', '123', 'mixed-CASE_value', 'with spaces', '!@#$%'];

        foreach ($ids as $id) {
            $this->assertSame($id, TransactionId::fromString($id)->toString());
            $this->assertSame($id, CustomerId::fromString($id)->toString());
            $this->assertSame($id, OrderId::fromString($id)->toString());
            $this->assertSame($id, Token::fromString($id)->toString());
            // WebhookSignature allows empty, uses truncated for __toString
            $this->assertSame($id, WebhookSignature::fromString($id)->toString());
        }
    }

    // =========================================================================
    // Property 9: Empty String Throws on Value Object Construction
    // Feature: laravel-payment-framework, Property 9: Empty string throws
    // =========================================================================
    /** @test */
    public function test_property_9_empty_string_throws_on_value_object_construction(): void
    {
        // Feature: laravel-payment-framework, Property 9: Empty string throws
        // TransactionId, CustomerId, OrderId, and Token must reject empty strings.
        // WebhookSignature is the intentional exception — it allows empty.
        $classes = [
            TransactionId::class,
            CustomerId::class,
            OrderId::class,
            Token::class,
        ];

        foreach ($classes as $class) {
            try {
                $class::fromString('');
                $this->fail("{$class}::fromString('') should throw InvalidArgumentException.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
