<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Enums;

/**
 * Payment method used or requested for a transaction.
 *
 * Design decisions:
 * - Cases are deliberately broad and provider-agnostic. No case is named
 *   after a specific provider (no "ApplePayStripe", no "PayPalWallet").
 * - `Wallet` covers Apple Pay, Google Pay, Samsung Pay, STC Pay, and any
 *   other digital wallet. Drivers that need to distinguish between wallet
 *   subtypes should do so internally and map to this single case.
 * - `BuyNowPayLater` covers Tabby, Tamara, Klarna, Afterpay, and similar
 *   deferred-payment services.
 * - `Token` represents a previously saved payment method being reused
 *   (often called "recurring" or "stored credentials" by providers).
 * - `PaymentLink` represents a redirect-based hosted page. The distinction
 *   from `Card` matters because payment links often have different
 *   expiry and lifecycle semantics.
 * - Adding a future method (e.g., `Crypto`, `DirectDebit`) requires adding
 *   a new case here and updating `supportsMethod()` in drivers that handle it —
 *   no other framework code changes are needed (Open/Closed Principle).
 */
enum PaymentMethod: string
{
    // ── Common card-based ─────────────────────────────────────────────────────

    /** Credit or debit card (Visa, Mastercard, Amex, Mada, etc.). */
    case Card = 'card';

    // ── Digital wallets ───────────────────────────────────────────────────────

    /**
     * Digital wallet.
     *
     * Covers Apple Pay, Google Pay, Samsung Pay, STC Pay, OPay Wallet, and
     * any other device/app-based wallet. Drivers distinguish the subtype
     * internally; the framework sees only `Wallet`.
     */
    case Wallet = 'wallet';

    // ── Bank & direct ─────────────────────────────────────────────────────────

    /** Bank transfer / ACH / SEPA / SWIFT / local bank schemes. */
    case BankTransfer = 'bank_transfer';

    // ── Deferred / alternative ───────────────────────────────────────────────

    /**
     * Buy Now Pay Later.
     *
     * Covers Tabby, Tamara, Klarna, Afterpay, Affirm, and similar BNPL services.
     */
    case BuyNowPayLater = 'buy_now_pay_later';

    /**
     * Instalment plan.
     *
     * A fixed-instalment split distinct from open-ended BNPL. Some providers
     * (e.g., Fawry, Paymob) offer bank-backed instalment products.
     */
    case Installment = 'installment';

    // ── Redirect / link-based ────────────────────────────────────────────────

    /**
     * Hosted payment link / redirect page.
     *
     * The customer completes payment on a provider-hosted page via a URL.
     * Distinct from `Card` because the lifecycle (expiry, redirect flow)
     * differs from a direct card charge.
     */
    case PaymentLink = 'payment_link';

    // ── Token / saved methods ────────────────────────────────────────────────

    /**
     * Tokenised / stored-credential payment.
     *
     * Represents any charge using a previously saved payment method token,
     * regardless of the underlying method type (card, wallet, etc.).
     */
    case Token = 'token';

    // ── Code-based ───────────────────────────────────────────────────────────

    /**
     * QR-code-based payment.
     *
     * Covers UnionPay QR, local schemes (SadaD QR, Fawry QR, etc.),
     * and any provider offering QR-initiated payments.
     */
    case QrCode = 'qr_code';

    // =========================================================================
    // Helper methods
    // =========================================================================

    /**
     * Human-readable label for display in UIs, logs, and receipts.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::Card           => 'Card',
            self::Wallet         => 'Digital Wallet',
            self::BankTransfer   => 'Bank Transfer',
            self::BuyNowPayLater => 'Buy Now Pay Later',
            self::Installment    => 'Installment',
            self::PaymentLink    => 'Payment Link',
            self::Token          => 'Saved Payment Method',
            self::QrCode         => 'QR Code',
        };
    }

    /**
     * Whether this method typically requires a redirect to a provider-hosted page.
     *
     * Useful for determining whether the host application should expect a
     * redirect URL in the payment response rather than an immediate result.
     *
     * @return bool
     */
    public function requiresRedirect(): bool
    {
        return match ($this) {
            self::PaymentLink,
            self::BuyNowPayLater,
            self::Installment,
            self::QrCode         => true,
            default              => false,
        };
    }

    /**
     * Whether this method represents a stored / reusable credential.
     *
     * @return bool
     */
    public function isStoredCredential(): bool
    {
        return $this === self::Token;
    }

    /**
     * Whether this is a card-family method (direct card or token backed by a card).
     *
     * @return bool
     */
    public function isCardBased(): bool
    {
        return match ($this) {
            self::Card,
            self::Token => true,
            default     => false,
        };
    }

    /**
     * Methods that support recurring / subscription charges.
     *
     * These methods can be used for subscription billing. Others typically
     * require customer presence for each transaction.
     *
     * @return list<self>
     */
    public static function recurringCapable(): array
    {
        return [
            self::Card,
            self::Token,
        ];
    }
}
