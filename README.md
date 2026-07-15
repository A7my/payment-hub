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

## Usage

```php
use Mifatoyeh\LaravelPaymentFramework\Facades\Payment;

$response = Payment::driver('stripe')->charge([
    'amount'   => 1000, // smallest currency unit — 1000 = $10.00
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
}
```

Array input is optional — the framework's DTOs (`PaymentRequest`, etc.) work
exactly the same way if you prefer building them directly.

## Status

This package is under active development. Driver method support, per
provider:

| Driver | charge() | authorize() | void() | capture() | refund() | others |
|---|---|---|---|---|---|---|
| Stripe | ✅ | ✅ | ✅ | 🚧 | 🚧 | 🚧 |
| PayPal | — | — | — | — | — | planned, not started |
| Paymob | — | — | — | — | — | planned, not started |
| MyFatoorah | — | — | — | — | — | planned, not started |

✅ implemented and tested · 🚧 not yet implemented (throws until it is)

## License

MIT
