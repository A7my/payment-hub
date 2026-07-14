<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\ValueObjects;

use InvalidArgumentException;
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\MoneyException;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Money value object.
 *
 * Covers: construction, arithmetic, comparison, state inspection,
 * JSON serialization, string conversion, and error guards.
 */
class MoneyTest extends TestCase
{
    // =========================================================================
    // Construction
    // =========================================================================

    /** @test */
    public function test_of_minor_constructs_correctly(): void
    {
        $money = Money::ofMinor(1000, Currency::USD);

        $this->assertSame(1000, $money->amount);
        $this->assertSame(Currency::USD, $money->currency);
    }

    /** @test */
    public function test_of_is_alias_for_of_minor(): void
    {
        $a = Money::of(500, Currency::EUR);
        $b = Money::ofMinor(500, Currency::EUR);

        $this->assertTrue($a->equals($b));
    }

    /** @test */
    public function test_zero_constructs_zero_amount(): void
    {
        $money = Money::zero(Currency::SAR);

        $this->assertSame(0, $money->amount);
        $this->assertSame(Currency::SAR, $money->currency);
        $this->assertTrue($money->isZero());
    }

    /** @test */
    public function test_of_major_converts_usd_correctly(): void
    {
        $money = Money::ofMajor('10.50', Currency::USD);

        $this->assertSame(1050, $money->amount);
        $this->assertSame(Currency::USD, $money->currency);
    }

    /** @test */
    public function test_of_major_converts_kwd_3_decimal_correctly(): void
    {
        $money = Money::ofMajor('1.500', Currency::KWD);

        $this->assertSame(1500, $money->amount);
    }

    /** @test */
    public function test_of_major_converts_jpy_zero_decimal_correctly(): void
    {
        $money = Money::ofMajor('100', Currency::JPY);

        $this->assertSame(100, $money->amount);
    }

    /** @test */
    public function test_of_major_pads_missing_fractional_digits(): void
    {
        // "10.5" for USD should be treated as "10.50" → 1050 cents
        $money = Money::ofMajor('10.5', Currency::USD);

        $this->assertSame(1050, $money->amount);
    }

    /** @test */
    public function test_negative_amount_throws_money_exception(): void
    {
        $this->expectException(MoneyException::class);

        Money::ofMinor(-1, Currency::USD);
    }

