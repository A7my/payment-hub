<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Events;

use Mifatoyeh\LaravelPaymentFramework\DTO\RefundRequest;
use Mifatoyeh\LaravelPaymentFramework\Responses\RefundResponse;

/**
 * Dispatched when a full or partial refund is successfully processed.
 */
final readonly class PaymentRefunded
{
    /**
     * @param RefundRequest  $request  The refund request DTO.
     * @param RefundResponse $response The standardised refund response.
     */
    public function __construct(
        public RefundRequest $request,
        public RefundResponse $response,
    ) {
    }
}
