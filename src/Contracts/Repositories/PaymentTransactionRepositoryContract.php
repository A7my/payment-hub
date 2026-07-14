<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Contracts\Repositories;

use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentRequest;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentResponse;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\CustomerId;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\OrderId;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;

/**
 * Contract for optional payment transaction persistence.
 *
 * Two implementations are provided:
 *   - EloquentPaymentTransactionRepository  — persists to payment_transactions table
 *   - NullPaymentTransactionRepository      — discards all calls (default when disabled)
 *
 * The active implementation is selected by the payment.repository.enabled config flag.
 */
interface PaymentTransactionRepositoryContract
{
    /**
     * Persist a completed payment transaction record.
     *
     * @param PaymentResponse $response The standardised payment response.
     * @param PaymentRequest  $request  The original payment request DTO.
     */
    public function store(PaymentResponse $response, PaymentRequest $request): void;

    /**
     * Find a transaction record by its provider-assigned transaction identifier.
     *
     * @param TransactionId $id The transaction identifier to search for.
     *
     * @return array<string, mixed>|null The transaction data, or null if not found.
     */
    public function findByTransactionId(TransactionId $id): ?array;

    /**
     * Find all transaction records associated with a given order identifier.
     *
     * @param OrderId $id The order identifier to search for.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByOrderId(OrderId $id): array;

    /**
     * Find all transaction records associated with a given customer identifier.
     *
     * @param CustomerId $id The customer identifier to search for.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByCustomerId(CustomerId $id): array;
}
