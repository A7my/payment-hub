<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Contracts\Responses;

use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;

/**
 * Contract for the standardised capture response.
 *
 * Returned by the capture() driver method when settling a prior authorisation.
 */
interface CaptureResponseContract
{
    /**
     * Whether the capture was successfully processed.
     */
    public function isSuccessful(): bool;

    /**
     * The provider-assigned capture identifier.
     */
    public function getCaptureId(): string;

    /**
     * The monetary amount that was captured.
     */
    public function getAmount(): Money;

    /**
     * The canonical status after capture.
     */
    public function getStatus(): PaymentStatus;

    /**
     * A human-readable message describing the result.
     */
    public function getMessage(): string;
}
