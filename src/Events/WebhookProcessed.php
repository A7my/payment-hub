<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Events;

use Mifatoyeh\LaravelPaymentFramework\DTO\WebhookRequest;
use Mifatoyeh\LaravelPaymentFramework\Responses\WebhookResponse;

/**
 * Dispatched after a webhook has been successfully verified and processed.
 *
 * Listeners can use this to trigger downstream business logic based on
 * the normalised WebhookEventType in the response.
 */
final readonly class WebhookProcessed
{
    /**
     * @param WebhookRequest  $request  The normalised webhook request DTO.
     * @param WebhookResponse $response The standardised webhook processing response.
     */
    public function __construct(
        public WebhookRequest $request,
        public WebhookResponse $response,
    ) {
    }
}
