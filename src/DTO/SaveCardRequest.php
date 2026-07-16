<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\DTO;

use InvalidArgumentException;
use JsonSerializable;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\CustomerId;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Token;

/**
 * Immutable DTO representing a request to save a customer's payment method.
 *
 * Passed to PaymentDriverContract::saveCard(). The provider tokenises the
 * payment method referenced by $token for future recurring charges via
 * TokenChargeRequest.
 *
 * $customerId is the HOST APPLICATION's own customer reference ONLY — see
 * {@see \Mifatoyeh\LaravelPaymentFramework\ValueObjects\CustomerId}'s own
 * docblock. It is opaque to the provider and is never assumed to be (or be
 * convertible to) a provider-side customer identity. When a driver's
 * provider requires its own customer object to scope a saved payment method
 * for later off-session reuse (Stripe does — see
 * {@see \Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeDriver::saveCard()}),
 * that provider-side identity is returned via
 * {@see \Mifatoyeh\LaravelPaymentFramework\Responses\PaymentResponse::getProviderReference()},
 * not derived from or stored on $customerId. Callers who need to charge the
 * saved card later via {@see PaymentDriverContract::chargeToken()} must
 * capture that `providerReference` value themselves and round-trip it via
 * {@see TokenChargeRequest::$providerCustomerReference}.
 *
 * The $token here is typically a one-time setup/nonce token issued by the
 * provider's client-side SDK after the customer enters their card details.
 * The driver exchanges it for a reusable stored-credential token.
 */
final readonly class SaveCardRequest implements JsonSerializable
{
    /**
     * @param Token                $token          A one-time provider token representing the payment method to save.
     * @param CustomerId           $customerId     The host application's OWN customer identifier — opaque to the
     *                                              provider, never the provider-side customer id (see class docblock).
     * @param string               $idempotencyKey Unique key for safe retries (non-empty).
     * @param array<string, mixed> $metadata       Arbitrary key-value metadata forwarded to the provider.
     *
     * @throws InvalidArgumentException When $idempotencyKey is empty.
     */
    public function __construct(
        public Token $token,
        public CustomerId $customerId,
        public string $idempotencyKey,
        public array $metadata = [],
    ) {
        if ($this->idempotencyKey === '') {
            throw new InvalidArgumentException(
                'SaveCardRequest $idempotencyKey must not be empty.',
            );
        }
    }

    /**
     * Serialise to a JSON-compatible array.
     *
     * The token value is EXCLUDED from serialisation for security.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'token_masked'    => $this->token->masked(),
            'customer_id'     => $this->customerId->toString(),
            'idempotency_key' => $this->idempotencyKey,
            'metadata'        => $this->metadata,
        ];
    }
}
