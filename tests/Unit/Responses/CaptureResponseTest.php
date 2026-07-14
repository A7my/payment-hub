<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Responses;

use Mifatoyeh\LaravelPaymentFramework\Contracts\Responses\CaptureResponseContract;
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\Responses\CaptureResponse;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;
use JsonSerializable;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CaptureResponse.
 */
class CaptureResponseTest extends TestCase
{
    private function make(
        bool $successful = true,
        string $captureId = 'cap_001',
        int $amount = 2000,
        Currency $currency = Currency::USD,
        PaymentStatus $status = PaymentStatus::Captured,
        string $message = 'Captured.',
        array $raw = [],
    ): CaptureResponse {
        return new CaptureResponse(
            successful: $successful,
            captureId: $captureId,
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
        $this->assertInstanceOf(CaptureResponseContract::class, $r);
        $this->assertInstanceOf(JsonSerializable::class, $r);
    }

    /** @test */
    public function test_is_successful(): void
    {
        $this->assertTrue($this->make(successful: true)->isSuccessful());
        $this->assertFalse($this->make(successful: false)->isSuccessful());
    }

    /** @test */
    public function test_get_capture_id(): void
    {
        $this->assertSame('cap_abc', $this->make(captureId: 'cap_abc')->getCaptureId());
    }

    /** @test */
    public function test_get_amount(): void
    {
        $r = $this->make(amount: 3000, currency: Currency::SAR);
        $this->assertSame(3000, $r->getAmount()->amount);
        $this->assertSame(Currency::SAR, $r->getAmount()->currency);
    }

    /** @test */
    public function test_get_status(): void
    {
        $this->assertSame(PaymentStatus::Captured, $this->make()->getStatus());
    }

    /** @test */
    public function test_get_message(): void
    {
        $this->assertSame('Capture OK.', $this->make(message: 'Capture OK.')->getMessage());
    }

    /** @test */
    public function test_get_raw_response(): void
    {
        $raw = ['capture' => 'ch_cap_1'];
        $this->assertSame($raw, $this->make(raw: $raw)->getRawResponse());
    }

    /** @test */
    public function test_json_serialize_has_correct_structure(): void
    {
        $data = $this->make()->jsonSerialize();
        $this->assertArrayHasKey('successful', $data);
        $this->assertArrayHasKey('capture_id', $data);
        $this->assertArrayHasKey('amount', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayNotHasKey('raw_response', $data);
    }

    /** @test */
    public function test_json_serialize_status_is_enum_value(): void
    {
        $this->assertSame('captured', $this->make()->jsonSerialize()['status']);
    }

    /** @test */
    public function test_class_is_final_and_properties_readonly(): void
    {
        $rc = new \ReflectionClass(CaptureResponse::class);
        $this->assertTrue($rc->isFinal());
        foreach ($rc->getProperties() as $prop) {
            $this->assertTrue($prop->isReadOnly());
        }
    }
}
