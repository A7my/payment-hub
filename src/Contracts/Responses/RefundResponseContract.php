<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Contracts\Responses;

use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;

/**
 * Contract for the standardised refund response.
 *
 * Returned by both refund() and partialRefund() driver methods.
 */
interface RefundResponseContract
{
    /**
     * Whether the refund was successfully processed.
     */
    public function isSuccessful(): bool;

    /**
     * The provider-assigned refund identifier.
     */
    public function getRefundId(): string;

    /**
     * The monetary amount that was refunded.
     */
    public function getAmount(): Money;

    /**
     * The canonical status of the refund operation.
     */
    public function getStatus(): PaymentStatus;

    /**
     * A human-readable message describing the result.
     */
    public function getMessage(): string;
}
