<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Checkout;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for the checkout_transactions table.
 *
 * Written by {@see CheckoutService::checkout()} (a PENDING row, the moment
 * a provider order/intent exists) and updated in place by every
 * confirmation path (`confirm()`, the callback route, a webhook,
 * `VerifyPaymentJob`, the reconciliation sweep) once the outcome is
 * authoritatively known. Persistence is gated by
 * `payment.checkout.persist_transactions` (default true); see
 * {@see CheckoutService::persistTransaction()}.
 *
 * See the migration's docblock for why this is a separate table from the
 * older, generic `payment_transactions` table.
 */
final class CheckoutTransaction extends Model
{
    protected $table = 'checkout_transactions';

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'successful'   => 'boolean',
        'amount'       => 'integer',
        'raw_response' => 'array',
        'metadata'     => 'array',
    ];

    /** @param Builder<CheckoutTransaction> $query */
    public function scopeForPayable(Builder $query, string $modelType, string $modelId): Builder
    {
        return $query->where('model_type', $modelType)->where('model_id', $modelId);
    }
}
