<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers;

use Mifatoyeh\LaravelPaymentFramework\Responses\StatusResponse;

/**
 * Optional capability interface for drivers that can build an authoritative
 * {@see StatusResponse} directly from an already signature-verified webhook
 * payload, WITHOUT an extra live API call.
 *
 * The framework's default policy — see
 * {@see \Mifatoyeh\LaravelPaymentFramework\Checkout\CheckoutService::confirmFromWebhook()} —
 * is to never trust a webhook payload on its own, always re-checking status
 * via the driver's `lookup()`. That default holds for any driver that
 * doesn't implement this interface. It exists as an escape hatch for the
 * case where re-verification isn't actually possible: Paymob's KSA
 * (Intention API) platform has no confirmed equivalent of the legacy
 * `retrieveTransaction()` endpoint `lookup()` relies on (see
 * {@see \Mifatoyeh\LaravelPaymentFramework\Drivers\Paymob\PaymobDriver}'s
 * own implementation) — for that one case, the webhook's own HMAC
 * signature (already verified before this is ever called — see
 * {@see \Mifatoyeh\LaravelPaymentFramework\Services\WebhookVerifier::verify()})
 * is treated as the trust boundary instead of a second live check.
 *
 * Deliberately NOT part of {@see PaymentDriverContract} — additive and
 * optional, mirroring {@see SupportsSdkCheckout}'s own pattern.
 * `CheckoutService` checks `instanceof` and treats a `null` return as "not
 * applicable here, fall back to lookup()" — a driver may implement this
 * interface and still return `null` for some payloads/modes (e.g. Paymob's
 * Egypt mode, where the legacy lookup endpoint IS confirmed working and is
 * preferred).
 */
interface SupportsTrustedWebhookStatus
{
    /**
     * Build a StatusResponse directly from an already-verified webhook
     * payload, or return null to signal "use lookup() instead".
     *
     * @param array<string, mixed> $rawPayload The webhook's raw, driver-specific payload.
     */
    public function statusFromWebhookPayload(array $rawPayload): ?StatusResponse;
}
