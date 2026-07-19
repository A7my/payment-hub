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
 *     protected string $paymentAmountColumn = 'total_cents';
 *     protected string $paymentCurrencyColumn = 'currency_code';
 * }
 * ```
 *
 * Deliberately does NOT implement `getSupportedPaymentDrivers()` or
 * `authorizePayment()` — both are genuine per-model business logic (which
 * drivers are allowed, who's allowed to pay), not a column-mapping concern,
 * so every `Payable` model must still provide them itself.
 */
trait IsPayable
{
    /**
     * The column holding the amount, in the smallest currency unit.
     */
    protected string $paymentAmountColumn = 'amount';

    /**
     * The column holding the ISO 4217 currency code.
     */
    protected string $paymentCurrencyColumn = 'currency';

    public function getPaymentAmount(): Money
    {
        return Money::ofMinor((int) $this->{$this->paymentAmountColumn}, $this->getPaymentCurrency());
    }

    public function getPaymentCurrency(): Currency
    {
        $value = $this->{$this->paymentCurrencyColumn};

        return $value instanceof Currency ? $value : Currency::from(strtoupper((string) $value));
    }
}
