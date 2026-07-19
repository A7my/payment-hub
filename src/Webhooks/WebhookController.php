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
 *   GET|POST /payment/webhook/{driver}
 *
 * Both verbs are registered (not just POST) because providers disagree on
 * how they deliver a webhook/callback: some POST a JSON body with the
 * signature in a header (Stripe's `Stripe-Signature`), others — Paymob's
 * classic "Transaction Processed Callback" — send a GET request with every
 * field, including the HMAC, flattened into the query string. This
 * controller normalises either shape into one {@see WebhookRequest} DTO
 * without needing to know which provider it's talking to.
 *
 * The {driver} path segment is used to resolve the correct driver and
 * forward the webhook to its processWebhook() method after signature
 * verification. This single route handles all providers without any
 * provider-specific routing.
 *
 * Flow:
 *   1. Extract raw body, headers, signature, and driver name.
 *   2. Build a WebhookRequest DTO.
 *   3. Delegate to WebhookProcessor::process() (dispatches WebhookReceived,
 *      verifies, dispatches WebhookProcessed).
 *   4. Return HTTP 200 on success.
 *   5. Catch WebhookVerificationException → return HTTP 400.
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
        $rawBody = $request->getContent();

        // A generic header name is checked first (the shape most providers
        // use — Stripe's Stripe-Signature, etc.), falling back to Paymob's
        // `hmac` request param for providers that send the signature as
        // plain request data rather than a header. WebhookRequest itself
        // stays provider-agnostic either way — it just wraps whichever
        // value was found.
        $sigHeader = $request->header('x-payment-signature', '');

        if ($sigHeader === '') {
            $sigHeader = (string) $request->input('hmac', '');
        }

        $dto = new WebhookRequest(
            driver: $driver,
            rawBody: $rawBody,
            headers: $request->headers->all(),
            signature: WebhookSignature::fromString($sigHeader),
            metadata: $request->all(),
        );

        try {
            $response = $this->processor->process($dto);

            return response()->json([
                'status' => 'ok',
                'event'  => $response->getEventType()->value,
            ], 200);
        } catch (WebhookVerificationException $e) {
            return response()->json(['status' => 'error', 'message' => 'Invalid signature'], 400);
        }
    }
}
