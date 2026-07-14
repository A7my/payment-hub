<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Repositories;

use Mifatoyeh\LaravelPaymentFramework\Contracts\Repositories\PaymentTransactionRepositoryContract;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentRequest;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentResponse;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\CustomerId;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\OrderId;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;

/**
 * No-op repository that silently discards all persistence calls.
 *
 * Bound as the default PaymentTransactionRepositoryContract implementation
 * when payment.repository.enabled is false. This means the framework never
 * forces a database schema on the host application unless opted in.
 *
 * Uses the Null Object Pattern to avoid conditionals in callers.
 */
final class NullPaymentTransactionRepository implements PaymentTransactionRepositoryContract
{
    /** {@inheritDoc} */
    public function store(PaymentResponse $response, PaymentRequest $request): void
    {
        // Intentionally discards all persistence calls.
    }

    /** {@inheritDoc} */
    public function findByTransactionId(TransactionId $id): ?array
    {
        // Intentionally returns null — no persistence layer active.
        return null;
    }

    /** {@inheritDoc} */
    public function findByOrderId(OrderId $id): array
    {
        // Intentionally returns empty array — no persistence layer active.
        return [];
    }

    /** {@inheritDoc} */
    public function findByCustomerId(CustomerId $id): array
    {
        // Intentionally returns empty array — no persistence layer active.
        return [];
    }
}
