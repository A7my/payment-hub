<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Responses;

use Mifatoyeh\LaravelPaymentFramework\Contracts\Responses\VerificationResponseContract;
use Mifatoyeh\LaravelPaymentFramework\Responses\VerificationResponse;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;
use JsonSerializable;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for VerificationResponse.
 */
class VerificationResponseTest extends TestCase
{
    private function make(
        bool $successful = true,
        bool $verified = true,
        string $txId = 'txn_verify_001',
        string $message = 'Transaction verified.',
        array $raw = [],
    ): VerificationResponse {
        return new VerificationResponse(
            successful: $successful,
            verified: $verified,
            transactionId: TransactionId::fromString($txId),
            message: $message,
            rawResponse: $raw,
        );
    }

    /** @test */
    public function test_implements_contract_and_json_serializable(): void
    {
        $r = $this->make();
        $this->assertInstanceOf(VerificationResponseContract::class, $r);
        $this->assertInstanceOf(JsonSerializable::class, $r);
    }

    /** @test */
    public function test_is_successful(): void
    {
        $this->assertTrue($this->make(successful: true)->isSuccessful());
        $this->assertFalse($this->make(successful: false)->isSuccessful());
    }

    /** @test */
    public function test_is_verified_true(): void
    {
        $this->assertTrue($this->make(verified: true)->isVerified());
    }

    /** @test */
    public function test_is_verified_false(): void
    {
        $this->assertFalse($this->make(verified: false)->isVerified());
    }

    /** @test */
    public function test_successful_true_but_verified_false_is_valid(): void
    {
        // The verification request succeeded, but the transaction was not verified.
        $r = $this->make(successful: true, verified: false);
        $this->assertTrue($r->isSuccessful());
        $this->assertFalse($r->isVerified());
    }

    /** @test */
    public function test_get_transaction_id(): void
    {
        $this->assertSame('txn_v_42', $this->make(txId: 'txn_v_42')->getTransactionId()->toString());
    }

    /** @test */
    public function test_get_message(): void
    {
        $this->assertSame('Mismatch.', $this->make(message: 'Mismatch.')->getMessage());
    }

    /** @test */
    public function test_get_raw_response(): void
    {
        $raw = ['hash' => 'abc123'];
        $this->assertSame($raw, $this->make(raw: $raw)->getRawResponse());
    }

    /** @test */
    public function test_json_serialize_structure_and_values(): void
    {
        $data = $this->make(
            successful: true,
            verified: true,
            txId: 'txn_v_99',
            message: 'OK',
        )->jsonSerialize();

        $this->assertArrayHasKey('successful', $data);
        $this->assertArrayHasKey('verified', $data);
        $this->assertArrayHasKey('transaction_id', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayNotHasKey('raw_response', $data);
        $this->assertTrue($data['verified']);
        $this->assertSame('txn_v_99', $data['transaction_id']);
    }

    /** @test */
    public function test_class_is_final_and_properties_readonly(): void
    {
        $rc = new \ReflectionClass(VerificationResponse::class);
        $this->assertTrue($rc->isFinal());
        foreach ($rc->getProperties() as $prop) {
            $this->assertTrue($prop->isReadOnly());
        }
    }
}
