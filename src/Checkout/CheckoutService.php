<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Checkout;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Mifatoyeh\LaravelPaymentFramework\Checkout\Jobs\VerifyPaymentJob;
use Mifatoyeh\LaravelPaymentFramework\Contracts\CapturesCheckoutContext;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\PaymentDriverContract;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\SupportsCallbackHook;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\SupportsCapabilities;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\SupportsSdkCheckout;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\SupportsTrustedWebhookStatus;
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
 * Orchestrates the generic checkout flow for {@see CheckoutController}/
 * {@see CheckoutCallbackController}: resolve a {@see Payable} model from the
 * `payment.payables` allowlist, validate the requested driver is one that
 * model allows, authorise the payer, then dispatch to the resolved driver's
 * `createPaymentLink()` (`driver_type: webview`) or `createSdkIntent()`
 * (`driver_type: sdk`).
 *
 * Five entry points live on this one service, all funnelling through the
 * SAME persistence/callback/event pipeline
 * ({@see self::applyConfirmedStatus()}, reached via
 * {@see self::confirmTransaction()}):
 *   - `checkout()` — starts a payment, records a PENDING
 *     {@see CheckoutTransaction} row, and for `webview`+`os: web` rewrites
 *     the driver-facing return URL to the package's own auto-registered
 *     callback route (see {@see self::buildCallbackUrl()}).
 *   - `confirm()` — authoritative verification on a CLIENT's request.
 *   - `resolveAndConfirm()` — the same, triggered by an inbound provider
 *     callback/webhook instead of a client request (no `$payer` to
 *     authorise — see that method's own docblock). `confirmFromWebhook()`
 *     is now a thin wrapper over it.
 *   - `confirmTransaction()` — the same, given an already-resolved
 *     {@see CheckoutTransaction} and {@see StatusResponse} directly; used
 *     by {@see VerifyPaymentJob} and the reconciliation sweep command,
 *     neither of which have a raw inbound payload to resolve from.
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
        string $os,
        ?string $returnUrl,
        ?string $cancelUrl,
        ?Authenticatable $payer,
    ): PaymentLinkResponse|SdkCheckoutResponse {
        if (! in_array($driverType, ['sdk', 'webview'], true)) {
            throw CheckoutException::unsupportedDriverType($driverType);
        }

        if (! in_array($os, ['web', 'mobile'], true)) {
            throw CheckoutException::unsupportedOs($os);
        }

        // Only THIS combination redirects through the package's own callback
        // route (see buildCallbackUrl()) before landing anywhere — it's the
        // only combination that currently NEEDS return_url at all:
        //   - webview + mobile: out of scope for the callback route for now
        //     (still passes return_url straight to the driver, unchanged
        //     from prior behaviour).
        //   - sdk (either os): no server-side redirect happens at all — the
        //     client SDK confirms in place; confirmation arrives via webhook
        //     or VerifyPaymentJob, never a browser redirect.
        $isWebviewWeb = $driverType === 'webview' && $os === 'web';

        if ($isWebviewWeb && ($returnUrl === null || $returnUrl === '')) {
            throw CheckoutException::returnUrlRequiredForWebviewWeb();
        }

        $model = $this->resolvePayable($modelType, $modelId);

        if (! in_array($driver, $model->getSupportedPaymentDrivers(), true)) {
            throw CheckoutException::unsupportedDriverForPayable($driver, $modelType);
        }

        if (! $model->authorizePayment($payer)) {
            throw CheckoutException::unauthorized();
        }

        $idempotencyKey = (string) Str::uuid();

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
            // webview+web: the DRIVER redirects here first (see buildCallbackUrl()'s
            // own docblock) — the customer's own return_url is stored on the
            // pending row instead (see createPendingTransaction()) and used
            // by CheckoutCallbackController AFTER verification, never handed
            // to the provider directly. Every other combination is
            // unaffected — $returnUrl passes straight through, same as before.
            returnUrl: $isWebviewWeb ? $this->buildCallbackUrl($driver, $idempotencyKey) : $returnUrl,
            cancelUrl: $cancelUrl,
            expiresAt: null,
            idempotencyKey: $idempotencyKey,
            metadata: [
                'model_type' => $modelType,
                'model_id'   => (string) $model->getKey(),
            ],
        );

        $driverInstance = $this->manager->driver($driver);
        $wrapped        = $this->unwrap($driverInstance);

        if ($driverType === 'sdk') {
            if (! $wrapped instanceof SupportsSdkCheckout) {
                throw CheckoutException::sdkModeNotSupportedByDriver($driver);
            }

            $response = $wrapped->createSdkIntent($request);
        } else {
            $response = $driverInstance->createPaymentLink($request);
        }

        // Only reached after a non-throwing driver call — an order/intent
        // genuinely exists at the provider now, so it's worth recording as
        // pending. $idempotencyKey is what every driver forwards to the
        // provider as ITS OWN order/merchant reference (see
        // PaymobClient::createOrder()/createIntention()) — the only thing a
        // later webhook/callback can correlate back to this row by; see
        // self::resolveAndConfirm()'s own docblock.
        $transaction = $this->createPendingTransaction(
            $modelType,
            $modelId,
            $driver,
            $driverType,
            $idempotencyKey,
            $os,
            $returnUrl,
            $cancelUrl,
            // Server-resolved from the CURRENT authenticated request — never
            // client input. This is the only point in the whole checkout
            // lifecycle where a real session exists; every later
            // confirmation path (webhook, callback, background job, sweep)
            // reads it back from here via CheckoutContext, since none of
            // them have a session of their own. getAuthIdentifier() (not
            // ->id) to stay framework-agnostic about what Authenticatable is.
            $payer?->getAuthIdentifier() !== null ? (string) $payer->getAuthIdentifier() : null,
            // Opt-in, model-decided snapshot — see CapturesCheckoutContext's
            // own docblock. Empty array (not called at all) for any model
            // that doesn't implement it; never automatic/reflective.
            $model instanceof CapturesCheckoutContext ? $model->captureCheckoutContext() : [],
            // sdk mode's own reference IS the value lookup()/verify() needs
            // later (Stripe's PaymentIntent id stays stable through its
            // lifecycle) — stored immediately so VerifyPaymentJob/the sweep
            // have something to check from the very first run, rather than
            // waiting for a webhook that might not be coming (that's the
            // whole point of dispatching the job below). NOT stored for
            // webview mode: the provider's real transaction id doesn't exist
            // yet at this point (nothing has been charged), only an order/
            // intent reference the framework's lookup()/verify() contract
            // doesn't accept.
            $response instanceof SdkCheckoutResponse ? $response->getTransactionReference() : null,
            $model,
        );

        if ($transaction !== null && $driverType === 'sdk' && ! $this->driverSupportsWebhook($wrapped)) {
            // No automatic redirect happens for sdk mode, and this driver
            // won't tell us proactively either — dispatch the self-
            // rescheduling verification job so SOMETHING actively checks,
            // rather than leaving confirmation entirely to the 12h sweep.
            // (No row, no job — without a persisted transaction there is
            // nothing for the job to look up or confirm against.)
            VerifyPaymentJob::dispatch($driver, $transaction->id)
                ->delay(config('payment.verification.job.backoff.0', 30));
        }

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
     * {@see self::confirmTransaction()}.
     *
     * `Payable::authorizePayment()` runs here because there IS a client
     * making this request — compare {@see self::resolveAndConfirm()}, which
     * has no authenticated caller to check and relies on webhook signature
     * verification (or, for the callback route, the re-verification itself)
     * as its trust boundary instead.
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
     * triggered by an inbound PROVIDER request instead of an authenticated
     * client one — either:
     *   - `$source === 'webhook'`: the provider's server-to-server
     *     notification, via `routes/webhooks.php` (unchanged mechanism —
     *     {@see self::confirmFromWebhook()} is now a thin wrapper over this).
     *   - `$source === 'callback'`: the browser landing on the package's
     *     own auto-registered `{checkout.route}/callback/{driver}` route
     *     after a provider redirect (currently: Stripe's `success_url`,
     *     rewritten by {@see self::checkout()} to point here — Paymob has
     *     no per-request redirect URL at all, see
     *     {@see \Mifatoyeh\LaravelPaymentFramework\Drivers\Paymob\PaymobDriver}'s
     *     own docblock, so its callback route and webhook route are, in
     *     practice, the same dashboard-configured URL).
     *
     * No authenticated `$payer` exists in either case, so
     * `Payable::authorizePayment()` is deliberately NOT called — the trust
     * boundary is webhook signature verification (already run, for
     * `$source === 'webhook'`, by {@see \Mifatoyeh\LaravelPaymentFramework\Services\WebhookVerifier::verify()}
     * before this is ever reached) or the authoritative re-verification
     * this method itself performs (for `$source === 'callback'` — a raw
     * redirect's own query params are NEVER trusted directly either way).
     *
     * A raw payload never carries `model_type`/`model_id` — providers only
     * echo back whatever merchant-supplied order reference was set at
     * creation time. `checkout()` persists a PENDING {@see CheckoutTransaction}
     * row keyed by `(driver, merchant_order_id)` — the idempotency key it
     * generated and forwarded as the provider's own order reference — the
     * moment the order/intent is created; this method looks that row up to
     * recover which model the payment was for.
     *
     * Step order:
     *   1. Resolve the pending row by `(driver, merchant_order_id)`.
     *   2. If the driver implements {@see SupportsCallbackHook}, call
     *      `onCallbackReceived()` — side effects only, runs BEFORE status
     *      resolution and never influences it.
     *   3. Resolve the authoritative status: prefer
     *      {@see SupportsTrustedWebhookStatus::statusFromWebhookPayload()}
     *      when the driver implements it and returns non-null, else a live
     *      `lookup()` call.
     *   4. {@see self::confirmTransaction()} — persist, model callback, event.
     *
     * Deliberately silent (returns `null`, does not throw) when:
     *   - no order/transaction reference can be read from the payload,
     *   - no matching pending row exists (persistence disabled, or this
     *     payload has nothing to do with the checkout endpoint at all — a
     *     webhook for a bare `charge()`/`saveCard()` call, for instance),
     *   - the row's model has since been deleted.
     * Hard-failing on any of these would both reject legitimate unrelated
     * provider traffic and let a caller probe for which order references
     * exist. Callers that need a real user-facing response for the
     * unresolvable case (the callback route, for a browser sitting there
     * waiting) decide what to show on a `null` return themselves — this
     * method's job is only to resolve and confirm, not to render a response.
     *
     * @param array<string, mixed> $rawPayload The provider's raw payload — flat query params
     *                                          and/or body. Currently understands Paymob's
     *                                          `merchant_order_id`/`id` keys and the
     *                                          `merchant_order_id`/`session_id` keys this
     *                                          package's own callback URL construction uses
     *                                          for Stripe (see {@see self::buildCallbackUrl()}
     *                                          and {@see \Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeClient::createCheckoutSession()}).
     *                                          A third driver will need this list extended.
     *
     * @return CheckoutTransaction|null The refreshed row, or null if nothing could be resolved.
     */
    public function resolveAndConfirm(string $driver, array $rawPayload, string $source): ?CheckoutTransaction
    {
        $merchantOrderId = (string) ($rawPayload['merchant_order_id'] ?? '');
        $transactionId   = (string) ($rawPayload['transaction_reference'] ?? $rawPayload['session_id'] ?? $rawPayload['id'] ?? '');

        if ($merchantOrderId === '' || $transactionId === '') {
            return null;
        }

        $pending = CheckoutTransaction::query()
            ->where('driver', $driver)
            ->where('merchant_order_id', $merchantOrderId)
            ->first();

        if ($pending === null) {
            return null;
        }

        try {
            $model = $this->resolvePayable($pending->model_type, $pending->model_id);
        } catch (CheckoutException) {
            return null;
        }

        $driverInstance = $this->manager->driver($driver);
        $wrapped        = $this->unwrap($driverInstance);

        if ($wrapped instanceof SupportsCallbackHook) {
            $wrapped->onCallbackReceived($rawPayload, $source);
        }

        $status = $wrapped instanceof SupportsTrustedWebhookStatus
            ? $wrapped->statusFromWebhookPayload($rawPayload)
            : null;

        $status ??= $driverInstance->lookup(new TransactionLookupRequest(
            transactionId: TransactionId::fromString($transactionId),
        ));

        $this->applyConfirmedStatus($pending->model_type, $pending->model_id, $driver, $pending->driver_type, $model, $status);

        return $pending->refresh();
    }

    /** @deprecated Thin wrapper — kept as the existing webhook listener's entry point. */
    public function confirmFromWebhook(string $driver, array $rawPayload): void
    {
        $this->resolveAndConfirm($driver, $rawPayload, 'webhook');
    }

    /**
     * Apply an already-resolved {@see StatusResponse} to an already-resolved
     * {@see CheckoutTransaction} row — used by callers that have NO raw
     * inbound payload to correlate from, because they're not reacting to a
     * request at all: {@see VerifyPaymentJob} (re-checking a specific row it
     * was dispatched for) and the reconciliation sweep command (iterating
     * stale Pending rows directly). Both already know exactly which row and
     * already have a `StatusResponse` from their own `lookup()` call; this
     * is just {@see self::applyConfirmedStatus()} with the `Payable`
     * re-resolved from the row.
     *
     * Silently returns if the row's model has since been deleted — same
     * reasoning as {@see self::resolveAndConfirm()}.
     */
    public function confirmTransaction(CheckoutTransaction $transaction, StatusResponse $status): void
    {
        try {
            $model = $this->resolvePayable($transaction->model_type, $transaction->model_id);
        } catch (CheckoutException) {
            return;
        }

        $this->applyConfirmedStatus(
            $transaction->model_type,
            $transaction->model_id,
            $transaction->driver,
            $transaction->driver_type,
            $model,
            $status,
        );
    }

    /**
     * Shared reaction to an authoritatively-known payment outcome — persist
     * the transaction, run the model's callback, dispatch the app-wide
     * event. Safe to call more than once for the same transaction — see
     * {@see Payable::onPaymentCompleted()}'s own docblock for why
     * implementations must be idempotent.
     *
     * `onPaymentCompleted()` is called unconditionally, with no `instanceof`
     * check — it's a required method on `Payable` itself (see that
     * interface's own docblock for the "merged from HasPaymentCallback"
     * history), not an optional capability to detect. It's passed a
     * {@see CheckoutContext} alongside the status — built from the just-
     * persisted row (which carries whatever `checkout()` captured, notably
     * `payerId`) so every confirmation path, HTTP or not, supplies the same
     * shape. Falls back to {@see CheckoutContext::withoutTransaction()} when
     * persistence is disabled — there's no row to build a full context from.
     */
    private function applyConfirmedStatus(
        string $modelType,
        string $modelId,
        string $driver,
        ?string $driverType,
        Payable $model,
        StatusResponse $status,
    ): void {
        $transaction = $this->persistTransaction($modelType, $modelId, $driver, $driverType, $model, $status);

        $context = $transaction !== null
            ? CheckoutContext::fromTransaction($transaction)
            : CheckoutContext::withoutTransaction($driver, $driverType);

        $model->onPaymentCompleted($status, $context);

        $this->events->dispatch(new CheckoutPaymentConfirmed($model, $modelType, $status));
    }

    /**
     * Record a checkout attempt as pending, the moment a provider order/
     * intent genuinely exists (called only after a non-throwing driver
     * call — see {@see self::checkout()}). `merchant_order_id` is what
     * makes {@see self::resolveAndConfirm()} possible at all — without a
     * persisted row to correlate it against, an inbound webhook/callback
     * has no way to learn which model it belongs to.
     *
     * `return_url`/`cancel_url`/`os`/`payer_id`/`custom` are folded into the
     * existing `metadata` JSON column rather than given dedicated columns —
     * they're only ever read back by primary-key lookup (never filtered/
     * queried on), so a dedicated column each would just be migration
     * overhead for no query benefit. `custom` is whatever
     * {@see \Mifatoyeh\LaravelPaymentFramework\Contracts\CapturesCheckoutContext::captureCheckoutContext()}
     * returned, when the model implements it — empty array otherwise.
     * {@see CheckoutCallbackController} reads `os`/`return_url`/`cancel_url`
     * back out directly; {@see CheckoutContext::fromTransaction()} reads all
     * five when building what {@see Payable::onPaymentCompleted()} receives.
     *
     * Gated by `payment.checkout.persist_transactions` (default true), same
     * as {@see self::persistTransaction()} — see that method's own docblock.
     * Note this means the whole webhook/callback confirmation mechanism
     * requires persistence to be enabled — there is no correlation without
     * a stored row.
     */
    /** @param array<string, mixed> $custom */
    private function createPendingTransaction(
        string $modelType,
        string $modelId,
        string $driver,
        string $driverType,
        string $merchantOrderId,
        string $os,
        ?string $returnUrl,
        ?string $cancelUrl,
        ?string $payerId,
        array $custom,
        ?string $initialTransactionReference,
        Payable $model,
    ): ?CheckoutTransaction {
        if (! config('payment.checkout.persist_transactions', true)) {
            // No row, no correlation — the callback/webhook/job/sweep
            // mechanisms all require persistence; see this method's own
            // docblock. checkout() itself still succeeds either way.
            return null;
        }

        return CheckoutTransaction::updateOrCreate(
            ['driver' => $driver, 'model_type' => $modelType, 'model_id' => $modelId],
            array_filter(
                [
                    'driver_type'            => $driverType,
                    'merchant_order_id'      => $merchantOrderId,
                    'transaction_reference'  => $initialTransactionReference,
                    'status'                 => PaymentStatus::Pending->value,
                    'successful'             => false,
                    'amount'                 => $model->getPaymentAmount()->amount,
                    'currency'               => $model->getPaymentCurrency()->value,
                    'message'                => 'Checkout started; awaiting confirmation.',
                    'metadata'               => [
                        'os'         => $os,
                        'return_url' => $returnUrl,
                        'cancel_url' => $cancelUrl,
                        'payer_id'   => $payerId,
                        'custom'     => $custom,
                    ],
                ],
                static fn (mixed $value): bool => $value !== null,
            ),
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
     * the SAME row rather than accumulating duplicates. `metadata` is
     * deliberately NOT overwritten here (it's set once, at pending-creation
     * time, and only ever read afterwards).
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
    ): ?CheckoutTransaction {
        if (! config('payment.checkout.persist_transactions', true)) {
            return null;
        }

        return CheckoutTransaction::updateOrCreate(
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
     * Build the URL a `webview`+`os: web` checkout hands to the DRIVER as
     * its redirect target — the package's own auto-registered
     * `{checkout.route}/callback/{driver}` route (see `routes/callback.php`),
     * NOT the customer's own `return_url` directly. The customer's real
     * destination is stored on the pending row instead (see
     * {@see self::createPendingTransaction()}) and only redirected to AFTER
     * {@see CheckoutCallbackController} has authoritatively verified the
     * outcome — this is the entire point of the callback route existing.
     *
     * `merchant_order_id` is appended so {@see self::resolveAndConfirm()}
     * can correlate the redirect back to a pending row — this package
     * controls this URL end-to-end, so it embeds its own reconciliation key
     * directly rather than depending on a provider echoing one back in a
     * provider-specific field name. The provider's own transaction
     * reference (Stripe's Checkout Session id) is NOT embedded here — it
     * doesn't exist yet at `checkout()` time; see
     * {@see \Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeClient::createCheckoutSession()}
     * for how Stripe's own `{CHECKOUT_SESSION_ID}` template substitution
     * supplies it on redirect instead.
     *
     * UNUSED for Paymob in practice — confirmed (see
     * {@see \Mifatoyeh\LaravelPaymentFramework\Drivers\Paymob\PaymobDriver}'s
     * class docblock) that Paymob has no per-request redirect URL at all;
     * this value is computed and passed through regardless (harmless — the
     * Paymob client simply never reads `PaymentLinkRequest::$returnUrl`),
     * so no driver-specific branching is needed here.
     */
    private function buildCallbackUrl(string $driver, string $merchantOrderId): string
    {
        return route('payment.checkout.callback', ['driver' => $driver])
            . '?merchant_order_id=' . urlencode($merchantOrderId);
    }

    /**
     * Whether a driver proactively tells this package about payment
     * outcomes (a webhook), used by {@see self::checkout()} to decide
     * whether {@see VerifyPaymentJob} needs to actively poll for `sdk` mode.
     *
     * Deliberately inverts {@see SupportsCapabilities}'s own documented
     * default ("a driver that does not implement this interface is assumed
     * to support all operations") for this ONE capability: assuming webhook
     * support that doesn't exist would silently skip the only active
     * verification path `sdk` mode has for that driver. A driver that
     * hasn't explicitly declared `supports('webhook')` is treated as NOT
     * webhook-capable — the safe direction to be wrong in (an extra,
     * harmless verification job) rather than the dangerous one (a payment
     * that never gets actively checked at all).
     */
    private function driverSupportsWebhook(PaymentDriverContract $driver): bool
    {
        return $driver instanceof SupportsCapabilities && $driver->supports('webhook');
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
