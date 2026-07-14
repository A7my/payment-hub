<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Responses;

use Mifatoyeh\LaravelPaymentFramework\Contracts\Responses\WebhookResponseContract;
use Mifatoyeh\LaravelPaymentFramework\Enums\WebhookEventType;
use Mifatoyeh\LaravelPaymentFramework\Responses\WebhookResponse;
use JsonSerializable;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WebhookResponse.
 */
class WebhookResponseTest extends TestCase
{
    private function make(
        bool $successful = true,
        WebhookEventType $eventType = WebhookEventType::PaymentSucceeded,
        string $message = 'Webhook processed.',
        array $rawPayload = ['event' => 'payment.succeeded'],
    ): WebhookResponse {
        return new WebhookResponse(
            successful: $successful,
            eventType: $eventType,
            message: $message,
            rawPayload: $rawPayload,
        );
    }

    /** @test */
    public function test_implements_contract_and_json_serializable(): void
    {
        $r = $this->make();
        $this->assertInstanceOf(WebhookResponseContract::class, $r);
        $this->assertInstanceOf(JsonSerializable::class, $r);
    }

    /** @test */
    public function test_is_successful(): void
    {
        $this->assertTrue($this->make(successful: true)->isSuccessful());
        $this->assertFalse($this->make(successful: false)->isSuccessful());
    }

    /** @test */
    public function test_get_event_type(): void
    {
        $r = $this->make(eventType: WebhookEventType::RefundSucceeded);
        $this->assertSame(WebhookEventType::RefundSucceeded, $r->getEventType());
    }

    /** @test */
    public function test_get_message(): void
    {
        $this->assertSame('Failed.', $this->make(message: 'Failed.')->getMessage());
    }

    /** @test */
    public function test_get_raw_payload_returns_array(): void
    {
        $payload = ['type' => 'payment.succeeded', 'id' => 'evt_1'];
        $this->assertSame($payload, $this->make(rawPayload: $payload)->getRawPayload());
    }

    /** @test */
    public function test_unknown_event_type_is_valid(): void
    {
        $r = $this->make(eventType: WebhookEventType::Unknown);
        $this->assertSame(WebhookEventType::Unknown, $r->getEventType());
    }

    /** @test */
    public function test_json_serialize_excludes_raw_payload(): void
    {
        // WebhookResponse intentionally excludes rawPayload from jsonSerialize(),
        // consistent with every other Response class — it may be large and
        // contain sensitive provider data. Call getRawPayload() when needed.
        $payload = ['type' => 'subscription.renewed'];
        $data    = $this->make(rawPayload: $payload)->jsonSerialize();

        $this->assertArrayNotHasKey('raw_payload', $data);
    }

    /** @test */
    public function test_json_serialize_structure_and_event_type_value(): void
    {
        $data = $this->make(
            successful: true,
            eventType: WebhookEventType::PaymentSucceeded,
            message: 'OK',
        )->jsonSerialize();

        $this->assertArrayHasKey('successful', $data);
        $this->assertArrayHasKey('event_type', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayNotHasKey('raw_payload', $data);
        $this->assertSame('payment.succeeded', $data['event_type']);
        $this->assertTrue($data['successful']);
        $this->assertSame('OK', $data['message']);
    }

    /** @test */
    public function test_json_encode_round_trip(): void
    {
        $json    = json_encode($this->make());
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertSame('payment.succeeded', $decoded['event_type']);
    }

    /** @test */
    public function test_class_is_final_and_properties_readonly(): void
    {
        $rc = new \ReflectionClass(WebhookResponse::class);
        $this->assertTrue($rc->isFinal());
        foreach ($rc->getProperties() as $prop) {
            $this->assertTrue($prop->isReadOnly());
        }
    }
}
