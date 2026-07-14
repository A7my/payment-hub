<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Exceptions;

/**
 * Thrown when a subscription lifecycle operation fails in an unrecoverable way.
 *
 * Raised for errors such as: invalid plan ID, subscription already cancelled,
 * or provider-level subscription management errors that cannot be retried.
 */
final class SubscriptionException extends PaymentException
{
}
