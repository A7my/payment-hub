<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe;

use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\Responses\CaptureResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentLinkResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\RefundResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\StatusResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\SubscriptionResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\VerificationResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\VoidResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\WebhookResponse;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;

/**
 * Converts raw Stripe API payloads into framework Response objects.
 *
 * Contains ONLY translation logic — no HTTP communication (that is
 * {@see StripeClient}'s job) and no lifecycle orchestration (that is
 * {@see StripeDriver}'s job). Each method takes the raw, JSON-decoded
 * payload returned by the Stripe SDK and returns the corresponding
 * standardised framework Response, covering every response contract
 * declared under `Contracts\Responses`.
 */
final class StripeMapper
{
    /**
     * Map a raw Stripe PaymentIntent/Charge payload to a PaymentResponse.
     *
     * Used by charge(), authorize(), saveCard(), and chargeToken().
     *
     * Field mapping:
     *   - transaction id     `id`                        → TransactionId
     *   - status             `status`                    → PaymentStatus (via {@see self::mapStatus()})
     *   - amount / currency  `amount` / `currency`        → Money
     *   - requires action    `status === 'requires_action'` → PaymentStatus::RequiresAction
     *                                                         (exposed via PaymentResponse::requiresAction())
     *   - client secret      `client_secret`             → carried through untouched in $rawResponse
     *   - raw response       the entire payload           → $rawResponse (unmodified)
     *
     * `successful` is derived from `PaymentStatus::isSuccessful()` rather than
     * re-deriving it here, so the success rule lives in exactly one place.
     *
     * @param array<string, mixed> $raw The raw Stripe API response payload.
     */
    public function toPaymentResponse(array $raw): PaymentResponse
    {
        $status = $this->mapStatus((string) ($raw['status'] ?? ''));
        $amount = Money::ofMinor(
            (int) ($raw['amount'] ?? 0),
            Currency::from(strtoupper((string) ($raw['currency'] ?? 'USD'))),
        );

        return new PaymentResponse(
            successful: $status->isSuccessful(),
            transactionId: TransactionId::fromString((string) ($raw['id'] ?? '')),
            status: $status,
            providerReference: (string) ($raw['latest_charge'] ?? ''),
            amount: $amount,
            rawResponse: $raw,
            message: $this->resolvePaymentMessage($status, $raw),
        );
    }

    /**
     * Map a Stripe PaymentIntent `status` string to the canonical PaymentStatus enum.
     *
     * @param string $stripeStatus The raw Stripe PaymentIntent status value.
     */
    private function mapStatus(string $stripeStatus): PaymentStatus
    {
        return match ($stripeStatus) {
            'succeeded'               => PaymentStatus::Captured,
            'requires_action'         => PaymentStatus::RequiresAction,
            'requires_capture'        => PaymentStatus::Authorized,
            'processing',
            'requires_confirmation'   => PaymentStatus::Pending,
            'canceled'                => PaymentStatus::Cancelled,
            'requires_payment_method' => PaymentStatus::Failed,
            default                   => PaymentStatus::Failed,
        };
    }

    /**
     * Resolve a human-readable message for a mapped PaymentResponse.
     *
     * Prefers Stripe's own `last_payment_error.message` when present (e.g.
     * on a decline); otherwise falls back to a status-appropriate default.
     *
     * @param PaymentStatus         $status The mapped canonical status.
     * @param array<string, mixed>  $raw    The raw Stripe API response payload.
     */
    private function resolvePaymentMessage(PaymentStatus $status, array $raw): string
    {
        $providerMessage = $raw['last_payment_error']['message'] ?? null;

        if (is_string($providerMessage) && $providerMessage !== '') {
            return $providerMessage;
        }

        return match ($status) {
            PaymentStatus::Captured       => 'Payment succeeded.',
            PaymentStatus::RequiresAction => 'Additional customer action is required to complete this payment.',
            PaymentStatus::Authorized     => 'Payment authorised, awaiting capture.',
            PaymentStatus::Pending        => 'Payment is processing.',
            PaymentStatus::Cancelled      => 'Payment was cancelled.',
            default                       => 'Payment failed.',
        };
    }

