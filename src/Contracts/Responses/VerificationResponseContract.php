<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Contracts\Responses;

use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;

/**
 * Contract for the standardised payment verification response.
 *
 * Returned by the verify() driver method to confirm the authenticity
 * and integrity of a completed transaction.
 */
interface VerificationResponseContract
{
    /**
     * Whether the verification request was successfully completed.
     */
    public function isSuccessful(): bool;

    /**
     * Whether the transaction has been verified as authentic.
     */
    public function isVerified(): bool;

    /**
     * The transaction identifier that was verified.
     */
    public function getTransactionId(): TransactionId;

    /**
     * A human-readable message describing the verification result.
     */
    public function getMessage(): string;
}
