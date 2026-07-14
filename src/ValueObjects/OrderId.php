<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\ValueObjects;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Immutable value object wrapping an order identifier.
 *
 * Represents the host application's order reference as forwarded to
 * payment providers and persisted in the payment_transactions table.
 *
 * Design decisions:
 * - Format is application-controlled and opaque to the framework.
 * - Non-empty is the only invariant the framework enforces.
 * - Case-sensitive equality matches provider storage behaviour.
 */
final class OrderId implements JsonSerializable, Stringable
{
    /**
     * @param string $value Non-empty order identifier string.
     *
     * @throws InvalidArgumentException When $value is an empty string.
     */
    public function __construct(public readonly string $value)
    {
        if ($this->value === '') {
            throw new InvalidArgumentException(
                'OrderId must not be an empty string.',
            );
        }
    }

    /**
     * Named constructor — the preferred way to create an OrderId.
     *
     * @param string $id Non-empty order identifier.
     *
     * @throws InvalidArgumentException When $id is empty.
     *
     * @return self
     */
    public static function fromString(string $id): self
    {
        return new self($id);
    }

    /**
     * Return the underlying identifier string.
     *
     * @return string
     */
    public function toString(): string
    {
        return $this->value;
    }

    /**
     * Check equality with another OrderId.
     *
     * @param self $other The OrderId to compare against.
     *
     * @return bool True when both IDs are identical strings.
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Serialise to a JSON-compatible scalar.
     *
     * @return string The raw identifier string.
     */
    public function jsonSerialize(): string
    {
        return $this->value;
    }

    /**
     * Magic string cast — allows direct use in string contexts and interpolation.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