    /**
     * Map a raw Stripe PaymentIntent capture payload to a CaptureResponse.
     *
     * Like {@see self::toVoidResponse()}, this is a dedicated two-way branch
     * rather than reusing {@see self::mapStatus()}: a successful capture()
     * call has exactly one meaningful outcome — Stripe's `status` becomes
     * `'succeeded'`. Any other status on a 200 response is treated
     * defensively as a failure.
     *
     * `amount` uses Stripe's `amount_received` field (falling back to
     * `amount` if absent) rather than the originally-authorised `amount`, so
     * a partial capture is reported with the amount actually captured, not
     * the amount that was merely authorised.
     *
     * The capture identifier is the PaymentIntent's own `id` — Stripe's
     * PaymentIntents API does not mint a separate "capture" resource
     * distinct from the PaymentIntent itself, matching the "some providers
     * return the original transaction ID as the capture ID" case documented
     * on {@see CaptureResponse}.
     *
     * @param array<string, mixed> $raw The raw Stripe API response payload.
     */
    public function toCaptureResponse(array $raw): CaptureResponse
    {
        $captured = ((string) ($raw['status'] ?? '')) === 'succeeded';
        $status   = $captured ? PaymentStatus::Captured : PaymentStatus::Failed;
        $amount   = Money::ofMinor(
            (int) ($raw['amount_received'] ?? $raw['amount'] ?? 0),
            Currency::from(strtoupper((string) ($raw['currency'] ?? 'USD'))),
        );

        return new CaptureResponse(
            successful: $captured,
            captureId: (string) ($raw['id'] ?? ''),
            amount: $amount,
            status: $status,
            message: $captured
                ? 'Payment captured.'
                : (string) ($raw['last_payment_error']['message'] ?? 'Capture failed.'),
            rawResponse: $raw,
        );
    }

    /**
     * Map a raw Stripe Refund payload to a RefundResponse.
     *
     * Used by BOTH refund() and partialRefund() — the raw payload itself is
     * the sole source of truth for which of the two actually happened; the
     * driver method that was called is irrelevant to this mapping (see
     * {@see self::isFullyRefunded()}). Stripe's Refund object has five
     * possible `status` values (verified against the SDK's own
     * `Refund::STATUS_*` constants): `succeeded`, `pending`,
     * `requires_action`, `failed`, `canceled` — all five map onto existing
     * {@see PaymentStatus} cases via {@see self::mapRefundStatus()}, so
     * `pending`/`requires_action` are reported accurately rather than being
     * forced into success or failure.
     *
     * `successful` is `true` for both `PaymentStatus::Refunded` and
     * `PaymentStatus::PartiallyRefunded` — both represent a refund that was
     * genuinely processed, per {@see RefundResponse}'s own documented
     * contract (distinguish full vs. partial via `$status`/`isPartial()`,
     * not via `isSuccessful()`). It does NOT reuse
     * {@see PaymentStatus::isSuccessful()}, which is scoped to "funds are
     * captured/held" and answers a different question.
     *
     * @param array<string, mixed> $raw The raw Stripe API response payload
     *                                  (expects `charge` expanded — see
     *                                  {@see StripeClient::createRefund()}).
     */
    public function toRefundResponse(array $raw): RefundResponse
    {
        $status = $this->mapRefundStatus((string) ($raw['status'] ?? ''), $raw);
        $amount = Money::ofMinor(
            (int) ($raw['amount'] ?? 0),
            Currency::from(strtoupper((string) ($raw['currency'] ?? 'USD'))),
        );

        return new RefundResponse(
            successful: $status === PaymentStatus::Refunded || $status === PaymentStatus::PartiallyRefunded,
            refundId: (string) ($raw['id'] ?? ''),
            amount: $amount,
            status: $status,
            message: $this->resolveRefundMessage($status, $raw),
            rawResponse: $raw,
        );
    }

    /**
     * Map a Stripe Refund `status` string to the canonical PaymentStatus enum.
     *
     * A `succeeded` status is further split into `Refunded` vs.
     * `PartiallyRefunded` via {@see self::isFullyRefunded()} — Stripe's
     * Refund `status` field alone cannot distinguish the two; both a full
     * and a partial refund report `succeeded` once processed.
     *
     * @param string                $stripeStatus The raw Stripe Refund status value.
     * @param array<string, mixed>  $raw          The raw Stripe API response payload.
     */
    private function mapRefundStatus(string $stripeStatus, array $raw): PaymentStatus
    {
        if ($stripeStatus === 'succeeded') {
            return $this->isFullyRefunded($raw) ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded;
        }

        return match ($stripeStatus) {
            'pending'         => PaymentStatus::Pending,
            'requires_action' => PaymentStatus::RequiresAction,
            'canceled'        => PaymentStatus::Cancelled,
            'failed'          => PaymentStatus::Failed,
            default           => PaymentStatus::Failed,
        };
    }

