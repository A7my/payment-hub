<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Responses;

use JsonSerializable;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Responses\SdkCheckoutResponseContract;

/**
 * Standardised immutable response for the createSdkIntent() operation.
 *
 * See {@see SdkCheckoutResponseContract} for what each field is for and how
 * it differs from {@see PaymentLinkResponse}.
 */
final class SdkCheckoutResponse implements SdkCheckoutResponseContract, JsonSerializable
{
    /**
     * @param bool                 $successful           Whether the intent was created successfully.
     * @param string               $transactionReference Provider-assigned reference for this intent.
     * @param string               $clientSecret         The value the native SDK needs to confirm the charge.
     * @param string|null          $publishableKey        Provider-specific public key, if the provider's SDK needs one.
     * @param string               $message              Human-readable result message.
     * @param array<string, mixed> $rawResponse          Complete raw provider API response for debugging.
     */
    public function __construct(
        private readonly bool $successful,
        private readonly string $transactionReference,
        private readonly string $clientSecret,
        private readonly ?string $publishableKey,
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
    public function getTransactionReference(): string
    {
        return $this->transactionReference;
    }

    /** {@inheritDoc} */
    public function getClientSecret(): string
    {
        return $this->clientSecret;
    }

    /** {@inheritDoc} */
    public function getPublishableKey(): ?string
    {
        return $this->publishableKey;
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
     * Serialise to a JSON-compatible array.
     *
     * `rawResponse` is excluded — same reasoning as every other Response
     * class in this framework: it may be large and contain sensitive data.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'successful'             => $this->successful,
            'transaction_reference'  => $this->transactionReference,
            'client_secret'          => $this->clientSecret,
            'publishable_key'        => $this->publishableKey,
            'message'                => $this->message,
        ];
    }
}
