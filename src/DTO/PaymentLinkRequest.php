<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\DTO;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonSerializable;
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;

/**
 * Immutable DTO representing a hosted payment link creation request.
 *
 * Passed to PaymentDriverContract::createPaymentLink(). The provider generates
 * a URL to which the customer is redirected to complete payment on the
 * provider's hosted page.
 *
 * Use cases:
 * - Invoice payments (send a link via email/SMS)
 * - Checkout pages hosted by the provider
 * - Payment requests where the merchant does not handle card data directly
 */
final readonly class PaymentLinkRequest implements JsonSerializable
{
    /**
     * @param Money                  $amount         The amount the customer will be charged.
     * @param Currency               $currency       ISO 4217 currency — must match $amount->currency.
     * @param string                 $description    A human-readable description shown on the payment page.
     * @param CustomerData|null      $customer       Optional pre-filled customer data.
     * @param string|null            $returnUrl      URL to redirect after successful payment.
     * @param string|null            $cancelUrl      URL to redirect after cancelled or abandoned payment.
     * @param DateTimeImmutable|null $expiresAt      Optional expiry date/time for the payment link.
     * @param string                 $idempotencyKey Unique key for safe retries (non-empty).
     * @param array<string, mixed>   $metadata       Arbitrary key-value metadata forwarded to the provider.
     *
     * @throws InvalidArgumentException When $idempotencyKey is empty.
     * @throws InvalidArgumentException When $amount->currency !== $currency.
     */
    public function __construct(
        public Money $amount,
        public Currency $currency,
        public string $description,
        public ?CustomerData $customer,
        public ?string $returnUrl,
        public ?string $cancelUrl,
        public ?DateTimeImmutable $expiresAt,
        public string $idempotencyKey,
        public array $metadata = [],
    ) {
        if ($this->idempotencyKey === '') {
            throw new InvalidArgumentException(
                'PaymentLinkRequest $idempotencyKey must not be empty.',
            );
        }

        if ($this->amount->currency !== $this->currency) {
            throw new InvalidArgumentException(
                sprintf(
                    'PaymentLinkRequest currency mismatch: $amount is [%s] but $currency is [%s].',
                    $this->amount->currency->value,
                    $this->currency->value,
                ),
            );
        }
    }

    /**
     * Whether the link has an expiry time set.
     *
     * @return bool
     */
    public function hasExpiry(): bool
    {
        return $this->expiresAt !== null;
    }

    /**
     * Whether customer data has been pre-filled.
     *
     * @return bool
     */
    public function hasCustomer(): bool
    {
        return $this->customer !== null;
    }

    /**
     * Serialise to a JSON-compatible array.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'amount'          => $this->amount->jsonSerialize(),
            'currency'        => $this->currency->value,
            'description'     => $this->description,
            'customer'        => $this->customer?->jsonSerialize(),
            'return_url'      => $this->returnUrl,
            'cancel_url'      => $this->cancelUrl,
            'expires_at'      => $this->expiresAt?->format(\DateTimeInterface::ATOM),
            'idempotency_key' => $this->idempotencyKey,
            'metadata'        => $this->metadata,
        ];
    }
}
