<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Checkout;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Payable;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentLinkRequest;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\CheckoutException;
use Mifatoyeh\LaravelPaymentFramework\Managers\PaymentManager;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentLinkResponse;

/**
 * Orchestrates the generic checkout flow for {@see CheckoutController}:
 * resolve a {@see Payable} model from the `payment.payables` allowlist,
 * validate the requested driver is one that model allows, authorise the
 * payer, then dispatch to the resolved driver's `createPaymentLink()`.
 *
 * Uses {@see PaymentManager} directly (constructor-injected) rather than the
 * `Payment` facade — the facade is for host-application callers, not for
 * this package's own internal code, matching how
 * {@see \Mifatoyeh\LaravelPaymentFramework\Services\PaymentService} is
 * built the same way.
 *
 * `driver_type: sdk` is intentionally not implemented — see
 * {@see CheckoutException::sdkModeNotYetSupported()}'s own docblock for why:
 * neither built-in driver currently exposes a create-intent/return-client-
 * reference operation for native SDK confirmation, and this service does
 * not invent one.
 */
final class CheckoutService
{
    public function __construct(
        private readonly PaymentManager $manager,
    ) {
    }

    /**
     * @throws CheckoutException On any rejectable state of the request.
     */
    public function checkout(
        string $modelType,
        string $modelId,
        string $driver,
        string $driverType,
        ?string $returnUrl,
        ?string $cancelUrl,
        ?Authenticatable $payer,
    ): PaymentLinkResponse {
        if (! in_array($driverType, ['sdk', 'webview'], true)) {
            throw CheckoutException::unsupportedDriverType($driverType);
        }

        $model = $this->resolvePayable($modelType, $modelId);

        if (! in_array($driver, $model->getSupportedPaymentDrivers(), true)) {
            throw CheckoutException::unsupportedDriverForPayable($driver, $modelType);
        }

        if (! $model->authorizePayment($payer)) {
            throw CheckoutException::unauthorized();
        }

        if ($driverType === 'sdk') {
            throw CheckoutException::sdkModeNotYetSupported();
        }

        /** @var Model&Payable $model */
        $request = new PaymentLinkRequest(
            amount: $model->getPaymentAmount(),
            currency: $model->getPaymentCurrency(),
            description: class_basename($model) . ' #' . $model->getKey(),
            customer: null,
            returnUrl: $returnUrl,
            cancelUrl: $cancelUrl,
            expiresAt: null,
            idempotencyKey: (string) Str::uuid(),
            metadata: [
                'model_type' => $modelType,
                'model_id'   => (string) $model->getKey(),
            ],
        );

        // A DTO is built directly here, not a plain array — CheckoutService
        // is internal package code (like PaymentService), and PaymentManager
        // only auto-converts arrays for CONFIG-resolved drivers (wrapped in
        // PaymentDriverProxy); a driver registered via extend() (e.g. in
        // tests) is NOT wrapped and would receive this array unconverted,
        // fataling on a PaymentLinkRequest-typed parameter. Building the DTO
        // ourselves works identically either way, and matches the
        // framework-wide rule that internal code only ever passes DTOs.
        return $this->manager->driver($driver)->createPaymentLink($request);
    }

    /**
     * Resolve model_type through the payables allowlist and find the record.
     *
     * model_type is NEVER resolved directly to a class string from request
     * input — only keys already registered in `payment.payables` are
     * reachable (see that config key's own docblock for why).
     *
     * @throws CheckoutException
     */
    private function resolvePayable(string $modelType, string $modelId): Payable
    {
        $payables = (array) config('payment.payables', []);

        if (! array_key_exists($modelType, $payables) || ! is_string($payables[$modelType])) {
            throw CheckoutException::unknownPayableType($modelType);
        }

        $class = $payables[$modelType];

        if (! is_subclass_of($class, Model::class)) {
            throw CheckoutException::modelNotPayable($modelType, $class);
        }

        $model = $class::find($modelId);

        if ($model === null) {
            throw CheckoutException::payableNotFound($modelType, $modelId);
        }

        if (! $model instanceof Payable) {
            throw CheckoutException::modelNotPayable($modelType, $class);
        }

        return $model;
    }
}
