<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Responses;

use JsonSerializable;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Responses\StatusResponseContract;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;

/**
 * Standardised immutable response for the lookup() operation.
 *
 * Returns the current canonical status of a transaction without mutating
 * any funds. Used for polling, webhook reconciliation, and post-redirect
 * status checks.
 */
final class StatusResponse implements StatusResponseContract, JsonSerializable
{
    /**
     * @param bool                 $successful    Whether the status lookup completed successfully.
     * @param TransactionId        $transactionId The transaction identifier that was looked up.
     * @param PaymentStatus        $status        Current canonical status of the transaction.
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
     * Whether the transaction is in a terminal state (no further transitions possible).
     *
     * @return bool
     */
    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
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
