<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Events;

use Mifatoyeh\LaravelPaymentFramework\DTO\VoidRequest;
use Mifatoyeh\LaravelPaymentFramework\Responses\VoidResponse;

/**
 * Dispatched when an authorised payment is successfully voided.
 */
final readonly class PaymentVoided
{
    /**
     * @param VoidRequest  $request  The void request DTO.
     * @param VoidResponse $response The standardised void response.
     */
    public function __construct(
        public VoidRequest $request,
        public VoidResponse $response,
    ) {
    }
}
