<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Webhooks;

use Illuminate\Contracts\Events\Dispatcher;
use Mifatoyeh\LaravelPaymentFramework\DTO\WebhookRequest;
use Mifatoyeh\LaravelPaymentFramework\Events\WebhookProcessed;
use Mifatoyeh\LaravelPaymentFramework\Events\WebhookReceived;
use Mifatoyeh\LaravelPaymentFramework\Managers\PaymentManager;
use Mifatoyeh\LaravelPaymentFramework\Responses\WebhookResponse;
use Mifatoyeh\LaravelPaymentFramework\Services\WebhookVerifier;

/**
 * Orchestrates the full webhook verification and processing sequence.
 *
 * Called by WebhookController after the WebhookRequest DTO is built.
 *
 * Sequence:
 *   1. Dispatch WebhookReceived (before any verification).
 *   2. Call WebhookVerifier::verify() — throws WebhookVerificationException on failure.
 *   3. Resolve the driver via PaymentManager::driver($dto->driver).
 *   4. Call driver->processWebhook($dto) → WebhookResponse.
 *   5. Dispatch WebhookProcessed with the request and response.
 *   6. Return the WebhookResponse.
 *
 * The WebhookReceived event is always dispatched, even if verification fails,
 * allowing listeners to perform raw logging or rate-limiting.
 */
final class WebhookProcessor
{
    /**
     * @param PaymentManager  $manager  Driver resolver.
     * @param WebhookVerifier $verifier Signature verification service.
     * @param Dispatcher      $events   Laravel event dispatcher.
     */
    public function __construct(
        private readonly PaymentManager $manager,
        private readonly WebhookVerifier $verifier,
        private readonly Dispatcher $events,
    ) {
    }

    /**
     * Process a verified webhook request end-to-end.
     *
     * @param WebhookRequest $request The normalised webhook request DTO.
     *
     * @return WebhookResponse The standardised processing response.
     *
     * @throws \Mifatoyeh\LaravelPaymentFramework\Exceptions\WebhookVerificationException
     *         When the provider signature is invalid.
     */
    public function process(WebhookRequest $request): WebhookResponse
    {
        // TODO: $this->events->dispatch(new WebhookReceived($request));
        // TODO: $driver = $this->manager->driver($request->driver);
        // TODO: $this->verifier->verify($driver, $request);
        // TODO: $response = $driver->processWebhook($request);
        // TODO: $this->events->dispatch(new WebhookProcessed($request, $response));
        // TODO: return $response;
        throw new \LogicException('WebhookProcessor::process() not yet implemented.');
    }
}
