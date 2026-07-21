<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe;

use Mifatoyeh\LaravelPaymentFramework\DTO\CancelSubscriptionRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\CaptureRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentLinkRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\RefundRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\SaveCardRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\SubscriptionRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\TokenChargeRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\TransactionLookupRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\VoidRequest;
use Stripe\StripeClient as StripeSdkClient;

/**
 * Thin transport wrapper around the Stripe SDK.
 *
 * Responsible ONLY for communication with Stripe's API. Contains no business
 * logic (no status interpretation, no event dispatch, no exception mapping —
 * those belong to {@see StripeDriver}, {@see StripeMapper}, and
 * {@see StripeExceptionMapper} respectively) and no framework Response
 * construction. Every method returns the raw, JSON-decoded Stripe payload
 * as an array so {@see StripeMapper} can translate it.
 *
 * Credentials are read exclusively from the injected driver config (never
 * from globals or the environment directly) and are never exposed via any
 * public accessor — only the private, lazily-built SDK client instance ever
 * sees the secret key.
 */
final class StripeClient
{
    /**
     * The lazily-instantiated Stripe SDK client. Null until first use.
     */
    private ?StripeSdkClient $sdk = null;

    /**
     * @param array<string, mixed> $config The driver's config block from payment.drivers.stripe
     *                                      (secret key, sandbox flag, timeout, etc.).
     */
    public function __construct(
        private readonly array $config = [],
    ) {
    }

    /**
     * Create a Stripe PaymentIntent for the given payment request.
     *
     * Confirms immediately (`confirm: true`) since `charge()` represents an
     * intent to capture funds now, not merely to reserve them. The request's
     * idempotency key is forwarded as the Stripe idempotency key so retried
     * calls (via {@see \Mifatoyeh\LaravelPaymentFramework\Drivers\AbstractDriver::withRetry()})
     * are safe against duplicate charges on Stripe's side too.
     *
     * `$request->options` — arbitrary Stripe parameters with no dedicated
     * framework DTO property (`automatic_payment_methods`, `capture_method`,
     * `setup_future_usage`, `receipt_email`, `shipping`, or any future Stripe
     * parameter) — are merged in verbatim, forwarding them to Stripe
     * untouched. This class never hardcodes or special-cases any of them.
     * Framework-derived values (`amount`, `currency`, `confirm`,
     * `payment_method`, `metadata`) always win on key collision: `$params`
     * is merged SECOND, since PHP's `array_merge()` lets the later array's
     * values overwrite the earlier one's for matching string keys. A caller
     * cannot use `options` to override the amount actually charged.
     *
     * Performs no interpretation of the result or of any exception raised —
     * both are simply propagated to the caller.
     *
     * @param PaymentRequest $request The payment request to charge.
     *
     * @return array<string, mixed> The raw, decoded Stripe PaymentIntent payload.
     *
     * @throws \Stripe\Exception\ApiErrorException On any Stripe API error (including card declines).
     */
    public function createPaymentIntent(PaymentRequest $request): array
    {
        $intent = $this->sdk()->paymentIntents->create(
            $this->buildPaymentIntentParams($request),
            ['idempotency_key' => $request->idempotencyKey],
        );

        return $intent->toArray();
    }

    /**
     * Create a Stripe PaymentIntent with `capture_method: manual` — funds are
     * authorised (reserved) but not captured, for {@see StripeDriver::authorize()}.
     *
     * Identical to {@see self::createPaymentIntent()} in every other respect
     * (still confirms immediately, still forwards the idempotency key and
     * `$request->options` the same way). `capture_method` is a framework-level
     * semantic distinction between authorize() and charge() — not something a
     * caller chooses per-call via options — so, like `confirm`, it is always
     * set here and always wins over a conflicting option.
     *
     * @param PaymentRequest $request The payment request to authorize.
     *
     * @return array<string, mixed> The raw, decoded Stripe PaymentIntent payload.
     *
     * @throws \Stripe\Exception\ApiErrorException On any Stripe API error (including card declines).
     */
    public function createAuthorization(PaymentRequest $request): array
    {
        $intent = $this->sdk()->paymentIntents->create(
            $this->buildPaymentIntentParams($request, ['capture_method' => 'manual']),
            ['idempotency_key' => $request->idempotencyKey],
        );

        return $intent->toArray();
    }

