<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Repositories;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for the payment_transactions table.
 *
 * Used by EloquentPaymentTransactionRepository to persist and query
 * payment transaction records. Only available when the optional
 * payment.repository.enabled config flag is true.
 *
 * Table: payment_transactions
 */
class PaymentTransaction extends Model
{
    /** @var string The database table name. */
    protected $table = 'payment_transactions';

    /** @var array<int, string> The mass-assignable attributes. */
    protected $fillable = [
        'transaction_id',
        'driver',
        'order_id',
        'customer_id',
        'amount',
        'currency',
        'status',
        'payment_method',
        'metadata',
        'raw_response',
        'idempotency_key',
    ];

    /** @var array<string, string> Attribute casting map. */
    protected $casts = [
        'metadata'     => 'array',
        'raw_response' => 'array',
        'amount'       => 'integer',
    ];

    /**
     * Scope: filter by transaction ID.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $transactionId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByTransactionId($query, string $transactionId)
    {
        // TODO: return $query->where('transaction_id', $transactionId);
        return $query;
    }

    /**
     * Scope: filter by order ID.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $orderId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByOrderId($query, string $orderId)
    {
        // TODO: return $query->where('order_id', $orderId);
        return $query;
    }

    /**
     * Scope: filter by customer ID.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $customerId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByCustomerId($query, string $customerId)
    {
        // TODO: return $query->where('customer_id', $customerId);
        return $query;
    }
}
