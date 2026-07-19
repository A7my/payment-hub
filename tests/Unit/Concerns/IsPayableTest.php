<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Concerns;

use Mifatoyeh\LaravelPaymentFramework\Concerns\IsPayable;
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for IsPayable.
 *
 * The three "override" test cases below exist specifically because an
 * earlier version of this trait declared `$paymentAmountColumn`/
 * `$paymentCurrencyColumn` as typed properties with default values —
 * which fatals for ANY consuming class that redeclares them with a
 * different default value, typed or not:
 *   "Fatal error: X and IsPayable define the same property ($y) ...
 *    the definition differs and is considered incompatible."
 * This is a real bug a user hit in production. These tests exist to keep
 * it fixed, not just document it in a docblock.
 */
final class IsPayableTest extends TestCase
{
    /** @test */
    public function test_untyped_property_override_with_a_different_default_value_works(): void
    {
        // The exact shape that used to fatal: `protected $paymentAmountColumn = '...'`
        // with no type hint, overriding a different default.
        $model = new class {
            use IsPayable;

            protected $paymentAmountColumn = 'balance';
            protected $paymentCurrencyColumn = 'currency';

            public $balance = 5000;
            public $currency = 'USD';
        };

        $this->assertSame(5000, $model->getPaymentAmount()->amount);
        $this->assertSame(Currency::USD, $model->getPaymentCurrency());
    }

    /** @test */
    public function test_typed_property_override_with_a_different_default_value_works(): void
    {
        $model = new class {
            use IsPayable;

            protected string $paymentAmountColumn = 'total_cents';
            protected string $paymentCurrencyColumn = 'currency_code';

            public $total_cents = 7500;
            public $currency_code = 'EGP';
        };

        $this->assertSame(7500, $model->getPaymentAmount()->amount);
        $this->assertSame(Currency::EGP, $model->getPaymentCurrency());
    }

    /** @test */
    public function test_omitting_the_properties_entirely_falls_back_to_amount_and_currency_columns(): void
    {
        $model = new class {
            use IsPayable;

            public $amount = 999;
            public $currency = 'SAR';
        };

        $this->assertSame(999, $model->getPaymentAmount()->amount);
        $this->assertSame(Currency::SAR, $model->getPaymentCurrency());
    }

    /** @test */
    public function test_currency_column_holding_a_currency_enum_instance_directly_is_accepted(): void
    {
        $model = new class {
            use IsPayable;

            public $amount = 100;
            public $currency = Currency::AED;
        };

        $this->assertSame(Currency::AED, $model->getPaymentCurrency());
    }
}
