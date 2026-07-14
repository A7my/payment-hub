<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Events;

use Mifatoyeh\LaravelPaymentFramework\DTO\CaptureRequest;
use Mifatoyeh\LaravelPaymentFramework\Responses\CaptureResponse;

/**
 * Dispatched when a previously authorised payment is successfully captured.
 */
final readonly class PaymentCaptured
{
    /**
     * @param CaptureRequest  $request  The capture request DTO.
     * @param CaptureResponse $response The standardised capture response.
     */
    public function __construct(
        public CaptureRequest $request,
        public CaptureResponse $response,
    ) {
    }
}
