<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Responses;

use JsonSerializable;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Responses\WebhookResponseContract;
use Mifatoyeh\LaravelPaymentFramework\Enums\WebhookEventType;

/**
 * Standardised immutable response for the processWebhook() operation.
 *
 * Returned by drivers after they have verified and normalised an inbound
 * webhook payload. The host application uses the canonical $eventType to
 * dispatch business logic (e.g., fulfil an order on PaymentSucceeded).
 *
 * Design decisions:
 * - $rawPayload carries the provider-specific parsed payload for audit logging.
 *   Drivers should populate it even when processing fails.
 * - $eventType is always set to WebhookEventType::Unknown when the driver
 *   cannot map the provider event to a canonical type — callers should
 *   log Unknown events and skip processing rather than throwing.
 */
final class WebhookResponse implements WebhookResponseContract, JsonSerializable
{
    /**
     * @param bool                 $successful  Whether the webhook was successfully processed.
     * @param WebhookEventType     $eventType   Canonical event type derived from the provider payload.
     * @param string               $message     Human-readable processing result message.
     * @param array<string, mixed> $rawPayload  The raw parsed webhook payload from the provider.
     */
    public function __construct(
        private readonly bool $successful,
        private readonly WebhookEventType $eventType,
        private readonly string $message,
        private readonly array $rawPayload,
    ) {
    }

    /** {@inheritDoc} */
    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    /** {@inheritDoc} */
    public function getEventType(): WebhookEventType
    {
        return $this->eventType;
    }

    /** {@inheritDoc} */
    public function getMessage(): string
    {
        return $this->message;
    }

    /** {@inheritDoc} */
    public function getRawPayload(): array
    {
        return $this->rawPayload;
    }

    /**
     * Whether the event type was recognised (not Unknown).
     *
     * @return bool
     */
    public function isKnownEvent(): bool
    {
        return ! $this->eventType->isUnknown();
    }

    /**
     * Whether the event represents a successful payment.
     *
     * Convenience proxy to WebhookEventType::isPaymentSuccess().
     *
     * @return bool
     */
    public function isPaymentSuccess(): bool
    {
        return $this->eventType->isPaymentSuccess();
    }

    /**
     * Serialise to a JSON-compatible array.
     *
     * rawPayload is excluded — it may be large and contain provider-specific data.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'successful' => $this->successful,
            'event_type' => $this->eventType->value,
            'message'    => $this->message,
        ];
    }
}