    /**
     * Cancel (void) a Stripe PaymentIntent that has not yet been captured.
     *
     * Releases the reserved funds without any settlement. Stripe's cancel
     * endpoint (`POST /v1/payment_intents/{id}/cancel`) takes the PaymentIntent
     * id directly — there is no amount, currency, or payment_method to build,
     * unlike {@see self::createPaymentIntent()}.
     *
     * `$request->reason` is intentionally NOT forwarded as Stripe's
     * `cancellation_reason` parameter: Stripe restricts that field to a fixed
     * enum (`duplicate`, `fraudulent`, `requested_by_customer`, `abandoned`),
     * while `VoidRequest::$reason` is free-text audit information. Coercing
     * arbitrary text into that enum would be business logic this thin wrapper
     * should not contain; `$request->reason` remains available to
     * {@see StripeDriver} for logging only.
     *
     * Performs no interpretation of the result or of any exception raised —
     * both are simply propagated to the caller.
     *
     * @param VoidRequest $request The void request identifying the transaction to cancel.
     *
     * @return array<string, mixed> The raw, decoded Stripe PaymentIntent payload.
     *
     * @throws \Stripe\Exception\ApiErrorException On any Stripe API error
     *         (e.g. InvalidRequestException when the PaymentIntent is not in
     *         a cancellable state — cancelling never touches a card network,
     *         so a CardException cannot occur here).
     */
    public function cancelPaymentIntent(VoidRequest $request): array
    {
        $intent = $this->sdk()->paymentIntents->cancel(
            $request->transactionId->toString(),
            [],
            ['idempotency_key' => $request->idempotencyKey],
        );

        return $intent->toArray();
    }

    /**
     * Capture the funds of a PaymentIntent previously authorised via
     * {@see self::createAuthorization()} (status `requires_capture`).
     *
     * `CaptureRequest::$amount` is always forwarded as Stripe's
     * `amount_to_capture` — the DTO requires a non-zero amount regardless of
     * whether the caller intends a full or partial capture (capturing the
     * full originally-authorised amount vs. less is the caller's choice, not
     * something this client decides); Stripe treats a full-amount value the
     * same as omitting the parameter.
     *
     * Verified against the Stripe SDK: `PaymentIntentService::capture()` only
     * documents a generic `ApiErrorException` — capturing does not re-touch
     * a card network (the hold was already placed at authorize()-time), so
     * a {@see \Stripe\Exception\CardException} is not a realistic outcome
     * here. Real capture failures (expired authorisation window — 7 days by
     * default per Stripe's docs, already-captured/-cancelled PaymentIntent,
     * amount exceeding the authorised hold) surface as
     * {@see \Stripe\Exception\InvalidRequestException}.
     *
     * Performs no interpretation of the result or of any exception raised —
     * both are simply propagated to the caller.
     *
     * @param CaptureRequest $request The capture request identifying the transaction and amount.
     *
     * @return array<string, mixed> The raw, decoded Stripe PaymentIntent payload.
     *
     * @throws \Stripe\Exception\ApiErrorException On any Stripe API error.
     */
    public function capturePaymentIntent(CaptureRequest $request): array
    {
        $intent = $this->sdk()->paymentIntents->capture(
            $request->transactionId->toString(),
            ['amount_to_capture' => $request->amount->amount],
            ['idempotency_key' => $request->idempotencyKey],
        );

        return $intent->toArray();
    }

