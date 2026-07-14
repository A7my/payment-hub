<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Events;

use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentLinkRequest;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentLinkResponse;

/**
 * Dispatched when a hosted payment link is successfully created.
 */
final readonly class PaymentLinkCreated
{
    /**
     * @param PaymentLinkRequest  $request  The payment link creation request DTO.
     * @param PaymentLinkResponse $response The standardised payment link response containing the URL.
     */
    public function __construct(
        public PaymentLinkRequest $request,
        public PaymentLinkResponse $response,
    ) {
    }
}
