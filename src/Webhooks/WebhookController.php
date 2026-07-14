<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Webhooks;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Mifatoyeh\LaravelPaymentFramework\DTO\WebhookRequest;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\WebhookVerificationException;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\WebhookSignature;

/**
 * Handles inbound webhook HTTP requests from payment providers.
 *
 * Registered on the route:
 *   POST /payment/webhook/{driver}
 *
 * The {driver} path segment is used to resolve the correct driver and
 * forward the webhook to its processWebhook() method after signature
 * verification. This single route handles all providers without any
 * provider-specific routing.
 *
 * Flow:
 *   1. Extract raw body, headers, signature, and driver name.
 *   2. Build a WebhookRequest DTO.
 *   3. Dispatch WebhookReceived event.
 *   4. Delegate to WebhookProcessor::process().
 *   5. Return HTTP 200 on success.
 *   6. Catch WebhookVerificationException → return HTTP 400.
 */
final class WebhookController extends Controller
{
    /**
     * @param WebhookProcessor $processor The webhook processing orchestrator.
     */
    public function __construct(
        private readonly WebhookProcessor $processor,
    ) {
    }

    /**
     * Handle an inbound webhook request.
     *
     * @param Request $request The incoming HTTP request.
     * @param string  $driver  The payment driver name from the route parameter.
     *
     * @return JsonResponse HTTP 200 on success, HTTP 400 on verification failure.
     */
    public function handle(Request $request, string $driver): JsonResponse
    {
        // TODO: $rawBody    = $request->getContent();
        // TODO: $headers    = $request->headers->all();
        // TODO: $sigHeader  = $request->header('x-payment-signature', '');
        // TODO: $signature  = WebhookSignature::fromString($sigHeader);
        // TODO: $dto = new WebhookRequest(driver: $driver, rawBody: $rawBody, headers: $headers, signature: $signature);
        // TODO: try {
        //           $response = $this->processor->process($dto);
        //           return response()->json(['status' => 'ok', 'event' => $response->getEventType()->value], 200);
        //       } catch (WebhookVerificationException $e) {
        //           return response()->json(['status' => 'error', 'message' => 'Invalid signature'], 400);
        //       }
        throw new \LogicException('WebhookController::handle() not yet implemented.');
    }
}