    /**
     * Create a Stripe Refund for a previously charged/captured PaymentIntent.
     *
     * Shared verbatim by both {@see StripeDriver::refund()} and
     * {@see StripeDriver::partialRefund()} — Stripe's `refunds->create()`
     * takes the identical `amount` parameter for a full or partial refund
     * (full vs. partial is not a distinct Stripe API operation), so there is
     * nothing for this client method to fork on. `RefundRequest::$amount` is
     * always forwarded as Stripe's `amount`.
     *
     * `expand: ['charge']` is always requested so the response embeds the
     * full Charge object (not just its id) in the SAME API call. The Charge
     * object — verified against the SDK — carries `amount` (the original
     * total charged) and `amount_refunded` (the CUMULATIVE amount refunded
     * across every refund on that charge, per Stripe's own docblock: "can be
     * less than the amount attribute on the charge if a partial refund was
     * issued"). {@see StripeMapper::toRefundResponse()} uses these two
     * fields to correctly distinguish a full refund from a partial one —
     * including when several partial refunds sum to the total — without a
     * second API round-trip. Note: `PaymentIntent` itself has NO refund-
     * related fields at all (verified against the SDK); the Charge is the
     * only object that tracks this.
     *
     * `$request->reason` is intentionally NOT forwarded as Stripe's `reason`
     * parameter: Stripe restricts that field to a fixed enum (`duplicate`,
     * `fraudulent`, `requested_by_customer` — a fourth value,
     * `expired_uncaptured_charge`, is Stripe-generated and not caller-settable),
     * while `RefundRequest::$reason` is free-text audit information, exactly
     * the same mismatch as {@see self::cancelPaymentIntent()}'s `reason`
     * handling. Coercing arbitrary text into that enum would be business
     * logic this thin wrapper should not contain; `$request->reason` remains
     * available to {@see StripeDriver} for logging only.
     *
     * Verified against the Stripe SDK: `RefundService::create()` only
     * documents a generic `ApiErrorException` — this is true regardless of
     * whether the caller conceptually intends a full or partial refund (an
     * amount exceeding the remaining refundable balance is rejected the same
     * way for both). There is no dedicated "insufficient balance" exception
     * class in the SDK — a synchronous refund-creation failure of that kind
     * surfaces as an {@see \Stripe\Exception\InvalidRequestException} like
     * any other invalid refund request, already covered by the existing
     * {@see StripeExceptionMapper} rule. Note that many refund failures are
     * NOT synchronous at all: a refund can be accepted with `status: pending`
     * and fail later (reported via `failure_reason`, observable on
     * subsequent lookup or via webhook) — this method simply reports
     * whatever status Stripe returns at call time.
     *
     * Performs no interpretation of the result or of any exception raised —
     * both are simply propagated to the caller.
     *
     * @param RefundRequest $request The refund request identifying the transaction and amount.
     *
     * @return array<string, mixed> The raw, decoded Stripe Refund payload (with `charge` expanded).
     *
     * @throws \Stripe\Exception\ApiErrorException On any Stripe API error.
     */
    public function createRefund(RefundRequest $request): array
    {
        $refund = $this->sdk()->refunds->create(
            [
                'payment_intent' => $request->transactionId->toString(),
                'amount'         => $request->amount->amount,
                'expand'         => ['charge'],
            ],
            ['idempotency_key' => $request->idempotencyKey],
        );

        return $refund->toArray();
    }

    /**
     * Create a Stripe Customer to scope a saved payment method to, for
     * {@see StripeDriver::saveCard()}.
     *
     * `SaveCardRequest` carries no name/email/phone (verified — it only has
     * `$token`, `$customerId`, `$idempotencyKey`, `$metadata`; `$customerId`
     * is the host application's own opaque reference, per its own docblock,
     * not identity data usable here), so this creates a Customer with no
     * identifying fields — Stripe's `customers->create()` accepts an empty
     * params array. `$request->customerId` is forwarded ONLY inside
     * `metadata.host_customer_id`, purely for cross-referencing/traceability
     * on the Stripe dashboard; it is never sent as `email`, `name`, or any
     * field Stripe would treat as identity data.
     *
     * Uses `$request->idempotencyKey` suffixed with `:customer` — NOT the
     * bare key — because {@see self::createSetupIntent()} (a second, distinct
     * Stripe API call for the same `saveCard()` operation) also needs an
     * idempotency key, and Stripe idempotency keys must uniquely identify
     * one specific request; reusing the identical key string across two
     * different endpoints is avoided entirely rather than relying on
     * cross-endpoint dedup behaviour that isn't documented.
     *
     * Performs no interpretation of the result or of any exception raised —
     * both are simply propagated to the caller.
     *
     * @param SaveCardRequest $request The save-card request (used here only for its idempotency key and metadata).
     *
     * @return array<string, mixed> The raw, decoded Stripe Customer payload.
     *
     * @throws \Stripe\Exception\ApiErrorException On any Stripe API error.
     */
    public function createCustomer(SaveCardRequest $request): array
    {
        $customer = $this->sdk()->customers->create(
            [
                'metadata' => array_merge(
                    ['host_customer_id' => $request->customerId->toString()],
                    $request->metadata,
                ),
            ],
            ['idempotency_key' => $request->idempotencyKey . ':customer'],
        );

        return $customer->toArray();
    }

