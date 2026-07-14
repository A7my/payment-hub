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
 * Eloquent-backed implementation of the payment transaction repository.
 *
 * Bound as the active PaymentTransactionRepositoryContract implementation
 * when payment.repository.enabled is true. The PaymentServiceProvider
 * registers a PaymentSucceeded listener that calls store() automatically.
 *
 * All methods delegate to the PaymentTransaction Eloquent model.
 */
final class EloquentPaymentTransactionRepository implements PaymentTransactionRepositoryContract
{
    /**
     * @param PaymentTransaction $model The Eloquent model for payment_transactions.
     */
    public function __construct(
        private readonly PaymentTransaction $model,
    ) {
    }

    /** {@inheritDoc} */
    public function store(PaymentResponse $response, PaymentRequest $request): void
    {
        // TODO: $this->model->create([
        //     'transaction_id'  => $response->getTransactionId()->toString(),
        //     'driver'          => config('payment.default'),
        //     'order_id'        => $request->order?->orderId->toString(),
        //     'customer_id'     => $request->customer->externalId,
        //     'amount'          => $response->getAmount()->amount,
        //     'currency'        => $response->getAmount()->currency->value,
        //     'status'          => $response->getStatus()->value,
        //     'payment_method'  => $request->paymentMethod->value,
        //     'metadata'        => $request->metadata,
        //     'raw_response'    => $response->getRawResponse(),
        //     'idempotency_key' => $request->idempotencyKey,
        // ]);
        throw new \LogicException('EloquentPaymentTransactionRepository::store() not yet implemented.');
    }

    /** {@inheritDoc} */
    public function findByTransactionId(TransactionId $id): ?array
    {
        // TODO: return $this->model->byTransactionId($id->toString())->first()?->toArray();
        throw new \LogicException('EloquentPaymentTransactionRepository::findByTransactionId() not yet implemented.');
    }

    /** {@inheritDoc} */
    public function findByOrderId(OrderId $id): array
    {
        // TODO: return $this->model->byOrderId($id->toString())->get()->toArray();
        throw new \LogicException('EloquentPaymentTransactionRepository::findByOrderId() not yet implemented.');
    }

    /** {@inheritDoc} */
    public function findByCustomerId(CustomerId $id): array
    {
        // TODO: return $this->model->byCustomerId($id->toString())->get()->toArray();
        throw new \LogicException('EloquentPaymentTransactionRepository::findByCustomerId() not yet implemented.');
    }
}
