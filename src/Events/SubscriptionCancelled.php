<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Events;

use Mifatoyeh\LaravelPaymentFramework\DTO\CancelSubscriptionRequest;
use Mifatoyeh\LaravelPaymentFramework\Responses\SubscriptionResponse;

/**
 * Dispatched when a subscription cancellation request completes successfully
 * (either immediately, or scheduled for the end of the current billing
 * period — see {@see CancelSubscriptionRequest::$cancelAtPeriodEnd}).
 *
 * Carries the full request DTO rather than a bare subscription id — matching
 * every other lifecycle event in this namespace (e.g. {@see CardSaved},
 * {@see PaymentVoided}) — now that {@see CancelSubscriptionRequest} exists;
 * this event previously carried a bare `TransactionId` because no request
 * DTO existed yet for this operation.
 */
final readonly class SubscriptionCancelled
{
    /**
     * @param CancelSubscriptionRequest $request  The cancellation request DTO.
     * @param SubscriptionResponse      $response The standardised subscription response.
     */
    public function __construct(
        public CancelSubscriptionRequest $request,
        public SubscriptionResponse $response,
    ) {
    }
}
