<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Contracts\Responses;

use DateTimeImmutable;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;

/**
 * Contract for the standardised subscription response.
 *
 * Returned by both createSubscription() and cancelSubscription() driver methods.
 */
interface SubscriptionResponseContract
{
    /**
     * Whether the subscription operation was successful.
     */
    public function isSuccessful(): bool;

    /**
     * The provider-assigned subscription identifier.
     */
    public function getSubscriptionId(): string;

    /**
     * The canonical status of the subscription after this operation.
     */
    public function getStatus(): PaymentStatus;

    /**
     * The date and time of the next scheduled billing cycle, if applicable.
     */
    public function getNextBillingDate(): ?DateTimeImmutable;

    /**
     * A human-readable message describing the result.
     */
    public function getMessage(): string;
}
