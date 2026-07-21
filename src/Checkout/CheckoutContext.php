<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Checkout;

use JsonSerializable;

/**
 * Everything about HOW a checkout was initiated, passed alongside
 * {@see \Mifatoyeh\LaravelPaymentFramework\Responses\StatusResponse} to
 * {@see \Mifatoyeh\LaravelPaymentFramework\Contracts\Payable::onPaymentCompleted()}.
 *
 * Exists because `onPaymentCompleted()` can fire from five different places
 * — a client `confirm()` call, the callback route, a webhook, `VerifyPaymentJob`,
 * the reconciliation sweep — and only ONE of them (`checkout()` itself) ever
 * runs inside a real authenticated HTTP request. By the time any of the
 * others fire, there is no `auth()->user()`/session to read — the payer has
 * to have been captured up front and carried forward. That's what this is:
 * a snapshot of `checkout()`'s own inputs, persisted on the pending
 * {@see CheckoutTransaction} row at creation time and read back out here.
 *
 * Deliberately built from SERVER-resolved data only:
 * - `payerId` comes from `Authenticatable::getAuthIdentifier()` at
 *   `checkout()` time — the already-authenticated session, never client
 *   request input. There is no way to pass an arbitrary "who paid this"
 *   value through this object; that would let a client claim to be paying
 *   on behalf of anyone.
 * - Everything else (`driver`, `driverType`, `os`, `merchantOrderId`) is
 *   likewise decided server-side by `checkout()`, not client-suppliable
 *   beyond the already-validated `driver`/`driver_type`/`os` request fields.
 *
 * `payerId` and `os` are `null` when `payment.checkout.persist_transactions`
 * is disabled (no row to read them back from) or, for `payerId`, when the
 * original checkout request itself was unauthenticated.
 */
final readonly class CheckoutContext implements JsonSerializable
{
    public function __construct(
        public ?string $payerId,
        public string $driver,
        public ?string $driverType,
        public ?string $os,
        public ?string $merchantOrderId,
    ) {
    }

    /**
     * Build from a persisted {@see CheckoutTransaction} row — the normal case.
     */
    public static function fromTransaction(CheckoutTransaction $transaction): self
    {
        $metadata = (array) ($transaction->metadata ?? []);

        return new self(
            payerId: isset($metadata['payer_id']) ? (string) $metadata['payer_id'] : null,
            driver: $transaction->driver,
            driverType: $transaction->driver_type,
            os: isset($metadata['os']) ? (string) $metadata['os'] : null,
            merchantOrderId: $transaction->merchant_order_id,
        );
    }

    /**
     * Build a partial context when there's no persisted row to read from
     * (`payment.checkout.persist_transactions` disabled) — everything
     * that's only ever known via persistence comes back `null`; `driver`/
     * `driverType` are still available since the caller already has them.
     */
    public static function withoutTransaction(string $driver, ?string $driverType): self
    {
        return new self(payerId: null, driver: $driver, driverType: $driverType, os: null, merchantOrderId: null);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'payer_id'          => $this->payerId,
            'driver'            => $this->driver,
            'driver_type'       => $this->driverType,
            'os'                => $this->os,
            'merchant_order_id' => $this->merchantOrderId,
        ];
    }
}
