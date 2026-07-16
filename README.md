# Laravel Payment Hub

A provider-agnostic payment framework for Laravel 12+ / PHP 8.4+, built around
the Strategy pattern: switch payment providers via config, not application
code. Stripe is the first (and currently only) built-in driver; PayPal,
Paymob, and MyFatoorah are planned but **not yet implemented** — see
[Status](#status) below.

## Install

```bash
composer require a7my/payment-hub
php artisan vendor:publish --tag=payment-config
```

Set your provider credentials in `.env`:

```
STRIPE_KEY=pk_test_...
STRIPE_SECRET=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

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
configured; only what happens *behind* the call differs per provider. The
examples use Stripe purely because it's the only driver implemented so far
(see [Status](#status)) — the exact same code will work unchanged against
`paypal`, `paymob`, or `myfatoorah` once those land, just by changing the
driver name (or nothing at all, if you rely on the default).

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

```php
use Mifatoyeh\LaravelPaymentFramework\Facades\Payment;
   $response = Payment::driver('paymob')->createPaymentLink([
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

    // Redirect to Paymob's hosted iframe checkout page
    return ($response->getPaymentUrl());
```php


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

| Driver     | charge / authorize / void / capture / refund | verify / lookup | saveCard / chargeToken | subscriptions | createPaymentLink | webhooks              |
| ---------- | ---------------------------------------------- | ---------------- | ------------------------ | -------------- | ------------------ | ---------------------- |
| Stripe     | ✅                                               | ✅                | ✅                        | ✅              | ✅                  | 🚧                      |
| PayPal     | —                                                | —                 | —                         | —              | —                   | planned, not started   |
| Paymob     | —                                                | —                 | —                         | —              | —                   | planned, not started   |
| MyFatoorah | —                                                | —                 | —                         | —              | —                   | planned, not started   |

✅ implemented and tested · 🚧 not yet implemented (throws until it is)

## License

MIT
