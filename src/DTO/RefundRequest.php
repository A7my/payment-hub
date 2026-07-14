<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\DTO;

use InvalidArgumentException;
use JsonSerializable;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;

/**
 * Immutable DTO representing a full or partial refund request.
 *
 * Passed to PaymentDriverContract::refund() and partialRefund().
 * The $idempotencyKey is required to prevent duplicate refunds on retry.
 *
 * The framework does not distinguish between full and partial refund at the
 * DTO level — that distinction is in the method name on the driver contract.
 * The $amount field is always the amount to be refunded (which may equal or
 * be less than the original captured amount).
 */
final readonly class RefundRequest implements JsonSerializable
{
    /**
     * @param TransactionId        $transactionId  The provider-assigned identifier of the transaction to refund.
     * @param Money                $amount         The amount to refund in the smallest currency unit.
     * @param string               $reason         A human-readable reason for the refund (e.g., "Customer request").
     * @param string               $idempotencyKey Unique key for safe retries (non-empty).
     * @param array<string, mixed> $metadata       Arbitrary key-value metadata forwarded to the provider.
     *
     * @throws InvalidArgumentException When $idempotencyKey is empty.
     * @throws InvalidArgumentException When $amount is zero (refunding zero is meaningless).
     */
    public function __construct(
        public TransactionId $transactionId,
        public Money $amount,
        public string $reason,
        public string $idempotencyKey,
        public array $metadata = [],
    ) {
        if ($this->idempotencyKey === '') {
            throw new InvalidArgumentException(
                'RefundRequest $idempotencyKey must not be empty.',
            );
        }

        if ($this->amount->isZero()) {
            throw new InvalidArgumentException(
                'RefundRequest $amount must be greater than zero. '
                . 'Refunding a zero amount is not meaningful.',
            );
        }
    }

    /**
     * Serialise to a JSON-compatible array.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'transaction_id'  => $this->transactionId->toString(),
            'amount'          => $this->amount->jsonSerialize(),
            'reason'          => $this->reason,
            'idempotency_key' => $this->idempotencyKey,
            'metadata'        => $this->metadata,
        ];
    }
}
