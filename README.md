# Laravel Payment Hub

A provider-agnostic payment framework for Laravel 12+ / PHP 8.4+, built around
the Strategy pattern: switch payment providers via config, not application
code. Stripe, Paymob, and MyFatoorah are built in today; PayPal is planned
but **not yet implemented** — see [Status](#status) below.

## Install

```bash
composer require a7my/payment-hub
```

No `repositories` block needed — the package is on [Packagist](https://packagist.org/packages/a7my/payment-hub).

<details>
<summary>Install from GitHub directly (before Packagist, or for unreleased commits)</summary>

Add to your app's `composer.json`:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/A7my/payment-hub"
    }
]
```

```bash
composer require a7my/payment-hub:dev-main
```

</details>

**Publish config & migrations:**

```bash
php artisan vendor:publish --tag=payment-config
php artisan vendor:publish --tag=payment-migrations
php artisan migrate
```

Set provider credentials in `.env` — see [Environment variables](#environment-variables) below.
Never commit real keys; use placeholders in docs and keep secrets in `.env` only.

## Environment variables

Shared:

```env
PAYMENT_DRIVER=stripe          # default driver when Payment::charge() has no driver()
PAYMENT_SANDBOX=true           # shared test flag (Stripe, Paymob); see MyFatoorah below
PAYMENT_CHECKOUT_PERSIST_TRANSACTIONS=true
```

**Stripe** — test mode is encoded in the key (`sk_test_…` / `pk_test_…`):

```env
STRIPE_KEY=your-stripe-publishable-key
STRIPE_SECRET=your-stripe-secret-key
STRIPE_WEBHOOK_SECRET=your-stripe-webhook-secret
```

**Paymob (KSA)** — test mode is encoded in the key (`sau_sk_test_…` / `sau_pk_test_…`):

```env
PAYMOB_API_KEY=your-paymob-api-key
PAYMOB_SECRET_KEY=your-paymob-secret-key
PAYMOB_PUBLIC_KEY=your-paymob-public-key
PAYMOB_HMAC_SECRET=your-hmac-secret
PAYMOB_INTEGRATION_ID=12345
PAYMOB_BASE_URL=https://ksa.paymob.com/api
```

**MyFatoorah** — test mode is the **host** (`apitest` vs `api-sa`), not the key prefix.
Use `MYFATOORAH_SANDBOX` so MyFatoorah can stay live while Stripe/Paymob stay on test keys:

```env
MYFATOORAH_API_KEY=your-myfatoorah-api-token
MYFATOORAH_SANDBOX=true         # true → apitest.myfatoorah.com; false → regional live host
MYFATOORAH_COUNTRY_CODE=SAU     # SAU, ARE, QAT, EGY, KWT, BHR, OMN, JOR — required when live
MYFATOORAH_WEBHOOK_SECRET=your-webhook-secret
MYFATOORAH_PAYMENT_METHOD_ID=2
# Do NOT set MYFATOORAH_BASE_URL unless you need an explicit host override
```

| `MYFATOORAH_COUNTRY_CODE` | Live host |
|---------------------------|-----------|
| `SAU` | `https://api-sa.myfatoorah.com` |
| `ARE` / `QAT` / `EGY` | `api-ae` / `api-qa` / `api-eg` |
| `KWT` / `BHR` / `OMN` / `JOR` | `https://api.myfatoorah.com` |

After changing `.env`: `php artisan config:clear`.

## Driver reference docs

Provider-specific details (webhooks, callbacks, env quirks) live in separate files:

| Driver | Reference |
|--------|-----------|
| Stripe | [`STRIPE.md`](STRIPE.md) |
| Paymob | [`PAYMOB.md`](PAYMOB.md) |
| MyFatoorah | [`MYFATOORAH.md`](MYFATOORAH.md) |
| Checkout (sdk, confirm, callbacks) | [`CHECKOUT.md`](CHECKOUT.md) |

## Core concepts

- **Two ways to call every method**: a plain array (shown throughout this
  README) or a hand-built DTO (`PaymentRequest`, `SubscriptionRequest`, …).
  Arrays are converted to DTOs internally by `PaymentRequestFactory` — pick
  whichever is more convenient; nothing behaves differently either way.
- **Every response is an object**, never a raw array: `isSuccessful()`,
  `getStatus()`, `getMessage()`, `getRawResponse()` (the untouched provider
  payload, for debugging) are available on all of them. Check
  `isSuccessful()` before trusting the outcome — some operations return a
  perfectly valid, non-throwing response that still represents a decline or
  an in-progress state (see each method below).
