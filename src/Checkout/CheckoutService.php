<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Checkout;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\PaymentDriverContract;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\SupportsSdkCheckout;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Payable;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentLinkRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\TransactionLookupRequest;
use Mifatoyeh\LaravelPaymentFramework\Drivers\PaymentDriverProxy;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
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
 * Three entry points live on this one service:
 *   - `checkout()` — starts a payment, and records a PENDING
 *     {@see CheckoutTransaction} row so it can be found again later.
 *   - `confirm()` — authoritatively verifies the outcome when the CLIENT
 *     (frontend after a webview redirect, or after an SDK reports success)
 *     asks this package to check.
 *   - `confirmFromWebhook()` — the same authoritative verification, but
 *     triggered automatically by the PROVIDER's own server-to-server
 *     webhook instead of a client request — see that method's own docblock
 *     for why it exists and how it correlates a webhook (which carries no
 *     model_type/model_id) back to the right row.
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

            $response = $wrapped->createSdkIntent($request);
        } else {
            $response = $driverInstance->createPaymentLink($request);
        }

        // Only reached after a non-throwing driver call — an order/intent
        // genuinely exists at the provider now, so it's worth recording as
        // pending. $request->idempotencyKey is what every driver forwards to
        // the provider as ITS OWN order/merchant reference (see
        // PaymobClient::createOrder()/createIntention()) — the only thing a
        // later webhook can correlate back to this row by; see
        // self::confirmFromWebhook()'s own docblock.
        $this->createPendingTransaction($modelType, $modelId, $driver, $driverType, $request->idempotencyKey, $model);

        return $response;
    }

    /**
     * Authoritatively verify a checkout payment's outcome and react to it,
     * on a CLIENT's request (a frontend calling back after a webview
     * redirect, or after an SDK reports it confirmed the charge).
     *
     * This method NEVER trusts a client-supplied "it succeeded" claim: it
     * re-checks status directly with the provider via the driver's already-
     * implemented, already-tested `lookup()`, then applies the result via
     * {@see self::applyConfirmedStatus()}.
     *
     * `Payable::authorizePayment()` runs here because there IS a client
     * making this request — compare {@see self::confirmFromWebhook()},
     * which has no authenticated caller to check and relies on webhook
     * signature verification as its trust boundary instead.
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

        $this->applyConfirmedStatus($modelType, $modelId, $driver, $driverType, $model, $status);

        return $status;
    }

    /**
     * The same authoritative confirmation as {@see self::confirm()}, but
     * triggered automatically by the PROVIDER's own server-to-server
     * webhook instead of a client request — wired via a `WebhookProcessed`
     * listener in `PaymentServiceProvider::boot()`, called only after
     * {@see \Mifatoyeh\LaravelPaymentFramework\Services\WebhookVerifier::verify()}
     * has already confirmed the request genuinely came from the provider.
     * That signature verification IS this method's trust boundary — unlike
     * `confirm()`, there is no authenticated `$payer` in a server-to-server
     * callback, so `Payable::authorizePayment()` is deliberately NOT called
     * here.
     *
     * A webhook payload never carries `model_type`/`model_id` — providers
     * only echo back whatever merchant-supplied order reference was set at
     * creation time. `checkout()` persists a PENDING {@see CheckoutTransaction}
     * row keyed by `(driver, merchant_order_id)` — the idempotency key it
     * generated and forwarded as the provider's own order reference — the
     * moment the order/intent is created; this method looks that row up to
     * recover which model the payment was for.
     *
     * Deliberately silent (does not throw) when:
     *   - no order/transaction reference can be read from the payload,
     *   - no matching pending row exists (persistence disabled, or this
     *     webhook has nothing to do with the checkout endpoint at all — a
     *     webhook for a bare `charge()`/`saveCard()` call, for instance),
     *   - the row's model has since been deleted.
     * A webhook endpoint hard-failing on any of these would both reject
     * legitimate unrelated provider traffic and let a caller probe for
     * which order references exist.
     *
     * @param array<string, mixed> $rawPayload The webhook's raw, driver-specific payload
     *                                          ({@see \Mifatoyeh\LaravelPaymentFramework\Responses\WebhookResponse::getRawPayload()}).
     *                                          Currently only understands Paymob's flat
     *                                          `merchant_order_id`/`id` keys — a second
     *                                          driver implementing webhooks will need this
     *                                          extended (or given a per-driver mapping).
     */
    public function confirmFromWebhook(string $driver, array $rawPayload): void
    {
        $merchantOrderId = (string) ($rawPayload['merchant_order_id'] ?? '');
        $transactionId   = (string) ($rawPayload['id'] ?? '');

        if ($merchantOrderId === '' || $transactionId === '') {
            return;
        }

        $pending = CheckoutTransaction::query()
            ->where('driver', $driver)
            ->where('merchant_order_id', $merchantOrderId)
            ->first();

        if ($pending === null) {
            return;
        }

        try {
            $model = $this->resolvePayable($pending->model_type, $pending->model_id);
        } catch (CheckoutException) {
            return;
        }

        $status = $this->manager->driver($driver)->lookup(new TransactionLookupRequest(
            transactionId: TransactionId::fromString($transactionId),
        ));

        $this->applyConfirmedStatus($pending->model_type, $pending->model_id, $driver, $pending->driver_type, $model, $status);
    }

    /**
     * Shared reaction to an authoritatively-known payment outcome, used by
     * both {@see self::confirm()} and {@see self::confirmFromWebhook()}:
     * persist the transaction, run the model's callback, dispatch the
     * app-wide event. Safe to call more than once for the same transaction
     * — see {@see Payable::onPaymentCompleted()}'s own docblock for why
     * implementations must be idempotent.
     *
     * `onPaymentCompleted()` is called unconditionally, with no `instanceof`
     * check — it's a required method on `Payable` itself (see that
     * interface's own docblock for the "merged from HasPaymentCallback"
     * history), not an optional capability to detect.
     */
    private function applyConfirmedStatus(
        string $modelType,
        string $modelId,
        string $driver,
        ?string $driverType,
        Payable $model,
        StatusResponse $status,
    ): void {
        $this->persistTransaction($modelType, $modelId, $driver, $driverType, $model, $status);

        $model->onPaymentCompleted($status);

        $this->events->dispatch(new CheckoutPaymentConfirmed($model, $modelType, $status));
    }

    /**
     * Record a checkout attempt as pending, the moment a provider order/
     * intent genuinely exists (called only after a non-throwing driver
     * call — see {@see self::checkout()}). `merchant_order_id` is what
     * makes {@see self::confirmFromWebhook()} possible at all — without a
     * persisted row to correlate it against, an inbound webhook has no way
     * to learn which model it belongs to.
     *
     * Gated by `payment.checkout.persist_transactions` (default true), same
     * as {@see self::persistTransaction()} — see that method's own docblock.
     */
    private function createPendingTransaction(
        string $modelType,
        string $modelId,
        string $driver,
        string $driverType,
        string $merchantOrderId,
        Payable $model,
    ): void {
        if (! config('payment.checkout.persist_transactions', true)) {
            return;
        }

        CheckoutTransaction::updateOrCreate(
            ['driver' => $driver, 'model_type' => $modelType, 'model_id' => $modelId],
            [
                'driver_type'       => $driverType,
                'merchant_order_id' => $merchantOrderId,
                'status'            => PaymentStatus::Pending->value,
                'successful'        => false,
                'amount'            => $model->getPaymentAmount()->amount,
                'currency'          => $model->getPaymentCurrency()->value,
                'message'           => 'Checkout started; awaiting confirmation.',
            ],
        );
    }

    /**
     * Persist (or update, on a repeat confirmation) a checkout transaction
     * record — gated by `payment.checkout.persist_transactions` (default
     * true) so a host app that hasn't published/run the
     * `checkout_transactions` migration doesn't hit a missing-table error
     * merely by using the checkout endpoint.
     *
     * `updateOrCreate()` keyed on `(driver, model_type, model_id)` — the
     * same triple the migration's own unique index enforces, and the same
     * key {@see self::createPendingTransaction()} inserted under — makes
     * both the initial pending write and every later confirmation land on
     * the SAME row rather than accumulating duplicates.
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
            ['driver' => $driver, 'model_type' => $modelType, 'model_id' => $modelId],
            array_filter(
                [
                    'driver_type'            => $driverType,
                    'transaction_reference'  => $status->getTransactionId()->toString(),
                    'status'                 => $status->getStatus()->value,
                    'successful'             => $status->isSuccessful() && $status->getStatus()->isSuccessful(),
                    'amount'                 => $model->getPaymentAmount()->amount,
                    'currency'               => $model->getPaymentCurrency()->value,
                    'message'                => $status->getMessage(),
                    'raw_response'           => $status->getRawResponse(),
                ],
                static fn (mixed $value): bool => $value !== null,
            ),
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
