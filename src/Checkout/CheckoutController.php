<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Checkout;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\CheckoutException;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\PaymentException;

/**
 * Handles the generic checkout HTTP endpoint.
 *
 * Registered on the route:
 *   POST {config('payment.checkout.route')}
 *
 * Deliberately thin — all resolution, authorisation, and driver dispatch
 * logic lives in {@see CheckoutService}, mirroring
 * {@see \Mifatoyeh\LaravelPaymentFramework\Webhooks\WebhookController}'s
 * split between itself and `WebhookProcessor`.
 *
 * {@see CheckoutService::checkout()} calls
 * {@see \Mifatoyeh\LaravelPaymentFramework\Contracts\Payable::authorizePayment()}
 * unconditionally — this controller does NOT additionally trust route
 * middleware to have handled authorisation; that check happens regardless
 * of how (or whether) `payment.checkout.middleware` is configured.
 */
final class CheckoutController extends Controller
{
    public function __construct(
        private readonly CheckoutService $service,
    ) {
    }

    /**
     * Handle an inbound checkout request.
     *
     * @return JsonResponse HTTP 200 on success; 4xx/5xx per
     *         {@see CheckoutException::getStatusCode()} on any rejectable
     *         checkout-specific failure; 502 on any other framework
     *         {@see PaymentException} (e.g. the provider API itself failed).
     */
    public function handle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'model_type'  => ['required', 'string'],
            'model_id'    => ['required', 'string'],
            'driver'      => ['required', 'string'],
            'driver_type' => ['required', 'string', 'in:sdk,webview'],
            'return_url'  => ['nullable', 'string'],
            'cancel_url'  => ['nullable', 'string'],
        ]);

        try {
            $response = $this->service->checkout(
                modelType: $validated['model_type'],
                modelId: $validated['model_id'],
                driver: $validated['driver'],
                driverType: $validated['driver_type'],
                returnUrl: $validated['return_url'] ?? null,
                cancelUrl: $validated['cancel_url'] ?? null,
                payer: $request->user(),
            );
        } catch (CheckoutException $e) {
            return response()->json([
                'status'  => 'fail',
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        } catch (PaymentException $e) {
            return response()->json([
                'status'  => 'fail',
                'message' => $e->getMessage(),
            ], 502);
        }

        return response()->json([
            'status'       => 'success',
            'checkout_url' => $response->getPaymentUrl(),
            'link_id'      => $response->getLinkId(),
            'expires_at'   => $response->getExpiresAt()?->format(DATE_ATOM),
            'message'      => $response->getMessage(),
        ]);
    }
}