- **`idempotency_key` is optional on every call** — if you don't pass one, a
  UUID is generated for you. Pass your own when you need to safely retry the
  *same* logical operation (e.g. a queued job retry) without risking a
  duplicate charge.
- **Amounts are always the smallest currency unit** — cents for USD, so
  `1000` = $10.00.
- **Exceptions vs. soft failures**: a *declined card* comes back as a normal,
  non-throwing response with `isSuccessful() === false` — inspect
  `getStatus()`/`getMessage()`. An actual *unrecoverable error* (bad API key,
  unknown transaction id, network failure) throws a
  `Mifatoyeh\LaravelPaymentFramework\Exceptions\PaymentException` subclass.

## Usage

This is how you call **any** driver — the calling code below is not
Stripe-specific. Every array key, method name, and response method
(`isSuccessful()`, `getStatus()`, …) is identical no matter which provider is
configured; only what happens *behind* the call differs per provider. Switch
providers by changing `Payment::driver('…')` or `PAYMENT_DRIVER` in `.env` —
no application code changes required. Stripe, Paymob, and MyFatoorah are
built in today (see [Status](#status)).

```php
use Mifatoyeh\LaravelPaymentFramework\Facades\Payment;

// Explicitly pick a provider...
$payment = Payment::driver('stripe'); // -> ->driver('paypal') once available, etc.

// ...or omit driver() entirely and use whatever's configured as the
// default (PAYMENT_DRIVER env var, or payment.default in config/payment.php).
// Payment::charge([...]) works the same way.
```

Every example below uses `$payment` from this line.

### charge() — take a payment immediately

```php
$response = $payment->charge([
    'amount'   => 1000, // $10.00
    'currency' => 'USD',

    'customer' => [
        'name'  => 'Mohamed Azmy',
        'email' => 'azmy@example.com',
    ],

    // To charge a specific, already-created Stripe payment method,
    // pass its PaymentMethod ID under 'token' — NOT 'payment_method'.
    // ('payment_method' selects a method *category* — card, wallet,
    // bank_transfer, etc. — not a specific Stripe object. Passing a
    // Stripe ID like 'pm_...' under 'payment_method' will throw.)
    'token' => 'pm_1N...',

    'metadata' => ['order_id' => 123],
]);

if ($response->isSuccessful()) {
    // $response->getTransactionId(), $response->getStatus(), ...
} elseif ($response->requiresAction()) {
    // 3-D Secure or similar — you'll need a client-side confirmation flow.
} else {
    // Declined — $response->getMessage() has the reason.
}
```

### authorize() / capture() / void() — reserve funds, then settle or release

```php
// Reserve $10.00 without capturing yet (capture_method: manual).
$response = $payment->authorize(['amount' => 1000, 'currency' => 'USD', /* ... */]);
$transactionId = $response->getTransactionId()->toString();

// Later: capture the hold (full or partial amount).
$payment->capture(['transaction_id' => $transactionId, 'amount' => 1000]);

// Or release it without ever charging the customer.
$payment->void(['transaction_id' => $transactionId, 'reason' => 'Order cancelled']);
```

### refund() / partialRefund()

```php
// Full refund.
$payment->refund(['transaction_id' => $transactionId, 'amount' => 1000]);

// Partial refund — same call shape, smaller amount. The response's
// getStatus() tells you Refunded vs. PartiallyRefunded based on how much
// of the original charge has now been refunded in total.
$payment->partialRefund(['transaction_id' => $transactionId, 'amount' => 300]);
```

### verify() / lookup() — read-only status checks

```php
// verify(): "can I trust this transaction to fulfil an order?" — a boolean.
$verification = $payment->verify(['transaction_id' => $transactionId]);
$verification->isVerified(); // bool

// lookup(): the full canonical status, for reconciliation/dashboards.
$status = $payment->lookup(['transaction_id' => $transactionId]);
$status->getStatus(); // PaymentStatus enum
```

### saveCard() / chargeToken() — save a card, charge it later

Saving a card creates a Stripe Customer behind the scenes (this package has
no other way to identify "this card belongs to this Stripe customer" later).
**Persist `getProviderReference()` yourself** — it's your only way to charge
that card again.

```php
// Step 1 — save a card the customer entered client-side (via Stripe.js/
// Elements), which gives you a `pm_...` PaymentMethod id.
$saved = $payment->saveCard([
    'token'       => 'pm_1N...',      // from Stripe.js on the client
    'customer_id' => (string) $user->id, // YOUR OWN customer reference
]);

$stripeCustomerId = $saved->getProviderReference(); // 'cus_...' — SAVE THIS
// e.g. $user->update(['stripe_customer_id' => $stripeCustomerId]);

// Step 2 — charge that saved card later (renewal, off-session purchase, ...).
$response = $payment->chargeToken([
    'token'                       => 'pm_1N...',       // the SAME pm_... from step 1
    'amount'                      => 1000,
    'currency'                    => 'USD',
    'customer'                    => ['name' => $user->name, 'email' => $user->email],
    'provider_customer_reference' => $user->stripe_customer_id, // from step 1
]);
```

`provider_customer_reference` is required for `chargeToken()` — Stripe
rejects charging a saved payment method under any customer other than the
one it was originally attached to, so omitting it throws a clear
`InvalidArgumentException` before any API call is made, rather than a
cryptic Stripe error.

### createSubscription() / cancelSubscription()

```php
$response = $payment->createSubscription([
    'amount'                      => 2000,
    'currency'                    => 'USD',
    'interval'                    => 'monthly',
    'plan_id'                     => 'price_1N...', // a Stripe Price ID you created beforehand
    'customer'                    => ['name' => $user->name, 'email' => $user->email],
    'provider_customer_reference' => $user->stripe_customer_id, // from saveCard(), same as above
    'token'                       => 'pm_1N...', // optional — omit to bill the customer's stored default card
    'trial_days'                  => 14, // optional
]);

$subscriptionId = $response->getSubscriptionId();

// Cancel immediately:
$payment->cancelSubscription(['subscription_id' => $subscriptionId]);

// Or let it run until the current billing period ends:
$payment->cancelSubscription([
    'subscription_id'      => $subscriptionId,
    'cancel_at_period_end' => true,
]);
```

`plan_id` must be an existing Stripe Price ID (create it once via the Stripe
dashboard or API) — this driver does not support ad-hoc/inline subscription
pricing, and does not create Products/Prices on your behalf.

### createPaymentLink() — hosted checkout page

See the [dedicated walkthrough](#createpaymentlink-walkthrough) below.

## createPaymentLink() walkthrough

`createPaymentLink()` itself is a generic driver method (same call shape for
any provider), but the explanation below of *what happens behind it* is
specifically how Stripe's driver currently implements it (via a Checkout
Session) — another provider's driver would generate the hosted page
differently internally, even though the call into it looks identical.

```php
$response = Payment::driver('stripe')->createPaymentLink([
    'amount'      => 10000,          // amount in smallest unit (cents) → $100.00
    'currency'    => 'USD',
    'description' => 'Sandbox test payment',
    'customer' => [
        'name'  => 'Mohamed Azmy',
        'email' => 'azmy@example.com',
    ],
    'return_url' => url('/payment/success'),
    'cancel_url' => url('/payment/cancel'),
    'metadata' => [
        'order_id' => 123,
    ],
]);

// Redirect the browser to the hosted Stripe Checkout page
return redirect($response->getPaymentUrl());
```

What each field does, and what actually happens on Stripe's side:

- **`amount` / `currency` / `description`** — build a single, one-off line
  item on the fly (Stripe calls this "inline pricing"). No Stripe Price or
  Product needs to exist beforehand — that's specific to `createPaymentLink()`;
  `createSubscription()`'s `plan_id` above does need a pre-existing Price.
  `description` becomes the line item's name on the hosted checkout page.
- **`customer`** — currently only `email` is forwarded to Stripe (as
  `customer_email`, to prefill the checkout page). `name` is accepted by the
  DTO but **not currently sent to Stripe** — a known limitation, not a bug;
  say if you want that wired up.
- **`return_url`** — required. Stripe calls this `success_url` — where the
  browser lands after the customer completes payment. **Important:** landing
  on this URL is not proof the payment succeeded — see the warning below.
- **`cancel_url`** — optional. Where the browser lands if the customer backs
  out. Without it, Stripe just doesn't show a "back" button on the checkout
  page; nothing else changes.
- **`metadata`** — forwarded to Stripe as-is, visible on the Checkout Session
  in the Stripe dashboard. Handy for reconciling `order_id` against your own
  records later.
- **`$response->getPaymentUrl()`** — the `https://checkout.stripe.com/...`
  URL to send the customer to. `$response->isSuccessful()` here only means
  *"the Checkout Session and URL were created successfully"* — it says
  nothing about whether the customer has paid yet, since no charge happens
  at creation time. The actual charge happens later, asynchronously, when
  the customer completes the hosted page.

**One thing to fix in the snippet above**: `return redirect($response->getPaymentUrl())`
is correct — it sends an HTTP redirect. If you instead write
`return $response->getPaymentUrl();` (dropping `redirect()`), Laravel just
returns the URL as plain text in the response body — the browser is never
actually redirected anywhere. Keep the `redirect()` wrapper.

**Don't trust `return_url` alone to mark an order as paid.** A customer can
reach `return_url` by hitting the browser back/forward buttons, refreshing,
or even guessing the URL, none of which mean they paid. Once webhook
handling (`processWebhook()`) lands in this package, listen for
`checkout.session.completed` there to confirm payment server-side. Until
then, use `lookup()` (via the transaction/session id) to confirm the actual
payment status before fulfilling an order — never fulfil directly off of a
`return_url` hit.

## Generic checkout endpoint

Everything above requires you to write your own controller/route. This
package can also expose a single, ready-to-use checkout endpoint that works
against any of your own Eloquent models, with zero routes or controllers of
your own — you implement one interface on a model, register it in config,
and the endpoint already exists.

### 1. Implement `Payable` on your model

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable;
use Mifatoyeh\LaravelPaymentFramework\Concerns\IsPayable;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Payable;

class Order extends Model implements Payable
{
    use IsPayable;

    // Tell the trait which columns hold the amount/currency — it reads
    // them for you, no accessor methods to write. Both are optional
    // (default to 'amount'/'currency' if omitted) and can be typed or
    // untyped — either works, e.g. `protected $paymentAmountColumn = '...'`
    // is equally fine if you prefer matching Eloquent's own untyped-
    // property style ($table, $fillable, etc.).
    protected string $paymentAmountColumn = 'total_cents';
    protected string $paymentCurrencyColumn = 'currency_code';

    // Which drivers THIS model may be paid through — checked even if a
    // driver is otherwise configured and enabled application-wide.
    public function getSupportedPaymentDrivers(): array
    {
        return ['stripe', 'paymob'];
    }

    // Called unconditionally by the endpoint's own controller, regardless
    // of route middleware — this is your real security boundary, not
    // whatever middleware you do or don't attach to the route.
    public function authorizePayment(?Authenticatable $payer): bool
    {
        return $payer?->id === $this->user_id;
    }
}
```

`total_cents` must already be in the smallest currency unit (cents), same as
everywhere else in this package. `currency_code` must be a value `Currency::from()`
accepts (e.g. `'USD'`, `'EGP'`) — either a plain string column or store a
`Currency` enum cast, both work.

If your amount isn't a single stored column (e.g. it's computed from related
rows), skip the trait and implement `getPaymentAmount()`/`getPaymentCurrency()`
yourself — they just need to return `Money`/`Currency` values, however you
get there.

**Example — `Package` with a stored price in major units (SAR):**

```php
use Mifatoyeh\LaravelPaymentFramework\Contracts\Payable;
use Mifatoyeh\LaravelPaymentFramework\Concerns\IsPayable;
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;

class Package extends Model implements Payable
{
    use IsPayable;

    public function getPaymentAmount(): Money
    {
        // price is stored in major units (e.g. 40.00 SAR) — convert to halalas
        $minor = (int) round((float) $this->price * 100);

        return Money::ofMinor(max($minor, 1), Currency::SAR);
    }

    public function getPaymentCurrency(): Currency
    {
        return Currency::SAR;
    }

    public function getSupportedPaymentDrivers(): array
    {
        return ['stripe', 'paymob', 'myfatoorah'];
    }

    public function authorizePayment(?Authenticatable $payer): bool
    {
        return $payer !== null;
    }
}
```

**Optional — snapshot data at checkout time** (`CapturesCheckoutContext`):

Some values only exist during the original `POST /payment/checkout` call (a
locked-in price, a discount code). Implement `captureCheckoutContext()` to
persist them into the pending transaction; read them back in
`onPaymentCompleted()` via `$context->get('key')` or `$context->payer()`.

```php
use Mifatoyeh\LaravelPaymentFramework\Contracts\CapturesCheckoutContext;
use Mifatoyeh\LaravelPaymentFramework\Checkout\CheckoutContext;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\Responses\StatusResponse;

class Package extends Model implements Payable, CapturesCheckoutContext
{
    // ... getPaymentAmount(), etc.

    public function captureCheckoutContext(): array
    {
        return [
            'price' => $this->price,
            // JSON-serialisable scalars/arrays only — not Eloquent models
        ];
    }

    public function onPaymentCompleted(StatusResponse $status, CheckoutContext $context): void
    {
        if (! $status->isSuccessful() || $status->getStatus() !== PaymentStatus::Captured) {
            return;
        }

        $transactionId = $status->getTransactionId()->toString();

        // Idempotent — webhooks and confirm() can both fire
        if (Transaction::where('transaction_id', $transactionId)->exists()) {
            return;
        }

        $payer = $context->payer();
        if ($payer === null) {
            return;
        }

        Transaction::create([
            'transaction_id' => $transactionId,
            'user_id'        => $payer->getAuthIdentifier(),
            'package_id'     => $this->id,
            'amount'         => $context->get('price', $this->price),
            'payment_method' => $context->driver,
        ]);
    }
}
```

See [`CHECKOUT.md`](CHECKOUT.md) for confirmation flows (callback, webhook,
`confirm()`), and the app-wide `CheckoutPaymentConfirmed` event.

### 2. Register it in config

```php
// config/payment.php
'payables' => [
    'order'   => \App\Models\Order::class,
    'package' => \App\Models\Package::class,
],

'checkout' => [
    'enabled'    => env('PAYMENT_CHECKOUT_ENABLED', true),
    'route'      => env('PAYMENT_CHECKOUT_ROUTE', 'payment/checkout'),
    // Default is ['web', 'auth'] — use API auth for SPA/mobile backends:
    'middleware' => ['api', 'auth:api'],
],
```

This is a deliberate allowlist, not optional boilerplate — the endpoint never
resolves a class from the request directly; only keys registered here are
reachable at all. An unregistered `model_type` gets a 422, not a lookup
attempt.

### 3. Call the endpoint from your frontend

```
POST /payment/checkout
```

**Stripe (webview + web — package handles callback redirect):**

```json
{
  "model_type": "package",
  "model_id": "3",
  "driver": "stripe",
  "driver_type": "webview",
  "os": "web",
  "return_url": "https://your-app.com/payment/success",
  "cancel_url": "https://your-app.com/payment/cancel"
}
```

**Paymob (KSA):**

```json
{
  "model_type": "package",
  "model_id": "3",
  "driver": "paymob",
  "driver_type": "webview",
  "os": "web",
  "return_url": "https://your-app.com/payment/success",
  "cancel_url": "https://your-app.com/payment/cancel"
}
```

**MyFatoorah (Saudi):**

```json
{
  "model_type": "package",
  "model_id": "3",
  "driver": "myfatoorah",
  "driver_type": "webview",
  "os": "web",
  "return_url": "https://your-app.com/payment/success",
  "cancel_url": "https://your-app.com/payment/cancel"
}
```

The request body shape is identical for every driver — only `"driver"` changes.

- **`model_type`** — the key you used in `payment.payables` (`"order"` above), not a class name.
- **`model_id`** — the record's primary key.
- **`driver`** — `"stripe"`, `"paymob"`, etc. — must also be in that model's own `getSupportedPaymentDrivers()`.
- **`driver_type`** — `"webview"` (redirects to a hosted checkout page, same as `createPaymentLink()`) or `"sdk"` (returns a client-confirmable reference for a native/client-side SDK instead — a driver that doesn't support it yet returns a clear 422, not a silent wrong response). See [`CHECKOUT.md`](CHECKOUT.md) for the full sdk-mode walkthrough, per-model/per-driver payment callbacks, background verification, and transaction persistence — none of that is duplicated here.
- **`os`** — `"web"` or `"mobile"`, required. Decides how you eventually learn the outcome — see [`CHECKOUT.md`](CHECKOUT.md#webview--os-web-the-auto-registered-callback-route) for the one combination (`webview` + `web`) where this package handles the entire round trip (including redirecting the browser back to `return_url`) with no `confirm()` call needed from you at all.
- **`return_url`/`cancel_url`** — for `webview` + `os: "web"`, `return_url` is **required** and is where the browser lands *after* this package has verified the outcome (not forwarded to the provider directly — see CHECKOUT.md). For every other combination, both remain optional and, when present, are forwarded straight through to the driver's `createPaymentLink()`.

Success response (HTTP 200, webview mode):

```json
{
  "status": "success",
  "driver_type": "webview",
  "checkout_url": "https://checkout.stripe.com/c/pay/...",
  "link_id": "cs_test_...",
  "expires_at": null,
  "message": "Payment link created."
}
```

Redirect the browser (or open a webview) to `checkout_url`. Failure responses
always look like `{"status": "fail", "message": "..."}`, with the HTTP status
telling you what kind of failure it was: `404` (no such record), `422`
(unknown `model_type`, driver not allowed for this model, invalid
`driver_type`, or a driver that doesn't support the requested `driver_type`),
`403` (`authorizePayment()` returned false), `502` (the provider API itself
failed).

Whichever `driver_type` you use, `createPaymentLink()`/`createSdkIntent()`
alone never confirms a payment happened — see
[`CHECKOUT.md`](CHECKOUT.md) for why `POST {route}/confirm` is a required
second step, not an optional extra.

### Route configuration

Configured under `payment.checkout` in step 2 above. Change `route` if
`/payment/checkout` collides with something in your app, or set `enabled` to
`false` to register the route yourself. Don't rely on middleware alone —
`authorizePayment()` is always checked by the controller regardless of route
middleware.

## Events

Every mutating operation dispatches a Laravel event you can listen for —
`PaymentInitiated`, `PaymentSucceeded`, `PaymentFailed`, `PaymentCaptured`,
`PaymentVoided`, `PaymentRefunded`, `CardSaved`, `TokenCharged`,
`SubscriptionCreated`, `SubscriptionCancelled`, `PaymentLinkCreated`,
`TransactionLookuped` — each carrying the original request DTO and the
resulting response.

## Status

This package is under active development. Driver method support, per
provider:

| Driver     | charge / authorize / void / capture / refund | verify / lookup | saveCard / chargeToken | subscriptions | createPaymentLink | sdk checkout | webhooks              |
| ---------- | ---------------------------------------------- | ---------------- | ------------------------ | -------------- | ------------------ | ------------- | ---------------------- |
| Stripe     | ✅                                               | ✅                | ✅                        | ✅              | ✅                  | ✅             | ✅                      |
| Paymob     | ✅                                               | ✅                | ✅                        | 🚫              | ✅                  | ✅             | ⚠️                      |
| MyFatoorah | ✅                                               | ✅                | 🚫                        | 🚫              | ✅                  | ✅             | ⚠️                      |
| PayPal     | —                                                | —                 | —                         | —              | —                   | —             | planned, not started   |

✅ implemented and tested · 🚧 not yet implemented (throws until it is) · 🚫 not supported by this provider (throws `UnsupportedOperationException`) · ⚠️ implemented, but unverified against a real provider signature — see [`CHECKOUT.md`](CHECKOUT.md#a-note-on-trust) before relying on it in production

Stripe's and Paymob's webhooks both double as the automatic checkout-confirmation trigger — see [`CHECKOUT.md`](CHECKOUT.md#automatic-confirmation-via-webhooks-no-frontend-call-needed).

Two things worth knowing about Paymob specifically before relying on it:
- It has no official SDK, so its driver was built from general API knowledge
  and live-debugged against real errors rather than verified against SDK
  source the way Stripe's was — treat it as less battle-tested.
- `createSubscription()`/`cancelSubscription()` are permanently unsupported,
  not just unimplemented — Paymob has no recurring-billing API resembling
  Stripe's Subscription object.

MyFatoorah-specific notes:
- Test vs live is determined by **host** (`MYFATOORAH_SANDBOX` / `PAYMENT_SANDBOX`
  → `apitest`), not by a key prefix like Stripe/Paymob — see
  [`MYFATOORAH.md`](MYFATOORAH.md).
- `saveCard()` / `chargeToken()` / subscriptions are permanently unsupported.
- For webview checkout, MyFatoorah uses `CallBackUrl` (package callback route);
  webhooks are for sdk-mode intents — see [`CHECKOUT.md`](CHECKOUT.md).

## Publishing (maintainers)

To make `composer require a7my/payment-hub` work for everyone without a VCS
repository block, submit this repo to [Packagist](https://packagist.org):

1. **Public GitHub repo** — `https://github.com/A7my/payment-hub` must be public.
2. **Create a release tag** (Packagist needs at least one tag for stable installs):
   ```bash
   git tag v1.0.0
   git push origin v1.0.0
   ```
3. **Register on Packagist** — sign in at [packagist.org](https://packagist.org) with GitHub.
4. **Submit package** — paste `https://github.com/A7my/payment-hub` and confirm.
5. **Enable auto-update** — on the package page, set up the GitHub webhook so
   new tags/commits sync automatically.

After that, anyone can run `composer require a7my/payment-hub` with no extra config.

## License

MIT
