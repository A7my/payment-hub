<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Exceptions;

/**
 * Thrown when a required configuration key is missing or invalid.
 *
 * Raised at service-provider boot time during config validation, or at
 * driver resolution time when a driver config block is incomplete.
 */
final class InvalidConfigurationException extends PaymentException
{
    /**
     * Create an exception for a missing configuration key.
     *
     * @param string $key The dot-notation config key that is missing (e.g. "payment.drivers.stripe.secret").
     */
    public static function forMissingKey(string $key): self
    {
        return new self(
            "Required payment configuration key [{$key}] is missing or null. "
            . "Check your config/payment.php and .env file."
        );
    }

    /**
     * Create an exception for an invalid configuration value.
     *
     * @param string $key     The dot-notation config key with the invalid value.
     * @param string $reason  A human-readable explanation of why the value is invalid.
     */
    public static function forInvalidValue(string $key, string $reason): self
    {
        return new self(
            "Payment configuration key [{$key}] has an invalid value: {$reason}"
        );
    }
}
