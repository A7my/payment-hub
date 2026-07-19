<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Checkout;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\PaymentDriverContract;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\SupportsSdkCheckout;
use Mifatoyeh\LaravelPaymentFramework\Contracts\HasPaymentCallback;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Payable;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentLinkRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\TransactionLookupRequest;
use Mifatoyeh\LaravelPaymentFramework\Drivers\PaymentDriverProxy;
use Mifatoyeh\LaravelPaymentFramework\Events\CheckoutPaymentConfirmed;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\CheckoutException;
use Mifatoyeh\LaravelPaymentFramework\Managers\PaymentManager;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentLinkResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\SdkCheckoutResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\StatusResponse;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;

/**
 * Orchestrates the generic checkout flow for {@see CheckoutController}:
 * resolve a {@see Payable} model from the `payment.payables` allowlist,
 * validate the requested driver is one that model allows, authorise the
 * payer, then dispatch to the resolved driver's `createPaymentLink()`
 * (`driver_type: webview`) or `createSdkIntent()` (`driver_type: sdk`).
 *
 * Uses {@see PaymentManager} directly (constructor-injected) rather than the
 * `Payment` facade — the facade is for host-application callers, not for
 * this package's own internal code, matching how
 * {@see \Mifatoyeh\LaravelPaymentFramework\Services\PaymentService} is
 * built the same way.
 *
 * Both `checkout()` (starting a payment) and `confirm()` (authoritatively
 * verifying its outcome) live on this one service — see `confirm()`'s own
 * docblock for why persistence and callbacks happen there and not here.
 */
final class CheckoutService
{
    public function __construct(
        private readonly PaymentManager $manager,
        private readonly Dispatcher $events,
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
    ): PaymentLinkResponse|SdkCheckoutResponse {
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

        // A DTO is built directly here, not a plain array — CheckoutService
        // is internal package code (like PaymentService), and PaymentManager
        // only auto-converts arrays for CONFIG-resolved drivers (wrapped in
        // PaymentDriverProxy); a driver registered via extend() (e.g. in
        // tests) is NOT wrapped and would receive this array unconverted,
        // fataling on a PaymentLinkRequest-typed parameter. Building the DTO
        // ourselves works identically either way, and matches the
        // framework-wide rule that internal code only ever passes DTOs.
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

        $driverInstance = $this->manager->driver($driver);

        if ($driverType === 'sdk') {
            $wrapped = $this->unwrap($driverInstance);

            if (! $wrapped instanceof SupportsSdkCheckout) {
                throw CheckoutException::sdkModeNotSupportedByDriver($driver);
            }

            return $wrapped->createSdkIntent($request);
        }

        return $driverInstance->createPaymentLink($request);
    }

    /**
     * Authoritatively verify a checkout payment's outcome and react to it.
     *
     * Both `driver_type: webview` (the customer returns from a hosted page)
     * and `driver_type: sdk` (a client-side SDK reports it confirmed the
     * charge) are asynchronous from this package's perspective — neither
     * `createPaymentLink()` nor `createSdkIntent()` itself charges anything.
     * This method NEVER trusts a client-supplied "it succeeded" claim: it
     * re-checks status directly with the provider via the driver's already-
     * implemented, already-tested `lookup()`, then:
     *   1. persists a {@see CheckoutTransaction} row (see
     *      {@see self::persistTransaction()}),
     *   2. calls the model's {@see HasPaymentCallback::onPaymentCompleted()}
     *      if it implements that optional interface,
     *   3. dispatches {@see CheckoutPaymentConfirmed} unconditionally, for
     *      application-wide listeners.
     *
     * Callers should treat this as safely re-triggerable: a webview/SDK flow
     * confirming twice (a double-submitting user, a retried client request)
     * simply re-verifies and re-applies steps 1-3 — see
     * {@see HasPaymentCallback::onPaymentCompleted()}'s own docblock for why
     * implementations must be idempotent, and {@see self::persistTransaction()}
     * for why step 1 is an upsert rather than a duplicate insert.
     *
     * @throws CheckoutException On an unknown/unauthorised model.
     */
    public function confirm(
        string $modelType,
        string $modelId,
        string $driver,
        string $transactionReference,
        ?string $driverType,
        ?Authenticatable $payer,
    ): StatusResponse {
        $model = $this->resolvePayable($modelType, $modelId);

        if (! $model->authorizePayment($payer)) {
            throw CheckoutException::unauthorized();
        }

        $status = $this->manager->driver($driver)->lookup(new TransactionLookupRequest(
            transactionId: TransactionId::fromString($transactionReference),
        ));

        $this->persistTransaction($modelType, $modelId, $driver, $driverType, $model, $status);

        if ($model instanceof HasPaymentCallback) {
            $model->onPaymentCompleted($status);
        }

        $this->events->dispatch(new CheckoutPaymentConfirmed($model, $modelType, $status));

        return $status;
    }

    /**
     * Persist (or update, on a repeat confirmation) a checkout transaction
     * record — gated by `payment.checkout.persist_transactions` (default
     * true) so a host app that hasn't published/run the
     * `checkout_transactions` migration doesn't hit a missing-table error
     * merely by using the checkout endpoint.
     *
     * `updateOrCreate()` keyed on (driver, transaction_reference) — the same
     * pair the migration's own unique index enforces — makes repeat
     * `confirm()` calls for the same transaction update the existing row
     * with the latest status rather than accumulating duplicates.
     *
     * @param Model&Payable $model
     */
    private function persistTransaction(
        string $modelType,
        string $modelId,
        string $driver,
        ?string $driverType,
        Payable $model,
        StatusResponse $status,
    ): void {
        if (! config('payment.checkout.persist_transactions', true)) {
            return;
        }

        CheckoutTransaction::updateOrCreate(
            [
                'driver'                 => $driver,
                'transaction_reference'  => $status->getTransactionId()->toString(),
            ],
            [
                'model_type'   => $modelType,
                'model_id'     => $modelId,
                'driver_type'  => $driverType,
                'status'       => $status->getStatus()->value,
                'successful'   => $status->isSuccessful() && $status->getStatus()->isSuccessful(),
                'amount'       => $model->getPaymentAmount()->amount,
                'currency'     => $model->getPaymentCurrency()->value,
                'message'      => $status->getMessage(),
                'raw_response' => $status->getRawResponse(),
            ],
        );
    }

    /**
     * Unwrap a `PaymentManager::driver()` result to the real driver instance
     * so optional capability interfaces (like {@see SupportsSdkCheckout})
     * not part of {@see PaymentDriverContract} can be `instanceof`-checked.
     *
     * Only CONFIG-resolved drivers are wrapped in {@see PaymentDriverProxy};
     * a driver registered via `extend()` (tests, `Payment::fake()`) is
     * returned as-is — see {@see PaymentDriverProxy::getWrappedDriver()}'s
     * own docblock.
     */
    private function unwrap(PaymentDriverContract $driver): PaymentDriverContract
    {
        return $driver instanceof PaymentDriverProxy ? $driver->getWrappedDriver() : $driver;
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
