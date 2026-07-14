<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the payment_transactions table for optional transaction persistence.
 *
 * Publish and run this migration:
 *   php artisan vendor:publish --tag=payment-migrations
 *   php artisan migrate
 *
 * Only required when payment.repository.enabled is true in config.
 */
return new class extends Migration
{
    /**
     * Run the migrations — create the payment_transactions table.
     */
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table): void {
            // Primary key
            $table->bigIncrements('id');

            // Provider-assigned transaction identifier (unique per provider)
            $table->string('transaction_id')->unique();

            // The driver/provider that processed this transaction (e.g. "stripe")
            $table->string('driver');

            // Host application references (nullable — not all payments have orders/customers)
            $table->string('order_id')->nullable()->index();
            $table->string('customer_id')->nullable()->index();

            // Monetary amount in the smallest currency unit (e.g. cents)
            $table->integer('amount');

            // ISO 4217 currency code (e.g. "USD", "SAR")
            $table->string('currency', 3);

            // Canonical payment status (PaymentStatus enum value)
            $table->string('status');

            // Payment method used (PaymentMethod enum value)
            $table->string('payment_method');

            // Arbitrary application metadata (JSON)
            $table->json('metadata')->nullable();

            // Full provider API response for debugging (JSON)
            $table->json('raw_response')->nullable();

            // Idempotency key from the original request
            $table->string('idempotency_key')->nullable()->index();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations — drop the payment_transactions table.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