    /**
     * Create and immediately confirm a Stripe SetupIntent, attaching
     * `$request->token` (a PaymentMethod id) to `$stripeCustomerId` for
     * future off-session reuse via {@see StripeDriver::chargeToken()}, for
     * {@see StripeDriver::saveCard()}.
     *
     * Verified against the SDK (`PaymentMethodService::attach()`'s own
     * docblock, `PaymentMethod::$customer`/`PaymentIntent::$customer`): a
     * bare `payment_methods/{id}/attach` call is NOT Stripe's recommended
     * path for enabling later off-session charges — a SetupIntent (or
     * `setup_future_usage`) is. `usage: 'off_session'` records that intent
     * explicitly. `payment_method_types: ['card']` is fixed rather than
     * left to Stripe's automatic detection, so confirming synchronously
     * here never picks a redirect-based method type that would require a
     * `return_url` we have no use for (this driver has no customer-present
     * redirect flow for saveCard()).
     *
     * Uses `$request->idempotencyKey` suffixed with `:setup_intent` — see
     * {@see self::createCustomer()}'s docblock for why a suffix is used at
     * all instead of the bare key.
     *
     * Verified against the SDK: SetupIntent confirmation genuinely touches
     * a card network (the card is verified, sometimes via a zero/small
     * authorisation) and CAN raise a {@see \Stripe\Exception\CardException}
     * on decline — this is not a money-movement-free operation like
     * {@see self::cancelPaymentIntent()} or {@see self::capturePaymentIntent()}.
     *
     * Performs no interpretation of the result or of any exception raised —
     * both are simply propagated to the caller.
     *
     * @param string          $stripeCustomerId The Stripe Customer id (from {@see self::createCustomer()}) to scope this payment method to.
     * @param SaveCardRequest $request           The save-card request identifying the token and metadata.
     *
     * @return array<string, mixed> The raw, decoded Stripe SetupIntent payload.
     *
     * @throws \Stripe\Exception\ApiErrorException On any Stripe API error, including {@see \Stripe\Exception\CardException} on decline.
     */
    public function createSetupIntent(string $stripeCustomerId, SaveCardRequest $request): array
    {
        $setupIntent = $this->sdk()->setupIntents->create(
            array_filter(
                [
                    'customer'              => $stripeCustomerId,
                    'payment_method'        => $request->token->toString(),
                    'confirm'               => true,
                    'usage'                 => 'off_session',
                    'payment_method_types'  => ['card'],
                    'metadata'              => $request->metadata,
                ],
                static fn (mixed $value): bool => $value !== null && $value !== [],
            ),
            ['idempotency_key' => $request->idempotencyKey . ':setup_intent'],
        );

        return $setupIntent->toArray();
    }

    /**
     * Create and immediately confirm a Stripe PaymentIntent against a
     * previously saved payment method, for {@see StripeDriver::chargeToken()}.
     *
     * `$request->providerCustomerReference` is forwarded as Stripe's
     * `customer` parameter — REQUIRED, not optional, whenever it is
     * present: verified against the SDK
     * (`PaymentIntent::$customer`'s own docblock — "Payment methods attached
     * to other Customers cannot be used with this PaymentIntent") that a
     * saved payment method is scoped to the Customer it was attached to via
     * {@see self::createSetupIntent()}; omitting `customer` here (or
     * supplying a mismatched one) causes Stripe to reject the charge. This
     * client method does not itself validate presence — see
     * {@see StripeDriver::chargeToken()} for the framework-level guard that
     * rejects a missing value before any Stripe call is attempted.
     *
     * `off_session: true` is always set (not caller-configurable — this
     * driver has no merchant-initiated/customer-initiated distinction
     * exposed on `TokenChargeRequest`): `chargeToken()`'s own docblock
     * describes charging "without requiring the customer to re-enter
     * payment details", i.e. no customer session is present.
     *
     * Performs no interpretation of the result or of any exception raised —
     * both are simply propagated to the caller.
     *
     * @param TokenChargeRequest $request The token charge request identifying the token, amount, and provider customer reference.
     *
     * @return array<string, mixed> The raw, decoded Stripe PaymentIntent payload.
     *
     * @throws \Stripe\Exception\ApiErrorException On any Stripe API error, including {@see \Stripe\Exception\CardException} on decline.
     */
    public function createTokenCharge(TokenChargeRequest $request): array
    {
        $intent = $this->sdk()->paymentIntents->create(
            array_filter(
                [
                    'amount'         => $request->amount->amount,
                    'currency'       => strtolower($request->currency->value),
                    'customer'       => $request->providerCustomerReference,
                    'payment_method' => $request->token->toString(),
                    'confirm'        => true,
                    'off_session'    => true,
                    'metadata'       => $request->metadata,
                ],
                static fn (mixed $value): bool => $value !== null && $value !== [],
            ),
            ['idempotency_key' => $request->idempotencyKey],
        );

        return $intent->toArray();
    }

