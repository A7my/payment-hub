<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Responses;

use DateTimeImmutable;
use JsonSerializable;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Responses\SubscriptionResponseContract;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;

/**
 * Standardised immutable response for createSubscription() and cancelSubscription().
 *
 * Represents the state of a subscription after a lifecycle operation.
 * The $status field indicates the subscription's current state:
 * - PaymentStatus::Captured  → subscription created and active (first charge succeeded)
 * - PaymentStatus::Pending   → subscription created but first charge pending
 * - PaymentStatus::Cancelled → subscription cancelled
 * - PaymentStatus::Failed    → subscription creation or renewal failed
 */
final class SubscriptionResponse implements SubscriptionResponseContract, JsonSerializable
{
    /**
     * @param bool                   $successful      Whether the subscription operation succeeded.
     * @param string                 $subscriptionId  Provider-assigned subscription identifier.
     * @param PaymentStatus          $status          Canonical status of the subscription after this operation.
     * @param DateTimeImmutable|null $nextBillingDate Next scheduled billing date, if applicable.
     * @param string                 $message         Human-readable result message.
     * @param array<string, mixed>   $rawResponse     Complete raw provider API response for debugging.
     */
    public function __construct(
        private readonly bool $successful,
        private readonly string $subscriptionId,
        private readonly PaymentStatus $status,
        private readonly ?DateTimeImmutable $nextBillingDate,
        private readonly string $message,
        private readonly array $rawResponse,
    ) {
    }

    /** {@inheritDoc} */
    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    /** {@inheritDoc} */
    public function getSubscriptionId(): string
    {
        return $this->subscriptionId;
    }

    /** {@inheritDoc} */
    public function getStatus(): PaymentStatus
    {
        return $this->status;
    }

    /** {@inheritDoc} */
    public function getNextBillingDate(): ?DateTimeImmutable
    {
        return $this->nextBillingDate;
    }

    /** {@inheritDoc} */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * The raw provider API response payload (for debugging and logging).
     *
     * @return array<string, mixed>
     */
    public function getRawResponse(): array
    {
        return $this->rawResponse;
    }

    /**
     * Whether the subscription has been cancelled.
     *
     * @return bool
     */
    public function isCancelled(): bool
    {
        return $this->status === PaymentStatus::Cancelled;
    }

    /**
     * Whether a next billing date is available.
     *
     * @return bool
     */
    public function hasNextBillingDate(): bool
    {
        return $this->nextBillingDate !== null;
    }

    /**
     * Serialise to a JSON-compatible array.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'successful'       => $this->successful,
            'subscription_id'  => $this->subscriptionId,
            'status'           => $this->status->value,
            'next_billing_date' => $this->nextBillingDate?->format(\DateTimeInterface::ATOM),
            'message'          => $this->message,
        ];
    }
}
