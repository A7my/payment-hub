<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Events;

use Mifatoyeh\LaravelPaymentFramework\DTO\SaveCardRequest;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentResponse;

/**
 * Dispatched when a customer's payment method is successfully saved as a token.
 */
final readonly class CardSaved
{
    /**
     * @param SaveCardRequest $request  The save-card request DTO.
     * @param PaymentResponse $response The standardised response containing the saved card token.
     */
    public function __construct(
        public SaveCardRequest $request,
        public PaymentResponse $response,
    ) {
    }
}
