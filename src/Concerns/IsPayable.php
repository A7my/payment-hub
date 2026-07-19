<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Concerns;

use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;

/**
 * Convenience implementation of the value-based half of
 * {@see \Mifatoyeh\LaravelPaymentFramework\Contracts\Payable} — reads the
 * amount and currency from two configurable column-name properties, so a
 * model only has to declare which columns hold them:
 *
 * ```php
 * class Order extends Model implements Payable
 * {
 *     use IsPayable;
 *
 *     protected $paymentAmountColumn = 'total_cents';
 *     protected $paymentCurrencyColumn = 'currency_code';
 * }
 * ```
 *
 * Both properties are OPTIONAL to declare (defaulting to `'amount'`/
 * `'currency'` if omitted), and — deliberately — this trait does NOT
 * declare them itself with a default value the way an earlier version did.
 * PHP requires a class's re-declaration of a property a trait already
 * declares to match not just the type but the exact default value too, or
 * it's a fatal "definition differs and is considered incompatible" error —
 * which made every consuming model's override a landmine regardless of
 * whether it added a type hint or not. Reading the properties defensively
 * via `property_exists()` instead means the trait owns nothing to conflict
 * with: a consuming model may declare these typed, untyped, or not at all,
 * with any default value, and it always works.
 *
 * Deliberately does NOT implement `getSupportedPaymentDrivers()` or
 * `authorizePayment()` — both are genuine per-model business logic (which
 * drivers are allowed, who's allowed to pay), not a column-mapping concern,
 * so every `Payable` model must still provide them itself.
 */
trait IsPayable
{
    public function getPaymentAmount(): Money
    {
        return Money::ofMinor((int) $this->{$this->paymentAmountColumn()}, $this->getPaymentCurrency());
    }

    public function getPaymentCurrency(): Currency
    {
        $value = $this->{$this->paymentCurrencyColumn()};

        return $value instanceof Currency ? $value : Currency::from(strtoupper((string) $value));
    }

    /**
     * The column holding the amount, in the smallest currency unit —
     * `$this->paymentAmountColumn` if the model declares it, else `'amount'`.
     */
    private function paymentAmountColumn(): string
    {
        return property_exists($this, 'paymentAmountColumn') ? (string) $this->paymentAmountColumn : 'amount';
    }

    /**
     * The column holding the ISO 4217 currency code —
     * `$this->paymentCurrencyColumn` if the model declares it, else `'currency'`.
     */
    private function paymentCurrencyColumn(): string
    {
        return property_exists($this, 'paymentCurrencyColumn') ? (string) $this->paymentCurrencyColumn : 'currency';
    }
}