    /**
     * Determine whether a succeeded refund exhausted the full charge amount.
     *
     * This is the resolution to the Refunded-vs-PartiallyRefunded signal:
     * Stripe's `PaymentIntent` object carries NO refund-tracking fields at
     * all (verified against the SDK — no `amount_refunded` or equivalent).
     * The `Charge` object does: `amount` (the original total charged) and
     * `amount_refunded` (the CUMULATIVE amount refunded across every refund
     * ever applied to that charge — Stripe's own docblock: "can be less
     * than the amount attribute on the charge if a partial refund was
     * issued"). {@see StripeClient::createRefund()} always requests
     * `expand: ['charge']` so this object is embedded in the same response,
     * with no second API round-trip.
     *
     * Because `amount_refunded` is cumulative, this also correctly handles
     * several partial refunds summing to the total: the LAST one to bring
     * the running total up to the original `amount` is the one whose
     * response will show `amount_refunded === amount`, and THAT refund is
     * reported as `Refunded`, not `PartiallyRefunded` — matching reality
     * (the charge is now fully refunded), even though the individual refund
     * amount on that call may have been small.
     *
     * If `charge` was not expanded for any reason (e.g. an older or
     * synthetic payload missing the field), this defensively assumes a full
     * refund — the same behaviour this method had before charge expansion
     * was introduced, so it never regresses existing callers.
     *
     * @param array<string, mixed> $raw The raw Stripe API response payload.
     */
    private function isFullyRefunded(array $raw): bool
    {
        $charge = $raw['charge'] ?? null;

        if (! is_array($charge) || ! isset($charge['amount'], $charge['amount_refunded'])) {
            return true;
        }

        return (int) $charge['amount_refunded'] >= (int) $charge['amount'];
    }

    /**
     * Resolve a human-readable message for a mapped RefundResponse.
     *
     * Prefers Stripe's own `failure_reason` (when failed) or `pending_reason`
     * (when pending) — both documented, fixed-value fields on the Refund
     * object — before falling back to a status-appropriate default.
     *
     * @param PaymentStatus         $status The mapped canonical status.
     * @param array<string, mixed>  $raw    The raw Stripe API response payload.
     */
    private function resolveRefundMessage(PaymentStatus $status, array $raw): string
    {
        $failureReason = $raw['failure_reason'] ?? null;

        if ($status === PaymentStatus::Failed && is_string($failureReason) && $failureReason !== '') {
            return "Refund failed: {$failureReason}.";
        }

        $pendingReason = $raw['pending_reason'] ?? null;

        if ($status === PaymentStatus::Pending && is_string($pendingReason) && $pendingReason !== '') {
            return "Refund pending: {$pendingReason}.";
        }

        return match ($status) {
            PaymentStatus::Refunded          => 'Refund processed.',
            PaymentStatus::PartiallyRefunded => 'Partial refund processed.',
            PaymentStatus::Pending           => 'Refund is pending.',
            PaymentStatus::RequiresAction    => 'Additional action is required to complete this refund.',
            PaymentStatus::Cancelled         => 'Refund was cancelled.',
            default                          => 'Refund failed.',
        };
    }

    /**
     * Map a raw Stripe cancelled-PaymentIntent payload to a VoidResponse.
     *
     * Unlike {@see self::toPaymentResponse()}, this does not need the general
     * {@see self::mapStatus()} status table: a successful cancel() call has
     * exactly one meaningful outcome — Stripe's `status` becomes the string
     * `'canceled'`, which in the specific context of voiding an authorised
     * hold maps to {@see PaymentStatus::Voided} (not `Cancelled` — that case
     * is reserved for cancellation before any funds moved at all; see
     * {@see PaymentStatus}'s own docblock). Any other status on a 200
     * response is treated defensively as a failure.
     *
     * @param array<string, mixed> $raw The raw Stripe API response payload.
     */
    public function toVoidResponse(array $raw): VoidResponse
    {
        $voided = ((string) ($raw['status'] ?? '')) === 'canceled';
        $status = $voided ? PaymentStatus::Voided : PaymentStatus::Failed;

        return new VoidResponse(
            successful: $voided,
            transactionId: TransactionId::fromString((string) ($raw['id'] ?? '')),
            status: $status,
            message: $voided
                ? 'Payment voided.'
                : (string) ($raw['last_payment_error']['message'] ?? 'Void failed.'),
            rawResponse: $raw,
        );
    }

