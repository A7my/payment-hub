<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Contracts\Logging;

/**
 * Pluggable logging contract for the payment framework.
 *
 * Decouples the framework from any specific logger implementation.
 * Bind a concrete implementation in the service provider:
 *   - LaravelLogger  — writes to a configured Laravel log channel
 *   - NullLogger     — discards all messages (useful for testing or when logging is disabled)
 *   - DebugLogger    — writes to a dedicated debug channel when payment.logging.debug is true
 *   - StackLogger    — fans out to multiple loggers simultaneously
 */
interface PaymentLoggerContract
{
    /**
     * Log an informational message.
     *
     * @param string               $message The log message.
     * @param array<string, mixed> $context Additional context data.
     */
    public function info(string $message, array $context = []): void;

    /**
     * Log an error message.
     *
     * @param string               $message The log message.
     * @param array<string, mixed> $context Additional context data.
     */
    public function error(string $message, array $context = []): void;

    /**
     * Log a debug message (only emitted when payment.logging.debug is true).
     *
     * @param string               $message The log message.
     * @param array<string, mixed> $context Additional context data.
     */
    public function debug(string $message, array $context = []): void;

    /**
     * Log a warning message.
     *
     * @param string               $message The log message.
     * @param array<string, mixed> $context Additional context data.
     */
    public function warning(string $message, array $context = []): void;
}
