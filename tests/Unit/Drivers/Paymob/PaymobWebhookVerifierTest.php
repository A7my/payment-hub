<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Drivers\Paymob;

use Mifatoyeh\LaravelPaymentFramework\Drivers\Paymob\PaymobWebhookVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PaymobWebhookVerifier's HMAC computation/verification.
 *
 * These confirm the implementation is internally consistent (correctly
 * accepts a payload it signed itself, correctly rejects a tampered one) —
 * NOT that the field order matches Paymob's real production algorithm
 * bit-for-bit, which cannot be verified without a live signed sample. See
 * that class's own docblock.
 */
final class PaymobWebhookVerifierTest extends TestCase
{
    private const HMAC_SECRET = 'test-hmac-secret';

    /** @return array<string, mixed> A realistic flat Paymob "Transaction Processed Callback" payload. */
    private function samplePayload(): array
    {
        return [
            'amount_cents'            => '4000',
            'created_at'              => '2026-07-19T12:47:16.351233+03:00',
            'currency'                => 'SAR',
            'error_occured'           => 'false',
            'has_parent_transaction'  => 'false',
            'id'                      => '7773107',
            'integration_id'          => '30075',
            'is_3d_secure'            => 'true',
            'is_auth'                 => 'false',
            'is_capture'              => 'false',
            'is_refunded'             => 'false',
            'is_standalone_payment'   => 'true',
            'is_voided'               => 'false',
            'order'                   => '6976637',
            'owner'                   => '23938',
            'pending'                 => 'false',
            'source_data.pan'         => '1111',
            'source_data.sub_type'    => 'Visa',
            'source_data.type'        => 'card',
            'success'                 => 'true',
            // Present in a real callback but NOT part of the HMAC field set:
            'merchant_order_id'       => 'a4395022-5db7-484e-a4ce-fa27c1cd5554',
            'data.message'            => 'Approved',
        ];
    }

    private function makeVerifier(): PaymobWebhookVerifier
    {
        return new PaymobWebhookVerifier(['hmac_secret' => self::HMAC_SECRET]);
    }

    /** @test */
    public function test_verify_accepts_a_correctly_signed_payload(): void
    {
        $verifier = $this->makeVerifier();
        $payload  = $this->samplePayload();
        $hmac     = $verifier->compute($payload, self::HMAC_SECRET);

        $this->assertTrue($verifier->verify($payload, $hmac));
    }

    /** @test */
    public function test_verify_accepts_an_uppercase_hex_digest(): void
    {
        $verifier = $this->makeVerifier();
        $payload  = $this->samplePayload();
        $hmac     = strtoupper($verifier->compute($payload, self::HMAC_SECRET));

        $this->assertTrue($verifier->verify($payload, $hmac));
    }

    /** @test */
    public function test_verify_rejects_a_tampered_field(): void
    {
        $verifier = $this->makeVerifier();
        $payload  = $this->samplePayload();
        $hmac     = $verifier->compute($payload, self::HMAC_SECRET);

        $payload['amount_cents'] = '999999'; // tampered after signing

        $this->assertFalse($verifier->verify($payload, $hmac));
    }

    /** @test */
    public function test_verify_rejects_when_hmac_secret_is_not_configured(): void
    {
        $verifier = new PaymobWebhookVerifier([]);
        $payload  = $this->samplePayload();

        $this->assertFalse($verifier->verify($payload, 'anything'));
    }

    /** @test */
    public function test_verify_rejects_an_empty_provided_hmac(): void
    {
        $verifier = $this->makeVerifier();

        $this->assertFalse($verifier->verify($this->samplePayload(), ''));
    }

    /** @test */
    public function test_compute_is_order_sensitive(): void
    {
        $verifier = $this->makeVerifier();
        $payload  = $this->samplePayload();

        $a = $verifier->compute($payload, self::HMAC_SECRET);

        // Swap two field values — same set of values, different assignment —
        // must produce a different digest.
        $swapped                    = $payload;
        $swapped['amount_cents']    = $payload['order'];
        $swapped['order']           = $payload['amount_cents'];
        $b                          = $verifier->compute($swapped, self::HMAC_SECRET);

        $this->assertNotSame($a, $b);
    }
}
