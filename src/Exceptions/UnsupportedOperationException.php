<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Exceptions;

/**
 * Thrown when a driver is asked to perform an operation it does not support.
 *
 * Rather than returning null or an empty response, drivers that do not
 * implement a particular operation MUST throw this exception so the host
 * application receives a clear, actionable error message identifying both
 * the operation and the driver that does not support it.
 */
final class UnsupportedOperationException extends PaymentException
{
    /**
     * Create an exception for an unsupported driver operation.
     *
     * Both the operation name and the driver name are included in the message
     * so developers know exactly which capability is missing from which driver.
     *
     * @param string $operation The name of the unsupported operation (e.g., "createSubscription").
     * @param string $driver    The name of the driver that does not support it (e.g., "qr_pay").
     */
    public static function forOperation(string $operation, string $driver): self
    {
        return new self(
            "The [{$driver}] payment driver does not support the [{$operation}] operation. "
            . "Use a driver that implements this capability or check SupportsCapabilities::supports()."
        );
    }
}