    /**
     * Retrieve a PaymentIntent's current state from Stripe.
     *
     * Shared verbatim by both {@see StripeDriver::verify()} and
     * {@see StripeDriver::lookup()} — both need exactly the same data (the
     * base PaymentIntent, whose `status` field is always populated on a
     * plain retrieve, verified against the SDK). Neither operation requires
     * an `expand` param: unlike {@see self::createRefund()}, there is no
     * nested object needed here — Stripe does not attach a signature or
     * hash to a PaymentIntent to expand and check (that capability, alluded
     * to generically in {@see \Mifatoyeh\LaravelPaymentFramework\Responses\VerificationResponse}'s
     * docblock, targets providers that sign redirect parameters; Stripe does
     * not). The two driver methods differ only in how they INTERPRET this
     * same raw payload — see {@see StripeMapper::toVerificationResponse()}
     * vs {@see StripeMapper::toStatusResponse()}.
     *
     * Performs no interpretation of the result or of any exception raised —
     * both are simply propagated to the caller.
     *
     * @param TransactionLookupRequest $request The lookup/verification request identifying the transaction.
     *
     * @return array<string, mixed> The raw, decoded Stripe PaymentIntent payload.
     *
     * @throws \Stripe\Exception\ApiErrorException On any Stripe API error
     *         (e.g. InvalidRequestException — HTTP 404 — for an unknown or
     *         invalid PaymentIntent id).
     */
    public function retrievePaymentIntent(TransactionLookupRequest $request): array
    {
        $intent = $this->sdk()->paymentIntents->retrieve(
            $request->transactionId->toString(),
        );

        return $intent->toArray();
    }

    /**
     * Build the Stripe PaymentIntent params shared by {@see self::createPaymentIntent()}
     * and {@see self::createAuthorization()}.
     *
     * `$request->options` — arbitrary Stripe parameters with no dedicated
     * framework DTO property (`automatic_payment_methods`, `setup_future_usage`,
     * `receipt_email`, `shipping`, or any future Stripe parameter) — are
     * merged in verbatim, forwarding them to Stripe untouched. This class
     * never hardcodes or special-cases any of them. Framework-derived values
     * (`amount`, `currency`, `confirm`, `capture_method`, `payment_method`,
     * `metadata`) always win on key collision: `$params` is merged SECOND,
     * since PHP's `array_merge()` lets the later array's values overwrite the
     * earlier one's for matching string keys. A caller cannot use `options`
     * to override the amount actually charged or force auto-capture on an
     * authorize() call.
     *
     * @param PaymentRequest        $request The payment request being charged or authorized.
     * @param array<string, mixed>  $extra   Additional framework-derived params for this specific
     *                                       operation (e.g. `capture_method` for authorize()).
     *
     * @return array<string, mixed>
     */
    private function buildPaymentIntentParams(PaymentRequest $request, array $extra = []): array
    {
        $params = array_filter(
            array_merge(
                [
                    'amount'          => $request->amount->amount,
                    'currency'        => strtolower($request->currency->value),
                    'confirm'         => true,
                    'payment_method'  => $request->token?->toString(),
                    'metadata'        => $request->metadata,
                ],
                $extra,
            ),
            static fn (mixed $value): bool => $value !== null && $value !== [],
        );

        // Provider-specific options first, framework-derived $params second —
        // framework values must always win on key collision.
        return array_merge($request->options, $params);
    }

