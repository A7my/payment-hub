<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Contracts\Responses;

use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;

/**
 * Contract for the standardised payment (charge/authorize) response.
 *
 * Every driver's charge() and authorize() methods must return an object
 * implementing this contract, ensuring the host application works identically
 * with any provider.
 */
interface PaymentResponseContract
{
    /**
     * Whether the payment operation was successful from the provider's perspective.
     */
    public function isSuccessful(): bool;

    /**
     * The provider-assigned transaction identifier.
     */
    public function getTransactionId(): TransactionId;

    /**
     * The canonical payment status after this operation.
     */
    public function getStatus(): PaymentStatus;

    /**
     * The provider's own reference string (useful for reconciliation).
     */
    public function getProviderReference(): string;

    /**
     * The monetary amount that was charged or authorised.
     */
    public function getAmount(): Money;

    /**
     * The raw provider API response payload (for debugging and logging).
     *
     * @return array<string, mixed>
     */
    public function getRawResponse(): array;

    /**
     * A human-readable message describing the result (success message or error reason).
     */
    public function getMessage(): string;
}
