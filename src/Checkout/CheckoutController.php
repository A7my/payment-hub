<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Checkout;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\CheckoutException;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\PaymentException;
use Mifatoyeh\LaravelPaymentFramework\Responses\SdkCheckoutResponse;

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
     * Response shape depends on `driver_type`: `webview` returns a
     * `checkout_url` to redirect the customer to; `sdk` returns a
     * `client_secret`/`publishable_key` pair for a native client-side SDK to
     * confirm the charge itself. Either way, the actual outcome is not yet
     * known here.
     *
     * `os` decides how confirmation eventually reaches the client — see
     * {@see CheckoutService::checkout()}'s own docblock:
     *   - `driver_type: webview` + `os: web` — the provider redirects to the
     *     package's own callback route, verifies, then redirects again to
     *     `return_url` (required for this combination specifically — see
     *     {@see \Mifatoyeh\LaravelPaymentFramework\Exceptions\CheckoutException::returnUrlRequiredForWebviewWeb()}).
     *   - Every other combination: unchanged — the client still calls
     *     {@see self::confirm()} itself, or waits on a webhook/background job.
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
            'os'          => ['required', 'string', 'in:web,mobile'],
            'return_url'  => ['nullable', 'string'],
            'cancel_url'  => ['nullable', 'string'],
        ]);

        try {
            $response = $this->service->checkout(
                modelType: $validated['model_type'],
                modelId: $validated['model_id'],
                driver: $validated['driver'],
                driverType: $validated['driver_type'],
                os: $validated['os'],
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

        if ($response instanceof SdkCheckoutResponse) {
            return response()->json([
                'status'                => 'success',
                'driver_type'           => 'sdk',
                'transaction_reference' => $response->getTransactionReference(),
                'client_secret'         => $response->getClientSecret(),
                'publishable_key'       => $response->getPublishableKey(),
                'message'               => $response->getMessage(),
            ]);
        }

        return response()->json([
            'status'       => 'success',
            'driver_type'  => 'webview',
            'checkout_url' => $response->getPaymentUrl(),
            'link_id'      => $response->getLinkId(),
            'expires_at'   => $response->getExpiresAt()?->format(DATE_ATOM),
            'message'      => $response->getMessage(),
        ]);
    }

    /**
     * Authoritatively confirm a checkout payment's outcome.
     *
     * Called by the client after a webview redirect returns, or after a
     * client-side SDK reports it confirmed the charge — either way, this
     * package never trusts that claim: {@see CheckoutService::confirm()}
     * re-verifies directly with the provider via `lookup()`.
     * `transaction_reference` is whichever value the client received from
     * {@see self::handle()} (the SDK's `transaction_reference`) or was able
     * to observe from the provider's own return payload/redirect for a
     * webview flow.
     *
     * @return JsonResponse HTTP 200 with the authoritative status (which may
     *         itself report a failed/pending payment — that is not an HTTP
     *         error); 4xx/5xx per {@see CheckoutException::getStatusCode()}
     *         on a rejectable request; 502 on any other framework
     *         {@see PaymentException}.
     */
    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'model_type'             => ['required', 'string'],
            'model_id'               => ['required', 'string'],
            'driver'                 => ['required', 'string'],
            'transaction_reference'  => ['required', 'string'],
            'driver_type'            => ['nullable', 'string', 'in:sdk,webview'],
        ]);

        try {
            $status = $this->service->confirm(
                modelType: $validated['model_type'],
                modelId: $validated['model_id'],
                driver: $validated['driver'],
                transactionReference: $validated['transaction_reference'],
                driverType: $validated['driver_type'] ?? null,
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
            'status'         => $status->isSuccessful() && $status->getStatus()->isSuccessful() ? 'success' : 'fail',
            'payment_status' => $status->getStatus()->value,
            'transaction_id' => $status->getTransactionId()->toString(),
            'message'        => $status->getMessage(),
        ]);
    }
}
