<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Events;

use Mifatoyeh\LaravelPaymentFramework\DTO\WebhookRequest;

/**
 * Dispatched immediately when a webhook HTTP request is received,
 * before signature verification or processing.
 *
 * Listeners can use this for raw webhook logging or rate-limiting.
 * This event is dispatched regardless of whether verification succeeds.
 */
final readonly class WebhookReceived
{
    /**
     * @param WebhookRequest $request The normalised webhook request DTO.
     */
    public function __construct(
        public WebhookRequest $request,
    ) {
    }
}
