<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe;

use Mifatoyeh\LaravelPaymentFramework\DTO\CaptureRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\RefundRequest;
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
