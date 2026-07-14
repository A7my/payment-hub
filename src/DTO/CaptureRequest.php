<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\DTO;

use InvalidArgumentException;
use JsonSerializable;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;

/**
 * Immutable DTO representing a capture request for a previously authorised payment.
 *
 * Passed to PaymentDriverContract::capture(). A capture settles the funds that
 * were reserved during an earlier authorize() call. The captured amount may be
 * less than or equal to the originally authorised amount (partial capture).
 *
 * Some providers allow capturing less than the authorised amount; whether that
 * is supported is a driver-level concern.
 */
final readonly class CaptureRequest implements JsonSerializable
{
    /**
     * @param TransactionId        $transactionId  The provider-assigned identifier of the authorised transaction.
     * @param Money                $amount         The amount to capture (≤ authorised amount), in the smallest unit.
     * @param string               $idempotencyKey Unique key for safe retries (non-empty).
     * @param array<string, mixed> $metadata       Arbitrary key-value metadata forwarded to the provider.
     *
     * @throws InvalidArgumentException When $idempotencyKey is empty.
     * @throws InvalidArgumentException When $amount is zero.
     */
    public function __construct(
        public TransactionId $transactionId,
        public Money $amount,
        public string $idempotencyKey,
        public array $metadata = [],
    ) {
        if ($this->idempotencyKey === '') {
            throw new InvalidArgumentException(
                'CaptureRequest $idempotencyKey must not be empty.',
            );
        }

        if ($this->amount->isZero()) {
            throw new InvalidArgumentException(
                'CaptureRequest $amount must be greater than zero.',
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
            'idempotency_key' => $this->idempotencyKey,
            'metadata'        => $this->metadata,
        ];
    }
}
