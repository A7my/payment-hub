<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Drivers\Paymob;

use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\Responses\CaptureResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentLinkResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\RefundResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\StatusResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\VerificationResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\VoidResponse;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;

/**
 * Converts raw Paymob Transaction payloads into framework Response objects.
 *
 * UNVERIFIED AGAINST LIVE PAYMOB DOCS — same caveat as {@see PaymobClient}:
 * every field name read below (`success`, `pending`, `is_voided`,
 * `is_refunded`, `is_auth`, `is_capture`, `amount_cents`, …) is assumed from
 * general knowledge of Paymob's Transaction object shape, not confirmed
 * against a live payload. Where a signal genuinely could not be confirmed
 * (full vs. partial refund detection in particular — see
 * {@see self::isFullyRefunded()}), the fallback is documented explicitly
 * rather than silently guessed.
 *
 * Contains ONLY translation logic — no HTTP communication and no lifecycle
 * orchestration, mirroring
 * {@see \Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeMapper}'s role.
 */
final class PaymobMapper
{
    /**
     * Map a raw Paymob Transaction payload to a PaymentResponse.
     *
     * Used by charge(), authorize(), chargeToken(), and saveCard() — all
     * four go through {@see PaymobClient::payWithToken()} and get back the
     * same Transaction shape.
     *
     * @param array<string, mixed> $raw The raw Paymob Transaction payload.
     */
    public function toPaymentResponse(array $raw): PaymentResponse
    {
        $status = $this->mapTransactionStatus($raw);
        $amount = Money::ofMinor(
            (int) ($raw['amount_cents'] ?? 0),
            $this->currencyFrom($raw),
        );

        return new PaymentResponse(
            successful: $status->isSuccessful(),
            transactionId: TransactionId::fromString((string) ($raw['id'] ?? '')),
            status: $status,
            providerReference: (string) ($raw['order']['id'] ?? ''),
            amount: $amount,
            rawResponse: $raw,
            message: $this->resolveMessage($status, $raw),
        );
    }

    /**
     * Map a Paymob Transaction's boolean flags to the canonical PaymentStatus.
     *
     * UNVERIFIED mapping (best-effort, based on general knowledge of the
     * flags Paymob's Transaction object is documented to carry):
     *   - `pending: true`                          → Pending
     *   - `success: false`                          → Failed
     *   - `is_voided: true`                          → Voided
     *   - `is_refunded: true`                        → Refunded or PartiallyRefunded (see {@see self::isFullyRefunded()})
     *   - `success: true`, `is_auth: true`, `is_capture: false` → Authorized (authorised, not yet captured)
     *   - `success: true`, otherwise                 → Captured
     */
    private function mapTransactionStatus(array $raw): PaymentStatus
    {
        if (($raw['pending'] ?? false) === true) {
            return PaymentStatus::Pending;
        }

        if (($raw['is_voided'] ?? false) === true) {
            return PaymentStatus::Voided;
        }

        if (($raw['is_refunded'] ?? false) === true) {
            return $this->isFullyRefunded($raw) ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded;
        }

        if (($raw['success'] ?? false) !== true) {
            return PaymentStatus::Failed;
        }

        if (($raw['is_auth'] ?? false) === true && ($raw['is_capture'] ?? false) !== true) {
            return PaymentStatus::Authorized;
        }

        return PaymentStatus::Captured;
    }

    /**
     * Determine whether a refunded transaction was refunded in full.
     *
     * UNVERIFIED / NOT CONFIDENTLY KNOWN: unlike Stripe (where the expanded
     * Charge object's `amount` vs. cumulative `amount_refunded` gives an
     * exact signal — see `StripeMapper::isFullyRefunded()`), the exact field
     * Paymob uses to report the cumulative refunded amount on a Transaction
     * is not confirmed here. This defensively assumes a FULL refund
     * whenever the specific cumulative-refund field cannot be found, which
     * mirrors `StripeMapper::isFullyRefunded()`'s own fallback-when-missing
     * behaviour — but here it is the common case, not just a fallback for a
     * rare missing-field edge case. This needs live verification before the
     * Refunded-vs-PartiallyRefunded distinction can be trusted for Paymob.
     */
    private function isFullyRefunded(array $raw): bool
    {
        $refundedCents = $raw['refunded_amount_cents'] ?? null;
        $totalCents    = $raw['amount_cents'] ?? null;

        if (! is_int($refundedCents) && ! is_numeric($refundedCents)) {
            return true;
        }

        if (! is_int($totalCents) && ! is_numeric($totalCents)) {
            return true;
        }

        return (int) $refundedCents >= (int) $totalCents;
    }

    /**
     * Map a raw Paymob Transaction payload to a CaptureResponse.
     *
     * @param array<string, mixed> $raw The raw Paymob Transaction payload.
     */
    public function toCaptureResponse(array $raw): CaptureResponse
    {
        $captured = ($raw['success'] ?? false) === true && ($raw['pending'] ?? false) !== true;
        $status   = $captured ? PaymentStatus::Captured : PaymentStatus::Failed;

        return new CaptureResponse(
            successful: $captured,
            captureId: (string) ($raw['id'] ?? ''),
            amount: Money::ofMinor((int) ($raw['amount_cents'] ?? 0), $this->currencyFrom($raw)),
            status: $status,
            message: $captured ? 'Payment captured.' : $this->resolveMessage($status, $raw),
            rawResponse: $raw,
        );
    }

