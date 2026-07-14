<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Exceptions;

/**
 * Thrown when an idempotency key is missing, empty, or invalid.
 *
 * The framework enforces that every PaymentRequest and RefundRequest carries
 * a non-empty idempotency key. AbstractDriver checks this before invoking
 * any driver method to prevent duplicate charges.
 */
final class IdempotencyException extends PaymentException
{
    /**
     * Create an exception for a missing or empty idempotency key.
     */
    public static function forEmptyKey(): self
    {
        return new self(
            'An idempotency key is required and must not be empty or whitespace-only. '
            . 'Provide a unique string (e.g., UUID v4) to safely retry this request.'
        );
    }
}
