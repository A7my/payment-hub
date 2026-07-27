# Paymob Driver Reference

Provider-specific reference for `PaymobDriver` (`src/Drivers/Paymob/`).  
Public calling API is the same for every driver — only what happens behind each method is Paymob-specific here.

Use with [`README.md`](README.md) (generic API), [`CHECKOUT.md`](CHECKOUT.md) (checkout / confirm), and [`STRIPE.md`](STRIPE.md) (parallel Stripe reference).

> **UNVERIFIED against live Paymob docs.** Paymob has no official PHP SDK; this driver was built from Accept / Intention API knowledge and live error debugging. Treat as less battle-tested than Stripe.

---

## Architecture

```
Payment::driver('paymob')->charge(...)
        │
        ▼
┌──────────────────┐
│  PaymobDriver    │  orchestration (idempotency, log, events, retry)
└────────┬─────────┘
         │
    ┌────┴────┬──────────────────┬────────────────────┐
    ▼         ▼                  ▼                    ▼
PaymobClient  PaymobMapper  PaymobWebhookVerifier  PaymobExceptionMapper
  (HTTP I/O)  (payload→DTO) (HMAC-SHA512)          (API errors→framework)
```

Collaborators are constructed inside `PaymobDriver` (same pattern as Stripe).

Implements: `PaymentDriverContract`, `SupportsSdkCheckout`, `SupportsCapabilities`, `SupportsTrustedWebhookStatus` (KSA only).

---

## Config

```php
// config/payment.php → drivers.paymob
'paymob' => [
    'class'          => PaymobDriver::class,
    'api_key'        => env('PAYMOB_API_KEY'),        // Egypt/Accept token exchange
    'secret_key'     => env('PAYMOB_SECRET_KEY'),     // KSA Bearer (sau_sk_test_/sau_sk_live_)
    'public_key'     => env('PAYMOB_PUBLIC_KEY'),     // KSA unified checkout
    'integration_id' => env('PAYMOB_INTEGRATION_ID'),
    'iframe_id'      => env('PAYMOB_IFRAME_ID'),      // Egypt hosted iframe
    'hmac_secret'    => env('PAYMOB_HMAC_SECRET'),
    'base_url'       => env('PAYMOB_BASE_URL', 'https://accept.paymob.com/api'),
    'sandbox'        => env('PAYMENT_SANDBOX', true),
    'timeout'        => (int) env('PAYMENT_TIMEOUT', 30),
],
```

### Egypt vs KSA mode

KSA mode activates when `base_url` contains `ksa.paymob.com` **or** `secret_key` starts with `sau_sk_test_` / `sau_sk_live_`:

| | Egypt / Accept | KSA |
|--|----------------|-----|
| Auth | `POST /auth/tokens` with `api_key` → body `auth_token` | `Authorization: Bearer <secret_key>` (no `/auth/tokens`) |
| Hosted checkout | Order → payment key → iframe URL | Intention API → unified checkout URL |
| Status after webhook | Live `lookup()` | Trusted HMAC-verified payload (`SupportsTrustedWebhookStatus`) |

---

## Checkout confirmation (Stripe-aligned)

| `driver_type` | Confirmation path | Paymob wiring |
|---------------|-------------------|---------------|
| **`webview`** | Package **callback** route | KSA: Intention `redirection_url` = package callback. Egypt: dashboard **Transaction Response Callback** → `/payment/checkout/callback/paymob` |
| **`sdk`** | Package **webhook** route | KSA: Intention `notification_url` = package webhook. Egypt: dashboard **Transaction Processed Callback** → `/payment/webhook/paymob` |

```
webview:  Paymob redirect → /payment/checkout/callback/paymob → resolveAndConfirm('callback')
          → (os=web) redirect to your return_url
          → (os=mobile) JSON response

sdk:      Paymob server → /payment/webhook/paymob → confirmFromWebhook → resolveAndConfirm('webhook')
```

**Important:** a webhook for a pending **webview** Paymob row is ignored (HTTP 200, no confirm). Webview is callback-only — same UX goal as Stripe’s hosted checkout callback, without dual confirmation.

