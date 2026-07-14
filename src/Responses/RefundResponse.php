<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Responses;

use JsonSerializable;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Responses\RefundResponseContract;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;

/**
 * Standardised immutable response for refund() and partialRefund() operations.
 *
 * Drivers return this for both full and partial refund operations.
 * The host application can inspect $status to distinguish:
 * - PaymentStatus::Refunded          → full refund processed
 * - PaymentStatus::PartiallyRefunded → partial refund processed
 * - PaymentStatus::Failed            → refund declined or failed
 *
 * Design decisions:
 * - $rawResponse is excluded from jsonSerialize() output — it may contain
 *   sensitive provider data. Call getRawResponse() when needed.
 */
final class RefundResponse implements RefundResponseContract, JsonSerializable
{
    /**
     * @param bool                 $successful  Whether the refund was successfully processed.
     * @param string               $refundId    Provider-assigned refund identifier.
     * @param Money                $amount      The monetary amount refunded.
     * @param PaymentStatus        $status      Canonical status after this refund operation.
     * @param string               $message     Human-readable result message.
     * @param array<string, mixed> $rawResponse Complete raw provider API response for debugging.
     */
    public function __construct(
        private readonly bool $successful,
        private readonly string $refundId,
        private readonly Money $amount,
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
    public function getRefundId(): string
    {
        return $this->refundId;
    }

    /** {@inheritDoc} */
    public function getAmount(): Money
    {
        return $this->amount;
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
     * Whether this was a partial refund (not the full original amount).
     */
    public function isPartial(): bool
    {
        return $this->status === PaymentStatus::PartiallyRefunded;
    }

    /**
     * Serialise to a JSON-compatible array.
     *
     * rawResponse is excluded — it is large and may contain sensitive provider data.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'successful' => $this->successful,
            'refund_id'  => $this->refundId,
            'amount'     => $this->amount->jsonSerialize(),
            'status'     => $this->status->value,
            'message'    => $this->message,
        ];
    }
}