    /**
     * Create a Stripe Subscription for the given subscription request.
     *
     * `$request->planId` is forwarded as `items[0].price` — verified against
     * the SDK: `items[].price_data` (Stripe's inline/ad-hoc pricing path)
     * still requires an existing Stripe *Product* id under `price_data.product`
     * even when the amount/interval are supplied inline, so there is no
     * genuinely ad-hoc path available without a pre-existing Stripe catalog
     * object either way — this driver only supports the pre-existing-Price
     * path. This method itself performs no validation of `$request->planId`
     * or `$request->providerCustomerReference` (thin wrapper, no business
     * logic) — see {@see StripeDriver::createSubscription()} for the
     * framework-level guards.
     *
     * `default_payment_method` is only included when `$request->token` is
     * present — verified against the SDK that it is genuinely optional:
     * Stripe falls back to the customer's own stored default payment method
     * when omitted.
     *
     * `expand: ['latest_invoice.payments.data.payment.payment_intent']` is
     * always requested so a caller can inspect the first invoice's payment
     * outcome (needed to disambiguate Stripe's `incomplete` status — see
     * {@see StripeMapper::toSubscriptionResponse()}) without a second round
     * trip. NOTE: this specific 4-level expand path has NOT been verified
     * against a live Stripe API call (no network access during development)
     * — verified only via the SDK's documented `Invoice::$payments` shape,
     * which replaced the older, no-longer-existent `Invoice::$payment_intent`
     * property in this SDK version (stripe-php 20.3.1). The mapper handles a
     * partially- or non-resolved expand defensively rather than assuming it
     * always succeeds.
     *
     * Verified against the SDK: creating a subscription does NOT throw a
     * synchronous {@see \Stripe\Exception\CardException} on a first-charge
     * decline the way confirming a PaymentIntent does — a failed first
     * charge instead surfaces as `Subscription::$status === 'incomplete'`,
     * inspected via the payload, not an exception. This is unlike
     * {@see self::createPaymentIntent()}/{@see self::createTokenCharge()}.
     *
     * Performs no interpretation of the result or of any exception raised —
     * both are simply propagated to the caller.
     *
     * @param SubscriptionRequest $request The subscription creation request.
     *
     * @return array<string, mixed> The raw, decoded Stripe Subscription payload.
     *
     * @throws \Stripe\Exception\ApiErrorException On any Stripe API error.
     */
    public function createSubscription(SubscriptionRequest $request): array
    {
        $subscription = $this->sdk()->subscriptions->create(
            array_filter(
                [
                    'customer'                => $request->providerCustomerReference,
                    'items'                   => [['price' => $request->planId]],
                    'default_payment_method'  => $request->token?->toString(),
                    'trial_period_days'       => $request->hasTrial() ? $request->trialDays : null,
                    'metadata'                => $request->metadata,
                    'expand'                  => ['latest_invoice.payments.data.payment.payment_intent'],
                ],
                static fn (mixed $value): bool => $value !== null && $value !== [],
            ),
            ['idempotency_key' => $request->idempotencyKey],
        );

        return $subscription->toArray();
    }

    /**
     * Cancel a Stripe Subscription immediately, for
     * {@see StripeDriver::cancelSubscription()} when
     * `$request->cancelAtPeriodEnd` is false.
     *
     * `$request->invoiceNow`/`$request->prorate` are forwarded as-is — both
     * verified against the SDK as genuine `SubscriptionService::cancel()`
     * params, only meaningful for immediate cancellation (verified: neither
     * appears in `SubscriptionService::update()`'s param list — see
     * {@see self::scheduleSubscriptionCancellation()}).
     *
     * `$request->reason` IS forwarded here, as `cancellation_details.comment`
     * — see {@see CancelSubscriptionRequest}'s own docblock for why this
     * differs from {@see self::cancelPaymentIntent()}/{@see self::createRefund()},
     * which deliberately do NOT forward their `$reason`.
     *
     * Performs no interpretation of the result or of any exception raised —
     * both are simply propagated to the caller.
     *
     * @param CancelSubscriptionRequest $request The cancellation request (must have cancelAtPeriodEnd === false).
     *
     * @return array<string, mixed> The raw, decoded Stripe Subscription payload.
     *
     * @throws \Stripe\Exception\ApiErrorException On any Stripe API error.
     */
    public function cancelSubscriptionImmediately(CancelSubscriptionRequest $request): array
    {
        $subscription = $this->sdk()->subscriptions->cancel(
            $request->subscriptionId->toString(),
            array_filter(
                [
                    'invoice_now'          => $request->invoiceNow,
                    'prorate'              => $request->prorate,
                    'cancellation_details' => $request->reason !== null
                        ? ['comment' => $request->reason]
                        : null,
                ],
                static fn (mixed $value): bool => $value !== null,
            ),
            ['idempotency_key' => $request->idempotencyKey],
        );

        return $subscription->toArray();
    }

