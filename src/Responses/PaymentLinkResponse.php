<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Responses;

use DateTimeImmutable;
use JsonSerializable;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Responses\PaymentLinkResponseContract;

/**
 * Standardised immutable response for the createPaymentLink() operation.
 *
 * Contains the URL to which the customer should be redirected to complete
 * payment on the provider's hosted page, along with the provider-assigned
 * link identifier and optional expiry.
 */
final class PaymentLinkResponse implements PaymentLinkResponseContract, JsonSerializable
{
    /**
     * @param bool                   $successful  Whether the payment link was created successfully.
     * @param string                 $paymentUrl  The URL for the customer to complete payment.
     * @param string                 $linkId      Provider-assigned payment link identifier.
     * @param DateTimeImmutable|null $expiresAt   When the link expires, if applicable.
     * @param string                 $message     Human-readable result message.
     * @param array<string, mixed>   $rawResponse Complete raw provider API response for debugging.
     */
    public function __construct(
        private readonly bool $successful,
        private readonly string $paymentUrl,
        private readonly string $linkId,
        private readonly ?DateTimeImmutable $expiresAt,
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
    public function getPaymentUrl(): string
    {
        return $this->paymentUrl;
    }

    /** {@inheritDoc} */
    public function getLinkId(): string
    {
        return $this->linkId;
    }

    /** {@inheritDoc} */
    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
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
     * Whether the payment link has an expiry set.
     *
     * @return bool
     */
    public function hasExpiry(): bool
    {
        return $this->expiresAt !== null;
    }

    /**
     * Whether the link has expired relative to the given time (defaults to now).
     *
     * @param DateTimeImmutable|null $now Reference time (null = current time).
     *
     * @return bool
     */
    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return ($now ?? new DateTimeImmutable()) > $this->expiresAt;
    }

    /**
     * Serialise to a JSON-compatible array.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'successful'  => $this->successful,
            'payment_url' => $this->paymentUrl,
            'link_id'     => $this->linkId,
            'expires_at'  => $this->expiresAt?->format(\DateTimeInterface::ATOM),
            'message'     => $this->message,
        ];
    }
}
