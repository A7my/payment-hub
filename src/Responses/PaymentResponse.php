<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Responses;

use JsonSerializable;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Responses\PaymentResponseContract;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;

/**
 * Standardised immutable response for charge() and authorize() operations.
 *
 * Returned by every driver's charge() and authorize() methods.
 * The host application uses only the interface methods — never driver-specific fields.
 *
 * Design decisions:
 * - $rawResponse carries the complete provider API response for debugging and
 *   audit logging. Drivers must always populate it, even on failure.
 * - $providerReference is a secondary reference (e.g., Stripe's charge ID,
 *   PayPal's payment ID) that may differ from the canonical $transactionId.
 *   Not all providers use both; drivers should set it to an empty string when
 *   not applicable.
 * - On soft failure (card declined), isSuccessful() returns false but the
 *   response object is still fully populated. Exceptions are reserved for
 *   unrecoverable errors.
 * - $rawResponse is intentionally excluded from jsonSerialize() output —
 *   it is large and may contain sensitive provider data.
 */
final class PaymentResponse implements PaymentResponseContract, JsonSerializable
{
    /**
     * @param bool                 $successful        Whether the operation succeeded.
     * @param TransactionId        $transactionId     Provider-assigned canonical transaction identifier.
     * @param PaymentStatus        $status            Canonical payment status after this operation.
     * @param string               $providerReference Provider's secondary reference string (may be empty).
     * @param Money                $amount            The monetary amount charged or authorised.
     * @param array<string, mixed> $rawResponse       Complete raw provider API response for debugging.
     * @param string               $message           Human-readable result message (success or decline reason).
     */
    public function __construct(
        private readonly bool $successful,
        private readonly TransactionId $transactionId,
        private readonly PaymentStatus $status,
        private readonly string $providerReference,
        private readonly Money $amount,
        private readonly array $rawResponse,
        private readonly string $message,
    ) {
    }

    /** {@inheritDoc} */
    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    /** {@inheritDoc} */
    public function getTransactionId(): TransactionId
    {
        return $this->transactionId;
    }

    /** {@inheritDoc} */
    public function getStatus(): PaymentStatus
    {
        return $this->status;
    }

    /** {@inheritDoc} */
    public function getProviderReference(): string
    {
        return $this->providerReference;
    }

    /** {@inheritDoc} */
    public function getAmount(): Money
    {
        return $this->amount;
    }

    /** {@inheritDoc} */
    public function getRawResponse(): array
    {
        return $this->rawResponse;
    }

    /** {@inheritDoc} */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Whether the transaction requires additional customer action (e.g., 3DS redirect).
     */
    public function requiresAction(): bool
    {
        return $this->status === PaymentStatus::RequiresAction;
    }

    /**
     * Serialise to a JSON-compatible array.
     *
     * rawResponse is excluded — it is large and may contain sensitive provider data.
     * Use getRawResponse() when you need it for logging or debugging.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'successful'         => $this->successful,
            'transaction_id'     => $this->transactionId->toString(),
            'status'             => $this->status->value,
            'provider_reference' => $this->providerReference,
            'amount'             => $this->amount->jsonSerialize(),
            'message'            => $this->message,
        ];
    }
}
