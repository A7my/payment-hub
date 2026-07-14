<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\ValueObjects;

use JsonSerializable;
use Stringable;

/**
 * Immutable value object wrapping a raw webhook signature header value.
 *
 * Design decisions:
 * - An empty signature is allowed at construction time. Some providers omit
 *   the signature header entirely (e.g., in sandbox mode). The downstream
 *   WebhookVerifier / driver is responsible for deciding whether an absent
 *   signature constitutes a verification failure.
 * - equals() uses hash_equals() for constant-time comparison to prevent
 *   timing-oracle attacks. This is critical because webhook signature
 *   verification is a security boundary.
 * - truncated() provides a safe string for error logs (max 32 chars) that
 *   avoids leaking the full signature while still providing debugging value.
 *   The design document mandates this 32-character truncation.
 * - The value is never validated beyond storage — format is provider-defined.
 */
final class WebhookSignature implements JsonSerializable, Stringable
{
    /**
     * Maximum number of characters to include in the truncated representation.
     * Matches the design document requirement for log safety.
     */
    private const TRUNCATE_LENGTH = 32;

    /**
     * @param string $value Raw signature header value (may be an empty string).
     */
    public function __construct(public readonly string $value)
    {
        // Empty signatures are accepted — verification is the driver's responsibility.
    }

    /**
     * Named constructor — the preferred way to create a WebhookSignature.
     *
     * @param string $signature Raw signature string from the provider's HTTP header.
     *
     * @return self
     */
    public static function fromString(string $signature): self
    {
        return new self($signature);
    }

    /**
     * Return the raw signature string.
     *
     * @return string The full, unmodified signature value.
     */
    public function toString(): string
    {
        return $this->value;
    }

    /**
     * Whether the signature is present (non-empty).
     *
     * @return bool
     */
    public function isPresent(): bool
    {
        return $this->value !== '';
    }

    /**
     * Whether the signature is absent (empty string).
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->value === '';
    }

    /**
     * Return a truncated representation safe for error logs and exception messages.
     *
     * Truncates to {@see TRUNCATE_LENGTH} characters per the design document's
     * security requirement: "log the raw signature header value truncated to
     * 32 characters for security."
     *
     * @return string Up to 32 characters of the signature, suitable for logging.
     */
    public function truncated(): string
    {
        return mb_substr($this->value, 0, self::TRUNCATE_LENGTH);
    }

    /**
     * Check equality with another WebhookSignature using constant-time comparison.
     *
     * SECURITY: Uses hash_equals() to prevent timing-oracle attacks. Regular
     * string comparison (===) leaks information about where strings differ
     * through measurable timing differences. For webhook verification — a
     * security boundary — constant-time comparison is mandatory.
     *
     * @param self $other The signature to compare against.
     *
     * @return bool True when both signatures are identical strings.
     */
    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }

    /**
     * Perform a secure constant-time comparison against a raw string.
     *
     * Convenience method for drivers that need to compare against a computed
     * HMAC without wrapping it in a WebhookSignature first.
     *
     * SECURITY: Uses hash_equals() — see equals() for rationale.
     *
     * @param string $expected The expected/computed signature to compare against.
     *
     * @return bool True when the signature matches the expected value.
     */
    public function secureEquals(string $expected): bool
    {
        return hash_equals($expected, $this->value);
    }

    /**
     * Serialise to a JSON-compatible scalar.
     *
     * Returns the TRUNCATED value (not the full signature) to prevent
     * accidental exposure of the full signature in JSON responses or logs.
     *
     * @return string Up to 32 characters of the signature.
     */
    public function jsonSerialize(): string
    {
        return $this->truncated();
    }

    /**
     * Magic string cast — returns the truncated representation.
     *
     * Intentionally returns the truncated form (not the full value) so that
     * accidental string interpolation in log messages is safe by default.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->truncated();
    }
}
