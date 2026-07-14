<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Enums;

/**
 * The operation type performed in a payment transaction.
 *
 * Design decisions:
 * - This enum describes WHAT was done, not the result. It is used to
 *   categorise records in the `payment_transactions` table and to provide
 *   meaningful context in logs, events, and audit trails.
 * - `Charge` vs `Authorization`+`Capture`: some providers always do auth-capture
 *   in two steps; others support a single-step charge. Both flows are first-class.
 * - `TokenCharge` is separate from `Charge` because it has distinct risk,
 *   compliance (stored-credential mandate), and audit requirements.
 * - `Verification` covers lookup-only calls that confirm a transaction status
 *   without mutating any funds (useful for webhook reconciliation).
 */
enum TransactionType: string
{
    /**
     * Single-step charge: authorise and capture simultaneously.
     *
     * Use when the provider does not distinguish between authorisation and
     * settlement, or when the merchant wants immediate capture.
     */
    case Charge = 'charge';

    /**
     * Authorisation only — reserves funds without capturing them.
     *
     * A subsequent `Capture` is required to move funds.
     */
    case Authorization = 'authorization';

    /**
     * Capture of a previously authorised amount.
     *
     * May be for the full authorised amount or a lesser amount.
     */
    case Capture = 'capture';

    /**
     * Full refund of a previously captured payment.
     *
     * The entire captured amount is returned to the customer.
     */
    case Refund = 'refund';

    /**
     * Partial refund — a portion of the captured amount is returned.
     *
     * Stored separately from `Refund` to maintain an accurate audit trail.
     */
    case PartialRefund = 'partial_refund';

    /**
     * Void of an authorised but not yet captured transaction.
     *
     * Releases the reserved funds without any settlement.
     */
    case Void = 'void';

    /**
     * Recurring subscription creation or renewal charge.
     *
     * Logged separately to distinguish scheduled recurring charges from
     * one-off customer-initiated charges.
     */
    case Subscription = 'subscription';

    /**
     * Charge using a stored/tokenised payment method.
     *
     * Covers MIT (merchant-initiated transactions) and CIT (customer-initiated
     * using a saved card/wallet). Kept separate for compliance audit trails
     * (e.g., PSD2 stored-credential mandates).
     */
    case TokenCharge = 'token_charge';

    /**
     * Status lookup or verification query — no funds movement.
     *
     * Logged to support webhook reconciliation and idempotency checks.
     */
    case Verification = 'verification';

    // =========================================================================
    // Helper methods
    // =========================================================================

    /**
     * Human-readable label for this transaction type.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::Charge        => 'Charge',
            self::Authorization => 'Authorization',
            self::Capture       => 'Capture',
            self::Refund        => 'Refund',
            self::PartialRefund => 'Partial Refund',
            self::Void          => 'Void',
            self::Subscription  => 'Subscription',
            self::TokenCharge   => 'Token Charge',
            self::Verification  => 'Verification',
        };
    }

    /**
     * Whether this transaction type moves funds (debit or credit to the customer).
     *
     * Returns false for Void (funds were never settled) and Verification (read-only).
     *
     * @return bool
     */
    public function movesFunds(): bool
    {
        return match ($this) {
            self::Void,
            self::Verification => false,
            default            => true,
        };
    }

    /**
     * Whether this type represents a debit (money taken from customer).
     *
     * @return bool
     */
    public function isDebit(): bool
    {
        return match ($this) {
            self::Charge,
            self::Authorization,
            self::Capture,
            self::Subscription,
            self::TokenCharge   => true,
            default             => false,
        };
    }

    /**
     * Whether this type represents a credit (money returned to customer).
     *
     * @return bool
     */
    public function isCredit(): bool
    {
        return match ($this) {
            self::Refund,
            self::PartialRefund => true,
            default             => false,
        };
    }
}
