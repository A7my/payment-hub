<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Contracts\Responses;

use Mifatoyeh\LaravelPaymentFramework\Enums\WebhookEventType;

/**
 * Contract for the standardised webhook processing response.
 *
 * Returned by the processWebhook() driver method after a webhook event
 * has been normalised and processed.
 */
interface WebhookResponseContract
{
    /**
     * Whether the webhook was successfully processed.
     */
    public function isSuccessful(): bool;

    /**
     * The canonical event type derived from the provider's webhook payload.
     */
    public function getEventType(): WebhookEventType;

    /**
     * A human-readable message describing the processing result.
     */
    public function getMessage(): string;

    /**
     * The raw webhook payload received from the provider.
     *
     * @return array<string, mixed>
     */
    public function getRawPayload(): array;
}
