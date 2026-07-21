<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Contracts;

/**
 * Optional companion to {@see Payable} — implement this to snapshot
 * whatever SERVER-RESOLVED data your model needs later, at the one moment
 * a real request/session actually exists: `checkout()` itself.
 *
 * Same reasoning as `CheckoutContext::$payerId`
 * (see {@see \Mifatoyeh\LaravelPaymentFramework\Checkout\CheckoutContext}'s
 * own docblock): `onPaymentCompleted()` can fire from a webhook or a
 * background job hours later, with no request context at all. Anything
 * reachable via a relation on the model itself (`$this->someRelation`)
 * needs no help — that works normally once the model is re-fetched at
 * confirmation time, same as anywhere else in your app. This interface is
 * for the other case: something that only exists, or only has this exact
 * value, during the ORIGINAL checkout() call — a locked-in price, a
 * discount/referral code, a related record picked at initiation that might
 * not be findable the same way later.
 *
 * Deliberately NOT automatic — this package does not (and will not) capture
 * arbitrary database activity into a JSON column on your behalf. You decide
 * exactly what's relevant and safe to snapshot by returning it here; nothing
 * is captured unless a model explicitly implements this and returns it.
 *
 * Called ONCE, by `CheckoutService::checkout()`, right after the model and
 * payer are resolved — the return value is persisted into the pending
 * {@see \Mifatoyeh\LaravelPaymentFramework\Checkout\CheckoutTransaction}
 * row's `metadata.custom` and read back out via
 * {@see \Mifatoyeh\LaravelPaymentFramework\Checkout\CheckoutContext::$custom}
 * in `onPaymentCompleted()`.
 */
interface CapturesCheckoutContext
{
    /**
     * Return arbitrary data to snapshot at checkout() time.
     *
     * Must be JSON-serialisable (this is stored in a JSON column) — pass
     * scalars/arrays, not Eloquent model instances or other objects. If you
     * need a related model's data later, pull out the specific columns you
     * need here rather than the model itself.
     *
     * @return array<string, mixed>
     */
    public function captureCheckoutContext(): array;
}
