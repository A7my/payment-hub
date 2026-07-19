<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Contracts\Responses;

/**
 * Contract for the standardised SDK-checkout response.
 *
 * Returned by {@see \Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\SupportsSdkCheckout::createSdkIntent()}.
 * Unlike {@see PaymentLinkResponseContract} (a URL to redirect to), this
 * carries whatever reference value a native provider SDK needs to complete
 * the charge itself, client-side — the driver call behind this never
 * charges anything on its own.
 */
interface SdkCheckoutResponseContract
{
    /**
     * Whether the intent/reference was created successfully.
     */
    public function isSuccessful(): bool;

    /**
     * The provider-assigned reference for this intent (e.g. a Stripe
     * PaymentIntent id, a Paymob order id) — pass this back to the
     * checkout confirmation endpoint once the native SDK reports completion.
     */
    public function getTransactionReference(): string;

    /**
     * The value the native SDK needs to confirm the charge itself — a
     * Stripe PaymentIntent `client_secret`, a Paymob `payment_key`/
     * `client_secret`, etc. Provider-specific in format; always opaque to
     * this framework.
     */
    public function getClientSecret(): string;

    /**
     * A provider-specific public/publishable key the client SDK needs
     * alongside the client secret, if any (e.g. Stripe's publishable key).
     * Null when the provider's SDK doesn't need one.
     */
    public function getPublishableKey(): ?string;

    /**
     * A human-readable message describing the result.
     */
    public function getMessage(): string;
}
