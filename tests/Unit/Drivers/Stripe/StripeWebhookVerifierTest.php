<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Drivers\Stripe;

use Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeWebhookVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StripeWebhookVerifier::verify() — real `Stripe-Signature`
 * verification via the Stripe SDK's own `\Stripe\WebhookSignature::verifyHeader()`
 * (verified against the SDK, vendor/stripe/stripe-php/lib/WebhookSignature.php:
 * extracts `t=`/`v1=` from the header, recomputes
 * `hash_hmac('sha256', "{timestamp}.{payload}", $secret)`, compares via
 * `Util::secureCompare()`, then checks the timestamp tolerance).
 *
 * No HTTP mocking needed — this is pure signature-computation logic, no
 * network call involved.
 */
final class StripeWebhookVerifierTest extends TestCase
{
    private const SECRET = 'whsec_test_secret_001';

    private function signatureHeader(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        return "t={$timestamp},v1={$signature}";
    }

    /** @test */
    public function test_a_correctly_signed_payload_verifies(): void
    {
        $payload  = '{"id":"evt_test_001","type":"checkout.session.completed"}';
        $verifier = new StripeWebhookVerifier(['webhook_secret' => self::SECRET]);

        $this->assertTrue($verifier->verify($payload, $this->signatureHeader($payload, self::SECRET)));
    }

    /** @test */
    public function test_a_payload_signed_with_the_wrong_secret_does_not_verify(): void
    {
        $payload  = '{"id":"evt_test_002","type":"checkout.session.completed"}';
        $verifier = new StripeWebhookVerifier(['webhook_secret' => self::SECRET]);

        $this->assertFalse($verifier->verify($payload, $this->signatureHeader($payload, 'whsec_wrong_secret')));
    }

    /** @test */
    public function test_a_payload_that_was_tampered_with_after_signing_does_not_verify(): void
    {
        $payload  = '{"id":"evt_test_003","type":"checkout.session.completed"}';
        $verifier = new StripeWebhookVerifier(['webhook_secret' => self::SECRET]);
        $header   = $this->signatureHeader($payload, self::SECRET);

        $tamperedPayload = '{"id":"evt_test_003","type":"payment_intent.succeeded"}';

        $this->assertFalse($verifier->verify($tamperedPayload, $header));
    }

    /** @test */
    public function test_a_missing_signature_header_does_not_verify(): void
    {
        $verifier = new StripeWebhookVerifier(['webhook_secret' => self::SECRET]);

        $this->assertFalse($verifier->verify('{"id":"evt_test_004"}', ''));
    }

    /** @test */
    public function test_an_unparsable_signature_header_does_not_verify(): void
    {
        $verifier = new StripeWebhookVerifier(['webhook_secret' => self::SECRET]);

        $this->assertFalse($verifier->verify('{"id":"evt_test_005"}', 'not-a-real-stripe-signature-header'));
    }

    /** @test */
    public function test_a_signature_older_than_the_default_tolerance_does_not_verify(): void
    {
        $payload   = '{"id":"evt_test_006"}';
        $verifier  = new StripeWebhookVerifier(['webhook_secret' => self::SECRET]);
        $timestamp = time() - 400; // Webhook::DEFAULT_TOLERANCE is 300 seconds.

        $this->assertFalse($verifier->verify($payload, $this->signatureHeader($payload, self::SECRET, $timestamp)));
    }

    /** @test */
    public function test_no_configured_webhook_secret_never_verifies_even_with_a_well_formed_header(): void
    {
        $payload  = '{"id":"evt_test_007"}';
        $verifier = new StripeWebhookVerifier([]);

        $this->assertFalse($verifier->verify($payload, $this->signatureHeader($payload, 'whsec_anything')));
    }
}
