<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers;

use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentLinkRequest;
use Mifatoyeh\LaravelPaymentFramework\Responses\SdkCheckoutResponse;

/**
 * Optional capability interface for drivers that support native-SDK
 * checkout (`driver_type: sdk` on the generic checkout endpoint), as
 * opposed to the hosted-redirect flow every driver already supports via
 * {@see PaymentDriverContract::createPaymentLink()}.
 *
 * Deliberately NOT part of {@see PaymentDriverContract} — this is an
 * additive, optional capability (mirroring
 * {@see SupportsCapabilities}'s own pattern), not a required method every
 * driver must implement. A driver that doesn't implement this interface
 * simply doesn't support `driver_type: sdk` yet;
 * `CheckoutService` checks `instanceof` before calling it and returns a
 * clear "not supported by this driver" error otherwise, rather than a
 * fatal error from calling an undefined method.
 *
 * Unlike `createPaymentLink()`, this method must NEVER itself charge
 * anything — it only creates whatever reference (a Stripe PaymentIntent, a
 * Paymob order/intention) the *native* provider SDK needs to complete the
 * charge itself, client-side, without raw card data ever reaching this
 * package's server. The actual outcome is confirmed later via
 * `CheckoutService::confirm()` (`POST {route}/confirm`), which calls the
 * driver's already-existing, already-tested `lookup()` — never trusting a
 * client-supplied "it succeeded" claim.
 */
interface SupportsSdkCheckout
{
    /**
     * Create a client-confirmable payment reference for a native SDK flow.
     *
     * @param PaymentLinkRequest $request The same request shape createPaymentLink() takes.
     *
     * @return SdkCheckoutResponse
     */
    public function createSdkIntent(PaymentLinkRequest $request): SdkCheckoutResponse;
}
