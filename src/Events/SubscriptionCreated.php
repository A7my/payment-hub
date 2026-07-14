<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Events;

use Mifatoyeh\LaravelPaymentFramework\DTO\SubscriptionRequest;
use Mifatoyeh\LaravelPaymentFramework\Responses\SubscriptionResponse;

/**
 * Dispatched when a recurring subscription is successfully created.
 */
final readonly class SubscriptionCreated
{
    /**
     * @param SubscriptionRequest  $request  The subscription creation request DTO.
     * @param SubscriptionResponse $response The standardised subscription response.
     */
    public function __construct(
        public SubscriptionRequest $request,
        public SubscriptionResponse $response,
    ) {
    }
}
