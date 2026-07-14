<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Exceptions;

/**
 * Thrown when a void operation fails in an unrecoverable way.
 *
 * Raised when the authorisation cannot be voided, for example because it
 * has already been captured or has expired.
 */
final class VoidFailedException extends PaymentException
{
}
