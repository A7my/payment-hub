# Stripe Driver Reference

Provider-specific reference for `StripeDriver` (`src/Drivers/Stripe/`).  
Public calling API is the same for every driver — only what happens behind each method is Stripe-specific here.

Use with [`README.md`](README.md) (generic API) and [`CHECKOUT.md`](CHECKOUT.md) (checkout / confirm / webhooks).

---

## Architecture

```
Payment::driver('stripe')->charge(...)
        │
        ▼
┌──────────────────┐
│  StripeDriver    │  orchestration only (idempotency, log, events, retry)
└────────┬─────────┘
         │
    ┌────┴────┬──────────────┬──────────────────┐
    ▼         ▼              ▼                  ▼
StripeClient  StripeMapper  StripeWebhookVerifier  StripeExceptionMapper
  (SDK I/O)   (payload→DTO) (Stripe-Signature)     (SDK exception→framework)
```

Collaborators are **constructed inside `StripeDriver`**, not resolved from the container, so they all receive the same `payment.drivers.stripe` config.

| Class | Role |
|-------|------|
| `StripeDriver` | Implements `PaymentDriverContract`, `SupportsSdkCheckout`, `SupportsCapabilities` |
| `StripeClient` | Thin wrapper around `stripe/stripe-php` — returns raw arrays |
| `StripeMapper` | Maps Stripe payloads → framework Response objects |
| `StripeWebhookVerifier` | HMAC verify of `Stripe-Signature` |
| `StripeExceptionMapper` | Maps Stripe SDK exceptions → `PaymentException` subclasses |

---

## Config

```php
// config/payment.php → drivers.stripe
'stripe' => [
    'class'          => StripeDriver::class,
    'key'            => env('STRIPE_KEY'),            // pk_… (publishable, for SDK checkout)
    'secret'         => env('STRIPE_SECRET'),         // sk_… (API secret)
    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'), // whsec_…
    'sandbox'        => env('PAYMENT_SANDBOX', true),
    'timeout'        => (int) env('PAYMENT_TIMEOUT', 30),
],
```

```env
PAYMENT_DRIVER=stripe
STRIPE_KEY=pk_test_...
STRIPE_SECRET=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

---

## Capability matrix

| Method | Stripe API used | Soft decline (`CardException` → response)? | Notes |
|--------|-----------------|--------------------------------------------|-------|
| `charge` | PaymentIntent (`confirm: true`, auto-capture) | Yes | |
| `authorize` | PaymentIntent (`capture_method: manual`) | Yes | Status → `Authorized` |
| `capture` | PaymentIntent capture | No | |
| `void` | PaymentIntent cancel | No | |
| `refund` / `partialRefund` | Refund create | No | Same API; status from cumulative `amount_refunded` |
| `verify` | PaymentIntent retrieve | No | Boolean trust check; no event |
| `lookup` | PaymentIntent retrieve | No | Full status; dispatches `TransactionLookuped` |
| `createPaymentLink` | **Checkout Session** (not Stripe Payment Link resource) | No | `return_url` **required** |
| `createSdkIntent` | Unconfirmed PaymentIntent | No | Returns `client_secret` + publishable key |
| `saveCard` | Customer create + SetupIntent | Yes | Persist `getProviderReference()` (`cus_…`) |
| `chargeToken` | PaymentIntent off-session | Yes | `provider_customer_reference` **required** |
| `createSubscription` | Subscription create | No* | `plan_id` + `provider_customer_reference` **required** |
| `cancelSubscription` | Cancel now **or** `cancel_at_period_end` | No | |
| `processWebhook` | Parse Event JSON | — | Signature verified separately |
| `verifyWebhookSignature` | `Stripe-Signature` HMAC | — | Header name: `stripe-signature` |

\* First-invoice decline surfaces as Subscription `incomplete` in the response, not a thrown `CardException`.

`supports($capability)` always returns `true` (including `'webhook'`).

---

## Status mapping (PaymentIntent)

| Stripe `status` | Framework `PaymentStatus` |
|-----------------|---------------------------|
| `succeeded` | `Captured` |
| `requires_capture` | `Authorized` |
| `requires_action` | `RequiresAction` |
| `processing` / `requires_confirmation` | `Pending` |
| `canceled` | `Cancelled` |
| `requires_payment_method` | `Failed` |
| anything else | `Failed` |

### Subscription status mapping

| Stripe Subscription `status` | Framework |
|------------------------------|-----------|
| `active` | `Captured` |
| `trialing` | `Pending` |
| `incomplete` | `RequiresAction` or `Failed` (from invoice PaymentIntent) |
| `incomplete_expired` | `Expired` |
| `past_due` / `unpaid` | `Failed` † |
| `paused` | `RequiresAction` |
| `canceled` | `Cancelled` |

† Accepted limitation: Stripe keeps retrying `past_due`; framework has no non-terminal “degraded” status.

---

## Method reference

### `charge()` — capture now

Creates and confirms a PaymentIntent.

```php
$response = Payment::driver('stripe')->charge([
    'amount'   => 1000, // cents
    'currency' => 'USD',
    'customer' => ['name' => '…', 'email' => '…'],
    'token'    => 'pm_…',   // PaymentMethod id — NOT under 'payment_method'
    'metadata' => ['order_id' => 123],
]);
```

- Soft decline → `isSuccessful() === false`, no throw.
- 3DS / SCA → `requiresAction()` / `PaymentStatus::RequiresAction`.
- `getTransactionId()` → PaymentIntent id (`pi_…`).
- `getProviderReference()` → `latest_charge` when present.

### `authorize()` / `capture()` / `void()`

```php
$auth = Payment::driver('stripe')->authorize([/* same shape as charge */]);
$id   = $auth->getTransactionId()->toString();

