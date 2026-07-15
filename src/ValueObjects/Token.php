<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\ValueObjects;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Immutable value object wrapping a provider-issued payment token.
 *
 * A token is an opaque, provider-issued string that represents either:
 * - A saved payment method (stored credential / recurring token)
 * - A one-time payment authorisation token
 *
 * Per-provider examples:
 * - Stripe: a PaymentMethod ID (e.g. `pm_1N...`, or the test helper
 *   `pm_card_visa`) — pass it here (via `PaymentRequest::$token`, or the
 *   `'token'` array key when using array input) to charge that specific
 *   payment method. Do NOT confuse this with `PaymentRequest::$paymentMethod`
 *   (the `'payment_method'` array key), which selects a payment method
 *   *category* (card, wallet, bank_transfer, …) — not a specific Stripe
 *   PaymentMethod instance.
 *
 * Design decisions:
 * - The token value is treated as completely opaque. Format, length, and
 *   character set are provider-defined and subject to change.
 * - Non-empty is the only invariant enforced — an empty token is always
 *   a programming error or a malformed provider response.
 * - Tokens MUST NOT be logged in full or stored in plain text in production.
 *   The masked() helper provides a safe representation for logs.
 * - Equality is case-sensitive — provider tokens are case-sensitive strings.
 */
final class Token implements JsonSerializable, Stringable
{
    /**
     * @param string $value Non-empty provider-issued token string.
     *
     * @throws InvalidArgumentException When $value is an empty string.
     */
    public function __construct(public readonly string $value)
    {
        if ($this->value === '') {
            throw new InvalidArgumentException(
                'Token must not be an empty string.',
            );
        }
    }

    /**
     * Named constructor — the preferred way to create a Token.
     *
     * @param string $token Non-empty provider-issued token.
     *
     * @throws InvalidArgumentException When $token is empty.
     *
     * @return self
     */
    public static function fromString(string $token): self
    {
        return new self($token);
    }

    /**
     * Return the underlying token string.
     *
     * WARNING: Do not log or display the full token value in production.
     * Use masked() for safe representation in logs.
     *
     * @return string
     */
    public function toString(): string
    {
        return $this->value;
    }

    /**
     * Return a masked representation safe for logging and display.
     *
     * Shows the first 4 characters and replaces the remainder with asterisks.
     * For tokens shorter than 8 characters, shows only the first 2 characters.
     *
     * Examples:
     *   tok_live_abc123def456 → tok_****
     *   pm_1234567890         → pm_1****
     *
     * @return string Partially masked token string.
     */
    public function masked(): string
    {
        $length  = mb_strlen($this->value);
        $visible = $length >= 8 ? 4 : 2;
        $visible = min($visible, $length);

        return mb_substr($this->value, 0, $visible) . str_repeat('*', max(0, $length - $visible));
    }

    /**
     * Check equality with another Token using a constant-time comparison.
     *
     * Regular === comparison is sufficient for equality (not authentication),
     * but constant-time is used defensively to prevent timing-oracle attacks
     * in any code path that might branch on token equality.
     *
     * @param self $other The Token to compare against.
     *
     * @return bool True when both tokens are identical.
     */
    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }

    /**
     * Serialise to a JSON-compatible scalar.
     *
     * WARNING: Only include token values in JSON output when strictly
     * necessary and the transport is secured (TLS). Never log token JSON.
     *
     * @return string The raw token string.
     */
    public function jsonSerialize(): string
    {
        return $this->value;
    }

    /**
     * Magic string cast — returns the full token value.
     *
     * WARNING: Do not use in log messages. Use masked() instead.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
