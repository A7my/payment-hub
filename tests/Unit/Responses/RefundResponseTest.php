<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Responses;

use Mifatoyeh\LaravelPaymentFramework\Contracts\Responses\RefundResponseContract;
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\Responses\RefundResponse;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;
use JsonSerializable;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RefundResponse.
 */
class RefundResponseTest extends TestCase
{
    private function make(
        bool $successful = true,
        string $refundId = 'ref_001',
        int $amount = 500,
        Currency $currency = Currency::USD,
        PaymentStatus $status = PaymentStatus::Refunded,
        string $message = 'Refund processed.',
        array $raw = [],
    ): RefundResponse {
        return new RefundResponse(
            successful: $successful,
            refundId: $refundId,
            amount: Money::ofMinor($amount, $currency),
            status: $status,
            message: $message,
            rawResponse: $raw,
        );
    }

    /** @test */
    public function test_implements_contract_and_json_serializable(): void
    {
        $r = $this->make();
        $this->assertInstanceOf(RefundResponseContract::class, $r);
        $this->assertInstanceOf(JsonSerializable::class, $r);
    }

    /** @test */
    public function test_is_successful(): void
    {
        $this->assertTrue($this->make(successful: true)->isSuccessful());
        $this->assertFalse($this->make(successful: false)->isSuccessful());
    }

    /** @test */
    public function test_get_refund_id(): void
    {
        $this->assertSame('ref_xyz', $this->make(refundId: 'ref_xyz')->getRefundId());
    }

    /** @test */
    public function test_get_amount(): void
    {
        $r = $this->make(amount: 750, currency: Currency::EUR);
        $this->assertSame(750, $r->getAmount()->amount);
        $this->assertSame(Currency::EUR, $r->getAmount()->currency);
    }

    /** @test */
    public function test_get_status(): void
    {
        $r = $this->make(status: PaymentStatus::PartiallyRefunded);
        $this->assertSame(PaymentStatus::PartiallyRefunded, $r->getStatus());
    }

    /** @test */
    public function test_get_message(): void
    {
        $this->assertSame('Done.', $this->make(message: 'Done.')->getMessage());
    }

    /** @test */
    public function test_get_raw_response(): void
    {
        $raw = ['refund_id' => 're_123'];
        $this->assertSame($raw, $this->make(raw: $raw)->getRawResponse());
    }

    /** @test */
    public function test_is_partial_true_for_partially_refunded(): void
    {
        $this->assertTrue($this->make(status: PaymentStatus::PartiallyRefunded)->isPartial());
    }

    /** @test */
    public function test_is_partial_false_for_full_refund(): void
    {
        $this->assertFalse($this->make(status: PaymentStatus::Refunded)->isPartial());
    }

    /** @test */
    public function test_json_serialize_excludes_raw_response(): void
    {
        $data = $this->make(raw: ['secret' => 'x'])->jsonSerialize();
        $this->assertArrayNotHasKey('raw_response', $data);
        $this->assertArrayHasKey('refund_id', $data);
        $this->assertArrayHasKey('amount', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('successful', $data);
        $this->assertArrayHasKey('message', $data);
    }

    /** @test */
    public function test_json_serialize_status_is_string_value(): void
    {
        $data = $this->make(status: PaymentStatus::Refunded)->jsonSerialize();
        $this->assertSame('refunded', $data['status']);
    }

    /** @test */
    public function test_class_is_final_and_properties_readonly(): void
    {
        $rc = new \ReflectionClass(RefundResponse::class);
        $this->assertTrue($rc->isFinal());
        foreach ($rc->getProperties() as $prop) {
            $this->assertTrue($prop->isReadOnly());
        }
    }
}
