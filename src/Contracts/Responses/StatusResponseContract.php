<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Contracts\Responses;

use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;

/**
 * Contract for the standardised transaction status lookup response.
 *
 * Returned by the lookup() driver method when querying the current
 * state of a transaction.
 */
interface StatusResponseContract
{
    /**
     * Whether the status lookup was successfully completed.
     */
    public function isSuccessful(): bool;

    /**
     * The transaction identifier that was looked up.
     */
    public function getTransactionId(): TransactionId;

    /**
     * The current canonical status of the transaction.
     */
    public function getStatus(): PaymentStatus;

    /**
     * A human-readable message describing the result.
     */
    public function getMessage(): string;
}
