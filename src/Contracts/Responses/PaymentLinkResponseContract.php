<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Contracts\Responses;

use DateTimeImmutable;

/**
 * Contract for the standardised payment link creation response.
 *
 * Returned by the createPaymentLink() driver method.
 */
interface PaymentLinkResponseContract
{
    /**
     * Whether the payment link was successfully created.
     */
    public function isSuccessful(): bool;

    /**
     * The URL the customer should be redirected to in order to complete payment.
     */
    public function getPaymentUrl(): string;

    /**
     * The provider-assigned identifier for this payment link.
     */
    public function getLinkId(): string;

    /**
     * The date and time at which this payment link expires, if applicable.
     */
    public function getExpiresAt(): ?DateTimeImmutable;

    /**
     * A human-readable message describing the result.
     */
    public function getMessage(): string;
}
