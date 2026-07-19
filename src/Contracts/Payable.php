<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\Responses\StatusResponse;
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
 * configurable column-name properties — and gives {@see self::onPaymentCompleted()}
 * below a default no-op, so most models don't need to write it at all.
 *
 * `onPaymentCompleted()` used to live on a separate `HasPaymentCallback`
 * interface, opt-in per model. It was merged directly into `Payable` (one
 * interface, one thing to implement, instead of remembering a second
 * optional one) — the only consumers this could break are `Payable` models
 * that do NOT use the `IsPayable` trait and implement every method by hand;
 * those need to add `onPaymentCompleted()` themselves (a one-line no-op body
 * is enough if you only want {@see \Mifatoyeh\LaravelPaymentFramework\Events\CheckoutPaymentConfirmed}'s
 * app-wide event and don't need per-model reaction logic).
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

    /**
     * Called by `CheckoutService::confirm()`/`confirmFromWebhook()` after
     * authoritatively verifying the payment status directly with the
     * provider (via `lookup()` — never from a client-supplied claim). Only
     * called once per confirmation request; a webview/SDK flow that
     * confirms multiple times (a user double-submitting, a provider webhook
     * arriving after a client already confirmed) will call this multiple
     * times too — implementations must be idempotent (e.g. check the model
     * isn't already marked paid before applying side effects again).
     *
     * {@see \Mifatoyeh\LaravelPaymentFramework\Concerns\IsPayable} gives
     * this a default no-op body, so models using that trait only need to
     * override it if they actually have per-model logic to run. For
     * application-wide reactions that don't belong to any one model
     * (analytics, notifications, logging), listen for
     * {@see \Mifatoyeh\LaravelPaymentFramework\Events\CheckoutPaymentConfirmed}
     * instead — both fire on every confirmation; use whichever fits, or both.
     *
     * @param StatusResponse $status The authoritative status, straight from the provider.
     */
    public function onPaymentCompleted(StatusResponse $status): void;
}
