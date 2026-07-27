# MyFatoorah Driver Reference

Provider-specific reference for `MyFatoorahDriver` (`src/Drivers/MyFatoorah/`).  
Public calling API matches Stripe/Paymob — only the provider wiring differs.

Use with [`README.md`](README.md), [`CHECKOUT.md`](CHECKOUT.md), [`STRIPE.md`](STRIPE.md), [`PAYMOB.md`](PAYMOB.md).

---

## Architecture

```
Payment::driver('myfatoorah')->createPaymentLink(...)
        │
        ▼
┌────────────────────┐
│  MyFatoorahDriver  │  orchestration
└─────────┬──────────┘
          │
     ┌────┴────┬──────────────────┬────────────────────┐
     ▼         ▼                  ▼                    ▼
MyFatoorahClient  Mapper   WebhookVerifier      ExceptionMapper
  (REST v2)     (DTO)    (MyFatoorah-Signature)  (HTTP→framework)
```

Implements: `PaymentDriverContract`, `SupportsSdkCheckout`, `SupportsCapabilities`.

---

## Config

```php
'myfatoorah' => [
    'class'             => MyFatoorahDriver::class,
    'api_key'           => env('MYFATOORAH_API_KEY'),
    'webhook_secret'    => env('MYFATOORAH_WEBHOOK_SECRET'),
    'payment_method_id' => (int) env('MYFATOORAH_PAYMENT_METHOD_ID', 2),
    'country_code'      => env('MYFATOORAH_COUNTRY_CODE'), // SAU, KWT, … (official `countryCode`)
    'base_url'          => env('MYFATOORAH_BASE_URL'), // optional override
    'sandbox'           => env('PAYMENT_SANDBOX', true), // official `isTest`
    'timeout'           => (int) env('PAYMENT_TIMEOUT', 30),
],
```

Map from the official MyFatoorah Laravel package:

| Official config | This package |
|-----------------|--------------|
| `apiKey` / `myfatoorah.api_key` | `MYFATOORAH_API_KEY` |
| `isTest` / `myfatoorah.test_mode` | `PAYMENT_SANDBOX` |
| `countryCode` / `myfatoorah.country_iso` | `MYFATOORAH_COUNTRY_CODE` |

```env
PAYMENT_DRIVER=myfatoorah
MYFATOORAH_API_KEY=...          # same value as myfatoorah.api_key
PAYMENT_SANDBOX=false           # same as myfatoorah.test_mode=false for live
MYFATOORAH_COUNTRY_CODE=SAU     # same as myfatoorah.country_iso (Saudi → api-sa)
MYFATOORAH_WEBHOOK_SECRET=...
MYFATOORAH_PAYMENT_METHOD_ID=2
```

| Environment | Resolved host |
|-------------|----------------|
| Sandbox (`PAYMENT_SANDBOX=true`) | `https://apitest.myfatoorah.com` |
| Live + `SAU` | `https://api-sa.myfatoorah.com` |
| Live + `ARE` / `QAT` / `EGY` | `api-ae` / `api-qa` / `api-eg` |
| Live + `KWT` / `BHR` / `OMN` / `JOR` | `https://api.myfatoorah.com` |
| Explicit `MYFATOORAH_BASE_URL` | that URL (wins over country) |

Auth on every call: `Authorization: Bearer {api_key}`.

### Troubleshooting HTTP 401

MyFatoorah often returns **401 with an empty body** when the token is wrong
for the host being called (their samples treat that as “API key is not correct”).

Your working app calls `api-sa.myfatoorah.com` for Saudi live via `countryCode`.
This package previously defaulted sandbox → `apitest`, which rejects a **live SAU**
token with exactly that 401.

1. Copy the **same three values** from the working app into `.env`.
2. For Saudi live (SAR): `PAYMENT_SANDBOX=false` + `MYFATOORAH_COUNTRY_CODE=SAU`.
3. Ensure the portal key is Active and has Create Payments permission.
4. `php artisan config:clear` (restart Octane/queue workers if used).
5. Do **not** put `Bearer ` in the env value — the driver adds it.

---

## Capability matrix

| Method | Status | MyFatoorah API |
|--------|--------|----------------|
| `createPaymentLink` | ✅ | `POST /v2/SendPayment` → `InvoiceURL` |
| `createSdkIntent` | ✅ | `POST /v2/ExecutePayment` → `PaymentURL` as `client_secret` |
| `charge` / `authorize` | ✅ | `ExecutePayment` (`AutoCapture` true/false) → usually `RequiresAction` until paid |
| `capture` / `void` | ✅ | `UpdatePaymentStatus` Capture / Release |
| `refund` / `partialRefund` | ✅ | `MakeRefund` |
| `verify` / `lookup` | ✅ | `GetPaymentStatus` |
| `saveCard` / `chargeToken` | 🚫 | Unsupported |
| `createSubscription` / `cancelSubscription` | 🚫 | Unsupported |
| Webhooks | ⚠️ | Webhook v2 + `MyFatoorah-Signature` |

Amounts are converted from framework **minor units** → MyFatoorah **major** decimals via `Money::toDecimalString()`.

---

## Checkout confirmation (same as Paymob)

| `driver_type` | Confirms via | Wiring |
|---------------|--------------|--------|
| **webview** | Package **callback** | `CallBackUrl` / `ErrorUrl` = package callback URL. Webhook ignored for pending webview rows. |
| **sdk** | Package **webhook** | `WebhookUrl` = `/payment/webhook/myfatoorah` |

Correlation:

- Outbound: `CustomerReference` + `UserDefinedField` = checkout idempotency key (`merchant_order_id`)
- Inbound callback: MyFatoorah appends `paymentId`; package already has `merchant_order_id` on the URL
- Inbound webhook v2: flattened to `merchant_order_id` + `id` / `paymentId` for `resolveAndConfirm()`

### Dashboard

```
Webhook URL → https://your-app.com/payment/webhook/myfatoorah
```

Enable Webhook v2 + secret key. Per-invoice `WebhookUrl` on sdk ExecutePayment also points here.

---

## Webhook signature (v2)

Header: `MyFatoorah-Signature`  
Algorithm: HMAC-SHA256 over a canonical `Key=Value,…` string (not the raw body), then Base64.

`PAYMENT_STATUS_CHANGED` field order:

```
Invoice.Id, Invoice.Status, Transaction.Status, Transaction.PaymentId, Invoice.ExternalIdentifier
```

Null values → empty string.

---

## Status mapping (`GetPaymentStatus`)

| MyFatoorah `InvoiceStatus` | Framework |
|----------------------------|-----------|
| `Paid` | `Captured` |
| `Pending` | From last `InvoiceTransactions[].TransactionStatus` (`Succss` → Captured, etc.) |
| `Canceled` | `Cancelled` |
| `Expired` | `Expired` |

Note: MyFatoorah documents successful transaction status as **`Succss`** (their spelling).

---

## Source map

| Concern | File |
|---------|------|
| Orchestration | `src/Drivers/MyFatoorah/MyFatoorahDriver.php` |
| HTTP | `src/Drivers/MyFatoorah/MyFatoorahClient.php` |
| Mapping | `src/Drivers/MyFatoorah/MyFatoorahMapper.php` |
| Signature | `src/Drivers/MyFatoorah/MyFatoorahWebhookVerifier.php` |
| Exceptions | `src/Drivers/MyFatoorah/MyFatoorahExceptionMapper.php` |
| Config | `config/payment.php` → `drivers.myfatoorah` |
| Tests | `tests/Unit/Drivers/MyFatoorah/` |
