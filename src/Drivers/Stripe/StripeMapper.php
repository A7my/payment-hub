<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe;

use DateTimeImmutable;
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
     * Used by charge(), authorize(), and chargeToken() — all three create a
     * real Stripe PaymentIntent, so this payload shape (`amount`, `currency`,
     * `latest_charge`, `last_payment_error`) applies to all of them
     * unchanged. NOT used by saveCard(): that operation creates a Stripe
     * SetupIntent, a structurally different object with no `amount`/
     * `currency` at all and a differently-named error field
     * (`last_setup_error`, not `last_payment_error`) — see
     * {@see self::toSaveCardResponse()}, its dedicated mapping.
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
     * Map a raw Stripe SetupIntent payload to a PaymentResponse, for
     * {@see StripeDriver::saveCard()}.
     *
     * NOT a reuse of {@see self::toPaymentResponse()} — verified against the
     * SDK that a SetupIntent is structurally different from a PaymentIntent
     * in exactly the ways that matter here:
     *   - No `amount`/`currency` fields exist on a SetupIntent at all (no
     *     funds ever move). `amount` is reported as a documented zero-value
     *     placeholder — {@see PaymentResponse::$amount} is non-nullable, so
     *     some value must be supplied; there is no meaningful amount to put
     *     there for a card-saving operation.
     *   - The decline-detail field is named `last_setup_error`, NOT
     *     `last_payment_error` — reusing {@see self::resolvePaymentMessage()}
     *     unchanged would silently look at the wrong key and always fall
     *     back to a generic message, dropping Stripe's actual decline
     *     reason. This method reads `last_setup_error.message` instead.
     *   - `providerReference` is the created Stripe Customer id (`cus_...`,
     *     Stripe's `customer` field on the SetupIntent — a plain id string,
     *     never expanded) — this is the value the design proposal identified
     *     as needing a carrier; {@see PaymentResponse::getProviderReference()}
     *     is that carrier, to be round-tripped via
     *     {@see \Mifatoyeh\LaravelPaymentFramework\DTO\TokenChargeRequest::$providerCustomerReference}
     *     on a later chargeToken() call.
     *
     * Status IS mapped via the shared {@see self::mapStatus()} table: a
     * SetupIntent's possible `status` values (`succeeded`,
     * `requires_action`, `requires_confirmation`, `requires_payment_method`,
     * `processing`, `canceled` — verified against the SDK's own
     * `SetupIntent::STATUS_*` constants) are a strict subset of the
     * PaymentIntent status vocabulary that table already covers (the one
     * PaymentIntent-only value, `requires_capture`, simply never occurs for
     * a SetupIntent). Reusing it keeps exactly one status-mapping table in
     * this class rather than a near-duplicate second one. `succeeded` maps
     * to `PaymentStatus::Captured` — there is no dedicated "saved"/"verified"
     * case in the {@see PaymentStatus} enum, so this reuses the existing
     * "operation completed successfully" meaning that table already assigns
     * to `succeeded`, consistent with how it is used everywhere else in
     * this class, rather than adding a new enum case for this one call site.
     *
     * @param array<string, mixed> $raw The raw Stripe SetupIntent API response payload.
     */
    public function toSaveCardResponse(array $raw): PaymentResponse
    {
        $status = $this->mapStatus((string) ($raw['status'] ?? ''));

        return new PaymentResponse(
            successful: $status->isSuccessful(),
            transactionId: TransactionId::fromString((string) ($raw['id'] ?? '')),
            status: $status,
            providerReference: (string) ($raw['customer'] ?? ''),
            amount: Money::ofMinor(0, Currency::USD),
            rawResponse: $raw,
            message: $this->resolveSaveCardMessage($status, $raw),
        );
    }

    /**
     * Resolve a human-readable message for a mapped saveCard() PaymentResponse.
     *
     * Prefers Stripe's own `last_setup_error.message` when present (e.g. on
     * a decline) — the SetupIntent counterpart of
     * {@see self::resolvePaymentMessage()}'s `last_payment_error.message`,
     * under its own differently-named field (verified against the SDK).
     *
     * @param PaymentStatus         $status The mapped canonical status.
     * @param array<string, mixed>  $raw    The raw Stripe SetupIntent API response payload.
     */
    private function resolveSaveCardMessage(PaymentStatus $status, array $raw): string
    {
        $providerMessage = $raw['last_setup_error']['message'] ?? null;

        if (is_string($providerMessage) && $providerMessage !== '') {
            return $providerMessage;
        }

        return match ($status) {
            PaymentStatus::Captured       => 'Card saved.',
            PaymentStatus::RequiresAction => 'Additional customer action is required to save this card.',
            PaymentStatus::Pending        => 'Card verification is processing.',
            PaymentStatus::Cancelled      => 'Card save was cancelled.',
            default                       => 'Card save failed.',
        };
    }

    /**
     * Map a raw Stripe Subscription payload to a SubscriptionResponse.
     *
     * Used by createSubscription() and cancelSubscription() (both immediate
     * and scheduled-at-period-end — the latter's raw payload still reports
     * whatever status the subscription was ALREADY in, e.g. `active`, since
     * `cancel_at_period_end` does not itself change `status`; verified
     * against `Subscription::$cancel_at_period_end`'s own docblock. This
     * method does not force that case to `Cancelled` — the underlying
     * status is mapped as-is via {@see self::mapSubscriptionStatus()}, and
     * {@see self::resolveSubscriptionMessage()} adds a clarifying message
     * instead, so the response never claims a still-active subscription is
     * already cancelled).
     *
     * Status mapping table (Stripe's 8 real `Subscription::$status` values,
     * verified against the SDK's own `Subscription::STATUS_*` constants,
     * onto the existing {@see PaymentStatus} vocabulary — no enum change):
     *
     *   | Stripe status       | PaymentStatus   | Reasoning                                            |
     *   |----------------------|-----------------|-------------------------------------------------------|
     *   | active               | Captured        | Subscription live, billing succeeding                 |
     *   | trialing             | Pending         | No payment attempted yet — not "reserved" (Authorized) |
     *   | incomplete           | RequiresAction or Failed | Disambiguated via the nested first-invoice payment status — see {@see self::mapIncompleteStatus()} |
     *   | incomplete_expired   | Expired         | Stripe's own docblock: "This is a terminal status"     |
     *   | past_due             | Failed          | KNOWN LIMITATION: not truly terminal (Stripe auto-retries) — closest available case, not a clean fit; accepted as-is |
     *   | paused               | RequiresAction  | Only enters this state needing the customer to add a payment method |
     *   | canceled             | Cancelled       | Direct match                                           |
     *   | unpaid               | Failed          | Same KNOWN LIMITATION as past_due — collection exhausted, but Stripe docs allow manually reopening invoices later, so not truly terminal either |
     *
     * `nextBillingDate` reads `items.data[0].current_period_end` — verified
     * against the SDK that `Subscription::$current_period_end` no longer
     * exists as a direct property in this API version (stripe-php 20.3.1);
     * billing periods moved to the per-item level
     * (`SubscriptionItem::$current_period_end`).
     *
     * `successful` is NOT a reuse of {@see PaymentStatus::isSuccessful()}
     * (unlike {@see self::toPaymentResponse()}) — that method's true-list
     * (Authorized/Captured/PartiallyRefunded) has no `Authorized` analogue
     * here (nothing is ever merely "reserved" in a subscription), and would
     * incorrectly mark a `trialing` subscription (mapped to `Pending`) as
     * unsuccessful even though nothing has failed — a trial is a normal,
     * working outcome, just with billing deferred. This method instead uses
     * its own explicit allow-list: `Captured` (active billing), `Pending`
     * (trial in progress, nothing due yet), and `Cancelled` (a cancellation
     * request succeeded) are successful; `RequiresAction`, `Failed`, and
     * `Expired` are not — consistent with how `RequiresAction` is treated
     * as unsuccessful everywhere else in this class (e.g.
     * {@see self::toPaymentResponse()}).
     *
     * @param array<string, mixed> $raw The raw Stripe API response payload.
     */
    public function toSubscriptionResponse(array $raw): SubscriptionResponse
    {
        $stripeStatus = (string) ($raw['status'] ?? '');
        $status       = $this->mapSubscriptionStatus($stripeStatus, $raw);

        return new SubscriptionResponse(
            successful: in_array($status, [PaymentStatus::Captured, PaymentStatus::Pending, PaymentStatus::Cancelled], true),
            subscriptionId: (string) ($raw['id'] ?? ''),
            status: $status,
            nextBillingDate: $this->resolveNextBillingDate($raw),
            message: $this->resolveSubscriptionMessage($status, $stripeStatus, $raw),
            rawResponse: $raw,
        );
    }

    /**
     * Map a Stripe Subscription `status` string to the canonical
     * PaymentStatus enum, per the table on {@see self::toSubscriptionResponse()}.
     *
     * @param string                $stripeStatus The raw Stripe Subscription status value.
     * @param array<string, mixed>  $raw          The raw Stripe API response payload.
     */
    private function mapSubscriptionStatus(string $stripeStatus, array $raw): PaymentStatus
    {
        return match ($stripeStatus) {
            'active'             => PaymentStatus::Captured,
            'trialing'           => PaymentStatus::Pending,
            'incomplete'         => $this->mapIncompleteStatus($raw),
            'incomplete_expired' => PaymentStatus::Expired,
            // past_due / unpaid: mapped to Failed as the closest available
            // case. KNOWN, ACCEPTED LIMITATION (confirmed, not an
            // oversight): Failed::isTerminal() === true, but neither Stripe
            // status is truly terminal — Stripe keeps auto-retrying
            // past_due subscriptions, and unpaid invoices can be manually
            // reopened and paid later per Stripe's own docs. There is no
            // "degraded but still live" PaymentStatus case; this is the
            // best available fit, not a clean one.
            'past_due'           => PaymentStatus::Failed,
            'unpaid'             => PaymentStatus::Failed,
            'paused'             => PaymentStatus::RequiresAction,
            'canceled'           => PaymentStatus::Cancelled,
            default              => PaymentStatus::Failed,
        };
    }

    /**
     * Disambiguate Stripe's `incomplete` Subscription status, which alone
     * cannot distinguish "needs 3DS/customer action" from "hard-declined"
     * (verified against Stripe's own docblock on `Subscription::$status`).
     *
     * Inspects the first invoice's payment attempt, reached via
     * {@see self::firstInvoicePaymentIntent()}. UNVERIFIED LIVE: the deep
     * expand path this depends on (`latest_invoice.payments.data.payment.payment_intent`,
     * requested by {@see StripeClient::createSubscription()}) has not been
     * exercised against a real Stripe API call — only verified against the
     * SDK's documented shape. If it fails to resolve for any reason (wrong
     * depth, API version mismatch, a not-yet-created invoice), this
     * defaults to `RequiresAction` rather than `Failed` — the safer,
     * more actionable signal when the true cause is unknown; a caller
     * seeing `RequiresAction` will investigate, whereas `Failed` may cause
     * them to give up and re-attempt from scratch.
     *
     * @param array<string, mixed> $raw The raw Stripe API response payload.
     */
    private function mapIncompleteStatus(array $raw): PaymentStatus
    {
        $paymentIntent = $this->firstInvoicePaymentIntent($raw);

        if ($paymentIntent === null) {
            return PaymentStatus::RequiresAction;
        }

        return match ((string) ($paymentIntent['status'] ?? '')) {
            'requires_action', 'requires_confirmation' => PaymentStatus::RequiresAction,
            'succeeded'                                => PaymentStatus::Captured,
            default                                     => PaymentStatus::Failed,
        };
    }

    /**
     * Defensively traverse to the first invoice's PaymentIntent, through
     * the newer `Invoice::$payments: Collection<InvoicePayment>` shape
     * (verified against the SDK — the older, no-longer-existent
     * `Invoice::$payment_intent` property does not exist in this SDK
     * version). Returns null at the first missing/wrong-type level rather
     * than risk a type error on a partially-resolved expand — e.g. when
     * `payment_intent` comes back as a bare id STRING (not expanded) rather
     * than an array.
     *
     * @param array<string, mixed> $raw The raw Stripe API response payload.
     *
     * @return array<string, mixed>|null
     */
    private function firstInvoicePaymentIntent(array $raw): ?array
    {
        $invoice = $raw['latest_invoice'] ?? null;

        if (! is_array($invoice)) {
            return null;
        }

        $payments = $invoice['payments']['data'] ?? null;

        if (! is_array($payments) || ! isset($payments[0]) || ! is_array($payments[0])) {
            return null;
        }

        $paymentIntent = $payments[0]['payment']['payment_intent'] ?? null;

        return is_array($paymentIntent) ? $paymentIntent : null;
    }

    /**
     * Resolve the next billing date from the first subscription item's
     * current billing period end — see {@see self::toSubscriptionResponse()}'s
     * docblock for why this reads `items.data[0].current_period_end` rather
     * than a top-level `current_period_end` field (verified: the latter no
     * longer exists on Subscription in this SDK version).
     *
     * @param array<string, mixed> $raw The raw Stripe API response payload.
     */
    private function resolveNextBillingDate(array $raw): ?DateTimeImmutable
    {
        $timestamp = $raw['items']['data'][0]['current_period_end'] ?? null;

        if (! is_int($timestamp)) {
            return null;
        }

        return (new DateTimeImmutable())->setTimestamp($timestamp);
    }

    /**
     * Resolve a human-readable message for a mapped SubscriptionResponse.
     *
     * Prefers a specific decline message (when `incomplete` resolved to a
     * hard failure via {@see self::firstInvoicePaymentIntent()}), then a
     * scheduled-cancellation clarification (when `cancel_at_period_end` is
     * true but the status is not already `canceled` — see
     * {@see self::toSubscriptionResponse()}'s docblock), then a
     * status-appropriate default.
     *
     * @param PaymentStatus         $status       The mapped canonical status.
     * @param string                $stripeStatus The raw Stripe Subscription status value.
     * @param array<string, mixed>  $raw          The raw Stripe API response payload.
     */
    private function resolveSubscriptionMessage(PaymentStatus $status, string $stripeStatus, array $raw): string
    {
        if ($stripeStatus === 'incomplete' && $status === PaymentStatus::Failed) {
            $paymentIntent   = $this->firstInvoicePaymentIntent($raw);
            $providerMessage = $paymentIntent['last_payment_error']['message'] ?? null;

            if (is_string($providerMessage) && $providerMessage !== '') {
                return $providerMessage;
            }
        }

        if (($raw['cancel_at_period_end'] ?? false) === true && $stripeStatus !== 'canceled') {
            return sprintf(
                'Subscription will be cancelled at the end of the current billing period (currently %s).',
                $status->label(),
            );
        }

        return match ($status) {
            PaymentStatus::Captured       => 'Subscription is active.',
            PaymentStatus::Pending        => 'Subscription created; trial period in progress.',
            PaymentStatus::RequiresAction => 'Additional action is required to activate this subscription.',
            PaymentStatus::Cancelled      => 'Subscription cancelled.',
            PaymentStatus::Expired        => 'Subscription setup expired before the first invoice was paid.',
            default                       => 'Subscription payment failed.',
        };
    }

    /**
     * Map a raw Stripe Checkout Session payload to a PaymentLinkResponse.
     *
     * Used by createPaymentLink() — backed by a Stripe Checkout Session
     * (verified against the SDK; see {@see StripeClient::createCheckoutSession()}'s
     * own docblock for why a Checkout Session was chosen over Stripe's
     * separate `PaymentLink` API resource: `PaymentLink` has no `cancel_url`
     * concept at all, since it is a reusable/static link, whereas
     * `PaymentLinkRequest::$cancelUrl` is a real field this framework
     * exposes — Checkout Session supports it directly).
     *
     * `successful` is unconditionally `true` — same reasoning as
     * {@see self::toStatusResponse()}/{@see self::toVerificationResponse()}:
     * only reached after a non-throwing create call, and creating a
     * Checkout Session never itself charges a card (that happens later,
     * asynchronously, when the customer completes checkout on Stripe's
     * hosted page) — there is no soft-decline outcome to represent here.
     *
     * @param array<string, mixed> $raw The raw Stripe Checkout Session API response payload.
     */
    public function toPaymentLinkResponse(array $raw): PaymentLinkResponse
    {
        $expiresAt = isset($raw['expires_at']) && is_int($raw['expires_at'])
            ? (new DateTimeImmutable())->setTimestamp($raw['expires_at'])
            : null;

        return new PaymentLinkResponse(
            successful: true,
            paymentUrl: (string) ($raw['url'] ?? ''),
            linkId: (string) ($raw['id'] ?? ''),
            expiresAt: $expiresAt,
            message: 'Payment link created.',
            rawResponse: $raw,
        );
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
