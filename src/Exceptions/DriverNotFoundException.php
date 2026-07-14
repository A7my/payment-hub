<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Exceptions;

/**
 * Thrown when a requested payment driver cannot be resolved.
 *
 * This occurs when:
 *   - The driver name is not registered in config/payment.php
 *   - The resolved class does not implement PaymentDriverContract
 *   - Payment::driver('unknown') is called with an unregistered name
 */
final class DriverNotFoundException extends PaymentException
{
    /**
     * Create an exception for a missing driver key.
     *
     * @param string $driver The driver name that could not be resolved.
     */
    public static function forDriver(string $driver): self
    {
        return new self(
            "Payment driver [{$driver}] is not configured. "
            . "Add a [{$driver}] block to config/payment.php and register the driver class."
        );
    }
}
