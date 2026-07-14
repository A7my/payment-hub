<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Exceptions;

/**
 * Thrown when a capture operation fails in an unrecoverable way.
 *
 * Raised for situations such as: the authorisation has already been voided,
 * the capture window has expired, or the provider returns a permanent error.
 * For recoverable provider errors, the driver returns a CaptureResponse
 * with isSuccessful() === false instead.
 */
final class CaptureFailedException extends PaymentException
{
}
