<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Events;

use Mifatoyeh\LaravelPaymentFramework\DTO\TransactionLookupRequest;
use Mifatoyeh\LaravelPaymentFramework\Responses\StatusResponse;

/**
 * Dispatched when a transaction status lookup is completed.
 *
 * Note: "Lookuped" matches the design document naming convention.
 */
final readonly class TransactionLookuped
{
    /**
     * @param TransactionLookupRequest $request  The transaction lookup request DTO.
     * @param StatusResponse           $response The standardised status response.
     */
    public function __construct(
        public TransactionLookupRequest $request,
        public StatusResponse $response,
    ) {
    }
}
