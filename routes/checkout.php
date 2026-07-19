<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Mifatoyeh\LaravelPaymentFramework\Checkout\CheckoutController;

/**
 * Generic Payment Framework Checkout Routes
 *
 * Registers two package-owned endpoints against any model registered in
 * payment.payables:
 *   POST {route}          — start a payment (webview or sdk)
 *   POST {route}/confirm  — authoritatively confirm its outcome
 *
 * A consuming application does not need its own route/controller for this —
 * it only needs to implement Payable on a model and register it in the
 * payment.payables config map.
 *
 * The route path and middleware are configurable via payment.checkout.route
 * and payment.checkout.middleware. Disable auto-registration by setting
 * payment.checkout.enabled to false (e.g. to register the routes yourself
 * with different middleware).
 *
 * This file is loaded by PaymentServiceProvider::boot() when enabled.
 */
Route::middleware(config('payment.checkout.middleware', ['web', 'auth']))->group(function (): void {
    $route = config('payment.checkout.route', 'payment/checkout');

    Route::post($route, [CheckoutController::class, 'handle'])->name('payment.checkout');
    Route::post($route . '/confirm', [CheckoutController::class, 'confirm'])->name('payment.checkout.confirm');
});
