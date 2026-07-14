<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Exceptions;

/**
 * Thrown when a refund operation fails in an unrecoverable way.
 *
 * This is raised for unrecoverable failures (e.g., already refunded,
 * refund window expired). For recoverable provider declines, the driver
 * returns a RefundResponse with isSuccessful() === false instead.
 */
final class RefundFailedException extends PaymentException
{
}
