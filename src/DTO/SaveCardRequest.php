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
 * payment method referenced by $token and associates it with $customerId for
 * future recurring charges via TokenChargeRequest.
 *
 * The $token here is typically a one-time setup/nonce token issued by the
 * provider's client-side SDK after the customer enters their card details.
 * The driver exchanges it for a reusable stored-credential token.
 */
final readonly class SaveCardRequest implements JsonSerializable
{
    /**
     * @param Token                $token          A one-time provider token representing the payment method to save.
     * @param CustomerId           $customerId     The host application's customer identifier.
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
