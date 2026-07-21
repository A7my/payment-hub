<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Checkout;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\PaymentException;

/**
 * Handles the package-owned, per-driver callback route:
 *   GET|POST {payment.checkout.route}/callback/{driver}
 *
 * This is where a provider's hosted-checkout redirect lands — CURRENTLY
 * only meaningfully reachable for Stripe: {@see CheckoutService::checkout()}
 * rewrites `success_url` to point here for EVERY `driver_type: webview`
 * checkout, both `os` values, and Stripe genuinely supports a dynamic,
 * per-session redirect target. Paymob does not (see
 * {@see \Mifatoyeh\LaravelPaymentFramework\Drivers\Paymob\PaymobDriver}'s
 * class docblock) — its redirect target is fixed in the Paymob dashboard,
 * so in practice its existing webhook route
 * (`routes/webhooks.php` → `payment/webhook/paymob`) already IS its
 * callback route; nothing here changes that.
 *
 * `os` decides the response shape, not whether this route is reached at
 * all: `web` redirects the browser to `return_url` afterward; `mobile` gets
 * a plain JSON response instead — there's no browser to redirect, and a
 * mobile client calling this as an API is a more natural fit than parsing
 * query params off a redirect it can't easily follow.
 *
 * Deliberately thin — all resolution/verification/persistence logic lives
 * in {@see CheckoutService::resolveAndConfirm()}, same split as
 * {@see \Mifatoyeh\LaravelPaymentFramework\Webhooks\WebhookController}
 * has with `WebhookProcessor`.
 *
 * No `Payable::authorizePayment()` check here — same reasoning as
 * {@see CheckoutService::resolveAndConfirm()}'s own docblock: there is no
 * authenticated caller on a provider redirect; the re-verification
 * `resolveAndConfirm()` performs IS the trust boundary.
 */
final class CheckoutCallbackController extends Controller
{
    public function __construct(
        private readonly CheckoutService $service,
    ) {
    }

    /**
     * @return JsonResponse For `os: mobile` — everything `confirm()`'s
     *         response already carries, PLUS `driver`/`model_type`/
     *         `model_id`/`amount`/`amount_formatted`/`currency` off the
     *         persisted row, since a mobile client landing here from a
     *         webview redirect (rather than making its own `confirm()` call
     *         with those values already in hand) is more likely to need them
     *         to update its own UI. `amount` stays in the smallest currency
     *         unit (this package's convention everywhere); `amount_formatted`
     *         is the currency-aware human-readable string (`Currency::format()`)
     *         so the client doesn't need to know each currency's own decimal
     *         precision. Also returned when nothing could be resolved at
     *         all — there's no `return_url` to redirect to in that case, so
     *         JSON is the only option regardless of `os`.
     * @return RedirectResponse For `os: web` — an HTTP redirect to the
     *         `return_url` stored on the pending row at `checkout()` time,
     *         with named query params appended (`checkout_status`,
     *         `payment_status`, `transaction_id`).
     */
    public function handle(Request $request, string $driver): JsonResponse|RedirectResponse
    {
        try {
            $transaction = $this->service->resolveAndConfirm($driver, $request->all(), 'callback');
        } catch (PaymentException $e) {
            // A live lookup()/verify() call failed (provider outage, network
            // error) — no pending row context survives the exception, so
            // there is no return_url to redirect to either way; JSON is the
            // only option here regardless of the eventual os.
            return response()->json(['status' => 'fail', 'message' => $e->getMessage()], 502);
        }

        if ($transaction === null) {
            return response()->json([
                'status'  => 'fail',
                'message' => 'Unable to resolve this checkout callback — no matching pending transaction.',
            ], 404);
        }

        $metadata  = (array) ($transaction->metadata ?? []);
        $os        = (string) ($metadata['os'] ?? 'web');
        $returnUrl = $metadata['return_url'] ?? null;

        if ($os === 'mobile' || ! is_string($returnUrl) || $returnUrl === '') {
            $currency = Currency::tryFrom($transaction->currency);

            return response()->json([
                'status'            => $transaction->successful ? 'success' : 'fail',
                'payment_status'    => $transaction->status,
                'transaction_id'    => $transaction->transaction_reference,
                'message'           => $transaction->message,
                // Beyond confirm()'s own shape — a mobile client landing
                // here via a webview redirect didn't necessarily keep these
                // around from the original checkout() response, unlike a
                // client calling confirm() itself with them already in hand.
                'driver'            => $transaction->driver,
                'model_type'        => $transaction->model_type,
                'model_id'          => $transaction->model_id,
                // `amount` is in the SMALLEST currency unit (halalas/cents —
                // matches Money::ofMinor()'s convention everywhere else in
                // this package, and Stripe/Paymob's own API convention) —
                // `amount_formatted` is the human-readable major-unit string
                // (currency-aware: correctly "40.00" for SAR, "1050" for
                // zero-decimal JPY, "1.050" for 3-decimal KWD — see
                // Currency::format()) so a mobile client doesn't have to
                // know each currency's decimal precision itself.
                'amount'            => $transaction->amount,
                'amount_formatted'  => $currency?->format($transaction->amount),
                'currency'          => $transaction->currency,
            ]);
        }

        $separator = str_contains($returnUrl, '?') ? '&' : '?';

        return redirect()->away($returnUrl . $separator . http_build_query([
            'checkout_status' => $transaction->successful ? 'success' : 'fail',
            'payment_status'  => $transaction->status,
            'transaction_id'  => $transaction->transaction_reference,
        ]));
    }
}
