# SDK checkout, confirmation, callbacks & transaction storage

This document covers everything added on top of the base [generic checkout
endpoint](README.md#generic-checkout-endpoint) described in the README:
`driver_type: sdk`, three ways confirmation reaches this package (a client
`confirm()` call, a package-owned callback route, or a provider webhook),
background verification for the cases none of those cover (a self-
rescheduling job, and a universal reconciliation sweep), per-model AND
per-driver payment callbacks, and automatic transaction persistence. Read
the README's "Generic checkout endpoint" section first if you haven't
implemented `Payable` on a model yet — everything here builds on it.

Every `POST /payment/checkout` request now also takes **`os`** (required,
`"web"` or `"mobile"`) — it decides how you eventually learn the outcome:
see [Webview + os: web](#webview--os-web-the-auto-registered-callback-route)
below for the one combination (`driver_type: webview` + `os: web`) that
changes behaviour; every other combination works exactly as already
documented above, just with `os` added to the request body.

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
  "driver_type": "sdk",
  "os": "mobile"
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

## Webview + os: web — the auto-registered callback route

For `driver_type: "webview"` + `os: "web"` specifically, you don't call
`confirm()` at all — the package handles the whole round trip itself.

```json
POST /payment/checkout
{
  "model_type": "order",
  "model_id": "123",
  "driver": "stripe",
  "driver_type": "webview",
  "os": "web",
  "return_url": "https://your-app.com/thank-you",
  "cancel_url": "https://your-app.com/cart"
}
```

`return_url` is **required** for this exact combination (`webview` + `web`)
— every other combination still treats it as optional, same as before.

### What actually happens

The response still looks the same (`checkout_url` to redirect the browser
to), but the URL the *provider* itself redirects back to afterwards is no
longer your `return_url` directly — it's the package's own auto-registered
callback route:

```
GET|POST {payment.checkout.route}/callback/{driver}
```

registered automatically for **every** driver, the same parameterised-route
mechanism `routes/webhooks.php` already uses — a new driver gets a working
callback route the moment it's registered in `payment.drivers`, no route
code to write. Your real `return_url`/`cancel_url` are stored on the
pending `checkout_transactions` row instead ({@see the metadata columns} —
see [Transaction persistence](#transaction-persistence)) and only used
*after* verification:

1. Provider redirects the browser to the callback route.
2. The package authoritatively re-verifies the outcome (never trusts the
   redirect's own query params) — running the driver-level hook, then
   status resolution, then persistence/callbacks/event; see
   [Reacting to a confirmed payment](#reacting-to-a-confirmed-payment) for
   the exact order.
3. **Only then** does it redirect the browser again — this time to your
   real `return_url`, with query params appended:
   ```
   https://your-app.com/thank-you?checkout_status=success&payment_status=captured&transaction_id=pi_3Nxxx...
   ```
   `checkout_status` is `success`/`fail`; `payment_status` is the exact
   `PaymentStatus` value; `transaction_id` is the provider's own reference.

If nothing could be resolved at all (an unrecognised/unrelated request
hitting the callback route), you get a JSON `404` instead of a redirect —
there's no `return_url` to send the browser to in that case.

### Why Stripe, and (currently) not Paymob

This only actually changes anything for **Stripe** — verified against the
SDK, `success_url` genuinely accepts a fresh, dynamic value on every
Checkout Session, so the package can point it at its own callback route
per-request.

**Paymob cannot do this.** Its hosted checkout always redirects to whatever
single URL is configured in the Paymob dashboard for that integration —
there is no per-request override (see `PaymobDriver`'s own class docblock).
In practice, that means Paymob's *existing* webhook route
(`payment/webhook/paymob` — see the section above) already **is** its
callback route; nothing changes for Paymob here, and you don't need to
touch its dashboard setting again. `os`/`return_url` on a Paymob `webview`
checkout are stored the same way, but nothing currently redirects the
browser there automatically the way Stripe's does — Paymob customers still
land whichever way your app already handles today, confirmed via the
webhook as before.

### The mobile side of the same route

If a `webview` checkout is ever confirmed via this route with `os: "mobile"`
stored on it, the response is JSON instead of a redirect — the exact same
shape `confirm()`'s response already has:

```json
{
  "status": "success",
  "payment_status": "captured",
  "transaction_id": "pi_3Nxxx...",
  "message": "Payment succeeded."
}
```

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
   callback, finds the matching pending row, and determines the
   authoritative outcome — see "Where the status comes from" below, since
   this differs between Paymob's two platforms — then runs the exact same
   persistence/callback/event steps described below that `confirm()` runs.

A webhook that doesn't match any pending checkout row (unrelated Paymob
traffic, or a transaction that didn't originate from this endpoint) is
accepted (HTTP 200) but does nothing — this is normal, not an error.

### Where the status comes from: Egypt vs. KSA

- **Egypt/Accept mode** (`api_key` configured): the package calls the
  driver's `lookup()` again to re-confirm the outcome directly with Paymob
  — a live re-check, never trusting the callback's own `success`/`pending`
  fields on their own. This endpoint is confirmed working.
- **KSA mode** (`secret_key` configured): `lookup()`'s underlying endpoint
  (`retrieveTransaction()`, a legacy Accept-API path) is **not** confirmed
  to exist on Paymob's KSA platform — live testing hit a 404 there, and
  after a related fix, a 401 "Authentication credentials were not
  provided.", both consistent with hitting a route the KSA gateway doesn't
  really support this way. Rather than `confirmFromWebhook()` permanently
  failing for every KSA payment, the package uses the webhook payload
  itself as authoritative instead — its HMAC signature (already verified in
  step 2 above) is the trust boundary in this case, not a second live call.
  See {@see SupportsTrustedWebhookStatus}'s docblock in code for the full
  reasoning. If Paymob's real KSA status-lookup endpoint is ever confirmed,
  this should be revisited — a live re-check is strictly stronger than
  trusting a single webhook delivery.

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

## Background verification for sdk mode

`driver_type: "sdk"` has no browser redirect at all — the client SDK
confirms the charge in place, so the package needs another way to learn the
outcome. Which mechanism applies depends on `supports('webhook')` on the
resolved driver:

- **Driver supports webhooks** (Paymob today; Stripe once its webhook
  implementation lands): its existing webhook route resolves `sdk`-mode
  checkouts exactly the same way it already resolves `webview` ones — see
  [Automatic confirmation via Paymob's webhook](#automatic-confirmation-via-paymobs-webhook-no-frontend-call-needed)
  above. Nothing else is dispatched.
- **Driver does NOT support webhooks**: `checkout()` dispatches a
  self-rescheduling `VerifyPaymentJob` — it calls `lookup()`, and if the
  result isn't conclusive yet (still `pending`/`requires_action`), reschedules
  itself with backoff (`30s → 1m → 5m → 15m → 1h` by default) until either a
  conclusive status or `payment.verification.job.max_attempts`/
  `max_duration` is reached. **Requires a delay-capable queue driver**
  (redis, database, sqs) — `QUEUE_CONNECTION=sync` runs every attempt
  immediately, back-to-back, defeating the backoff entirely; the package
  logs a boot-time warning when it detects this.

```php
// config/payment.php
'verification' => [
    'sweep_interval_hours' => env('PAYMENT_SWEEP_INTERVAL_HOURS', 12),
    'job' => [
        'backoff'      => [30, 60, 300, 900, 3600],
        'max_attempts' => env('PAYMENT_VERIFY_JOB_MAX_ATTEMPTS', 8),
        'max_duration' => env('PAYMENT_VERIFY_JOB_MAX_DURATION', 86400), // 24h
    ],
],
```

### The universal reconciliation sweep

Regardless of driver, `driver_type`, or `os` — a scheduled command,
`php artisan payment:reconcile-checkouts`, re-verifies any
`checkout_transactions` row still `pending` and older than
`payment.verification.sweep_interval_hours` (default 12h). Registered
automatically via Laravel's scheduler; nothing to add to your own
`Kernel`/`routes/console.php` beyond making sure your scheduler itself runs
(`* * * * * php artisan schedule:run` in cron, same as any Laravel app).

This exists because "supports webhooks" only means the provider *offers*
the mechanism — not that a given delivery actually arrives (provider-side
outage, a misconfigured dashboard URL, a dropped delivery), and because a
`VerifyPaymentJob` chain can be lost entirely (a worker crash, a queue
flush) before reaching a conclusive status. The sweep doesn't care which
path was *supposed* to resolve a row, only that it didn't — it's the
backstop under every other mechanism in this document.

Safe to run against a row a webhook/job already resolved moments earlier —
the same `(driver, model_type, model_id)` unique constraint that makes
every other confirmation path an upsert (see
[Transaction persistence](#transaction-persistence)) makes this a no-op in
that case, not a duplicate.

## Reacting to a confirmed payment

Three independent mechanisms can fire on every confirmation (`confirm()`,
the callback route, the webhook, `VerifyPaymentJob`, or the reconciliation
sweep — all of them funnel through the same pipeline) — use whichever fit,
or all three. **Execution order, always:**

```
1. Driver-level hook   (SupportsCallbackHook::onCallbackReceived() — callback/webhook paths only, side effects only)
2. Status resolution   (SupportsTrustedWebhookStatus, or a live lookup()/verify() call)
3. Persistence         (the checkout_transactions row)
4. Model-level         (Payable::onPaymentCompleted())
5. App-wide event      (CheckoutPaymentConfirmed)
```

Step 1 runs BEFORE the outcome is even known and never influences it — it's
for side effects only (see below). Steps 3-5 always run together, in that
order, every time — see `CheckoutService::applyConfirmedStatus()`.

### Per-driver: `SupportsCallbackHook` (optional, for driver authors)

Implement this on a *driver* (not a model) when that specific gateway needs
custom handling the moment a callback/webhook is received — before this
package has even determined what it means. Examples: provider-specific
audit logging, capturing a field the generic pipeline doesn't look for,
alerting on a gateway-specific partial-success state.

```php
use Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\SupportsCallbackHook;

final class MyGatewayDriver extends AbstractDriver implements PaymentDriverContract, SupportsCallbackHook
{
    public function onCallbackReceived(array $rawPayload, string $source): void
    {
        // $source is "callback" (the browser-redirect route) or "webhook"
        // (the server-to-server route). Side effects only — this cannot
        // change the outcome; that's determined by the step right after it.
        Log::info('MyGateway callback received', ['source' => $source]);
    }
}
```

This is a driver-authoring concern, not something most consumers of this
package ever implement themselves — most host apps only ever touch the
per-model and app-wide mechanisms below. Deliberately a different job from
`SupportsTrustedWebhookStatus` (used internally by Paymob's KSA mode) —
that interface can supply the actual outcome; this one never does.

### Per-model: `Payable::onPaymentCompleted()`

`onPaymentCompleted()` is a required method on `Payable` itself — implement
it to run model-specific logic when its payment is confirmed: mark an order
paid, credit a wallet, activate a subscription.

If your model uses `IsPayable` (the common case), you get a default no-op
for free and only need to write this method when you actually have
per-model logic to run:

```php
use Mifatoyeh\LaravelPaymentFramework\Checkout\CheckoutContext;
use Mifatoyeh\LaravelPaymentFramework\Responses\StatusResponse;

class Order extends Model implements Payable
{
    use IsPayable;

    // ...getSupportedPaymentDrivers()/authorizePayment() from the README...

    public function onPaymentCompleted(StatusResponse $status, CheckoutContext $context): void
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

#### `$context` — data from the original checkout, since there's no `auth()->user()` here

`onPaymentCompleted()` can fire from five different places — a client
`confirm()` call, the callback route, a webhook, `VerifyPaymentJob`, the
reconciliation sweep — and only the FIRST of those (`checkout()` itself)
ever runs inside a real authenticated HTTP request. By the time any of the
others fire, there is no session to read `auth()->user()`/`Auth::id()`
from — calling either inside this method returns `null`, not your user, no
matter how the request got here. **This is not a bug to work around; it's
why `$context` exists.**

```php
public function onPaymentCompleted(StatusResponse $status, CheckoutContext $context): void
{
    // ...

    Subscription::where('id', $this->id)->update([
        'user_id' => $context->payerId, // NOT Auth::id() — no session here
    ]);
}
```

`$context->payerId` is `Authenticatable::getAuthIdentifier()`, captured
server-side during the ORIGINAL `checkout()` request and carried forward —
never read from client input, so a request can't spoof who it's paying on
behalf of. The rest of `$context`: `driver`, `driverType`, `os`,
`merchantOrderId` (the correlation id `checkout()` generated). `payerId`/
`os` are `null` when `payment.checkout.persist_transactions` is disabled
(nothing to read them back from), or `payerId` alone is `null` when the
original checkout request itself was unauthenticated.

Need the actual model, not just the id? `$context->payer()` resolves it —
lazily (only queries when you call it; most confirmations never need this)
and through Laravel's own auth guard/provider, so the package never has to
know your `User` model's class name:

```php
public function onPaymentCompleted(StatusResponse $status, CheckoutContext $context): void
{
    $user = $context->payer(); // ?Authenticatable — a real, queried model, or null

    // Behind a non-default guard (e.g. checkout() runs behind `auth:api`
    // and that isn't also your app's config('auth.defaults.guard')):
    $user = $context->payer('api');
}
```

Returns `null` — never throws — when there's no `payerId` at all, the id no
longer resolves to a real row, or the guard's provider doesn't support
`retrieveById()` (a few third-party guard packages don't). Handle a `null`
payer the same way you'd handle a missing `payerId`.

**If your model needs `user_id` for anything (creating a related row, an
authorization check that runs later, etc.), don't put the shared/catalog
row behind `Payable` at all — put the per-user row behind it instead**, and
store the buyer's id on it up front, before checkout starts (e.g. create a
`pending` `Subscription` row with `user_id` already set, THEN check out
against that `Subscription`, not the `Package` catalog entry it belongs
to). `$context->payerId` covers the common case without that restructuring,
but a model tied to one specific user from creation is still the more
robust design when you have a choice.

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
| `merchant_order_id`       | The idempotency key `checkout()` forwarded to the provider as its own order reference — how a webhook/callback correlates back to this row. |
| `transaction_reference`   | The provider-assigned reference — `null` while pending, set by `lookup()` once confirmed (or immediately at `checkout()` time for `sdk` mode, when the driver's own SDK-intent reference is itself usable — see `VerifyPaymentJob`). |
| `status`                  | The `PaymentStatus` enum value — `"pending"` until confirmed.           |
| `successful`              | Boolean — whether that status was a successful terminal state.          |
| `amount` / `currency`     | Read from the `Payable` model.                                          |
| `message`                 | The provider's own human-readable message, if any.                      |
| `raw_response`            | The full raw provider payload (JSON) — for debugging/audit.             |
| `metadata`                | JSON — currently `os`, `return_url`, `cancel_url` as stored at `checkout()` time (see [Webview + os: web](#webview--os-web-the-auto-registered-callback-route)). |

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

Three route files, three different trust models — none of them need any
route code of your own, and each takes its own middleware config
deliberately (a provider's redirect/server is never your logged-in user):

| Method     | Path                          | Route name                  | Config                                                      |
| ---------- | ------------------------------ | ----------------------------- | --------------------------------------------------------------- |
| POST       | `{checkout.route}`              | `payment.checkout`            | `payment.checkout.route` / `payment.checkout.middleware` (default `['web', 'auth']`) |
| POST       | `{checkout.route}/confirm`      | `payment.checkout.confirm`    | same as above                                                |
| GET, POST  | `{checkout.route}/callback/{driver}` | `payment.checkout.callback`   | `payment.checkout.route` / `payment.checkout.callback_middleware` (default `[]` — deliberately NOT `web`/`auth`, see [Webview + os: web](#webview--os-web-the-auto-registered-callback-route)) |
| GET, POST  | `{webhook.prefix}/{driver}`     | `payment.webhook`             | `payment.webhook.prefix` / `payment.webhook.middleware` (default `['api']`) |

`routes/checkout.php` owns the first two; `routes/callback.php` (new) owns
the callback route; `routes/webhooks.php` owns the last. The callback route
is registered whenever `payment.checkout.enabled` is true — same flag as
the plain checkout route, since it only exists to serve `checkout()`'s own
webview flow.

`{checkout.route}` defaults to `payment/checkout`; `{webhook.prefix}`
defaults to `payment/webhook`.
