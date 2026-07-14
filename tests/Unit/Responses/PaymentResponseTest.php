<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Responses;

use Mifatoyeh\LaravelPaymentFramework\Contracts\Responses\PaymentResponseContract;
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentResponse;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;
use JsonSerializable;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PaymentResponse.
 */
class PaymentResponseTest extends TestCase
{
    private function makeResponse(
        bool $successful = true,
        string $txId = 'txn_001',
        PaymentStatus $status = PaymentStatus::Captured,
        string $providerRef = 'ch_abc',
        int $amount = 1000,
        Currency $currency = Currency::USD,
        array $raw = ['provider' => 'stripe'],
        string $message = 'Payment successful.',
    ): PaymentResponse {
        return new PaymentResponse(
            successful: $successful,
            transactionId: TransactionId::fromString($txId),
            status: $status,
            providerReference: $providerRef,
            amount: Money::ofMinor($amount, $currency),
            rawResponse: $raw,
            message: $message,
        );
    }

    /** @test */
    public function test_implements_contract_and_json_serializable(): void
    {
        $r = $this->makeResponse();
        $this->assertInstanceOf(PaymentResponseContract::class, $r);
        $this->assertInstanceOf(JsonSerializable::class, $r);
    }

    /** @test */
    public function test_is_successful_returns_true_when_successful(): void
    {
        $this->assertTrue($this->makeResponse(successful: true)->isSuccessful());
    }

    /** @test */
    public function test_is_successful_returns_false_when_failed(): void
    {
        $this->assertFalse($this->makeResponse(successful: false)->isSuccessful());
    }

    /** @test */
    public function test_get_transaction_id_returns_correct_value(): void
    {
        $r = $this->makeResponse(txId: 'txn_xyz');
        $this->assertSame('txn_xyz', $r->getTransactionId()->toString());
    }

    /** @test */
    public function test_get_status_returns_correct_enum(): void
    {
        $r = $this->makeResponse(status: PaymentStatus::Authorized);
        $this->assertSame(PaymentStatus::Authorized, $r->getStatus());
    }

    /** @test */
    public function test_get_provider_reference_returns_string(): void
    {
        $r = $this->makeResponse(providerRef: 'ch_stripe_ref');
        $this->assertSame('ch_stripe_ref', $r->getProviderReference());
    }

    /** @test */
    public function test_get_amount_returns_money(): void
    {
        $r = $this->makeResponse(amount: 2500, currency: Currency::EUR);
        $this->assertSame(2500, $r->getAmount()->amount);
        $this->assertSame(Currency::EUR, $r->getAmount()->currency);
    }

    /** @test */
    public function test_get_raw_response_returns_array(): void
    {
        $raw = ['id' => 'ch_1', 'object' => 'charge'];
        $r   = $this->makeResponse(raw: $raw);
        $this->assertSame($raw, $r->getRawResponse());
    }

    /** @test */
    public function test_get_message_returns_string(): void
    {
        $r = $this->makeResponse(message: 'Card declined.');
        $this->assertSame('Card declined.', $r->getMessage());
    }

    /** @test */
    public function test_requires_action_true_when_status_requires_action(): void
    {
        $r = $this->makeResponse(status: PaymentStatus::RequiresAction);
        $this->assertTrue($r->requiresAction());
    }

    /** @test */
    public function test_requires_action_false_for_other_statuses(): void
    {
        foreach ([PaymentStatus::Captured, PaymentStatus::Failed, PaymentStatus::Pending] as $s) {
            $this->assertFalse($this->makeResponse(status: $s)->requiresAction());
        }
    }

    /** @test */
    public function test_json_serialize_has_correct_keys(): void
    {
        $r    = $this->makeResponse();
        $data = $r->jsonSerialize();

        $this->assertArrayHasKey('successful', $data);
        $this->assertArrayHasKey('transaction_id', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('provider_reference', $data);
        $this->assertArrayHasKey('amount', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayNotHasKey('raw_response', $data);
    }

    /** @test */
    public function test_json_serialize_values_are_correct(): void
    {
        $r    = $this->makeResponse(
            successful: true,
            txId: 'txn_abc',
            status: PaymentStatus::Captured,
            providerRef: 'ch_ref',
            amount: 1000,
            currency: Currency::USD,
            message: 'OK',
        );
        $data = $r->jsonSerialize();

        $this->assertTrue($data['successful']);
        $this->assertSame('txn_abc', $data['transaction_id']);
        $this->assertSame('captured', $data['status']);
        $this->assertSame('ch_ref', $data['provider_reference']);
        $this->assertSame(1000, $data['amount']['amount']);
        $this->assertSame('USD', $data['amount']['currency']);
        $this->assertSame('OK', $data['message']);
    }

    /** @test */
    public function test_json_encode_produces_valid_json(): void
    {
        $json = json_encode($this->makeResponse());
        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('transaction_id', $decoded);
    }

    /** @test */
    public function test_class_is_final(): void
    {
        $r = new \ReflectionClass(PaymentResponse::class);
        $this->assertTrue($r->isFinal());
    }

    /** @test */
    public function test_properties_are_readonly(): void
    {
        $rc = new \ReflectionClass(PaymentResponse::class);
        foreach ($rc->getProperties() as $prop) {
            $this->assertTrue($prop->isReadOnly(), "Property {$prop->getName()} should be readonly.");
        }
    }
}
