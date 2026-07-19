# SDK checkout, confirmation, callbacks & transaction storage

This document covers everything added on top of the base [generic checkout
endpoint](README.md#generic-checkout-endpoint) described in the README:
`driver_type: sdk`, the required confirmation step (either client-driven or
fully automatic via a provider webhook), per-model payment callbacks, and
automatic transaction persistence. Read the README's "Generic checkout
endpoint" section first if you haven't implemented `Payable` on a model yet
— everything here builds on it.

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

## Automatic confirmation via Paymob's webhook (no frontend call needed)

Calling `POST {route}/confirm` from your frontend is one way to trigger
confirmation. For Paymob specifically, there's a second, fully automatic
path: Paymob's own "Transaction Processed Callback" — a request Paymob's
servers send directly to yours after a payment completes — is now wired
straight into the same confirmation logic. **If you use this, you don't
need a frontend confirm call, a custom route, or a return-URL landing page
at all** — the package confirms the payment itself as soon as Paymob tells
it the outcome.

### Setup

1. In the Paymob dashboard (Developers → Payment Integrations → your
   integration → HMAC / webhook settings), set the callback URL to:
   ```
   https://your-app.test/payment/webhook/paymob
   ```
   (or wherever `payment.webhook.prefix` resolves to — default
   `payment/webhook`). Do **not** point it at a route in your own app —
   this package owns and registers that route itself, the same way it owns
   `payment/checkout` (see the README's checkout section).
2. Set `PAYMOB_HMAC_SECRET` in your `.env` to the HMAC secret shown on that
   same dashboard page (`payment.drivers.paymob.hmac_secret` in config).
   Without it, every webhook is rejected with HTTP 400 — this package never
   accepts an unsigned/unverifiable webhook as authentic.
3. That's it. No route, no controller, no frontend code to write.

### How it works

Paymob's classic callback is a **GET** request with every field — including
the HMAC signature — flattened into the query string (not a JSON POST body
the way most modern webhooks work). The webhook route accepts both GET and
POST for exactly this reason.

1. When you call `checkout()`, it generates an idempotency key and forwards
   it to Paymob as the order's own merchant reference. The moment Paymob
   confirms the order/intent was created, this package records a **pending**
   `checkout_transactions` row keyed by that reference (`merchant_order_id`)
   — this is what makes step 3 possible at all.
2. Paymob later sends its callback to `/payment/webhook/paymob`. The
   package verifies its HMAC-SHA512 signature against `hmac_secret` — this
   is the trust boundary for the whole mechanism; an unsigned or tampered
   request never reaches step 3.
3. Once verified, the package reads `merchant_order_id` back out of the
   callback, finds the matching pending row, and — rather than trusting the
   callback's own `success`/`pending` fields directly — calls the driver's
   `lookup()` again to authoritatively re-confirm the outcome with Paymob,
   then runs the exact same persistence/callback/event steps described
   below that `confirm()` runs.

A webhook that doesn't match any pending checkout row (unrelated Paymob
traffic, or a transaction that didn't originate from this endpoint) is
accepted (HTTP 200) but does nothing — this is normal, not an error.

### A note on trust

> **UNVERIFIED AGAINST A LIVE SIGNED PAYLOAD.** The HMAC field list and
> order this package uses come from Paymob's publicly documented
> "Transaction Processed Callback" calculation — the field *names* are
> confirmed against a real production Paymob callback URL, but the exact
> concatenation order has not been cross-checked against a signature Paymob
> itself generated and this package successfully matched. Before relying on
> this in production, trigger a real test webhook from your Paymob
> dashboard and confirm it returns HTTP 200, not 400. If it doesn't, check
> your `hmac_secret` first — that's the most common cause.

One more subtlety worth knowing if you ever need to debug this yourself:
Paymob's callback URL contains dotted field names like `source_data.pan`
and `data.message`. PHP's own query-string parser silently rewrites dots to
underscores in top-level parameter names before any framework code sees the
request — so `source_data.pan` in Paymob's URL actually arrives as
`source_data_pan`. This package already accounts for that (it checks both
forms), but it's a sharp edge if you're ever comparing a raw Paymob URL
against what `request()->all()` reports and wondering why they don't match.

## Reacting to a confirmed payment

Two independent mechanisms fire on every `confirm()` call — use whichever
fits, or both:

### Per-model: `Payable::onPaymentCompleted()`

`onPaymentCompleted()` is a required method on `Payable` itself — implement
it to run model-specific logic when its payment is confirmed: mark an order
paid, credit a wallet, activate a subscription.

If your model uses `IsPayable` (the common case), you get a default no-op
for free and only need to write this method when you actually have
per-model logic to run:

```php
use Mifatoyeh\LaravelPaymentFramework\Responses\StatusResponse;

class Order extends Model implements Payable
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

If you implement `Payable` by hand (without `IsPayable`), you must add this
method yourself — a one-line no-op body (`{}`) is fine if you only care
about the app-wide event below. Each model gets its **own** callback —
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

`checkout()` persists a **pending** `checkout_transactions` row the moment a
provider order/intent is created, and `confirm()`/`confirmFromWebhook()`
update that same row once the outcome is authoritatively known — gated by
`payment.checkout.persist_transactions` (default `true`):

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
callback and event above still fire either way for a client-driven
`confirm()` call; only the database write is gated. (Note that the
webhook-driven auto-confirmation flow above *needs* this table — it's how a
webhook finds which model a payment belongs to at all — so leave it enabled
if you're using that.)

Each row (`Mifatoyeh\LaravelPaymentFramework\Checkout\CheckoutTransaction`):

| Column                  | Meaning                                                                 |
| ------------------------ | ------------------------------------------------------------------------ |
| `model_type`             | The `payment.payables` key (e.g. `"order"`), not a class name.          |
| `model_id`                | The record's primary key.                                               |
| `driver`                  | Which driver processed it (`"stripe"`, `"paymob"`, ...).                |
| `driver_type`             | `"sdk"` / `"webview"` / `null` — whatever `checkout()`/confirm sent.    |
| `merchant_order_id`       | The idempotency key `checkout()` forwarded to the provider as its own order reference — how a webhook correlates back to this row. |
| `transaction_reference`   | The provider-assigned reference — `null` while pending, set by `lookup()` once confirmed. |
| `status`                  | The `PaymentStatus` enum value — `"pending"` until confirmed.           |
| `successful`              | Boolean — whether that status was a successful terminal state.          |
| `amount` / `currency`     | Read from the `Payable` model.                                          |
| `message`                 | The provider's own human-readable message, if any.                      |
| `raw_response`            | The full raw provider payload (JSON) — for debugging/audit.             |

A `(driver, model_type, model_id)` unique constraint means there's **one**
row per model per driver: `checkout()`'s initial pending insert and every
later confirmation (repeat client `confirm()` calls, or a webhook) all
update that same row in place rather than accumulating duplicates —
matching `onPaymentCompleted()`'s own idempotency requirement above. Paying
for the same model again later (a new checkout attempt after a previous one
finished) overwrites the row with the new attempt's data; `updated_at`
tracks when that last happened.

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

The checkout/confirm endpoints are registered by `routes/checkout.php`,
under `payment.checkout.route`/`payment.checkout.middleware`. The webhook
endpoint (`routes/webhooks.php`) is separate — different config, different
middleware, and it accepts GET as well as POST (see the automatic-
confirmation section above for why):

| Method     | Path                       | Route name                | Config                                             |
| ---------- | --------------------------- | -------------------------- | ---------------------------------------------------- |
| POST       | `{checkout.route}`           | `payment.checkout`         | `payment.checkout.route` / `payment.checkout.middleware` |
| POST       | `{checkout.route}/confirm`   | `payment.checkout.confirm` | same as above                                       |
| GET, POST  | `{webhook.prefix}/{driver}`  | `payment.webhook`          | `payment.webhook.prefix` / `payment.webhook.middleware`  |

`{checkout.route}` defaults to `payment/checkout`; `{webhook.prefix}`
defaults to `payment/webhook`.
