<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\ValueObjects;

use JsonSerializable;
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\MoneyException;
use Stringable;

/**
 * Immutable value object representing a monetary amount in a specific currency.
 *
 * Design decisions:
 * - Amounts are stored EXCLUSIVELY as non-negative integers in the smallest
 *   currency unit (subunit) to eliminate floating-point precision errors.
 *   e.g. USD 10.00 → 1000 cents, KWD 1.000 → 1000 fils, JPY 100 → 100 yen.
 * - The Currency enum's subunitExponent() is the single source of truth for
 *   how many decimal places each currency uses.
 * - All arithmetic returns NEW instances — this class is completely immutable.
 * - Cross-currency arithmetic is explicitly forbidden and throws MoneyException.
 * - The class is framework-independent: no Laravel dependencies.
 *
 * Usage:
 *   $price    = Money::ofMinor(1000, Currency::USD);   // $10.00
 *   $tax      = Money::ofMinor(90, Currency::USD);     // $0.90
 *   $total    = $price->add($tax);                     // $10.90
 *   $refund   = $total->subtract($tax);                // $10.00
 *   $doubled  = $price->multiply(2);                   // $20.00
 *   $major    = Money::ofMajor('10.50', Currency::USD); // $10.50 → 1050 minor
 */
final class Money implements JsonSerializable, Stringable
{
    /**
     * @param int      $amount   Non-negative amount in the smallest currency unit.
     * @param Currency $currency The ISO 4217 currency.
     *
     * @throws MoneyException When $amount is negative.
     */
    public function __construct(
        public readonly int $amount,
        public readonly Currency $currency,
    ) {
        if ($this->amount < 0) {
            throw MoneyException::negativeAmount($this->amount);
        }
    }

    // =========================================================================
    // Named constructors
    // =========================================================================

    /**
     * Create a Money instance from a minor unit (subunit) integer.
     *
     * This is the primary constructor. All internal storage is in minor units.
     *
     * @param int      $minorAmount Non-negative amount in the smallest currency unit.
     * @param Currency $currency    The ISO 4217 currency.
     *
     * @throws MoneyException When $minorAmount is negative.
     *
     * @return self
     */
    public static function ofMinor(int $minorAmount, Currency $currency): self
    {
        return new self($minorAmount, $currency);
    }

    /**
     * Alias for ofMinor() — matches the design document API.
     *
     * @param int      $amount   Non-negative amount in the smallest currency unit.
     * @param Currency $currency The ISO 4217 currency.
     *
     * @throws MoneyException When $amount is negative.
     *
     * @return self
     */
    public static function of(int $amount, Currency $currency): self
    {
        return new self($amount, $currency);
    }

    /**
     * Create a Money instance from a major unit decimal string.
     *
     * Converts the human-readable decimal representation to the integer subunit
     * storage format using the currency's subunit exponent.
     *
     * Examples:
     *   Money::ofMajor('10.50', Currency::USD)  → 1050 cents
     *   Money::ofMajor('1.000', Currency::KWD)  → 1000 fils
     *   Money::ofMajor('100',   Currency::JPY)  → 100  yen
     *
     * @param string|int|float $major    Major-unit amount (e.g., "10.50", 10, 10.50).
     *                                   Floats are accepted but converted via string
     *                                   to avoid IEEE 754 rounding surprises.
     * @param Currency         $currency The ISO 4217 currency.
     *
     * @throws MoneyException           When the resulting minor amount is negative.
     * @throws \InvalidArgumentException When $major cannot be parsed as a decimal number.
     *
     * @return self
     */
    public static function ofMajor(string|int|float $major, Currency $currency): self
    {
        // Convert to string first to avoid float precision issues
        $str = is_float($major) ? number_format($major, $currency->subunitExponent(), '.', '') : (string) $major;

        if (! preg_match('/^\d+(\.\d+)?$/', $str)) {
            throw new \InvalidArgumentException(
                "Cannot parse [{$str}] as a valid monetary amount.",
            );
        }

        $exponent  = $currency->subunitExponent();
        $subunits  = $currency->subunitsPerUnit();

        if ($exponent === 0) {
            return new self((int) $str, $currency);
        }

        // Split into integer and fractional parts
        $parts   = explode('.', $str, 2);
        $integer = (int) $parts[0];
        $fracStr = isset($parts[1]) ? str_pad($parts[1], $exponent, '0') : str_repeat('0', $exponent);

        // Truncate to the currency's precision (ignore extra decimal places)
        $fracStr = substr($fracStr, 0, $exponent);
        $frac    = (int) $fracStr;

        $minorAmount = ($integer * $subunits) + $frac;

        return new self($minorAmount, $currency);
    }

    /**
     * Create a zero-amount Money instance for the given currency.
     *
     * Useful as a neutral element for fold/reduce operations.
     *
     * @param Currency $currency The ISO 4217 currency.
     *
     * @return self
     */
    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    // =========================================================================
    // Arithmetic operations (all return new instances)
    // =========================================================================

