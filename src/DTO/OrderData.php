<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\DTO;

use JsonSerializable;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\OrderId;

/**
 * Immutable DTO carrying order context for a payment operation.
 *
 * Provides providers with order-level metadata for reconciliation, receipts,
 * and fraud detection. Passed as the optional $order field on PaymentRequest.
 *
 * $items is an open array of line items whose structure is application-defined.
 * The framework does not enforce a specific line-item schema.
 */
final readonly class OrderData implements JsonSerializable
{
    /**
     * @param OrderId              $orderId     The host application's order identifier.
     * @param string               $description A human-readable order description shown on receipts.
     * @param array<int, mixed>    $items       Line items comprising the order (application-defined structure).
     * @param array<string, mixed> $metadata    Arbitrary key-value metadata forwarded to the provider.
     */
    public function __construct(
        public OrderId $orderId,
        public string $description,
        public array $items = [],
        public array $metadata = [],
    ) {
    }

    /**
     * Whether this order contains line items.
     *
     * @return bool
     */
    public function hasItems(): bool
    {
        return count($this->items) > 0;
    }

    /**
     * Number of line items in this order.
     *
     * @return int
     */
    public function itemCount(): int
    {
        return count($this->items);
    }

    /**
     * Serialise to a JSON-compatible array.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'order_id'    => $this->orderId->toString(),
            'description' => $this->description,
            'items'       => $this->items,
            'metadata'    => $this->metadata,
        ];
    }
}
