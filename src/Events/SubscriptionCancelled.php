<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Events;

use Mifatoyeh\LaravelPaymentFramework\Responses\SubscriptionResponse;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;

/**
 * Dispatched when a recurring subscription is successfully cancelled.
 */
final readonly class SubscriptionCancelled
{
    /**
     * @param TransactionId        $subscriptionId The provider-assigned subscription identifier.
     * @param SubscriptionResponse $response       The standardised subscription response.
     */
    public function __construct(
        public TransactionId $subscriptionId,
        public SubscriptionResponse $response,
    ) {
    }
}
