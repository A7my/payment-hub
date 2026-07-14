<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Logging;

use Illuminate\Log\LogManager;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Logging\PaymentLoggerContract;

/**
 * Logger implementation that writes to a configured Laravel log channel.
 *
 * The target channel is set via the payment.logging.channel config key.
 * Falls back to the application default channel if none is configured.
 *
 * Bound as the default PaymentLoggerContract implementation when
 * payment.logging.enabled is true.
 */
final class LaravelLogger implements PaymentLoggerContract
{
    /**
     * @param LogManager $log     The Laravel log manager.
     * @param string     $channel The log channel name (e.g. "stack", "payment", "daily").
     */
    public function __construct(
        private readonly LogManager $log,
        private readonly string $channel,
    ) {
    }

    /** {@inheritDoc} */
    public function info(string $message, array $context = []): void
    {
        // TODO: $this->log->channel($this->channel)->info($message, $context);
    }

    /** {@inheritDoc} */
    public function error(string $message, array $context = []): void
    {
        // TODO: $this->log->channel($this->channel)->error($message, $context);
    }

    /** {@inheritDoc} */
    public function debug(string $message, array $context = []): void
    {
        // TODO: $this->log->channel($this->channel)->debug($message, $context);
    }

    /** {@inheritDoc} */
    public function warning(string $message, array $context = []): void
    {
        // TODO: $this->log->channel($this->channel)->warning($message, $context);
    }
}
