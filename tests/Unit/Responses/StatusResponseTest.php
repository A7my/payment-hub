<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Responses;

use Mifatoyeh\LaravelPaymentFramework\Contracts\Responses\StatusResponseContract;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\Responses\StatusResponse;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;
use JsonSerializable;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StatusResponse.
 */
class StatusResponseTest extends TestCase
{
    private function make(
        bool $successful = true,
        string $txId = 'txn_status_001',
        PaymentStatus $status = PaymentStatus::Captured,
        string $message = 'Transaction found.',
        array $raw = [],
    ): StatusResponse {
        return new StatusResponse(
            successful: $successful,
            transactionId: TransactionId::fromString($txId),
            status: $status,
            message: $message,
            rawResponse: $raw,
        );
    }

    /** @test */
    public function test_implements_contract_and_json_serializable(): void
    {
        $r = $this->make();
        $this->assertInstanceOf(StatusResponseContract::class, $r);
        $this->assertInstanceOf(JsonSerializable::class, $r);
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
        $this->assertSame('txn_99', $this->make(txId: 'txn_99')->getTransactionId()->toString());
    }

    /** @test */
    public function test_get_status(): void
    {
        $this->assertSame(PaymentStatus::Pending, $this->make(status: PaymentStatus::Pending)->getStatus());
    }

    /** @test */
    public function test_get_message(): void
    {
        $this->assertSame('OK', $this->make(message: 'OK')->getMessage());
    }

    /** @test */
    public function test_get_raw_response(): void
    {
        $raw = ['txn' => 'data'];
        $this->assertSame($raw, $this->make(raw: $raw)->getRawResponse());
    }

    /** @test */
    public function test_json_serialize_structure_and_values(): void
    {
        $data = $this->make(
            successful: true,
            txId: 'txn_status_5',
            status: PaymentStatus::Captured,
            message: 'Found.',
        )->jsonSerialize();

        $this->assertArrayHasKey('successful', $data);
        $this->assertArrayHasKey('transaction_id', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayNotHasKey('raw_response', $data);
        $this->assertSame('txn_status_5', $data['transaction_id']);
        $this->assertSame('captured', $data['status']);
    }

    /** @test */
    public function test_class_is_final_and_properties_readonly(): void
    {
        $rc = new \ReflectionClass(StatusResponse::class);
        $this->assertTrue($rc->isFinal());
        foreach ($rc->getProperties() as $prop) {
            $this->assertTrue($prop->isReadOnly());
        }
    }
}