    /** @test */
    public function test_of_major_invalid_string_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::ofMajor('not-a-number', Currency::USD);
    }

    // =========================================================================
    // Arithmetic
    // =========================================================================

    /** @test */
    public function test_add_returns_correct_sum(): void
    {
        $a = Money::ofMinor(500, Currency::USD);
        $b = Money::ofMinor(300, Currency::USD);

        $result = $a->add($b);

        $this->assertSame(800, $result->amount);
        $this->assertSame(Currency::USD, $result->currency);
    }

    /** @test */
    public function test_add_returns_new_instance(): void
    {
        $a      = Money::ofMinor(500, Currency::USD);
        $b      = Money::ofMinor(300, Currency::USD);
        $result = $a->add($b);

        $this->assertNotSame($a, $result);
        $this->assertNotSame($b, $result);
        $this->assertSame(500, $a->amount); // original unchanged
    }

    /** @test */
    public function test_add_cross_currency_throws(): void
    {
        $this->expectException(MoneyException::class);

        Money::ofMinor(100, Currency::USD)->add(Money::ofMinor(100, Currency::EUR));
    }

    /** @test */
    public function test_subtract_returns_correct_difference(): void
    {
        $a = Money::ofMinor(1000, Currency::USD);
        $b = Money::ofMinor(300, Currency::USD);

        $result = $a->subtract($b);

        $this->assertSame(700, $result->amount);
    }

    /** @test */
    public function test_subtract_to_zero_is_valid(): void
    {
        $a = Money::ofMinor(500, Currency::USD);
        $b = Money::ofMinor(500, Currency::USD);

        $this->assertSame(0, $a->subtract($b)->amount);
    }

    /** @test */
    public function test_subtract_cross_currency_throws(): void
    {
        $this->expectException(MoneyException::class);

        Money::ofMinor(100, Currency::USD)->subtract(Money::ofMinor(50, Currency::EUR));
    }

    /** @test */
    public function test_subtract_negative_result_throws(): void
    {
        $this->expectException(MoneyException::class);

        Money::ofMinor(50, Currency::USD)->subtract(Money::ofMinor(100, Currency::USD));
    }

    /** @test */
    public function test_multiply_returns_correct_product(): void
    {
        $money  = Money::ofMinor(500, Currency::USD);
        $result = $money->multiply(3);

        $this->assertSame(1500, $result->amount);
    }

    /** @test */
    public function test_multiply_by_one_returns_equal_instance(): void
    {
        $money  = Money::ofMinor(500, Currency::USD);
        $result = $money->multiply(1);

        $this->assertTrue($money->equals($result));
        $this->assertNotSame($money, $result);
    }

    /** @test */
    public function test_multiply_by_zero_throws(): void
    {
        $this->expectException(MoneyException::class);

        Money::ofMinor(500, Currency::USD)->multiply(0);
    }

    /** @test */
    public function test_multiply_by_negative_throws(): void
    {
        $this->expectException(MoneyException::class);

        Money::ofMinor(500, Currency::USD)->multiply(-1);
    }

    // =========================================================================
    // Comparison
    // =========================================================================

    /** @test */
    public function test_equals_returns_true_for_same_amount_and_currency(): void
    {
        $a = Money::ofMinor(1000, Currency::USD);
        $b = Money::ofMinor(1000, Currency::USD);

        $this->assertTrue($a->equals($b));
    }

    /** @test */
    public function test_equals_returns_false_for_different_amount(): void
    {
        $a = Money::ofMinor(1000, Currency::USD);
        $b = Money::ofMinor(999, Currency::USD);

        $this->assertFalse($a->equals($b));
    }

    /** @test */
    public function test_equals_returns_false_for_different_currency(): void
    {
        $a = Money::ofMinor(1000, Currency::USD);
        $b = Money::ofMinor(1000, Currency::EUR);

        $this->assertFalse($a->equals($b));
    }

    /** @test */
    public function test_compare_to_returns_negative_when_less(): void
    {
        $a = Money::ofMinor(500, Currency::USD);
        $b = Money::ofMinor(1000, Currency::USD);

        $this->assertSame(-1, $a->compareTo($b));
    }

    /** @test */
    public function test_compare_to_returns_zero_when_equal(): void
    {
        $a = Money::ofMinor(1000, Currency::USD);
        $b = Money::ofMinor(1000, Currency::USD);

        $this->assertSame(0, $a->compareTo($b));
    }

    /** @test */
    public function test_compare_to_returns_positive_when_greater(): void
    {
        $a = Money::ofMinor(1000, Currency::USD);
        $b = Money::ofMinor(500, Currency::USD);

        $this->assertSame(1, $a->compareTo($b));
    }

    /** @test */
    public function test_compare_to_cross_currency_throws(): void
    {
        $this->expectException(MoneyException::class);

        Money::ofMinor(100, Currency::USD)->compareTo(Money::ofMinor(100, Currency::EUR));
    }

    /** @test */
    public function test_is_greater_than(): void
    {
        $large = Money::ofMinor(1000, Currency::USD);
        $small = Money::ofMinor(500, Currency::USD);

        $this->assertTrue($large->isGreaterThan($small));
        $this->assertFalse($small->isGreaterThan($large));
    }

    /** @test */
    public function test_is_less_than(): void
    {
        $small = Money::ofMinor(100, Currency::USD);
        $large = Money::ofMinor(500, Currency::USD);

        $this->assertTrue($small->isLessThan($large));
        $this->assertFalse($large->isLessThan($small));
    }

    // =========================================================================
    // State inspection
    // =========================================================================

    /** @test */
    public function test_is_zero_returns_true_for_zero_amount(): void
    {
        $this->assertTrue(Money::zero(Currency::USD)->isZero());
    }

    /** @test */
    public function test_is_zero_returns_false_for_non_zero(): void
    {
        $this->assertFalse(Money::ofMinor(1, Currency::USD)->isZero());
    }

    /** @test */
    public function test_is_positive_returns_true_for_non_zero(): void
    {
        $this->assertTrue(Money::ofMinor(1, Currency::USD)->isPositive());
    }

    /** @test */
    public function test_is_positive_returns_false_for_zero(): void
    {
        $this->assertFalse(Money::zero(Currency::USD)->isPositive());
    }

    /** @test */
    public function test_is_negative_always_returns_false_for_valid_money(): void
    {
        // Money enforces non-negative construction, so isNegative() is always false
        $this->assertFalse(Money::ofMinor(0, Currency::USD)->isNegative());
        $this->assertFalse(Money::ofMinor(1000, Currency::USD)->isNegative());
    }

    // =========================================================================
    // Conversion & serialisation
    // =========================================================================

    /** @test */
    public function test_to_decimal_string_formats_usd_correctly(): void
    {
        $this->assertSame('10.50', Money::ofMinor(1050, Currency::USD)->toDecimalString());
    }

    /** @test */
    public function test_to_decimal_string_formats_kwd_3_decimals(): void
    {
        $this->assertSame('1.050', Money::ofMinor(1050, Currency::KWD)->toDecimalString());
    }

    /** @test */
    public function test_to_decimal_string_formats_jpy_no_decimals(): void
    {
        $this->assertSame('100', Money::ofMinor(100, Currency::JPY)->toDecimalString());
    }

    /** @test */
    public function test_to_string_includes_currency_code(): void
    {
        $money = Money::ofMinor(1050, Currency::USD);

        $this->assertSame('USD 10.50', (string) $money);
    }

    /** @test */
    public function test_json_serialize_returns_correct_structure(): void
    {
        $money  = Money::ofMinor(1050, Currency::USD);
        $json   = json_encode($money);
        $decoded = json_decode($json, true);

        $this->assertSame(1050, $decoded['amount']);
        $this->assertSame('USD', $decoded['currency']);
        $this->assertSame('10.50', $decoded['formatted']);
    }

    // =========================================================================
    // Property 5: Money Constructor Round-Trip
    // Feature: laravel-payment-framework, Property 5: Money constructor round-trip
    // =========================================================================
    /** @test */
    public function test_property_5_money_constructor_round_trip(): void
    {
        // Feature: laravel-payment-framework, Property 5: Money constructor round-trip
        // For any non-negative integer n and any Currency value c,
        // Money::of(n, c)->amount === n and ->currency === c.
        foreach (Currency::cases() as $currency) {
            foreach ([0, 1, 100, 9999, 1_000_000] as $amount) {
                $money = Money::of($amount, $currency);
                $this->assertSame($amount, $money->amount);
                $this->assertSame($currency, $money->currency);
            }
        }
    }

    // =========================================================================
    // Property 6: Money Arithmetic Preserves Non-Negative Invariant
    // Feature: laravel-payment-framework, Property 6: Money arithmetic invariant
    // =========================================================================
    /** @test */
    public function test_property_6_money_arithmetic_invariant(): void
    {
        // Feature: laravel-payment-framework, Property 6: Money arithmetic invariant
        // For any two non-negative integers a, b and same currency:
        //   add(a, b) == a + b
        //   add then subtract round-trip equals original
        $cases = [[0, 0], [100, 50], [500, 300], [1000, 1000]];

        foreach ($cases as [$a, $b]) {
            $moneyA = Money::ofMinor($a, Currency::USD);
            $moneyB = Money::ofMinor($b, Currency::USD);

            // add invariant
            $this->assertSame($a + $b, $moneyA->add($moneyB)->amount);

            // round-trip: add then subtract equals original
            $this->assertTrue($moneyA->add($moneyB)->subtract($moneyB)->equals($moneyA));
        }
    }

    // =========================================================================
    // Property 7: Cross-Currency Arithmetic Throws
    // Feature: laravel-payment-framework, Property 7: Cross-currency arithmetic throws
    // =========================================================================
    /** @test */
    public function test_property_7_cross_currency_arithmetic_throws(): void
    {
        // Feature: laravel-payment-framework, Property 7: Cross-currency arithmetic throws
        $pairs = [
            [Currency::USD, Currency::EUR],
            [Currency::SAR, Currency::KWD],
            [Currency::GBP, Currency::JPY],
        ];

        foreach ($pairs as [$c1, $c2]) {
            try {
                Money::ofMinor(100, $c1)->add(Money::ofMinor(100, $c2));
                $this->fail("Expected MoneyException for {$c1->value} + {$c2->value}");
            } catch (MoneyException) {
                $this->addToAssertionCount(1);
            }

            try {
                Money::ofMinor(100, $c1)->subtract(Money::ofMinor(50, $c2));
                $this->fail("Expected MoneyException for {$c1->value} - {$c2->value}");
            } catch (MoneyException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
