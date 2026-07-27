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
    'base_url'          => env('MYFATOORAH_BASE_URL'), // optional
    'sandbox'           => env('PAYMENT_SANDBOX', true),
    'timeout'           => (int) env('PAYMENT_TIMEOUT', 30),
],
```

```env
PAYMENT_DRIVER=myfatoorah
MYFATOORAH_API_KEY=...
MYFATOORAH_WEBHOOK_SECRET=...
MYFATOORAH_PAYMENT_METHOD_ID=2
# MYFATOORAH_BASE_URL=https://api-sa.myfatoorah.com   # optional regional live host
```

| Environment | Default `base_url` |
|-------------|--------------------|
| Sandbox (`sandbox=true`) | `https://apitest.myfatoorah.com` |
| Live | `https://api.myfatoorah.com` (override for `api-sa` / `api-eg` / `api-ae` / …) |

Auth on every call: `Authorization: Bearer {api_key}`.

### Troubleshooting HTTP 401

MyFatoorah often returns **401 with an empty body** when the token is wrong
for the host being called. Their own sample code treats that as “API key is
not correct”.

1. Confirm `.env` has a real token (not empty / not still a placeholder):
   ```
   MYFATOORAH_API_KEY=...long portal token...
   ```
2. Match **token environment** to **host**:
   | Token from | Set |
   |------------|-----|
   | Test / demo portal (`demo.myfatoorah.com`) | `PAYMENT_SANDBOX=true` → `apitest.myfatoorah.com` |
   | Live Kuwait/etc. | `PAYMENT_SANDBOX=false` |
   | Live Saudi Arabia | `PAYMENT_SANDBOX=false` + `MYFATOORAH_BASE_URL=https://api-sa.myfatoorah.com` |
   | Live Egypt | `PAYMENT_SANDBOX=false` + `MYFATOORAH_BASE_URL=https://api-eg.myfatoorah.com` |
3. In the portal, open the API key and ensure it is **Active** and has
   **Create Payments** (or Super Rules) permission.
4. After changing `.env`: `php artisan config:clear` (and restart Octane/queue
   workers if you use them).
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
