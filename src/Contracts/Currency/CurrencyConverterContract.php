<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Contracts\Currency;

use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;

/**
 * Contract for currency conversion between Money instances.
 *
 * Implementations must return a new Money instance in the target currency
 * using the supplied exchange rate. The framework does not bundle a default
 * implementation — applications must bind their own rate provider.
 *
 * Amounts remain integers in the smallest currency unit throughout.
 */
interface CurrencyConverterContract
{
    /**
     * Convert a Money amount from one currency to another using the given rate.
     *
     * @param Money    $from The source monetary amount.
     * @param Currency $to   The target currency.
     * @param float    $rate The exchange rate (1 unit of $from->currency = $rate units of $to).
     *
     * @return Money A new Money instance in the target currency.
     */
    public function convert(Money $from, Currency $to, float $rate): Money;
}
