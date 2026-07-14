<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Contracts\Services;

use Throwable;

/**
 * Contract for retry-with-backoff handling of transient provider errors.
 *
 * Decouples {@see \Mifatoyeh\LaravelPaymentFramework\Drivers\AbstractDriver} from
 * the concrete retry implementation, matching the same DI pattern used for
 * logging ({@see \Mifatoyeh\LaravelPaymentFramework\Contracts\Logging\PaymentLoggerContract}).
 */
interface RetryServiceContract
{
    /**
     * Execute a callable with automatic retry on transient exceptions.
     *
     * @param callable $operation The operation to execute (wraps a provider API call).
     *
     * @return mixed The return value of the callable on success.
     *
     * @throws Throwable The last exception after all retry attempts are exhausted.
     */
    public function execute(callable $operation): mixed;

    /**
     * Determine whether a thrown exception represents a transient (retryable) error.
     *
     * @param Throwable $e The exception to classify.
     *
     * @return bool True if the error is transient and the operation should be retried.
     */
    public function isTransient(Throwable $e): bool;
}
