<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\DTO;

use InvalidArgumentException;
use JsonSerializable;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;

/**
 * Immutable DTO representing a void request for an authorised but uncaptured payment.
 *
 * Passed to PaymentDriverContract::void(). A void releases the reserved funds
 * without any settlement. Only transactions in Authorized status can be voided.
 *
 * There is no amount field because a void always releases the full authorised
 * hold — partial voids are not universally supported across providers and would
 * be expressed as a partial capture followed by a void of the remainder.
 */
final readonly class VoidRequest implements JsonSerializable
{
    /**
     * @param TransactionId        $transactionId  The provider-assigned identifier of the authorised transaction to void.
     * @param string               $reason         A human-readable reason for the void (e.g., "Order cancelled").
     * @param string               $idempotencyKey Unique key for safe retries (non-empty).
     * @param array<string, mixed> $metadata       Arbitrary key-value metadata forwarded to the provider.
     *
     * @throws InvalidArgumentException When $idempotencyKey is empty.
     */
    public function __construct(
        public TransactionId $transactionId,
        public string $reason,
        public string $idempotencyKey,
        public array $metadata = [],
    ) {
        if ($this->idempotencyKey === '') {
            throw new InvalidArgumentException(
                'VoidRequest $idempotencyKey must not be empty.',
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
            'reason'          => $this->reason,
            'idempotency_key' => $this->idempotencyKey,
            'metadata'        => $this->metadata,
        ];
    }
}
