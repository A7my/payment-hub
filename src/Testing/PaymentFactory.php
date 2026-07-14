<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Testing;

use Mifatoyeh\LaravelPaymentFramework\DTO\CustomerData;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\RefundRequest;
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentMethod;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;

/**
 * Fluent factory for generating valid test DTOs with sensible defaults.
 *
 * Eliminates constructor boilerplate in tests. Provides a fluent builder
 * that allows selective overrides while defaulting to safe, valid values.
 *
 * Usage:
 *   $request = PaymentFactory::paymentRequest()
 *       ->withAmount(1000, Currency::USD)
 *       ->withCustomer('Jane Doe', 'jane@example.com')
 *       ->make();
 *
 *   $refund = PaymentFactory::refundRequest()
 *       ->withTransactionId('txn_123')
 *       ->withAmount(500, Currency::USD)
 *       ->make();
 */
final class PaymentFactory
{
    /** @var string The type of DTO to build: 'payment' or 'refund'. */
    private string $type = 'payment';

    /** @var int Amount in smallest currency unit. */
    private int $amount = 1000;

    /** @var Currency */
    private Currency $currency;

    /** @var string */
    private string $idempotencyKey;

    /** @var string */
    private string $customerName = 'Test Customer';

    /** @var string */
    private string $customerEmail = 'test@example.com';

    /** @var string|null */
    private ?string $transactionId = null;

    /**
     * Private constructor — use static factory methods.
     */
    private function __construct()
    {
        $this->currency = Currency::USD;
        $this->idempotencyKey = 'test-key-' . uniqid();
    }

    /**
     * Create a factory for building a PaymentRequest DTO.
     */
    public static function paymentRequest(): self
    {
        $factory = new self();
        $factory->type = 'payment';
        return $factory;
    }

    /**
     * Create a factory for building a RefundRequest DTO.
     */
    public static function refundRequest(): self
    {
        $factory = new self();
        $factory->type = 'refund';
        $factory->transactionId = 'fake-txn-' . uniqid();
        return $factory;
    }

    /**
     * Override the monetary amount and currency.
     *
     * @param int      $amount   Amount in smallest currency unit.
     * @param Currency $currency The ISO 4217 currency.
     */
    public function withAmount(int $amount, Currency $currency): self
    {
        $clone = clone $this;
        $clone->amount = $amount;
        $clone->currency = $currency;
        return $clone;
    }

    /**
     * Override the customer name and email.
     *
     * @param string $name  Customer full name.
     * @param string $email Customer email address.
     */
    public function withCustomer(string $name, string $email): self
    {
        $clone = clone $this;
        $clone->customerName = $name;
        $clone->customerEmail = $email;
        return $clone;
    }

    /**
     * Override the idempotency key.
     *
     * @param string $key A unique idempotency key string.
     */
    public function withIdempotencyKey(string $key): self
    {
        $clone = clone $this;
        $clone->idempotencyKey = $key;
        return $clone;
    }

    /**
     * Override the transaction ID (for refund requests).
     *
     * @param string $id The transaction identifier string.
     */
    public function withTransactionId(string $id): self
    {
        $clone = clone $this;
        $clone->transactionId = $id;
        return $clone;
    }

    /**
     * Build and return the configured DTO with all defaults applied.
     *
     * @return PaymentRequest|RefundRequest The constructed DTO instance.
     */
    public function make(): PaymentRequest|RefundRequest
    {
        $money = Money::of($this->amount, $this->currency);

        if ($this->type === 'refund') {
            $txnId = TransactionId::fromString($this->transactionId ?? ('fake-txn-' . uniqid()));
            return new RefundRequest(
                transactionId: $txnId,
                amount: $money,
                reason: 'Test refund',
                idempotencyKey: $this->idempotencyKey,
                metadata: [],
            );
        }

        $customer = new CustomerData(
            name: $this->customerName,
            email: $this->customerEmail,
        );

        return new PaymentRequest(
            amount: $money,
            currency: $this->currency,
            idempotencyKey: $this->idempotencyKey,
            customer: $customer,
            order: null,
            billingAddress: null,
            returnUrl: null,
            cancelUrl: null,
            metadata: [],
            token: null,
            paymentMethod: PaymentMethod::Card,
        );
    }
}
