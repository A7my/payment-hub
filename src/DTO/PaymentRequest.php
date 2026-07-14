<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\DTO;

use InvalidArgumentException;
use JsonSerializable;
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentMethod;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Token;

/**
 * Immutable DTO representing a payment charge or authorisation request.
 *
 * This is the primary input DTO passed to PaymentDriverContract::charge()
 * and PaymentDriverContract::authorize(). All properties are readonly to
 * guarantee immutability across layer boundaries.
 *
 * Idempotency:
 * The $idempotencyKey is required and must be non-empty. It allows the caller
 * to safely retry a failed request without risk of duplicate charges. The
 * framework's AbstractDriver additionally enforces this before invoking any
 * provider API. Callers should use a UUID v4 or equivalent unique identifier.
 *
 * Currency consistency:
 * $amount->currency and $currency must match. This is validated at construction
 * to prevent subtle bugs where the amount is denominated in one currency but
 * the request declares a different one.
 */
final readonly class PaymentRequest implements JsonSerializable
{
    /**
     * @param Money          $amount         Monetary amount in the smallest currency unit.
     * @param Currency       $currency       ISO 4217 currency — must match $amount->currency.
     * @param string         $idempotencyKey Unique key for safe retries (non-empty, e.g. UUID v4).
     * @param CustomerData   $customer       Customer identity information.
     * @param OrderData|null $order          Optional order context for reconciliation and receipts.
     * @param AddressData|null $billingAddress Optional billing address for AVS / risk checks.
     * @param string|null    $returnUrl      URL to redirect the customer after successful payment.
     * @param string|null    $cancelUrl      URL to redirect the customer after cancelled payment.
     * @param array<string, mixed> $metadata Arbitrary key-value metadata forwarded to the provider.
     * @param Token|null     $token          Provider-issued token for saved-card / token-based charges.
     * @param PaymentMethod  $paymentMethod  The intended payment method.
     * @param array<string, mixed> $options  Gateway-specific options with no dedicated framework
     *                                       property (e.g. Stripe's `automatic_payment_methods`,
     *                                       `capture_method`, `setup_future_usage`). Forwarded
     *                                       verbatim to the provider by the driver; the framework
     *                                       never inspects or validates its contents. This keeps
     *                                       provider-specific parameters out of the shared DTO
     *                                       while still reaching the provider untouched. The same
     *                                       mechanism is intended for every driver, not just Stripe.
     *
     * @throws InvalidArgumentException When $idempotencyKey is empty.
     * @throws InvalidArgumentException When $amount->currency !== $currency.
     */
    public function __construct(
        public Money $amount,
        public Currency $currency,
        public string $idempotencyKey,
        public CustomerData $customer,
        public ?OrderData $order = null,
        public ?AddressData $billingAddress = null,
        public ?string $returnUrl = null,
        public ?string $cancelUrl = null,
        public array $metadata = [],
        public ?Token $token = null,
        public PaymentMethod $paymentMethod = PaymentMethod::Card,
        public array $options = [],
    ) {
        if ($this->idempotencyKey === '') {
            throw new InvalidArgumentException(
                'PaymentRequest $idempotencyKey must not be empty. '
                . 'Provide a unique string (e.g., UUID v4) to enable safe retries.',
            );
        }

        if ($this->amount->currency !== $this->currency) {
            throw new InvalidArgumentException(
                sprintf(
                    'PaymentRequest currency mismatch: $amount is denominated in [%s] '
                    . 'but $currency is [%s]. They must be identical.',
                    $this->amount->currency->value,
                    $this->currency->value,
                ),
            );
        }
    }

    /**
     * Whether this request uses a previously saved token.
     *
     * @return bool
     */
    public function hasToken(): bool
    {
        return $this->token !== null;
    }

    /**
     * Whether an order context is attached.
     *
     * @return bool
     */
    public function hasOrder(): bool
    {
        return $this->order !== null;
    }

    /**
     * Whether a billing address is attached.
     *
     * @return bool
     */
    public function hasBillingAddress(): bool
    {
        return $this->billingAddress !== null;
    }

    /**
     * Serialise to a JSON-compatible array.
     *
     * The idempotency key is included so that log entries and audit trails
     * can correlate requests and responses.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'amount'          => $this->amount->jsonSerialize(),
            'currency'        => $this->currency->value,
            'idempotency_key' => $this->idempotencyKey,
            'payment_method'  => $this->paymentMethod->value,
            'customer'        => $this->customer->jsonSerialize(),
            'order'           => $this->order?->jsonSerialize(),
            'billing_address' => $this->billingAddress?->jsonSerialize(),
            'return_url'      => $this->returnUrl,
            'cancel_url'      => $this->cancelUrl,
            'has_token'       => $this->hasToken(),
            'metadata'        => $this->metadata,
            'options'         => $this->options,
        ];
    }
}
