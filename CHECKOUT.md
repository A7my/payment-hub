# SDK checkout, confirmation, callbacks & transaction storage

This document covers everything added on top of the base [generic checkout
endpoint](README.md#generic-checkout-endpoint) described in the README:
`driver_type: sdk`, the required confirmation step, per-model payment
callbacks, and automatic transaction persistence. Read the README's
"Generic checkout endpoint" section first if you haven't implemented
`Payable` on a model yet — everything here builds on it.

## Why a confirmation step exists at all

Neither `driver_type: webview` nor `driver_type: sdk` ever charges anything
by itself:

- **`webview`** (`createPaymentLink()`) hands you a URL to a hosted page.
  The actual charge happens later, on that page, outside this package
  entirely.
- **`sdk`** (`createSdkIntent()`) hands you a client-confirmable reference
  (a Stripe `client_secret`, a Paymob `payment_key`/Intention `client_secret`)
  for a native/client-side SDK to confirm the charge itself, without raw
  card data ever reaching this package's server.

Either way, this package's server has no idea whether the payment actually
succeeded until it asks the provider directly. **Never trust a client
redirect or a client-side SDK callback claiming success** — a `return_url`
hit, or a JS SDK's "succeeded" event, is something a user's browser can
reach in ways that don't mean the money actually moved. That's what
`POST {route}/confirm` is for: it calls the driver's `lookup()` — the same
already-tested, provider-authoritative status check used everywhere else in
this package — and only reacts based on what the provider itself reports.

## driver_type: sdk

Request shape is identical to `webview` mode, just with `driver_type: "sdk"`:

```json
POST /payment/checkout
{
  "model_type": "order",
  "model_id": "123",
  "driver": "stripe",
  "driver_type": "sdk"
}
```

Response (HTTP 200):

```json
{
  "status": "success",
  "driver_type": "sdk",
  "transaction_reference": "pi_3Nxxx...",
  "client_secret": "pi_3Nxxx..._secret_...",
  "publishable_key": "pk_test_...",
  "message": "SDK checkout intent created."
}
```

- **`transaction_reference`** — pass this to `POST {route}/confirm` later
  (see below). For Stripe this is the PaymentIntent id; for Paymob it's the
  order id (Egypt/Accept mode) or the intention order id (KSA mode).
- **`client_secret`** — hand this straight to the provider's client-side SDK.
  For Stripe, that's `stripe.confirmPayment({ clientSecret, ... })` (Stripe.js).
  For Paymob, it's the `payment_key`/`client_secret` the hosted iframe or
  unified-checkout SDK expects.
- **`publishable_key`** — the provider's public key, if the client SDK needs
  one to initialise (Stripe always does; Paymob only in KSA mode — `null`
  otherwise).

If the driver you named doesn't implement SDK checkout yet, you get a `422`
with a message naming the driver, not a fatal error — check the
[Status table](README.md#status) for current per-driver support.

Nothing is charged by this call. The client SDK does the actual charge
client-side using `client_secret`; you find out whether it worked by calling
`confirm()` next.

## Confirming the outcome

```json
POST /payment/checkout/confirm
{
  "model_type": "order",
  "model_id": "123",
  "driver": "stripe",
  "transaction_reference": "pi_3Nxxx...",
  "driver_type": "sdk"
}
```

- **`transaction_reference`** — whatever you got back from `driver_type: sdk`
  above, or whatever reference you can observe from a `webview` flow's
  return payload/redirect (e.g. Stripe Checkout's `session.payment_intent`,
  or Paymob's transaction id echoed on the return URL).
- **`driver_type`** — optional, purely informational (stored on the
  persisted transaction row if you enable that — see below). Confirmation
  itself only needs `driver` + `transaction_reference`; it doesn't care how
  the intent was created.

Response (HTTP 200 — note a *failed* payment is still a 200, since the
confirmation check itself succeeded; only a rejectable *request* returns a
4xx/5xx):

```json
{
  "status": "success",
  "payment_status": "captured",
  "transaction_id": "pi_3Nxxx...",
  "message": "Payment succeeded."
}
```

`status` is `"fail"` when the provider reports anything other than a
successful terminal state — check `payment_status` for the exact
`PaymentStatus` value (`captured`, `pending`, `failed`, etc.).

The same `authorizePayment()` check from the initial checkout call runs
again here, independent of route middleware, for the same reason described
in the README's checkout section — confirming someone else's payment is
still someone else's payment.

`confirm()` is safe to call more than once for the same transaction (a user
double-tapping "I've paid", a client retry after a dropped response) — it
always re-verifies with the provider and re-applies the callback/event/
persistence steps below; it does not error on a repeat call.

## Reacting to a confirmed payment

Two independent mechanisms fire on every `confirm()` call — use whichever
fits, or both:

### Per-model: `HasPaymentCallback`

Implement this on a `Payable` model to run model-specific logic — mark an
order paid, credit a wallet, activate a subscription:

```php
use Mifatoyeh\LaravelPaymentFramework\Contracts\HasPaymentCallback;
use Mifatoyeh\LaravelPaymentFramework\Responses\StatusResponse;

class Order extends Model implements Payable, HasPaymentCallback
{
    use IsPayable;

    // ...getSupportedPaymentDrivers()/authorizePayment() from the README...

    public function onPaymentCompleted(StatusResponse $status): void
    {
        if (! $status->isSuccessful() || $status->getStatus() !== PaymentStatus::Captured) {
            return;
        }

        if ($this->paid_at !== null) {
            return; // already applied — confirm() can be called more than once
        }

        $this->update(['paid_at' => now()]);
    }
}
```

This is entirely opt-in — a `Payable` model that doesn't implement
`HasPaymentCallback` keeps working exactly as before; nothing about the
base `Payable` interface changed. Each model gets its **own** callback —
`Order` and `Wallet` react completely differently to a confirmed payment,
so this is deliberately per-model, not one global hook.

Because `confirm()` can genuinely be called more than once for the same
transaction (see above), **`onPaymentCompleted()` must be idempotent** —
check whether the side effect already happened before applying it again, as
in the `paid_at` example.

### App-wide: `CheckoutPaymentConfirmed`

For reactions that don't belong to any one model — analytics, notifications,
audit logging — listen for the event instead:

```php
use Mifatoyeh\LaravelPaymentFramework\Events\CheckoutPaymentConfirmed;

Event::listen(function (CheckoutPaymentConfirmed $event) {
    Log::info('Checkout confirmed', [
        'model_type' => $event->modelType,
        'model_id'   => $event->payable->getKey(),
        'status'     => $event->status->getStatus()->value,
    ]);
});
```

This fires **unconditionally** on every `confirm()` call, regardless of
`$status->isSuccessful()` — check the status yourself inside the listener if
you only care about successes. It fires alongside, not instead of, the
per-model callback above; they're not mutually exclusive.

## Transaction persistence

`confirm()` persists a `checkout_transactions` row for every confirmation,
gated by `payment.checkout.persist_transactions` (default `true`):

```php
// config/payment.php
'checkout' => [
    // ...
    'persist_transactions' => env('PAYMENT_CHECKOUT_PERSIST_TRANSACTIONS', true),
],
```

Publish and run the migration before relying on this:

```bash
php artisan vendor:publish --tag=payment-migrations
php artisan migrate
```

If you haven't run it yet, set `persist_transactions` to `false` — the
callback and event above still fire either way; only the database write is
gated.

Each row (`Mifatoyeh\LaravelPaymentFramework\Checkout\CheckoutTransaction`):

| Column                  | Meaning                                                                 |
| ------------------------ | ------------------------------------------------------------------------ |
| `model_type`             | The `payment.payables` key (e.g. `"order"`), not a class name.          |
| `model_id`                | The record's primary key.                                               |
| `driver`                  | Which driver processed it (`"stripe"`, `"paymob"`, ...).                |
| `driver_type`             | `"sdk"` / `"webview"` / `null` — whatever the confirm request sent.     |
| `transaction_reference`   | The provider-assigned reference, as returned by `lookup()`.             |
| `status`                  | The `PaymentStatus` enum value at last confirmation.                    |
| `successful`              | Boolean — whether that status was a successful terminal state.          |
| `amount` / `currency`     | Read from the `Payable` model at confirmation time.                     |
| `message`                 | The provider's own human-readable message, if any.                      |
| `raw_response`            | The full raw provider payload (JSON) — for debugging/audit.             |

A `(driver, transaction_reference)` unique constraint makes repeat
`confirm()` calls for the same transaction **update** the existing row
rather than accumulate duplicates — matching `onPaymentCompleted()`'s own
idempotency requirement above.

This is a separate table from the older, generic `payment_transactions`
table (used by the still-unimplemented `PaymentTransactionRepositoryContract`
infrastructure for `charge()`/`authorize()`-style flows). That table is
shaped around a bare order/customer id pair; this one is shaped around a
`Payable` model reference, matching how the checkout endpoint itself
resolves models. If you need to query a model's checkout history:

```php
use Mifatoyeh\LaravelPaymentFramework\Checkout\CheckoutTransaction;

CheckoutTransaction::forPayable('order', $order->id)->latest()->first();
```

## Routes

Both endpoints are registered by `routes/checkout.php`, under the same
`payment.checkout.route`/`payment.checkout.middleware` config as the base
checkout endpoint described in the README:

| Method | Path                    | Route name                |
| ------ | ------------------------ | -------------------------- |
| POST   | `{route}`                 | `payment.checkout`         |
| POST   | `{route}/confirm`         | `payment.checkout.confirm` |

Where `{route}` defaults to `payment/checkout` (`payment.checkout.route`).