    /**
     * Schedule a Stripe Subscription to cancel at the end of the current
     * billing period, for {@see StripeDriver::cancelSubscription()} when
     * `$request->cancelAtPeriodEnd` is true.
     *
     * A genuinely different Stripe API operation from
     * {@see self::cancelSubscriptionImmediately()} — verified against the
     * SDK: `cancel_at_period_end` is an `update()` param, not a `cancel()`
     * param. The subscription remains fully active until the period ends;
     * it does NOT become `canceled` as a result of this call (verified via
     * `Subscription::$cancel_at_period_end`'s own docblock: "Whether this
     * subscription will (if status=active)... cancel at the end of the
     * current billing period").
     *
     * `$request->invoiceNow`/`$request->prorate` are intentionally NOT
     * forwarded here — verified against the SDK that neither is a valid
     * `update()` param for this purpose.
     *
     * Performs no interpretation of the result or of any exception raised —
     * both are simply propagated to the caller.
     *
     * @param CancelSubscriptionRequest $request The cancellation request (must have cancelAtPeriodEnd === true).
     *
     * @return array<string, mixed> The raw, decoded Stripe Subscription payload.
     *
     * @throws \Stripe\Exception\ApiErrorException On any Stripe API error.
     */
    public function scheduleSubscriptionCancellation(CancelSubscriptionRequest $request): array
    {
        $subscription = $this->sdk()->subscriptions->update(
            $request->subscriptionId->toString(),
            array_filter(
                [
                    'cancel_at_period_end' => true,
                    'cancellation_details'  => $request->reason !== null
                        ? ['comment' => $request->reason]
                        : null,
                ],
                static fn (mixed $value): bool => $value !== null,
            ),
            ['idempotency_key' => $request->idempotencyKey],
        );

        return $subscription->toArray();
    }

    /**
     * Create a Stripe Checkout Session (hosted payment page), for
     * {@see StripeDriver::createPaymentLink()}.
     *
     * Verified against the SDK: unlike
     * {@see self::createSubscription()}'s `items[].price_data`, a Checkout
     * Session's `line_items[].price_data` supports a genuinely inline
     * `product_data.name` — NO pre-existing Stripe Product or Price id is
     * required. `$request->description` is forwarded there, so this method
     * needs no framework-level "must reference an existing catalog object"
     * guard the way `createSubscription()` does for `$request->planId`.
     *
     * `mode: 'payment'` is always set — this DTO has no recurring/interval
     * concept (that's {@see SubscriptionRequest}'s job), so every payment
     * link this method creates is a one-time payment.
     *
     * `success_url` (`$request->returnUrl`) is required by Stripe for the
     * default hosted redirect flow this driver uses (no `ui_mode`/
     * `redirect_on_completion` override is set) — this client method itself
     * performs no validation (thin wrapper, no business logic); see
     * {@see StripeDriver::createPaymentLink()} for the framework-level
     * guard. `cancel_url` (`$request->cancelUrl`) is NOT guarded — verified
     * against the SDK that it is genuinely optional (Stripe simply omits
     * the "back" button on the hosted page when absent).
     *
     * `customer_email` is forwarded from `$request->customer?->email` when
     * present, to prefill the hosted page — this does NOT create or attach
     * a Stripe Customer object (no `customer` param is set); `PaymentLinkRequest`
     * has no provider-customer-reference concept, unlike
     * {@see TokenChargeRequest}/{@see SubscriptionRequest}.
     *
     * Performs no interpretation of the result or of any exception raised —
     * both are simply propagated to the caller.
     *
     * @param PaymentLinkRequest $request The payment link creation request.
     *
     * @return array<string, mixed> The raw, decoded Stripe Checkout Session payload.
     *
     * @throws \Stripe\Exception\ApiErrorException On any Stripe API error.
     */
    public function createCheckoutSession(PaymentLinkRequest $request): array
    {
        $session = $this->sdk()->checkout->sessions->create(
            array_filter(
                [
                    'mode'           => 'payment',
                    'success_url'    => $this->withSessionIdPlaceholder($request->returnUrl),
                    'cancel_url'     => $request->cancelUrl,
                    'customer_email' => $request->customer?->email,
                    'expires_at'     => $request->expiresAt?->getTimestamp(),
                    'metadata'       => $request->metadata,
                    'line_items'     => [
                        [
                            'quantity'   => 1,
                            'price_data' => [
                                'currency'     => strtolower($request->currency->value),
                                'unit_amount'  => $request->amount->amount,
                                'product_data' => ['name' => $request->description],
                            ],
                        ],
                    ],
                ],
                static fn (mixed $value): bool => $value !== null && $value !== [],
            ),
            ['idempotency_key' => $request->idempotencyKey],
        );

        return $session->toArray();
    }

