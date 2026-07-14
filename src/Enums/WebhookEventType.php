<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Enums;

/**
 * Canonical webhook event types, normalised across all payment providers.
 *
 * Design decisions:
 * - Every driver MUST map its provider-specific event names (e.g.,
 *   `charge.succeeded` for Stripe, `TRANSACTION_PROCESSED` for MyFatoorah)
 *   to one of these canonical values before returning a `WebhookResponse`.
 *   This ensures the host application's listeners work identically regardless
 *   of provider.
 * - `Unknown` is the safe fallback for any provider event that has not yet
 *   been mapped. It is preferable to silently accepting unrecognised events
 *   without logging: callers should log and skip Unknown events.
 * - Dot-notation values mirror common industry conventions (Stripe, Adyen)
 *   but are canonical to THIS framework, not to any specific provider.
 * - Chargeback / dispute lifecycle events are included because they have
 *   significant business and compliance implications; ignoring them would
 *   be a dangerous omission.
 * - Subscription events are first-class: many businesses need to react to
 *   subscription renewals and cancellations in real time.
 */
enum WebhookEventType: string
{
    // ── Payment lifecycle ─────────────────────────────────────────────────────

    /** A payment was successfully captured/charged. */
    case PaymentSucceeded = 'payment.succeeded';

    /** A payment was declined or failed processing. */
    case PaymentFailed = 'payment.failed';

    /** A payment authorisation was placed (funds reserved, not yet captured). */
    case PaymentAuthorized = 'payment.authorized';

    /** A previously authorised payment was captured. */
    case PaymentCaptured = 'payment.captured';

    /** A previously authorised payment was voided. */
    case PaymentVoided = 'payment.voided';

    /** A payment requires additional customer action (3DS, OTP, redirect). */
    case PaymentActionRequired = 'payment.action_required';

    // ── Refund lifecycle ──────────────────────────────────────────────────────

    /** A refund was successfully processed by the provider. */
    case RefundSucceeded = 'refund.succeeded';

    /** A refund failed at the provider level. */
    case RefundFailed = 'refund.failed';

    // ── Dispute / chargeback ──────────────────────────────────────────────────

    /** A chargeback or dispute has been opened by the cardholder. */
    case DisputeOpened = 'dispute.opened';

    /** A dispute has been resolved (won or lost). */
    case DisputeResolved = 'dispute.resolved';

    // ── Subscription lifecycle ────────────────────────────────────────────────

    /** A subscription was successfully created. */
    case SubscriptionCreated = 'subscription.created';

    /** A subscription was renewed and the recurring charge succeeded. */
    case SubscriptionRenewed = 'subscription.renewed';

    /** A subscription renewal charge failed. */
    case SubscriptionRenewalFailed = 'subscription.renewal_failed';

    /** A subscription was cancelled (by customer, merchant, or provider). */
    case SubscriptionCancelled = 'subscription.cancelled';

    /** A subscription trial period has ended. */
    case SubscriptionTrialEnded = 'subscription.trial_ended';

    // ── Stored payment methods ────────────────────────────────────────────────

    /** A payment method (card/wallet) was successfully saved as a token. */
    case PaymentMethodSaved = 'payment_method.saved';

    /** A saved payment method was removed or expired. */
    case PaymentMethodRemoved = 'payment_method.removed';

    // ── Catch-all ─────────────────────────────────────────────────────────────

    /**
     * The provider event could not be mapped to a known canonical type.
     *
     * Drivers SHOULD log the raw provider event name and return this case.
     * The host application SHOULD log and skip Unknown events rather than
     * processing them blindly.
     */
    case Unknown = 'unknown';

    // =========================================================================
    // Helper methods
    // =========================================================================

    /**
     * Human-readable label for logs, UIs, and audit trails.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::PaymentSucceeded       => 'Payment Succeeded',
            self::PaymentFailed          => 'Payment Failed',
            self::PaymentAuthorized      => 'Payment Authorized',
            self::PaymentCaptured        => 'Payment Captured',
            self::PaymentVoided          => 'Payment Voided',
            self::PaymentActionRequired  => 'Payment Action Required',
            self::RefundSucceeded        => 'Refund Succeeded',
            self::RefundFailed           => 'Refund Failed',
            self::DisputeOpened          => 'Dispute Opened',
            self::DisputeResolved        => 'Dispute Resolved',
            self::SubscriptionCreated    => 'Subscription Created',
            self::SubscriptionRenewed    => 'Subscription Renewed',
            self::SubscriptionRenewalFailed => 'Subscription Renewal Failed',
            self::SubscriptionCancelled  => 'Subscription Cancelled',
            self::SubscriptionTrialEnded => 'Subscription Trial Ended',
            self::PaymentMethodSaved     => 'Payment Method Saved',
            self::PaymentMethodRemoved   => 'Payment Method Removed',
            self::Unknown                => 'Unknown Event',
        };
    }

    /**
     * Whether this event type represents a successful fund movement.
     *
     * Use this to trigger order fulfilment or revenue recognition logic
     * from a webhook listener.
     *
     * @return bool
     */
    public function isPaymentSuccess(): bool
    {
        return match ($this) {
            self::PaymentSucceeded,
            self::PaymentCaptured   => true,
            default                 => false,
        };
    }

    /**
     * Whether this event type is related to a refund.
     *
     * @return bool
     */
    public function isRefund(): bool
    {
        return match ($this) {
            self::RefundSucceeded,
            self::RefundFailed => true,
            default            => false,
        };
    }

    /**
     * Whether this event type is related to a subscription lifecycle.
     *
     * @return bool
     */
    public function isSubscription(): bool
    {
        return match ($this) {
            self::SubscriptionCreated,
            self::SubscriptionRenewed,
            self::SubscriptionRenewalFailed,
            self::SubscriptionCancelled,
            self::SubscriptionTrialEnded => true,
            default                      => false,
        };
    }

    /**
     * Whether this event type requires urgent merchant attention.
     *
     * Disputes and failed renewals should trigger alerts in most systems.
     *
     * @return bool
     */
    public function requiresAttention(): bool
    {
        return match ($this) {
            self::DisputeOpened,
            self::PaymentFailed,
            self::RefundFailed,
            self::SubscriptionRenewalFailed => true,
            default                         => false,
        };
    }

    /**
     * Whether this is the unknown/unmapped fallback case.
     *
     * @return bool
     */
    public function isUnknown(): bool
    {
        return $this === self::Unknown;
    }

    /**
     * All event types that should trigger downstream business logic
     * (excludes Unknown, metadata-only, and informational events).
     *
     * @return list<self>
     */
    public static function actionable(): array
    {
        return array_filter(
            self::cases(),
            static fn (self $e): bool => ! $e->isUnknown(),
        );
    }
}
