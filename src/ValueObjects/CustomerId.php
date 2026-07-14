<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\ValueObjects;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Immutable value object wrapping a customer identifier.
 *
 * Represents the host application's customer reference as forwarded to
 * payment providers and stored in transaction records.
 *
 * Design decisions:
 * - The identifier is treated as opaque: the framework makes no assumptions
 *   about format (UUID, integer, email, etc.).
 * - Non-empty is the only invariant enforced at this layer. Stricter format
 *   validation belongs in the application layer, not the framework.
 * - Equality is case-sensitive — the application controls the ID format.
 */
final class CustomerId implements JsonSerializable, Stringable
{
    /**
     * @param string $value Non-empty customer identifier string.
     *
     * @throws InvalidArgumentException When $value is an empty string.
     */
    public function __construct(public readonly string $value)
    {
        if ($this->value === '') {
            throw new InvalidArgumentException(
                'CustomerId must not be an empty string.',
            );
        }
    }

    /**
     * Named constructor — the preferred way to create a CustomerId.
     *
     * @param string $id Non-empty customer identifier.
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
     * Check equality with another CustomerId.
     *
     * @param self $other The CustomerId to compare against.
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
