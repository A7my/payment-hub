<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Responses;

use JsonSerializable;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;

/**
 * Standardised immutable response for the void() operation.
 *
 * A successful void releases the reserved funds from an Authorized transaction.
 * There is no VoidResponseContract because voiding has the same structural shape
 * as a general status response — but it is typed as VoidResponse so callers can
 * type-hint against it precisely.
 */
final class VoidResponse implements JsonSerializable
{
    /**
     * @param bool                 $successful    Whether the void was successfully processed.
     * @param TransactionId        $transactionId The transaction identifier that was voided.
     * @param PaymentStatus        $status        Canonical status after void (should be Voided on success).
     * @param string               $message       Human-readable result message.
     * @param array<string, mixed> $rawResponse   Complete raw provider API response for debugging.
     */
    public function __construct(
        private readonly bool $successful,
        private readonly TransactionId $transactionId,
        private readonly PaymentStatus $status,
        private readonly string $message,
        private readonly array $rawResponse,
    ) {
    }

    /**
     * Whether the void was successfully processed.
     *
     * @return bool
     */
    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    /**
     * The voided transaction identifier.
     *
     * @return TransactionId
     */
    public function getTransactionId(): TransactionId
    {
        return $this->transactionId;
    }

    /**
     * Canonical status after void.
     *
     * @return PaymentStatus
     */
    public function getStatus(): PaymentStatus
    {
        return $this->status;
    }

    /**
     * Human-readable result message.
     *
     * @return string
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * The raw provider API response payload (for debugging and logging).
     *
     * @return array<string, mixed>
     */
    public function getRawResponse(): array
    {
        return $this->rawResponse;
    }

    /**
     * Serialise to a JSON-compatible array.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'successful'     => $this->successful,
            'transaction_id' => $this->transactionId->toString(),
            'status'         => $this->status->value,
            'message'        => $this->message,
        ];
    }
}
