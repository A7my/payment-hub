<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Responses;

use DateTimeImmutable;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Responses\SubscriptionResponseContract;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\Responses\SubscriptionResponse;
use JsonSerializable;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SubscriptionResponse.
 */
class SubscriptionResponseTest extends TestCase
{
    private function make(
        bool $successful = true,
        string $subId = 'sub_001',
        PaymentStatus $status = PaymentStatus::Authorized,
        ?DateTimeImmutable $nextBilling = null,
        string $message = 'Subscription created.',
        array $raw = [],
    ): SubscriptionResponse {
        return new SubscriptionResponse(
            successful: $successful,
            subscriptionId: $subId,
            status: $status,
            nextBillingDate: $nextBilling,
            message: $message,
            rawResponse: $raw,
        );
    }

    /** @test */
    public function test_implements_contract_and_json_serializable(): void
    {
        $r = $this->make();
        $this->assertInstanceOf(SubscriptionResponseContract::class, $r);
        $this->assertInstanceOf(JsonSerializable::class, $r);
    }

    /** @test */
    public function test_is_successful(): void
    {
        $this->assertTrue($this->make(successful: true)->isSuccessful());
        $this->assertFalse($this->make(successful: false)->isSuccessful());
    }

    /** @test */
    public function test_get_subscription_id(): void
    {
        $this->assertSame('sub_xyz', $this->make(subId: 'sub_xyz')->getSubscriptionId());
    }

    /** @test */
    public function test_get_status(): void
    {
        $this->assertSame(PaymentStatus::Cancelled, $this->make(status: PaymentStatus::Cancelled)->getStatus());
    }

    /** @test */
    public function test_get_next_billing_date_null_when_not_provided(): void
    {
        $this->assertNull($this->make(nextBilling: null)->getNextBillingDate());
    }

    /** @test */
    public function test_get_next_billing_date_returns_correct_datetime(): void
    {
        $date = new DateTimeImmutable('2026-08-01T00:00:00+00:00');
        $this->assertSame($date, $this->make(nextBilling: $date)->getNextBillingDate());
    }

    /** @test */
    public function test_get_message(): void
    {
        $this->assertSame('Cancelled.', $this->make(message: 'Cancelled.')->getMessage());
    }

    /** @test */
    public function test_get_raw_response(): void
    {
        $raw = ['sub_data' => true];
        $this->assertSame($raw, $this->make(raw: $raw)->getRawResponse());
    }

    /** @test */
    public function test_json_serialize_next_billing_date_null(): void
    {
        $data = $this->make(nextBilling: null)->jsonSerialize();
        $this->assertNull($data['next_billing_date']);
    }

    /** @test */
    public function test_json_serialize_next_billing_date_is_atom_string(): void
    {
        $date = new DateTimeImmutable('2026-09-15T12:00:00+00:00');
        $data = $this->make(nextBilling: $date)->jsonSerialize();
        $this->assertSame($date->format(DateTimeImmutable::ATOM), $data['next_billing_date']);
    }

    /** @test */
    public function test_json_serialize_excludes_raw_response(): void
    {
        $data = $this->make()->jsonSerialize();
        $this->assertArrayNotHasKey('raw_response', $data);
        $this->assertArrayHasKey('subscription_id', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('successful', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('next_billing_date', $data);
    }

    /** @test */
    public function test_class_is_final_and_properties_readonly(): void
    {
        $rc = new \ReflectionClass(SubscriptionResponse::class);
        $this->assertTrue($rc->isFinal());
        foreach ($rc->getProperties() as $prop) {
            $this->assertTrue($prop->isReadOnly());
        }
    }
}