Payment::driver('stripe')->capture(['transaction_id' => $id, 'amount' => 1000]);
// or
Payment::driver('stripe')->void(['transaction_id' => $id, 'reason' => 'Order cancelled']);
```

Authorize uses `capture_method: manual` → status `Authorized` until capture.

### `refund()` / `partialRefund()`

Same Stripe Refund API. Whether the result is `Refunded` vs `PartiallyRefunded` comes from the Charge’s cumulative `amount_refunded`, not from which method you called.

### `verify()` / `lookup()`

Both retrieve the PaymentIntent.

- `verify()` → `isVerified()` boolean (fulfilable?).
- `lookup()` → full `PaymentStatus` + `TransactionLookuped` event.

### `createPaymentLink()` — hosted Checkout

Backed by a **Checkout Session**, not Stripe’s `PaymentLink` resource.

```php
$response = Payment::driver('stripe')->createPaymentLink([
    'amount'      => 10000,
    'currency'    => 'USD',
    'description' => 'Order #123',
    'customer'    => ['name' => '…', 'email' => '…'], // email prefilled; name not sent today
    'return_url'  => url('/payment/success'), // REQUIRED
    'cancel_url'  => url('/payment/cancel'),  // optional
    'metadata'    => ['order_id' => 123],
]);

redirect($response->getPaymentUrl()); // checkout.stripe.com/…
```

`isSuccessful()` here only means the Session/URL was created — not that the customer paid. Confirm via webhook (`checkout.session.completed`) or `lookup()`.

### `createSdkIntent()` — client-side confirm

Creates an **unconfirmed** PaymentIntent and returns `SdkCheckoutResponse` (`client_secret` + publishable `key`). Used by checkout `driver_type: sdk`. No `return_url` required.

### `saveCard()` / `chargeToken()`

```php
$saved = Payment::driver('stripe')->saveCard([
    'token'       => 'pm_…',           // from Stripe.js / Elements
    'customer_id' => (string) $user->id, // YOUR id — not Stripe’s
]);

$cus = $saved->getProviderReference(); // cus_… — PERSIST THIS

$response = Payment::driver('stripe')->chargeToken([
    'token'                       => 'pm_…',
    'amount'                      => 1000,
    'currency'                    => 'USD',
    'customer'                    => ['name' => $user->name, 'email' => $user->email],
    'provider_customer_reference' => $cus, // required
]);
```

Omitting `provider_customer_reference` on `chargeToken()` throws `InvalidArgumentException` before any Stripe call.

### `createSubscription()` / `cancelSubscription()`

```php
$sub = Payment::driver('stripe')->createSubscription([
    'amount'                      => 2000,
    'currency'                    => 'USD',
    'interval'                    => 'monthly',
    'plan_id'                     => 'price_1N…', // required existing Price ID
    'customer'                    => ['name' => '…', 'email' => '…'],
    'provider_customer_reference' => $user->stripe_customer_id, // required
    'token'                       => 'pm_…', // optional — else customer default PM
    'trial_days'                  => 14,
]);