    /**
     * Map a raw Stripe PaymentIntent payload to a StatusResponse.
     *
     * Used by lookup() — a plain, non-judgemental report of the current
     * canonical status. Reuses {@see self::mapStatus()} (the same table
     * `toPaymentResponse()` uses) and {@see self::resolvePaymentMessage()}
     * unchanged, since both are already fully generic over any raw
     * PaymentIntent payload, not specific to the charge()/authorize()
     * call site that originally introduced them.
     *
     * `successful` is unconditionally `true` here: this method is only ever
     * reached after a non-throwing retrieve — any Stripe API failure (e.g.
     * unknown transaction id) throws before the mapper is invoked, so there
     * is no "successful API call but bad outcome" case to represent, unlike
     * {@see self::toPaymentResponse()}'s soft-decline handling.
     *
     * @param array<string, mixed> $raw The raw Stripe API response payload.
     */
    public function toStatusResponse(array $raw): StatusResponse
    {
        $status = $this->mapStatus((string) ($raw['status'] ?? ''));

        return new StatusResponse(
            successful: true,
            transactionId: TransactionId::fromString((string) ($raw['id'] ?? '')),
            status: $status,
            message: $this->resolvePaymentMessage($status, $raw),
            rawResponse: $raw,
        );
    }

    /**
     * Map a raw Stripe PaymentIntent payload to a VerificationResponse.
     *
     * Used by verify(). `VerificationResponse`'s own docblock describes
     * `isVerified()` in generic, provider-agnostic terms ("the signature/
     * hash is invalid") aimed at gateways that sign their redirect
     * parameters (common outside Stripe, e.g. several MENA-region
     * providers). Stripe does not sign or hash redirect parameters at all —
     * there is nothing of that kind to check. For Stripe specifically,
     * "verified" is interpreted as: the transaction was retrieved
     * authoritatively from Stripe's own servers (never trusting a
     * client-supplied claim) AND is in a genuinely successful state —
     * reusing {@see PaymentStatus::isSuccessful()} (true for
     * Authorized/Captured/PartiallyRefunded) as that exact "genuinely
     * successful" test, rather than inventing a second, parallel notion of
     * success.
     *
     * `successful` is unconditionally `true` for the same reason as
     * {@see self::toStatusResponse()}: only reached after a non-throwing
     * retrieve.
     *
     * @param array<string, mixed> $raw The raw Stripe API response payload.
     */
    public function toVerificationResponse(array $raw): VerificationResponse
    {
        $status   = $this->mapStatus((string) ($raw['status'] ?? ''));
        $verified = $status->isSuccessful();

        return new VerificationResponse(
            successful: true,
            verified: $verified,
            transactionId: TransactionId::fromString((string) ($raw['id'] ?? '')),
            message: $verified
                ? 'Transaction verified as authentic.'
                : 'Transaction could not be verified: ' . $this->resolvePaymentMessage($status, $raw),
            rawResponse: $raw,
        );
    }

    /**
     * Map a raw Stripe Subscription payload to a SubscriptionResponse.
     *
     * Used by createSubscription() and cancelSubscription().
     *
     * @param array<string, mixed> $raw The raw Stripe API response payload.
     */
    public function toSubscriptionResponse(array $raw): SubscriptionResponse
    {
        // TODO: Construct SubscriptionResponse from the Stripe Subscription payload,
        //       mapping current_period_end to nextBillingDate.
        throw new \LogicException('StripeMapper::toSubscriptionResponse() not yet implemented.');
    }

    /**
     * Map a raw Stripe Checkout Session / Payment Link payload to a PaymentLinkResponse.
     *
     * @param array<string, mixed> $raw The raw Stripe API response payload.
     */
    public function toPaymentLinkResponse(array $raw): PaymentLinkResponse
    {
        // TODO: Construct PaymentLinkResponse from the Stripe Checkout Session payload.
        throw new \LogicException('StripeMapper::toPaymentLinkResponse() not yet implemented.');
    }

    /**
     * Map a raw Stripe Event payload to a WebhookResponse.
     *
     * @param array<string, mixed> $raw The raw, decoded Stripe Event payload.
     */
    public function toWebhookResponse(array $raw): WebhookResponse
    {
        // TODO: Map the Stripe Event `type` field to a WebhookEventType and
        //       construct WebhookResponse, preserving the raw payload.
        throw new \LogicException('StripeMapper::toWebhookResponse() not yet implemented.');
    }
}
