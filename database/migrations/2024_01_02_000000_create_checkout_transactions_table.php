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

            // Provider-assigned transaction identifier, as returned by lookup().
            $table->string('transaction_reference');

            // Canonical payment status (PaymentStatus enum value) at last confirmation.
            $table->string('status');
            $table->boolean('successful');

            // Amount/currency captured from the Payable model at confirmation time.
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);

            $table->string('message')->nullable();
            $table->json('raw_response')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['model_type', 'model_id']);

            // A confirm() call is expected to be re-triggerable (double-submit,
            // client retry) — this uniqueness constraint is what makes
            // CheckoutService::persistTransaction() an upsert (updateOrCreate)
            // rather than an ever-growing duplicate-row log per confirmation.
            $table->unique(['driver', 'transaction_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_transactions');
    }
};
