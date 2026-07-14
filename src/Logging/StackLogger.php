<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Logging;

use Mifatoyeh\LaravelPaymentFramework\Contracts\Logging\PaymentLoggerContract;

/**
 * Composite logger that fans out all messages to multiple logger instances.
 *
 * Useful when you need to write payment logs to multiple destinations
 * simultaneously (e.g., Laravel stack channel + external observability service).
 *
 * Example binding:
 *   new StackLogger([new LaravelLogger($log, 'payment'), new DebugLogger($log)])
 */
final class StackLogger implements PaymentLoggerContract
{
    /**
     * @param array<int, PaymentLoggerContract> $loggers The logger instances to fan out to.
     */
    public function __construct(
        private readonly array $loggers,
    ) {
    }

    /** {@inheritDoc} */
    public function info(string $message, array $context = []): void
    {
        // TODO: foreach ($this->loggers as $logger) { $logger->info($message, $context); }
    }

    /** {@inheritDoc} */
    public function error(string $message, array $context = []): void
    {
        // TODO: foreach ($this->loggers as $logger) { $logger->error($message, $context); }
    }

    /** {@inheritDoc} */
    public function debug(string $message, array $context = []): void
    {
        // TODO: foreach ($this->loggers as $logger) { $logger->debug($message, $context); }
    }

    /** {@inheritDoc} */
    public function warning(string $message, array $context = []): void
    {
        // TODO: foreach ($this->loggers as $logger) { $logger->warning($message, $context); }
    }
}
