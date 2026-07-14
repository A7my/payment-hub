<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\DTO;

use JsonSerializable;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;

/**
 * Immutable DTO representing a transaction lookup or verification request.
 *
 * Passed to both PaymentDriverContract::lookup() and verify().
 *
 * - lookup() fetches the current status of a transaction without asserting
 *   the result (read-only query).
 * - verify() confirms the authenticity and integrity of a completed transaction
 *   (typically used after a redirect-based payment to confirm the result was not
 *   tampered with).
 *
 * Both operations use the same DTO because they require the same input:
 * a transaction identifier and optional extra metadata.
 */
final readonly class TransactionLookupRequest implements JsonSerializable
{
    /**
     * @param TransactionId        $transactionId The provider-assigned transaction identifier to look up.
     * @param array<string, mixed> $metadata      Arbitrary key-value metadata forwarded to the provider.
     */
    public function __construct(
        public TransactionId $transactionId,
        public array $metadata = [],
    ) {
    }

    /**
     * Serialise to a JSON-compatible array.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'transaction_id' => $this->transactionId->toString(),
            'metadata'       => $this->metadata,
        ];
    }
}
