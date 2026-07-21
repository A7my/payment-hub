<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Console\Commands;

use Illuminate\Console\Command;
use Mifatoyeh\LaravelPaymentFramework\Checkout\CheckoutService;
use Mifatoyeh\LaravelPaymentFramework\Checkout\CheckoutTransaction;
use Mifatoyeh\LaravelPaymentFramework\DTO\TransactionLookupRequest;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\Managers\PaymentManager;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;
use Throwable;

/**
 * The universal reconciliation sweep — the backstop for EVERY combination
 * of driver/driver_type/os, scheduled every `payment.verification.sweep_interval_hours`
 * (default 12h) by {@see \Mifatoyeh\LaravelPaymentFramework\Providers\PaymentServiceProvider::boot()}.
 *
 * Re-verifies any `checkout_transactions` row still `pending` and older
 * than the sweep interval, via the SAME `driver->lookup()` →
 * {@see CheckoutService::confirmTransaction()} pipeline every other
 * confirmation path uses. Exists because a "supported" webhook can still
 * fail to arrive (provider-side outage, a misconfigured dashboard URL, a
 * dropped delivery — nothing about `supports('webhook') === true`
 * guarantees delivery, only that the provider offers the mechanism at
 * all), and because a {@see \Mifatoyeh\LaravelPaymentFramework\Checkout\Jobs\VerifyPaymentJob}
 * chain can be lost entirely (a worker crash, a queue flush) before it
 * reaches a terminal status.
 *
 * Idempotency: confirmed safe to re-verify a row a webhook/job already
 * resolved moments earlier — the existing `(driver, model_type, model_id)`
 * unique constraint on `checkout_transactions`
 * ({@see CheckoutService::persistTransaction()}) makes this a plain
 * no-op update in that case; no additional locking/guards needed.
 *
 * Only considers rows with a non-null `transaction_reference` — a row
 * still pending with none (a webview attempt where the customer never
 * completed anything at the provider, so no real transaction exists yet)
 * has nothing for `lookup()` to check; there is no live status to fetch,
 * only an abandoned attempt.
 */
final class ReconcilePendingCheckoutsCommand extends Command
{
    /** @var string */
    protected $signature = 'payment:reconcile-checkouts';

    /** @var string */
    protected $description = 'Re-verify stale pending checkout_transactions rows directly with each provider.';

    public function handle(CheckoutService $service, PaymentManager $manager): int
    {
        $hours = (int) config('payment.verification.sweep_interval_hours', 12);

        $stale = CheckoutTransaction::query()
            ->where('status', PaymentStatus::Pending->value)
            ->whereNotNull('transaction_reference')
            ->where('updated_at', '<', now()->subHours($hours))
            ->get();

        $reconciled = 0;

        foreach ($stale as $transaction) {
            try {
                $status = $manager->driver($transaction->driver)->lookup(new TransactionLookupRequest(
                    transactionId: TransactionId::fromString($transaction->transaction_reference),
                ));

                $service->confirmTransaction($transaction, $status);
                $reconciled++;
            } catch (Throwable $e) {
                $this->error("checkout_transactions#{$transaction->id} ({$transaction->driver}): {$e->getMessage()}");
            }
        }

        $this->info("Reconciled {$reconciled}/{$stale->count()} stale pending checkout(s) older than {$hours}h.");

        return self::SUCCESS;
    }
}
