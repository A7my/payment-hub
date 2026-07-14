<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Enums;

/**
 * Canonical lifecycle states of a payment transaction.
 *
 * Every driver MUST map its provider-specific status strings to one of these
 * cases before returning a response. This guarantees that the host application
 * works with an identical, predictable status vocabulary regardless of the
 * active payment provider.
 *
 * Design decisions:
 * - `RequiresAction` covers any provider-specific customer-action step (3DS,
 *   OTP, redirect), without encoding provider details into the enum.
 * - `PartiallyRefunded` is a separate case so the application can distinguish
 *   a transaction still partially live from a fully-refunded one.
 * - `Voided` is distinct from `Cancelled`: void applies only to authorised-but-
 *   not-captured transactions; cancelled is customer/merchant-initiated before
 *   any funds move.
 */
enum PaymentStatus: string
{
    /** Initiated but not yet processed by the provider. */
    case Pending = 'pending';

    /** Funds are reserved (hold placed) — not yet captured. */
    case Authorized = 'authorized';

    /** Funds have been captured and settlement is in progress or complete. */
    case Captured = 'captured';

    /** Provider declined or an irrecoverable error occurred. */
    case Failed = 'failed';

    /** An authorised-but-uncaptured hold has been released. */
    case Voided = 'voided';

    /** The full captured amount has been returned to the customer. */
    case Refunded = 'refunded';

    /** A portion of the captured amount has been returned. */
    case PartiallyRefunded = 'partially_refunded';

    /** Cancelled before any funds were moved (customer or merchant action). */
    case Cancelled = 'cancelled';

    /** The payment session, link, or authorisation window has expired. */
    case Expired = 'expired';

    /** Additional customer action is required before processing can continue (e.g., 3DS, OTP, redirect). */
    case RequiresAction = 'requires_action';

    // =========================================================================
    // Helper methods
    // =========================================================================

    /**
     * Human-readable label suitable for display in UIs or log messages.
     *
     * @return string Capitalised, spaced label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending           => 'Pending',
            self::Authorized        => 'Authorized',
            self::Captured          => 'Captured',
            self::Failed            => 'Failed',
            self::Voided            => 'Voided',
            self::Refunded          => 'Refunded',
            self::PartiallyRefunded => 'Partially Refunded',
            self::Cancelled         => 'Cancelled',
            self::Expired           => 'Expired',
            self::RequiresAction    => 'Requires Action',
        };
    }

    /**
     * Whether the transaction is in a terminal (final, non-changeable) state.
     *
     * Terminal states cannot transition to any other status. This is useful
     * for guarding against duplicate processing or UI conditional logic.
     *
     * @return bool True for Failed, Voided, Refunded, Cancelled, Expired.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Failed,
            self::Voided,
            self::Refunded,
            self::Cancelled,
            self::Expired   => true,
            default         => false,
        };
    }

    /**
     * Whether the transaction represents a successful fund movement.
     *
     * A "successful" status means money was captured or at least reserved.
     * Use this to gate order fulfilment logic.
     *
     * @return bool True for Authorized, Captured, PartiallyRefunded.
     */
    public function isSuccessful(): bool
    {
        return match ($this) {
            self::Authorized,
            self::Captured,
            self::PartiallyRefunded => true,
            default                 => false,
        };
    }

    /**
     * Whether the transaction can still be refunded.
     *
     * Drivers should check this before accepting a refund request to provide
     * an early, framework-level guard before hitting the provider API.
     *
     * @return bool True for Captured and PartiallyRefunded.
     */
    public function isRefundable(): bool
    {
        return match ($this) {
            self::Captured,
            self::PartiallyRefunded => true,
            default                 => false,
        };
    }

    /**
     * Whether the transaction can be voided.
     *
     * Only authorised-but-uncaptured transactions can be voided.
     *
     * @return bool True only for Authorized.
     */
    public function isVoidable(): bool
    {
        return $this === self::Authorized;
    }

    /**
     * Whether customer or host-application action is still pending.
     *
     * @return bool True for Pending and RequiresAction.
     */
    public function isPending(): bool
    {
        return match ($this) {
            self::Pending,
            self::RequiresAction => true,
            default              => false,
        };
    }

    /**
     * Return all statuses that are considered "active" (not terminal, not pending).
     *
     * Useful for database queries: "give me all transactions that can still change".
     *
     * @return list<self>
     */
    public static function activeStatuses(): array
    {
        return [
            self::Authorized,
            self::Captured,
            self::PartiallyRefunded,
        ];
    }

    /**
     * Return all terminal statuses.
     *
     * @return list<self>
     */
    public static function terminalStatuses(): array
    {
        return array_filter(
            self::cases(),
            static fn (self $s): bool => $s->isTerminal(),
        );
    }
}
