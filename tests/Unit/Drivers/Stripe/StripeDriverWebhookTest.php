<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Drivers\Stripe;

use Illuminate\Contracts\Events\Dispatcher;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\SupportsCapabilities;
use Mifatoyeh\LaravelPaymentFramework\DTO\WebhookRequest;
use Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeDriver;
use Mifatoyeh\LaravelPaymentFramework\Enums\WebhookEventType;
use Mifatoyeh\LaravelPaymentFramework\Services\RetryService;
use Mifatoyeh\LaravelPaymentFramework\Logging\NullLogger;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\WebhookSignature;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StripeDriver::verifyWebhookSignature() and
 * StripeDriver::processWebhook() — the orchestration layer over
 * StripeWebhookVerifier (signature) and StripeMapper::toWebhookResponse()
 * (translation), both already covered by their own dedicated unit tests.
 * No Stripe SDK HTTP call is involved in either method, so no
 * ApiRequestor::setHttpClient() fake is needed here.
 */
final class StripeDriverWebhookTest extends TestCase
{
    private const SECRET = 'whsec_driver_test_secret';

    private function makeDriver(): StripeDriver
    {
        return new StripeDriver(
            new NullLogger(),
            new WebhookRecordingDispatcher(),
            new RetryService(1, 0, true),
            ['secret' => 'sk_test_dummy_key', 'webhook_secret' => self::SECRET],
        );
    }

    private function signatureHeader(string $payload, string $secret): string
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        return "t={$timestamp},v1={$signature}";
    }

    private function requestFor(string $rawBody, string $signatureHeader): WebhookRequest
    {
        return new WebhookRequest(
            driver: 'stripe',
            rawBody: $rawBody,
            headers: ['stripe-signature' => $signatureHeader],
            signature: WebhookSignature::fromString(''),
            metadata: [],
        );
    }

    // =========================================================================
    // supports('webhook')
    // =========================================================================

    /** @test */
    public function test_driver_now_declares_webhook_support(): void
    {
        $driver = $this->makeDriver();

        $this->assertInstanceOf(SupportsCapabilities::class, $driver);
        $this->assertTrue($driver->supports('webhook'));
    }

    // =========================================================================
    // verifyWebhookSignature() — reads the real `stripe-signature` header,
    // not $request->signature (the generic Paymob-shaped fallback field).
    // =========================================================================

    /** @test */
    public function test_verify_webhook_signature_reads_the_stripe_signature_header_directly(): void
    {
        $payload = '{"id":"evt_001","type":"checkout.session.completed"}';
        $request = $this->requestFor($payload, $this->signatureHeader($payload, self::SECRET));

        $this->assertTrue($this->makeDriver()->verifyWebhookSignature($request));
    }

    /** @test */
    public function test_verify_webhook_signature_ignores_the_generic_signature_field(): void
    {
        $payload = '{"id":"evt_002","type":"checkout.session.completed"}';

        // A valid stripe-signature header is present, but $request->signature
        // (the generic field WebhookController falls back to for
        // Paymob-shaped payloads) is garbage — verification must still pass,
        // proving the header, not that field, is what's actually read.
        $request = new WebhookRequest(
            driver: 'stripe',
            rawBody: $payload,
            headers: ['stripe-signature' => $this->signatureHeader($payload, self::SECRET)],
            signature: WebhookSignature::fromString('not-the-real-signature'),
            metadata: [],
        );

        $this->assertTrue($this->makeDriver()->verifyWebhookSignature($request));
    }

    /** @test */
    public function test_verify_webhook_signature_rejects_a_forged_signature(): void
    {
        $payload = '{"id":"evt_003","type":"checkout.session.completed"}';
        $request = $this->requestFor($payload, $this->signatureHeader($payload, 'whsec_wrong_secret'));

        $this->assertFalse($this->makeDriver()->verifyWebhookSignature($request));
    }

    /** @test */
    public function test_verify_webhook_signature_rejects_a_missing_header(): void
    {
        $request = $this->requestFor('{"id":"evt_004"}', '');

        $this->assertFalse($this->makeDriver()->verifyWebhookSignature($request));
    }

    // =========================================================================
    // processWebhook()
    // =========================================================================

    /** @test */
    public function test_process_webhook_maps_a_checkout_session_completed_event(): void
    {
        $payload = json_encode([
            'id'   => 'evt_005',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id'             => 'cs_test_001',
                    'payment_status' => 'paid',
                    'metadata'       => ['merchant_order_id' => 'order-uuid-001'],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $response = $this->makeDriver()->processWebhook($this->requestFor($payload, ''));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame(WebhookEventType::PaymentSucceeded, $response->getEventType());
        $this->assertSame('order-uuid-001', $response->getRawPayload()['merchant_order_id']);
        $this->assertSame('cs_test_001', $response->getRawPayload()['session_id']);
    }

    /** @test */
    public function test_process_webhook_reports_unknown_for_an_unrecognised_event_type(): void
    {
        $payload = json_encode(['id' => 'evt_006', 'type' => 'customer.created', 'data' => ['object' => []]], JSON_THROW_ON_ERROR);

        $response = $this->makeDriver()->processWebhook($this->requestFor($payload, ''));

        $this->assertFalse($response->isSuccessful());
        $this->assertSame(WebhookEventType::Unknown, $response->getEventType());
    }

    /** @test */
    public function test_process_webhook_handles_malformed_json_without_throwing(): void
    {
        $response = $this->makeDriver()->processWebhook($this->requestFor('{not valid json', ''));

        $this->assertSame(WebhookEventType::Unknown, $response->getEventType());
    }
}

/**
 * Minimal event dispatcher test double — duplicated per this package's
 * established "every test file is self-contained" convention.
 */
final class WebhookRecordingDispatcher implements Dispatcher
{
    public function listen($events, $listener = null)
    {
    }

    public function hasListeners($eventName)
    {
        return false;
    }

    public function subscribe($subscriber)
    {
    }

    public function until($event, $payload = [])
    {
    }

    public function dispatch($event, $payload = [], $halt = false)
    {
        return null;
    }

    public function push($event, $payload = [])
    {
    }

    public function flush($event)
    {
    }

    public function forget($event)
    {
    }

    public function forgetPushed()
    {
    }
}