Payment::driver('stripe')->cancelSubscription([
    'subscription_id'      => $sub->getSubscriptionId(),
    'cancel_at_period_end' => true, // false = cancel immediately
]);
```

No ad-hoc/inline pricing — `plan_id` must be a real Stripe Price.

---

## Soft failure vs exception

| Outcome | Behaviour |
|---------|-----------|
| Card declined (`CardException`) on charge / authorize / saveCard / chargeToken | Mapped to unsuccessful `PaymentResponse`, **returned** |
| Bad key, unknown id, network after retries, config mistakes | Mapped via `StripeExceptionMapper`, **thrown** |
| Capture / void / refund / subscription create / link create failures | Thrown (no soft-decline path) |

Always check `isSuccessful()` / `getStatus()` on returned responses.

---

## Events (Stripe-relevant)

| Operation | Events |
|-----------|--------|
| `charge` / `authorize` | `PaymentInitiated` → `PaymentSucceeded` or `PaymentFailed` |
| `capture` | `PaymentCaptured` |
| `void` | `PaymentVoided` |
| `refund` / `partialRefund` | `PaymentRefunded` |
| `lookup` | `TransactionLookuped` |
| `createPaymentLink` | `PaymentLinkCreated` |
| `saveCard` (success) | `CardSaved` |
| `chargeToken` (success) | `TokenCharged` |
| `createSubscription` (success) | `SubscriptionCreated` |
| `cancelSubscription` | `SubscriptionCancelled` |
| Webhooks | `WebhookReceived` / `WebhookProcessed` (framework `WebhookProcessor`, not the driver) |

---

## Webhooks

Route (framework): `POST /payment/webhook/stripe`  
Header verified: **`Stripe-Signature`** (not a generic HMAC header).  
Secret: `STRIPE_WEBHOOK_SECRET` / `payment.drivers.stripe.webhook_secret`.  
Empty secret → verification **fails closed**.

### Mapped event types

| Stripe Event | Framework `WebhookEventType` |
|--------------|------------------------------|
| `checkout.session.completed` (`payment_status === paid`) | `PaymentSucceeded` |
| `checkout.session.completed` (otherwise) | `PaymentActionRequired` |
| `checkout.session.async_payment_succeeded` | `PaymentSucceeded` |
| `checkout.session.async_payment_failed` | `PaymentFailed` |
| `payment_intent.succeeded` | `PaymentSucceeded` |
| `payment_intent.payment_failed` | `PaymentFailed` |
| `payment_intent.canceled` | `PaymentVoided` |
| `payment_intent.amount_capturable_updated` | `PaymentAuthorized` |
| `charge.refunded` | `RefundSucceeded` |
| `charge.dispute.created` | `DisputeOpened` |
| `charge.dispute.closed` | `DisputeResolved` |
| anything else | `Unknown` (log & skip) |

Mapper also lifts `metadata.merchant_order_id` → `rawPayload['merchant_order_id']` and object `id` → `rawPayload['session_id']` when present (used by checkout auto-confirm).

---

## Stripe-specific gotchas

1. **`token` vs `payment_method`** — pass a Stripe PaymentMethod id (`pm_…`) as `token`. `payment_method` is the framework category enum (card, wallet, …), not a Stripe id.
2. **Amounts** — always smallest currency unit (cents for USD).
3. **`createPaymentLink` uses Checkout Sessions** — field names in docs may say “payment link”; the API resource is a Session.
4. **Customer email only** on hosted checkout — `customer.name` is accepted by the DTO but not currently forwarded to Stripe.
5. **Persist `cus_…` from `saveCard()`** — required for `chargeToken` and subscriptions.
6. **Subscriptions need a pre-created Price** — no inline product/price creation in this driver.
7. **Don’t fulfil from `return_url` alone** — confirm via webhook or `lookup()`.
8. **Idempotency** — forwarded to Stripe on mutating calls; omit and the framework generates a UUID.

---

## Source map

| Concern | File |
|---------|------|
| Orchestration | `src/Drivers/Stripe/StripeDriver.php` |
| SDK calls | `src/Drivers/Stripe/StripeClient.php` |
| Response / status / webhook mapping | `src/Drivers/Stripe/StripeMapper.php` |
| Signature verify | `src/Drivers/Stripe/StripeWebhookVerifier.php` |
| Exception mapping | `src/Drivers/Stripe/StripeExceptionMapper.php` |
| Config block | `config/payment.php` → `drivers.stripe` |
| Unit tests | `tests/Unit/Drivers/Stripe/` |
