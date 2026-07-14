<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Exceptions;

use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;

/**
 * Thrown when an invalid monetary operation is attempted.
 *
 * Covers:
 * - Cross-currency arithmetic (add/subtract between different currencies)
 * - Subtraction that would produce a negative result
 * - Multiplication by a non-positive factor
 * - Construction with a negative amount
 *
 * Extends PaymentException so callers can catch all framework errors
 * at a single granularity if needed.
 */
final class MoneyException extends PaymentException
{
    /**
     * Thrown when arithmetic is attempted between two different currencies.
     *
     * @param Currency $a The currency of the left operand.
     * @param Currency $b The currency of the right operand.
     *
     * @return self
     */
    public static function currencyMismatch(Currency $a, Currency $b): self
    {
        return new self(sprintf(
            'Cannot perform arithmetic between different currencies: [%s] and [%s].',
            $a->value,
            $b->value,
        ));
    }

    /**
     * Thrown when subtraction would produce a negative amount.
     *
     * Money amounts are always non-negative integers; a negative result
     * indicates a logic error in the calling code.
     *
     * @param int $minuend    The amount being subtracted from.
     * @param int $subtrahend The amount being subtracted.
     *
     * @return self
     */
    public static function negativeResult(int $minuend, int $subtrahend): self
    {
        return new self(sprintf(
            'Subtraction would produce a negative amount: %d - %d = %d.',
            $minuend,
            $subtrahend,
            $minuend - $subtrahend,
        ));
    }

    /**
     * Thrown when a Money instance is constructed with a negative amount.
     *
     * @param int $amount The invalid negative amount.
     *
     * @return self
     */
    public static function negativeAmount(int $amount): self
    {
        return new self(sprintf(
            'Money amount must be a non-negative integer; [%d] given.',
            $amount,
        ));
    }

    /**
     * Thrown when multiply() is called with a zero or negative factor.
     *
     * @param int $factor The invalid factor.
     *
     * @return self
     */
    public static function invalidMultiplier(int $factor): self
    {
        return new self(sprintf(
            'Money multiplier must be a positive integer; [%d] given.',
            $factor,
        ));
    }
}
