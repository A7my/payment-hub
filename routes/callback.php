<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Mifatoyeh\LaravelPaymentFramework\Checkout\CheckoutCallbackController;

/**
 * Generic Payment Framework Callback Route
 *
 * Registers a single, package-owned, per-driver callback endpoint:
 *   GET|POST {payment.checkout.route}/callback/{driver}
 *
 * A single parameterized route, not one entry per driver — exactly the
 * same shape `routes/webhooks.php` already uses for `{driver}`. A new
 * driver gets a working callback route automatically just by being
 * registered in `payment.drivers`; nothing here needs updating.
 * `PaymentManager::driver($driver)` throws `DriverNotFoundException` for an
 * unknown name at request time, same safety net the webhook route already
 * relies on — no separate driver enumeration/allowlist needed here either.
 *
 * Both GET and POST are accepted because providers disagree on how they
 * redirect: Stripe's Checkout Session redirect is a browser GET; a future
 * driver might POST instead — same reasoning as the webhook route.
 *
 * Deliberately its OWN middleware config — NOT `payment.checkout.middleware`
 * (default `['web', 'auth']`), which assumes an authenticated frontend
 * session that does not exist here (the caller is a provider's redirect or
 * server, never the logged-in user), and whose 'web' group's CSRF
 * middleware would reject a provider's POST outright, the same class of
 * problem already hit on the plain checkout route. Default is deliberately
 * light — no 'web'/'auth' — override via `payment.checkout.callback_middleware`.
 *
 * This file is loaded by PaymentServiceProvider::boot() when
 * payment.checkout.enabled is true — same flag as routes/checkout.php,
 * since this route only exists to serve checkout()'s own webview flow.
 */
Route::middleware(config('payment.checkout.callback_middleware', []))
    ->match(
        ['get', 'post'],
        config('payment.checkout.route', 'payment/checkout') . '/callback/{driver}',
        [CheckoutCallbackController::class, 'handle']
    )
    ->name('payment.checkout.callback');
