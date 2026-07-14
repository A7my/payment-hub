<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Exceptions;

/**
 * Thrown when a payment authorisation fails in an unrecoverable way.
 *
 * Raised for permanent authentication/authorisation failures such as
 * invalid API credentials, insufficient permissions, or account suspension.
 * Soft declines (e.g., insufficient funds) are returned as a PaymentResponse
 * with isSuccessful() === false and should not throw this exception.
 */
final class AuthorizationFailedException extends PaymentException
{
}
