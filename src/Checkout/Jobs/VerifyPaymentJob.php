<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Checkout\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Mifatoyeh\LaravelPaymentFramework\Checkout\CheckoutService;
use Mifatoyeh\LaravelPaymentFramework\Checkout\CheckoutTransaction;
use Mifatoyeh\LaravelPaymentFramework\DTO\TransactionLookupRequest;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\Managers\PaymentManager;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;

/**
 * Actively re-checks a `sdk`-mode checkout's outcome for drivers that don't
 * proactively tell this package anything (`supports('webhook') === false`
 * — see {@see \Mifatoyeh\LaravelPaymentFramework\Checkout\CheckoutService::driverSupportsWebhook()}).
 * Only ever dispatched by {@see CheckoutService::checkout()}, once, for
 * that exact case — a webview checkout gets its outcome from
 * {@see \Mifatoyeh\LaravelPaymentFramework\Checkout\CheckoutCallbackController}
 * or the customer's own return trip instead, and a webhook-capable driver's
 * `sdk` checkout gets it from `routes/webhooks.php`; neither needs active
 * polling.
 *
 * Self-rescheduling with backoff (`payment.verification.job.backoff`,
 * default `[30, 60, 300, 900, 3600]` seconds) rather than a single delayed
 * check — the customer may still be entering card details client-side when
 * the first attempt fires. Gives up after `payment.verification.job.max_attempts`
 * (default 8) or `payment.verification.job.max_duration` seconds
 * (default 24h) — after that, the universal 12h reconciliation sweep
 * remains the final backstop for this row regardless.
 *
 * REQUIRES a queue driver that supports delayed dispatch (redis, database,
 * sqs — NOT sync). With `QUEUE_CONNECTION=sync`, `->delay()` is a no-op and
 * every attempt runs immediately, back-to-back, defeating the entire
 * backoff — {@see \Mifatoyeh\LaravelPaymentFramework\Providers\PaymentServiceProvider}
 * logs a boot-time warning when this misconfiguration is detected for a
 * driver that needs this job.
 */
final class VerifyPaymentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public readonly int $firstDispatchedAt;

    public function __construct(
        public readonly string $driver,
        public readonly int $checkoutTransactionId,
        public readonly int $attempt = 1,
        int $firstDispatchedAt = 0,
    ) {
        // NOT constructor-promoted like the others above — promotion would
        // already assign it before this body runs, and a readonly property
        // can only be written once; this is that one write.
        $this->firstDispatchedAt = $firstDispatchedAt !== 0 ? $firstDispatchedAt : time();
    }

    public function handle(CheckoutService $service, PaymentManager $manager): void
    {
        $transaction = CheckoutTransaction::find($this->checkoutTransactionId);

        // Already resolved (a late webhook, a client confirm() call, a
        // previous job attempt) or the row is gone — nothing left to do.
        if ($transaction === null || $transaction->status !== PaymentStatus::Pending->value) {
            return;
        }

        if ($transaction->transaction_reference === null) {
            // Nothing to look up yet (see CheckoutService::checkout()'s own
            // docblock — only sdk mode stores an initial reference, and
            // only when the driver's SDK-intent reference is itself
            // lookup()-compatible). Reschedule and hope the next attempt
            // has one; the max-attempts/duration ceiling below still applies.
            $this->rescheduleOrGiveUp($transaction);

            return;
        }

        $status = $manager->driver($this->driver)->lookup(new TransactionLookupRequest(
            transactionId: TransactionId::fromString($transaction->transaction_reference),
        ));

        $service->confirmTransaction($transaction, $status);

        // StatusResponse::isTerminal() alone is NOT enough here — it's
        // deliberately scoped to "cannot transition to any other status"
        // (Failed/Voided/Refunded/Cancelled/Expired), which excludes
        // Captured/Authorized on purpose (a captured payment CAN still be
        // refunded later). For this job's purposes — "is there any point
        // still polling this checkout" — a successful outcome is equally
        // conclusive; only Pending/RequiresAction genuinely warrant another
        // attempt.
        $resolved = $status->getStatus()->isTerminal() || $status->getStatus()->isSuccessful();

        if (! $resolved) {
            $this->rescheduleOrGiveUp($transaction);
        }
    }

    private function rescheduleOrGiveUp(CheckoutTransaction $transaction): void
    {
        $maxAttempts = (int) config('payment.verification.job.max_attempts', 8);
        $maxDuration = (int) config('payment.verification.job.max_duration', 86400);

        if ($this->attempt >= $maxAttempts || (time() - $this->firstDispatchedAt) >= $maxDuration) {
            // Give up actively polling — the 12h reconciliation sweep is
            // the unconditional backstop for this row from here on.
            return;
        }

        $backoff = (array) config('payment.verification.job.backoff', [30, 60, 300, 900, 3600]);
        $delay   = $backoff[min($this->attempt - 1, count($backoff) - 1)] ?? end($backoff);

        self::dispatch($this->driver, $transaction->id, $this->attempt + 1, $this->firstDispatchedAt)
            ->delay((int) $delay);
    }
}
