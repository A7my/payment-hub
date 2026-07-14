<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Responses;

use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\Responses\VoidResponse;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;
use JsonSerializable;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for VoidResponse.
 */
class VoidResponseTest extends TestCase
{
    private function make(
        bool $successful = true,
        string $txId = 'txn_void_001',
        PaymentStatus $status = PaymentStatus::Voided,
        string $message = 'Void successful.',
        array $raw = [],
    ): VoidResponse {
        return new VoidResponse(
            successful: $successful,
            transactionId: TransactionId::fromString($txId),
            status: $status,
            message: $message,
            rawResponse: $raw,
        );
    }

    /** @test */
    public function test_implements_json_serializable(): void
    {
        $this->assertInstanceOf(JsonSerializable::class, $this->make());
    }

    /** @test */
    public function test_is_successful(): void
    {
        $this->assertTrue($this->make(successful: true)->isSuccessful());
        $this->assertFalse($this->make(successful: false)->isSuccessful());
    }

    /** @test */
    public function test_get_transaction_id(): void
    {
        $this->assertSame('txn_void_42', $this->make(txId: 'txn_void_42')->getTransactionId()->toString());
    }

    /** @test */
    public function test_get_status(): void
    {
        $this->assertSame(PaymentStatus::Voided, $this->make()->getStatus());
    }

    /** @test */
    public function test_get_message(): void
    {
        $this->assertSame('Voided OK.', $this->make(message: 'Voided OK.')->getMessage());
    }

    /** @test */
    public function test_get_raw_response(): void
    {
        $raw = ['provider_void_id' => 'v_1'];
        $this->assertSame($raw, $this->make(raw: $raw)->getRawResponse());
    }

    /** @test */
    public function test_json_serialize_structure(): void
    {
        $data = $this->make()->jsonSerialize();
        $this->assertArrayHasKey('successful', $data);
        $this->assertArrayHasKey('transaction_id', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayNotHasKey('raw_response', $data);
    }

    /** @test */
    public function test_json_serialize_values(): void
    {
        $data = $this->make(
            successful: true,
            txId: 'txn_void_99',
            status: PaymentStatus::Voided,
            message: 'Voided.',
        )->jsonSerialize();

        $this->assertTrue($data['successful']);
        $this->assertSame('txn_void_99', $data['transaction_id']);
        $this->assertSame('voided', $data['status']);
        $this->assertSame('Voided.', $data['message']);
    }

    /** @test */
    public function test_class_is_final_and_properties_readonly(): void
    {
        $rc = new \ReflectionClass(VoidResponse::class);
        $this->assertTrue($rc->isFinal());
        foreach ($rc->getProperties() as $prop) {
            $this->assertTrue($prop->isReadOnly());
        }
    }
}
