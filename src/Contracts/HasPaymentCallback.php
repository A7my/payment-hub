<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Contracts;

use Mifatoyeh\LaravelPaymentFramework\Responses\StatusResponse;

/**
 * Optional companion to {@see Payable} — implement this on a Payable model
 * to run model-specific logic when its payment is confirmed, e.g. marking
 * an order paid, crediting a wallet, activating a subscription.
 *
 * Deliberately a SEPARATE interface from `Payable`, not an extra required
 * method on it — every `Payable` model written before this existed
 * (this package has none built in; host applications write their own) must
 * keep working unchanged. `CheckoutService::confirm()` checks
 * `instanceof HasPaymentCallback` before calling it, so implementing this
 * is entirely opt-in per model.
 *
 * Each model gets its OWN callback — this is intentionally per-model
 * (`Order` and `Wallet` react completely differently to a confirmed
 * payment), not a single global hook. For application-wide reactions that
 * don't belong to any one model (analytics, notifications, logging),
 * listen for {@see \Mifatoyeh\LaravelPaymentFramework\Events\CheckoutPaymentConfirmed}
 * instead — both fire on every confirmation, use whichever fits.
 */
interface HasPaymentCallback
{
    /**
     * Called after `CheckoutService::confirm()` has authoritatively verified
     * the payment status directly with the provider (via `lookup()` — never
     * from a client-supplied claim). Only called once per confirmation
     * request; a webview/SDK flow that confirms multiple times (e.g. a user
     * double-submitting) will call this multiple times too — implementations
     * should be idempotent (e.g. check the model isn't already marked paid
     * before applying side effects again).
     *
     * @param StatusResponse $status The authoritative status, straight from the provider.
     */
    public function onPaymentCompleted(StatusResponse $status): void;
}
