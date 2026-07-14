<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Responses;

use JsonSerializable;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Responses\VerificationResponseContract;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;

/**
 * Standardised immutable response for the verify() operation.
 *
 * Confirms the authenticity and integrity of a completed transaction.
 * Typically used after a redirect-based payment to verify that the
 * result parameters have not been tampered with.
 *
 * Distinction from StatusResponse:
 * - StatusResponse answers "what is the current state of this transaction?"
 * - VerificationResponse answers "can we trust that this transaction is genuine?"
 *
 * Both isSuccessful() and isVerified() must be true for the application to
 * safely proceed with order fulfilment:
 * - isSuccessful() false → the API call to the provider failed.
 * - isVerified() false   → the transaction exists but the signature/hash is invalid.
 */
final class VerificationResponse implements VerificationResponseContract, JsonSerializable
{
    /**
     * @param bool                 $successful    Whether the verification API call completed successfully.
     * @param bool                 $verified      Whether the transaction was verified as authentic.
     * @param TransactionId        $transactionId The transaction identifier that was verified.
     * @param string               $message       Human-readable result message.
     * @param array<string, mixed> $rawResponse   Complete raw provider API response for debugging.
     */
    public function __construct(
        private readonly bool $successful,
        private readonly bool $verified,
        private readonly TransactionId $transactionId,
        private readonly string $message,
        private readonly array $rawResponse,
    ) {
    }

    /** {@inheritDoc} */
    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    /** {@inheritDoc} */
    public function isVerified(): bool
    {
        return $this->verified;
    }

    /** {@inheritDoc} */
    public function getTransactionId(): TransactionId
    {
        return $this->transactionId;
    }

    /** {@inheritDoc} */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * The raw provider API response payload (for debugging and logging).
     *
     * @return array<string, mixed>
     */
    public function getRawResponse(): array
    {
        return $this->rawResponse;
    }

    /**
     * Whether both the API call succeeded and the transaction is verified.
     *
     * This is the single safe guard for order fulfilment logic:
     *   if ($response->isTrusted()) { // proceed with order }
     *
     * @return bool
     */
    public function isTrusted(): bool
    {
        return $this->successful && $this->verified;
    }

    /**
     * Serialise to a JSON-compatible array.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'successful'     => $this->successful,
            'verified'       => $this->verified,
            'transaction_id' => $this->transactionId->toString(),
            'message'        => $this->message,
        ];
    }
}