    /**
     * Add another Money value and return a new immutable instance.
     *
     * @param self $other The amount to add.
     *
     * @throws MoneyException When the currencies differ.
     *
     * @return self New Money instance with the summed amount.
     */
    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    /**
     * Subtract another Money value and return a new immutable instance.
     *
     * @param self $other The amount to subtract.
     *
     * @throws MoneyException When the currencies differ.
     * @throws MoneyException When the result would be negative.
     *
     * @return self New Money instance with the difference.
     */
    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        if ($other->amount > $this->amount) {
            throw MoneyException::negativeResult($this->amount, $other->amount);
        }

        return new self($this->amount - $other->amount, $this->currency);
    }

    /**
     * Multiply the amount by a positive integer factor.
     *
     * Only integer multipliers are supported to maintain exact arithmetic.
     * For percentage-based calculations, perform integer arithmetic externally
     * and construct a new Money instance with the result.
     *
     * @param int $factor A positive integer multiplier (>= 1).
     *
     * @throws MoneyException When $factor is zero or negative.
     *
     * @return self New Money instance with the multiplied amount.
     */
    public function multiply(int $factor): self
    {
        if ($factor < 1) {
            throw MoneyException::invalidMultiplier($factor);
        }

        return new self($this->amount * $factor, $this->currency);
    }

    // =========================================================================
    // Comparison
    // =========================================================================

    /**
     * Check structural equality with another Money instance.
     *
     * Two Money objects are equal when BOTH amount AND currency are identical.
     *
     * @param self $other The other Money to compare.
     *
     * @return bool True when amount and currency are equal.
     */
    public function equals(self $other): bool
    {
        return $this->amount === $other->amount
            && $this->currency === $other->currency;
    }

    /**
     * Compare this Money to another of the same currency.
     *
     * Returns -1, 0, or 1 following the spaceship operator convention:
     *   -1 → this < other
     *    0 → this == other
     *    1 → this > other
     *
     * @param self $other The Money to compare against.
     *
     * @throws MoneyException When the currencies differ.
     *
     * @return int<-1, 1>
     */
    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->amount <=> $other->amount;
    }

    /**
     * Whether this amount is greater than another.
     *
     * @param self $other
     *
     * @throws MoneyException When the currencies differ.
     *
     * @return bool
     */
    public function isGreaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    /**
     * Whether this amount is less than another.
     *
     * @param self $other
     *
     * @throws MoneyException When the currencies differ.
     *
     * @return bool
     */
    public function isLessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    /**
     * Whether this amount is greater than or equal to another.
     *
     * @param self $other
     *
     * @throws MoneyException When the currencies differ.
     *
     * @return bool
     */
    public function isGreaterThanOrEqual(self $other): bool
    {
        return $this->compareTo($other) >= 0;
    }

    /**
     * Whether this amount is less than or equal to another.
     *
     * @param self $other
     *
     * @throws MoneyException When the currencies differ.
     *
     * @return bool
     */
    public function isLessThanOrEqual(self $other): bool
    {
        return $this->compareTo($other) <= 0;
    }

    // =========================================================================
    // State inspection
    // =========================================================================

    /**
     * Whether the amount is exactly zero.
     *
     * @return bool
     */
    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    /**
     * Whether the amount is greater than zero.
     *
     * @return bool
     */
    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    /**
     * Whether the amount is negative.
     *
     * Because Money enforces non-negative construction, this always returns
     * false for valid instances. It exists for symmetry and guard-clause use.
     *
     * @return bool
     */
    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    // =========================================================================
    // Conversion
    // =========================================================================

    /**
     * Return the amount as a formatted decimal string using the currency's precision.
     *
     * Delegates to Currency::format() which is the single source of truth
     * for decimal formatting rules.
     *
     * Examples:
     *   Money::ofMinor(1050, Currency::USD)->toDecimalString() === '10.50'
     *   Money::ofMinor(1050, Currency::KWD)->toDecimalString() === '1.050'
     *   Money::ofMinor(100,  Currency::JPY)->toDecimalString() === '100'
     *
     * @return string
     */
    public function toDecimalString(): string
    {
        return $this->currency->format($this->amount);
    }

    /**
     * Return a human-readable string in the format "CURRENCY AMOUNT".
     *
     * Examples:
     *   (string) Money::ofMinor(1050, Currency::USD) === 'USD 10.50'
     *   (string) Money::ofMinor(1050, Currency::KWD) === 'KWD 1.050'
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->currency->value . ' ' . $this->toDecimalString();
    }

    /**
     * Serialise to a JSON-compatible array.
     *
     * Structure:
     * {
     *   "amount":   1050,         // integer minor units
     *   "currency": "USD",        // ISO 4217 code
     *   "formatted": "10.50"      // decimal string
     * }
     *
     * @return array{amount: int, currency: string, formatted: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'amount'    => $this->amount,
            'currency'  => $this->currency->value,
            'formatted' => $this->toDecimalString(),
        ];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Assert that two Money instances share the same currency.
     *
     * @param self $other
     *
     * @throws MoneyException When currencies differ.
     */
    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw MoneyException::currencyMismatch($this->currency, $other->currency);
        }
    }
}
