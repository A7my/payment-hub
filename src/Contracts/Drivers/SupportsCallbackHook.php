<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers;

/**
 * Optional capability interface for drivers that need provider-specific
 * side effects at the moment a callback/webhook is received — BEFORE the
 * shared pipeline resolves an authoritative {@see \Mifatoyeh\LaravelPaymentFramework\Responses\StatusResponse}
 * for it.
 *
 * Deliberately a DIFFERENT job from {@see SupportsTrustedWebhookStatus}:
 * that interface participates in DECIDING the outcome (its return value
 * can replace a live `lookup()` call); this one never influences the
 * outcome at all — it's a side-effect-only extension point (provider-
 * specific audit logging, alerting on a provider-specific partial-success
 * state, capturing metadata the generic pipeline doesn't know to look for).
 * A driver may implement either, both, or neither.
 *
 * Deliberately NOT part of {@see PaymentDriverContract} — additive and
 * optional, mirroring {@see SupportsSdkCheckout}/{@see SupportsTrustedWebhookStatus}'s
 * own pattern. `CheckoutService::resolveAndConfirm()` checks `instanceof`
 * and calls this FIRST, before any status resolution — see that method's
 * own docblock for the full step-by-step order.
 */
interface SupportsCallbackHook
{
    /**
     * React to a just-received callback/webhook payload, before this
     * package has determined (or re-verified) what it means.
     *
     * @param array<string, mixed> $rawPayload The provider's raw, driver-specific payload
     *                                          (query params and/or body, unnormalised).
     * @param string                $source     `'callback'` (the browser-redirect route) or
     *                                          `'webhook'` (the server-to-server route) —
     *                                          which route triggered this.
     */
    public function onCallbackReceived(array $rawPayload, string $source): void;
}
