<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\ValueObjects;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Immutable value object wrapping a provider-returned transaction identifier.
 *
 * Design decisions:
 * - Transaction IDs are provider-opaque strings — we make no assumptions
 *   about their format, length, or character set beyond "non-empty".
 * - The value is stored as-is; trimming is intentionally NOT applied so that
 *   round-trip consistency is guaranteed: fromString(id)->toString() === id.
 * - Equality is case-sensitive because provider IDs are case-sensitive.
 */
final class TransactionId implements JsonSerializable, Stringable
{
    /**
     * @param string $value Non-empty transaction identifier string.
     *
     * @throws InvalidArgumentException When $value is an empty string.
     */
    public function __construct(public readonly string $value)
    {
        if ($this->value === '') {
            throw new InvalidArgumentException(
                'TransactionId must not be an empty string.',
            );
        }
    }

    /**
     * Named constructor — the preferred way to create a TransactionId.
     *
     * @param string $id Non-empty transaction identifier.
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
     * Check equality with another TransactionId.
     *
     * Comparison is case-sensitive to match provider behaviour.
     *
     * @param self $other The TransactionId to compare against.
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