    /**
     * Map a raw Paymob Transaction payload to a VoidResponse.
     *
     * @param array<string, mixed> $raw The raw Paymob Transaction payload.
     */
    public function toVoidResponse(array $raw): VoidResponse
    {
        $voided = ($raw['is_voided'] ?? false) === true;
        $status = $voided ? PaymentStatus::Voided : PaymentStatus::Failed;

        return new VoidResponse(
            successful: $voided,
            transactionId: TransactionId::fromString((string) ($raw['id'] ?? '')),
            status: $status,
            message: $voided ? 'Payment voided.' : $this->resolveMessage($status, $raw),
            rawResponse: $raw,
        );
    }

    /**
     * Map a raw Paymob Transaction payload to a RefundResponse.
     *
     * Used by both refund() and partialRefund() — same reasoning as
     * {@see \Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeMapper::toRefundResponse()}:
     * the payload itself is the source of truth, not which driver method
     * was called.
     *
     * @param array<string, mixed> $raw The raw Paymob Transaction payload.
     */
    public function toRefundResponse(array $raw): RefundResponse
    {
        $status = ($raw['is_refunded'] ?? false) === true
            ? ($this->isFullyRefunded($raw) ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded)
            : PaymentStatus::Failed;

        return new RefundResponse(
            successful: $status === PaymentStatus::Refunded || $status === PaymentStatus::PartiallyRefunded,
            refundId: (string) ($raw['id'] ?? ''),
            amount: Money::ofMinor((int) ($raw['amount_cents'] ?? 0), $this->currencyFrom($raw)),
            status: $status,
            message: $status === PaymentStatus::Failed ? $this->resolveMessage($status, $raw) : 'Refund processed.',
            rawResponse: $raw,
        );
    }

    /**
     * Map a raw Paymob Transaction payload to a StatusResponse, for lookup().
     *
     * @param array<string, mixed> $raw The raw Paymob Transaction payload.
     */
    public function toStatusResponse(array $raw): StatusResponse
    {
        $status = $this->mapTransactionStatus($raw);

        return new StatusResponse(
            successful: true,
            transactionId: TransactionId::fromString((string) ($raw['id'] ?? '')),
            status: $status,
            message: $this->resolveMessage($status, $raw),
            rawResponse: $raw,
        );
    }

    /**
     * Map a raw Paymob Transaction payload to a VerificationResponse, for verify().
     *
     * @param array<string, mixed> $raw The raw Paymob Transaction payload.
     */
    public function toVerificationResponse(array $raw): VerificationResponse
    {
        $status   = $this->mapTransactionStatus($raw);
        $verified = $status->isSuccessful();

        return new VerificationResponse(
            successful: true,
            verified: $verified,
            transactionId: TransactionId::fromString((string) ($raw['id'] ?? '')),
            message: $verified
                ? 'Transaction verified as authentic.'
                : 'Transaction could not be verified: ' . $this->resolveMessage($status, $raw),
            rawResponse: $raw,
        );
    }

    /**
     * Build a PaymentLinkResponse from a hosted checkout URL.
     *
     * Unlike every other method on this mapper, there is no Paymob
     * Transaction payload here — creating a payment link (order +
     * payment key) never itself charges anything, matching
     * {@see \Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeMapper::toPaymentLinkResponse()}'s
     * same reasoning for Stripe Checkout Sessions. `successful` is
     * unconditionally true — only reached after non-throwing order +
     * payment-key creation.
     *
     * @param array<string, mixed> $rawOrder The raw Paymob order payload (for debugging/audit).
     */
    public function toPaymentLinkResponse(string $url, array $rawOrder): PaymentLinkResponse
    {
        return new PaymentLinkResponse(
            successful: true,
            paymentUrl: $url,
            linkId: (string) ($rawOrder['id'] ?? ''),
            expiresAt: null,
            message: 'Payment link created.',
            rawResponse: $rawOrder,
        );
    }

    /**
     * Resolve a human-readable message, preferring Paymob's own decline
     * detail when present.
     *
     * UNVERIFIED: the exact field Paymob uses for a human-readable decline
     * reason (`data.message` here) is a best guess — Paymob transactions
     * are also documented to carry a `txn_response_code` (a short code, not
     * a message) that a real integration would want mapped to readable text
     * via a lookup table; that table is not built here.
     */
    private function resolveMessage(PaymentStatus $status, array $raw): string
    {
        $providerMessage = $raw['data']['message'] ?? null;

        if (is_string($providerMessage) && $providerMessage !== '') {
            return $providerMessage;
        }

        return match ($status) {
            PaymentStatus::Captured          => 'Payment succeeded.',
            PaymentStatus::Authorized        => 'Payment authorised, awaiting capture.',
            PaymentStatus::Pending           => 'Payment is processing.',
            PaymentStatus::Voided            => 'Payment voided.',
            PaymentStatus::Refunded          => 'Refund processed.',
            PaymentStatus::PartiallyRefunded => 'Partial refund processed.',
            default                          => 'Payment failed.',
        };
    }

    /**
     * Resolve the currency for a raw Paymob payload, defaulting to EGP
     * (Paymob's primary market) when absent rather than an arbitrary guess.
     */
    private function currencyFrom(array $raw): Currency
    {
        $value = strtoupper((string) ($raw['currency'] ?? 'EGP'));

        return Currency::tryFrom($value) ?? Currency::EGP;
    }
}
