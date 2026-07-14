<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Events;

use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentRequest;

/**
 * Dispatched immediately before a payment driver method is invoked.
 *
 * Listeners can use this event for pre-payment logging, analytics,
 * fraud pre-checks, or any side-effect that must occur before the
 * provider is contacted.
 */
final readonly class PaymentInitiated
{
    /**
     * @param PaymentRequest $request The payment request DTO about to be processed.
     */
    public function __construct(
        public PaymentRequest $request,
    ) {
    }
}