    /**
     * Append Stripe's `{CHECKOUT_SESSION_ID}` template placeholder to a
     * success URL as a `session_id` query param, unless one is already
     * present — Stripe substitutes it with the real session id (`cs_...`)
     * on redirect. Verified against the SDK's own documented convention for
     * `success_url`; unconditional (not just for the package's own checkout
     * endpoint) because ANY caller of `createPaymentLink()` benefits from
     * being able to identify which session a customer returned from,
     * exactly as Stripe's own docs recommend doing — this is not specific
     * to {@see \Mifatoyeh\LaravelPaymentFramework\Checkout\CheckoutService}'s
     * callback route.
     *
     * A bare `null` `$url` is returned as-is — `success_url` being absent
     * is {@see StripeDriver::createPaymentLink()}'s guard to enforce, not
     * this method's (see {@see self::createCheckoutSession()}'s own
     * docblock — "this client method itself performs no validation").
     */
    private function withSessionIdPlaceholder(?string $url): ?string
    {
        if ($url === null || str_contains($url, 'session_id=')) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . 'session_id={CHECKOUT_SESSION_ID}';
    }

    /**
     * Create a Stripe PaymentIntent WITHOUT confirming it, for
     * {@see StripeDriver::createSdkIntent()} — native-SDK checkout
     * (`driver_type: sdk`).
     *
     * Deliberately the mirror image of {@see self::createPaymentIntent()}:
     * that method always sets `confirm: true` because `charge()` represents
     * an immediate server-side charge using a token the caller already has.
     * This method never sets `confirm` at all and never sets `payment_method`
     * — `PaymentLinkRequest` (the DTO this takes, same as
     * {@see self::createCheckoutSession()}) has no token field, because the
     * whole point of SDK mode is that no token exists yet: the customer
     * enters their card in the NATIVE Stripe SDK on the client, which
     * confirms this PaymentIntent directly against Stripe using the
     * `client_secret` this call returns. No card data — not even a
     * token — ever reaches this package's server for this flow.
     *
     * `automatic_payment_methods: {enabled: true}` lets Stripe's client SDK
     * decide which payment methods to present, instead of this thin wrapper
     * hardcoding `payment_method_types` the way
     * {@see self::createCheckoutSession()} does for its own, different
     * (hosted-page) reasons.
     *
     * Performs no interpretation of the result or of any exception raised —
     * both are simply propagated to the caller.
     *
     * @param PaymentLinkRequest $request The checkout request — same shape createPaymentLink() takes.
     *
     * @return array<string, mixed> The raw, decoded Stripe PaymentIntent payload (needs `client_secret`).
     *
     * @throws \Stripe\Exception\ApiErrorException On any Stripe API error.
     */
    public function createUnconfirmedPaymentIntent(PaymentLinkRequest $request): array
    {
        $intent = $this->sdk()->paymentIntents->create(
            array_filter(
                [
                    'amount'                    => $request->amount->amount,
                    'currency'                  => strtolower($request->currency->value),
                    'description'               => $request->description,
                    'automatic_payment_methods' => ['enabled' => true],
                    'metadata'                  => $request->metadata,
                ],
                static fn (mixed $value): bool => $value !== null && $value !== [],
            ),
            ['idempotency_key' => $request->idempotencyKey],
        );

        return $intent->toArray();
    }

    /**
     * Lazily build (or return the already-built) underlying Stripe SDK client.
     *
     * The secret key is read only from the driver configuration and is held
     * exclusively by the SDK client instance — this method has no public
     * counterpart, so no caller outside this class can ever retrieve it.
     *
     * @return StripeSdkClient The underlying Stripe SDK client instance.
     */
    private function sdk(): StripeSdkClient
    {
        if ($this->sdk === null) {
            $this->sdk = new StripeSdkClient([
                'api_key' => (string) ($this->config['secret'] ?? ''),
            ]);
        }

        return $this->sdk;
    }
}
