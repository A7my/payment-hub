<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Logging;

use Mifatoyeh\LaravelPaymentFramework\Contracts\Logging\PaymentLoggerContract;

/**
 * No-op logger that silently discards all log messages.
 *
 * Bound as the PaymentLoggerContract implementation when:
 *   - payment.logging.enabled is false in config
 *   - In test environments where log noise is undesirable
 *
 * Using this class instead of conditionals inside callers keeps the
 * logging abstraction clean (Null Object Pattern).
 */
final class NullLogger implements PaymentLoggerContract
{
    /** {@inheritDoc} */
    public function info(string $message, array $context = []): void
    {
        // Intentionally discards all messages.
    }

    /** {@inheritDoc} */
    public function error(string $message, array $context = []): void
    {
        // Intentionally discards all messages.
    }

    /** {@inheritDoc} */
    public function debug(string $message, array $context = []): void
    {
        // Intentionally discards all messages.
    }

    /** {@inheritDoc} */
    public function warning(string $message, array $context = []): void
    {
        // Intentionally discards all messages.
    }
}
