<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\DTO;

use InvalidArgumentException;
use JsonSerializable;
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Token;

/**
 * Immutable DTO representing a charge request using a provider-issued token.
 *
 * Passed to PaymentDriverContract::chargeToken(). Used for charging saved
 * payment methods (e.g., stored card tokens, wallet tokens) without requiring
 * the customer to re-enter payment details.
 *
 * Token charges are classified as MIT (Merchant-Initiated Transactions) when
 * triggered without active customer involvement, or CIT (Customer-Initiated
 * Transactions) when the customer is present in the session. The distinction
 * has compliance implications (PSD2, card network mandates) that are handled
 * at the driver level.
 */
final readonly class TokenChargeRequest implements JsonSerializable
{
    /**
     * @param Token                $token          The provider-issued payment token to charge.
     * @param Money                $amount         The amount to charge in the smallest currency unit.
     * @param Currency             $currency       ISO 4217 currency — must match $amount->currency.
     * @param string               $idempotencyKey Unique key for safe retries (non-empty).
     * @param CustomerData         $customer       Customer identity information.
     * @param array<string, mixed> $metadata       Arbitrary key-value metadata forwarded to the provider.
     *
     * @throws InvalidArgumentException When $idempotencyKey is empty.
     * @throws InvalidArgumentException When $amount->currency !== $currency.
     */
    public function __construct(
        public Token $token,
        public Money $amount,
        public Currency $currency,
        public string $idempotencyKey,
        public CustomerData $customer,
        public array $metadata = [],
    ) {
        if ($this->idempotencyKey === '') {
            throw new InvalidArgumentException(
                'TokenChargeRequest $idempotencyKey must not be empty.',
            );
        }

        if ($this->amount->currency !== $this->currency) {
            throw new InvalidArgumentException(
                sprintf(
                    'TokenChargeRequest currency mismatch: $amount is [%s] but $currency is [%s].',
                    $this->amount->currency->value,
                    $this->currency->value,
                ),
            );
        }
    }

    /**
     * Serialise to a JSON-compatible array.
     *
     * The token value is EXCLUDED from serialisation for security.
     * Use $token->masked() if a reference is needed in logs.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'token_masked'    => $this->token->masked(),
            'amount'          => $this->amount->jsonSerialize(),
            'currency'        => $this->currency->value,
            'idempotency_key' => $this->idempotencyKey,
            'customer'        => $this->customer->jsonSerialize(),
            'metadata'        => $this->metadata,
        ];
    }
}
