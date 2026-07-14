<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Responses;

use DateTimeImmutable;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Responses\PaymentLinkResponseContract;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentLinkResponse;
use JsonSerializable;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PaymentLinkResponse.
 */
class PaymentLinkResponseTest extends TestCase
{
    private function make(
        bool $successful = true,
        string $paymentUrl = 'https://pay.example.com/link/abc',
        string $linkId = 'link_001',
        ?DateTimeImmutable $expiresAt = null,
        string $message = 'Link created.',
        array $raw = [],
    ): PaymentLinkResponse {
        return new PaymentLinkResponse(
            successful: $successful,
            paymentUrl: $paymentUrl,
            linkId: $linkId,
            expiresAt: $expiresAt,
            message: $message,
            rawResponse: $raw,
        );
    }

    /** @test */
    public function test_implements_contract_and_json_serializable(): void
    {
        $r = $this->make();
        $this->assertInstanceOf(PaymentLinkResponseContract::class, $r);
        $this->assertInstanceOf(JsonSerializable::class, $r);
    }

    /** @test */
    public function test_is_successful(): void
    {
        $this->assertTrue($this->make(successful: true)->isSuccessful());
        $this->assertFalse($this->make(successful: false)->isSuccessful());
    }

    /** @test */
    public function test_get_payment_url(): void
    {
        $url = 'https://checkout.example.com/pay';
        $this->assertSame($url, $this->make(paymentUrl: $url)->getPaymentUrl());
    }

    /** @test */
    public function test_get_link_id(): void
    {
        $this->assertSame('link_42', $this->make(linkId: 'link_42')->getLinkId());
    }

    /** @test */
    public function test_get_expires_at_null_when_not_provided(): void
    {
        $this->assertNull($this->make(expiresAt: null)->getExpiresAt());
    }

    /** @test */
    public function test_get_expires_at_returns_correct_datetime(): void
    {
        $date = new DateTimeImmutable('2026-12-31T23:59:59+00:00');
        $this->assertSame($date, $this->make(expiresAt: $date)->getExpiresAt());
    }

    /** @test */
    public function test_get_message(): void
    {
        $this->assertSame('Expired.', $this->make(message: 'Expired.')->getMessage());
    }

    /** @test */
    public function test_get_raw_response(): void
    {
        $raw = ['link_data' => 'x'];
        $this->assertSame($raw, $this->make(raw: $raw)->getRawResponse());
    }

    /** @test */
    public function test_json_serialize_expires_at_null(): void
    {
        $data = $this->make(expiresAt: null)->jsonSerialize();
        $this->assertNull($data['expires_at']);
    }

    /** @test */
    public function test_json_serialize_expires_at_is_atom_string(): void
    {
        $date = new DateTimeImmutable('2026-12-31T23:59:59+00:00');
        $data = $this->make(expiresAt: $date)->jsonSerialize();
        $this->assertSame($date->format(DateTimeImmutable::ATOM), $data['expires_at']);
    }

    /** @test */
    public function test_json_serialize_structure(): void
    {
        $data = $this->make()->jsonSerialize();
        $this->assertArrayHasKey('successful', $data);
        $this->assertArrayHasKey('payment_url', $data);
        $this->assertArrayHasKey('link_id', $data);
        $this->assertArrayHasKey('expires_at', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayNotHasKey('raw_response', $data);
    }

    /** @test */
    public function test_class_is_final_and_properties_readonly(): void
    {
        $rc = new \ReflectionClass(PaymentLinkResponse::class);
        $this->assertTrue($rc->isFinal());
        foreach ($rc->getProperties() as $prop) {
            $this->assertTrue($prop->isReadOnly());
        }
    }
}
