<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Mifatoyeh\LaravelPaymentFramework\Webhooks\WebhookController;

/**
 * Payment Framework Webhook Routes
 *
 * Registers a single provider-agnostic webhook endpoint:
 *   POST /payment/webhook/{driver}
 *
 * The {driver} path parameter routes the webhook to the correct driver
 * without requiring separate routes per provider.
 *
 * The route prefix is configurable via payment.webhook.prefix.
 * Disable auto-registration by setting payment.webhook.enabled to false.
 *
 * This file is loaded by PaymentServiceProvider::boot() when enabled.
 */
Route::post(
    config('payment.webhook.prefix', 'payment/webhook') . '/{driver}',
    [WebhookController::class, 'handle']
)->name('payment.webhook');
