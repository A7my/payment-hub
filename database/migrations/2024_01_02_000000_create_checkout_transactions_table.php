<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the checkout_transactions table — persistence for the generic
 * checkout endpoint (`CheckoutService::confirm()`), distinct from the
 * older, generic `payment_transactions` table (see that migration's own
 * docblock): this one is shaped around a `Payable` model reference
 * (model_type + model_id, matching the `payment.payables` allowlist key)
 * rather than a bare order/customer id pair, and is written unconditionally
 * by `confirm()` (gated only by `payment.checkout.persist_transactions`),
 * not behind the separate `payment.repository.enabled` flag.
 *
 * One row exists per (driver, model_type, model_id) — created in a
 * "pending" state by `CheckoutService::checkout()` the moment a driver
 * order/intent is created, then updated in place (never duplicated) once
 * the outcome is authoritatively known, either via an explicit
 * `POST {route}/confirm` call or automatically via
 * `CheckoutService::confirmFromWebhook()` (see that method's own docblock
 * for how an inbound Paymob webhook — which never carries model_type/
 * model_id itself — gets correlated back to this row via `merchant_order_id`).
 *
 * Publish and run this migration:
 *   php artisan vendor:publish --tag=payment-migrations
 *   php artisan migrate
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_transactions', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // The payment.payables allowlist key (e.g. "order"), not the raw class name.
            $table->string('model_type');
            $table->string('model_id');

            // The driver that processed this checkout (e.g. "stripe", "paymob").
            $table->string('driver');

            // "sdk" or "webview" — informational only, not required for lookup().
            $table->string('driver_type')->nullable();

            // The idempotency key checkout() generated and forwarded to the
            // provider as its own order/merchant reference — the only thing
            // an inbound webhook (which knows nothing about model_type/
            // model_id) can correlate back to this row by. Set at checkout()
            // time; unrelated to transaction_reference below.
            $table->string('merchant_order_id')->nullable();

            // Provider-assigned transaction identifier — null while pending
            // (set once confirm()/confirmFromWebhook() knows it).
            $table->string('transaction_reference')->nullable();

            // Canonical payment status (PaymentStatus enum value) — "pending"
            // from checkout() until a confirmation updates it.
            $table->string('status');
            $table->boolean('successful');

            // Amount/currency captured from the Payable model.
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);

            $table->string('message')->nullable();
            $table->json('raw_response')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['model_type', 'model_id']);
            $table->index(['driver', 'merchant_order_id']);

            // One row per (driver, model_type, model_id) — both the pending
            // insert from checkout() and every later confirmation upsert
            // into the SAME row via this key, rather than accumulating a
            // duplicate per attempt/re-confirmation.
            $table->unique(['driver', 'model_type', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_transactions');
    }
};
