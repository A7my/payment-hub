<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Logging;

use Illuminate\Log\LogManager;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Logging\PaymentLoggerContract;

/**
 * Debug logger that writes verbose payment logs to a dedicated debug channel.
 *
 * Activated when payment.logging.debug is true in config. Logs raw request
 * and response payloads at debug level in addition to standard info logs.
 *
 * Should never be used in production as it may log sensitive card data.
 */
final class DebugLogger implements PaymentLoggerContract
{
    /** The dedicated debug log channel name. */
    private const DEBUG_CHANNEL = 'payment-debug';

    /**
     * @param LogManager $log The Laravel log manager.
     */
    public function __construct(
        private readonly LogManager $log,
    ) {
    }

    /** {@inheritDoc} */
    public function info(string $message, array $context = []): void
    {
        // TODO: $this->log->channel(self::DEBUG_CHANNEL)->info($message, $context);
    }

    /** {@inheritDoc} */
    public function error(string $message, array $context = []): void
    {
        // TODO: $this->log->channel(self::DEBUG_CHANNEL)->error($message, $context);
    }

    /** {@inheritDoc} */
    public function debug(string $message, array $context = []): void
    {
        // TODO: $this->log->channel(self::DEBUG_CHANNEL)->debug($message, $context);
    }

    /** {@inheritDoc} */
    public function warning(string $message, array $context = []): void
    {
        // TODO: $this->log->channel(self::DEBUG_CHANNEL)->warning($message, $context);
    }
}
