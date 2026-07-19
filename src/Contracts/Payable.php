<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;

/**
 * Implemented by any host-application model that can be paid for through
 * the generic checkout endpoint (see `src/Checkout/CheckoutService.php`).
 *
 * Deliberately value-based, not column-name-based: the interface asks for
 * the actual amount/currency, not "which column holds them" — that keeps
 * the framework's resolver decoupled from Eloquent internals and works
 * equally for a model that computes its payable amount instead of storing
 * it in a single column. For the common "just read a column" case, use
 * {@see \Mifatoyeh\LaravelPaymentFramework\Concerns\IsPayable}, which
 * implements `getPaymentAmount()`/`getPaymentCurrency()` for you from two
 * configurable column-name properties.
 */
interface Payable
{
    /**
     * The amount to charge, in the smallest currency unit.
     */
    public function getPaymentAmount(): Money;

    /**
     * The currency to charge in.
     */
    public function getPaymentCurrency(): Currency;

    /**
     * Which payment driver names (e.g. 'stripe', 'paymob') this specific
     * model may be paid through. The checkout endpoint rejects any request
     * naming a driver not in this list, even if that driver is otherwise
     * configured and available application-wide.
     *
     * @return list<string>
     */
    public function getSupportedPaymentDrivers(): array;

    /**
     * Whether $payer is allowed to pay for this record.
     *
     * Called unconditionally by the checkout endpoint's own controller,
     * regardless of route middleware — the package does not trust host-app
     * middleware configuration alone for an operation that moves money.
     * $payer is null for an unauthenticated request; a model requiring
     * authentication should return false in that case rather than assuming
     * middleware already blocked it.
     */
    public function authorizePayment(?Authenticatable $payer): bool;
}
