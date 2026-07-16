<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\DTO;

use InvalidArgumentException;
use JsonSerializable;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;

/**
 * Immutable DTO representing a request to cancel a subscription.
 *
 * Passed to PaymentDriverContract::cancelSubscription().
 *
 * Introduced to replace a bare `TransactionId $subscriptionId` parameter,
 * which could not express cancellation semantics at all: providers commonly
 * distinguish IMMEDIATE cancellation from scheduling cancellation for the
 * end of the current billing period — two meaningfully different outcomes,
 * not two variants of the same call (verified against the Stripe SDK:
 * `SubscriptionService::cancel()` — immediate — and
 * `SubscriptionService::update()` with `cancel_at_period_end` — scheduled —
 * are genuinely different API operations with different param sets).
 *
 * $reason is forwarded to Stripe's `cancellation_details.comment` — a
 * genuinely free-text field, unlike {@see VoidRequest::$reason} and
 * {@see RefundRequest::$reason}, which are deliberately NOT forwarded to
 * Stripe because their target fields (`cancellation_reason`, `reason`) are
 * fixed enums that free text cannot be safely coerced into. No such mismatch
 * exists here, so this DTO's $reason is forwarded, unlike those two.
 */
final readonly class CancelSubscriptionRequest implements JsonSerializable
{
    /**
     * @param TransactionId $subscriptionId    The provider's subscription identifier.
     * @param string        $idempotencyKey    Unique key for safe retries (non-empty).
     * @param bool          $cancelAtPeriodEnd When true, the subscription remains active until the end of the
     *                                         current billing period and is then cancelled automatically; when
     *                                         false (default), it is cancelled immediately.
     * @param bool          $invoiceNow        Only meaningful when $cancelAtPeriodEnd is false: whether to
     *                                         immediately invoice the customer for any unbilled proration.
     * @param bool          $prorate           Only meaningful when $cancelAtPeriodEnd is false: whether to
     *                                         prorate unused time back to the customer. Defaults to true,
     *                                         mirroring Stripe's own documented default for this parameter.
     * @param string|null   $reason            Optional free-text cancellation reason — forwarded to the
     *                                         provider when supported (see class docblock), and always
     *                                         available for framework-side logging regardless.
     *
     * @throws InvalidArgumentException When $idempotencyKey is empty.
     */
    public function __construct(
        public TransactionId $subscriptionId,
        public string $idempotencyKey,
        public bool $cancelAtPeriodEnd = false,
        public bool $invoiceNow = false,
        public bool $prorate = true,
        public ?string $reason = null,
    ) {
        if ($this->idempotencyKey === '') {
            throw new InvalidArgumentException(
                'CancelSubscriptionRequest $idempotencyKey must not be empty.',
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
            'subscription_id'     => $this->subscriptionId->toString(),
            'idempotency_key'     => $this->idempotencyKey,
            'cancel_at_period_end' => $this->cancelAtPeriodEnd,
            'invoice_now'         => $this->invoiceNow,
            'prorate'             => $this->prorate,
            'reason'              => $this->reason,
        ];
    }
}
