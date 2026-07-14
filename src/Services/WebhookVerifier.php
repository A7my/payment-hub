<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Services;

use Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\PaymentDriverContract;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Logging\PaymentLoggerContract;
use Mifatoyeh\LaravelPaymentFramework\DTO\WebhookRequest;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\WebhookVerificationException;

/**
 * Orchestrates webhook signature verification before processing.
 *
 * Wraps the driver's verifyWebhookSignature() call with logging so that:
 *   - Every verification attempt (pass or fail) is logged at info level.
 *   - Every verification failure is additionally logged at error level with
 *     the driver name and a truncated (≤ 32 chars) signature value.
 *
 * The WebhookController calls this service before WebhookProcessor::process()
 * to enforce the verification-before-processing invariant.
 */
final class WebhookVerifier
{
    /**
     * @param PaymentLoggerContract $logger The bound logger implementation.
     */
    public function __construct(
        private readonly PaymentLoggerContract $logger,
    ) {
    }

    /**
     * Verify the webhook signature for the given request using the supplied driver.
     *
     * @param PaymentDriverContract $driver  The resolved driver for this webhook.
     * @param WebhookRequest        $request The normalised webhook request DTO.
     *
     * @throws WebhookVerificationException When the signature is invalid or missing.
     */
    public function verify(PaymentDriverContract $driver, WebhookRequest $request): void
    {
        // TODO: $this->logger->info('Verifying webhook signature', ['driver' => $request->driver]);
        // TODO: $valid = $driver->verifyWebhookSignature($request);
        // TODO: if (!$valid) {
        //           $this->logger->error('Webhook verification failed', [
        //               'driver'    => $request->driver,
        //               'signature' => substr($request->signature->toString(), 0, 32),
        //           ]);
        //           throw WebhookVerificationException::forDriver($request->driver, $request->signature->toString());
        //       }
        // TODO: $this->logger->info('Webhook signature verified', ['driver' => $request->driver]);
        throw new \LogicException('WebhookVerifier::verify() not yet implemented.');
    }
}
