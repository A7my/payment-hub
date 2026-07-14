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
     * @param array<string, mixed> $raw The raw Stripe API response payload.
     */
    public function toCaptureResponse(array $raw): CaptureResponse
    {
        // TODO: Construct CaptureResponse from the captured PaymentIntent payload.
        throw new \LogicException('StripeMapper::toCaptureResponse() not yet implemented.');
    }

    /**
     * Map a raw Stripe Refund payload to a RefundResponse.
     *
     * Used by both refund() and partialRefund().
     *
     * @param array<string, mixed> $raw The raw Stripe API response payload.
     */
    public function toRefundResponse(array $raw): RefundResponse
    {
        // TODO: Construct RefundResponse from the Stripe Refund object payload.
        throw new \LogicException('StripeMapper::toRefundResponse() not yet implemented.');
    }

    /**
     * Map a raw Stripe cancelled-PaymentIntent payload to a VoidResponse.
     *
     * @param array<string, mixed> $raw The raw Stripe API response payload.
     */
    public function toVoidResponse(array $raw): VoidResponse
    {
        // TODO: Construct VoidResponse from the cancelled PaymentIntent payload.
        throw new \LogicException('StripeMapper::toVoidResponse() not yet implemented.');
    }

    /**
     * Map a raw Stripe PaymentIntent payload to a StatusResponse.
     *
     * Used by lookup().
     *
     * @param array<string, mixed> $raw The raw Stripe API response payload.
     */
    public function toStatusResponse(array $raw): StatusResponse
    {
        // TODO: Construct StatusResponse from the retrieved PaymentIntent payload.
        throw new \LogicException('StripeMapper::toStatusResponse() not yet implemented.');
    }

    /**
     * Map a raw Stripe PaymentIntent payload to a VerificationResponse.
     *
     * Used by verify().
     *
     * @param array<string, mixed> $raw The raw Stripe API response payload.
     */
    public function toVerificationResponse(array $raw): VerificationResponse
    {
        // TODO: Construct VerificationResponse from the retrieved PaymentIntent payload.
        throw new \LogicException('StripeMapper::toVerificationResponse() not yet implemented.');
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
