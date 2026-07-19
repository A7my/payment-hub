<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Mifatoyeh\LaravelPaymentFramework\Webhooks\WebhookController;

/**
 * Payment Framework Webhook Routes
 *
 * Registers a single provider-agnostic webhook endpoint:
 *   GET|POST /payment/webhook/{driver}
 *
 * Both verbs are registered — see WebhookController's own docblock for why:
 * most providers POST a JSON body (Stripe), but Paymob's classic
 * "Transaction Processed Callback" is a GET request with every field
 * (including the HMAC) flattened into the query string.
 *
 * The {driver} path parameter routes the webhook to the correct driver
 * without requiring separate routes per provider.
 *
 * The route prefix and middleware are configurable via payment.webhook.prefix
 * and payment.webhook.middleware. Disable auto-registration by setting
 * payment.webhook.enabled to false.
 *
 * This file is loaded by PaymentServiceProvider::boot() when enabled.
 */
Route::middleware(config('payment.webhook.middleware', ['api']))
    ->match(
        ['get', 'post'],
        config('payment.webhook.prefix', 'payment/webhook') . '/{driver}',
        [WebhookController::class, 'handle']
    )
    ->name('payment.webhook');
