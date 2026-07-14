<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Events;

use Mifatoyeh\LaravelPaymentFramework\DTO\TokenChargeRequest;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentResponse;

/**
 * Dispatched when a payment is successfully charged using a provider token.
 */
final readonly class TokenCharged
{
    /**
     * @param TokenChargeRequest $request  The token charge request DTO.
     * @param PaymentResponse    $response The standardised charge response.
     */
    public function __construct(
        public TokenChargeRequest $request,
        public PaymentResponse $response,
    ) {
    }
}