### Dashboard URLs to set

```
# Browser redirect after hosted pay (webview / Egypt)
https://your-app.com/payment/checkout/callback/paymob

# Server-to-server (sdk — and optional noise on webview that is ignored)
https://your-app.com/payment/webhook/paymob
```

Set `PAYMOB_HMAC_SECRET` for both paths — signature verification fails closed without it.

---

## Capability matrix

| Method | Support | Notes |
|--------|---------|-------|
| `charge` / `authorize` / `void` / `capture` / `refund` / `partialRefund` | ✅ | Tokenised cards only — never raw PAN |
| `verify` / `lookup` | ✅ | Egypt live retrieve; KSA retrieve may 404/401 |
| `saveCard` / `chargeToken` | ✅ | |
| `createPaymentLink` | ✅ | Webview hosted checkout |
| `createSdkIntent` | ✅ | Returns payment key / Intention `client_secret` |
| `createSubscription` / `cancelSubscription` | 🚫 | Permanently unsupported |
| `processWebhook` / `verifyWebhookSignature` | ⚠️ | Implemented; verify HMAC against real dashboard secret before production |

`supports('subscription')` → `false`. Everything else (including `'webhook'`) → `true`.

---

## Method notes

### `charge()` / `authorize()` / `chargeToken()`

Always send a Paymob-issued **token** (`token` field), never raw card data. First-time cards should go through `createPaymentLink()` (hosted UI).

### `createPaymentLink()` — webview

```php
$response = Payment::driver('paymob')->createPaymentLink([
    'amount'      => 10000,
    'currency'    => 'EGP', // or SAR for KSA
    'description' => 'Order #123',
    'customer'    => ['name' => '…', 'email' => '…'],
    'return_url'  => url('/payment/checkout/callback/paymob?merchant_order_id=…'), // CheckoutService sets this
]);

redirect($response->getPaymentUrl());
```

- Egypt: iframe URL from payment key.
- KSA: `https://ksa.paymob.com/unifiedcheckout/?publicKey=…&clientSecret=…`  
  Intention body includes `redirection_url` when `return_url` is set; **no** `notification_url`.

### `createSdkIntent()` — sdk

Returns `SdkCheckoutResponse` (`client_secret` / payment key + optional publishable key).  
Intention (KSA) gets `notification_url` = package webhook route; **no** `redirection_url`.

### Subscriptions

Always throw `UnsupportedOperationException` — Paymob has no Stripe-like Subscription API in this driver.

---

## Webhooks / callbacks

Route (framework): `GET|POST /payment/webhook/paymob`  
Paymob’s classic callback is a **GET** with flat query params + `hmac`.

HMAC: `PaymobWebhookVerifier` over configured field order + `hmac_secret`.

### Checkout correlation

Both callback and webhook need:

- `merchant_order_id` — package idempotency key (Egypt order / Intention `special_reference`)
- `id` — Paymob transaction id

### Trusted status (KSA)

When HMAC verifies, `statusFromWebhookPayload()` maps the flat payload to `StatusResponse` without `lookup()`. Egypt always re-checks via `lookup()`.

---

## Soft failure vs exception

Recoverable declines return unsuccessful responses where applicable. API / auth / network failures throw via `PaymobExceptionMapper` (`PaymobApiException` and framework subclasses).

---

## Source map

| Concern | File |
|---------|------|
| Orchestration | `src/Drivers/Paymob/PaymobDriver.php` |
| HTTP / Intention / Egypt Accept | `src/Drivers/Paymob/PaymobClient.php` |
| Response / status mapping | `src/Drivers/Paymob/PaymobMapper.php` |
| HMAC verify | `src/Drivers/Paymob/PaymobWebhookVerifier.php` |
| Exception mapping | `src/Drivers/Paymob/PaymobExceptionMapper.php` |
| Webview skip on webhook | `CheckoutService::confirmFromWebhook()` |
| Config | `config/payment.php` → `drivers.paymob` |
| Tests | `tests/Unit/Drivers/Paymob/`, `tests/Feature/PaymobWebhookCheckoutTest.php` |
