<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Events;

use Mifatoyeh\LaravelPaymentFramework\Contracts\Payable;
use Mifatoyeh\LaravelPaymentFramework\Responses\StatusResponse;

/**
 * Dispatched by `CheckoutService::confirm()` after authoritatively verifying
 * a checkout payment's status directly with the provider.
 *
 * Fires unconditionally on every confirmation (regardless of whether
 * `$status->isSuccessful()` is true), alongside — not instead of — the
 * per-model {@see \Mifatoyeh\LaravelPaymentFramework\Contracts\HasPaymentCallback::onPaymentCompleted()}
 * callback: this event is for application-wide listeners (analytics,
 * notifications, logging) that don't belong to any one model; the callback
 * interface is for model-specific side effects. Use whichever fits, or both.
 */
final readonly class CheckoutPaymentConfirmed
{
    public function __construct(
        public Payable $payable,
        public string $modelType,
        public StatusResponse $status,
    ) {
    }
}
