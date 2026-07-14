<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Events;

use Throwable;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentRequest;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentResponse;

/**
 * Dispatched whenever a payment operation fails, regardless of cause.
 *
 * This event is dispatched for both provider-level soft declines
 * (response available, isSuccessful() === false) and unrecoverable
 * exceptions (response may be null). Listeners should handle both cases.
 */
final readonly class PaymentFailed
{
    /**
     * @param PaymentRequest       $request   The original payment request DTO.
     * @param PaymentResponse|null $response  The response if one was returned, null if an exception was thrown.
     * @param Throwable|null       $exception The exception if one was thrown, null for soft declines.
     */
    public function __construct(
        public PaymentRequest $request,
        public ?PaymentResponse $response,
        public ?Throwable $exception,
    ) {
    }
}
