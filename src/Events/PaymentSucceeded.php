<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Events;

use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentRequest;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentResponse;

/**
 * Dispatched when a payment charge or authorisation succeeds.
 *
 * Both the original request and the standardised response are carried
 * so listeners have full context for receipts, order fulfilment, analytics,
 * and transaction persistence.
 */
final readonly class PaymentSucceeded
{
    /**
     * @param PaymentRequest  $request  The original payment request DTO.
     * @param PaymentResponse $response The standardised successful payment response.
     */
    public function __construct(
        public PaymentRequest $request,
        public PaymentResponse $response,
    ) {
    }
}
 