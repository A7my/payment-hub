<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Exceptions;

/**
 * Thrown when a webhook signature fails verification.
 *
 * The WebhookController catches this exception and returns HTTP 400.
 * The framework logs this at error level including the driver name and
 * a truncated (≤ 32 characters) form of the raw signature for security.
 */
final class WebhookVerificationException extends PaymentException
{
    /**
     * Create an exception for a failed signature verification.
     *
     * The signature is truncated to 32 characters in the message to avoid
     * leaking sensitive data to logs or error responses.
     *
     * @param string $driver    The driver name that failed verification.
     * @param string $signature The raw signature value received (truncated in message).
     */
    public static function forDriver(string $driver, string $signature): self
    {
        $truncated = substr($signature, 0, 32);

        return new self(
            "Webhook signature verification failed for driver [{$driver}]. "
            . "Signature (truncated): [{$truncated}]"
        );
    }
}
