<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Drivers\Stripe;

use Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeMapper;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StripeMapper::toRefundResponse(), specifically the
 * Refunded-vs-PartiallyRefunded signal derivation.
 *
 * Stripe's Refund `status` field alone cannot distinguish a full refund
 * from a partial one — both report `succeeded`. The signal instead comes
 * from the expanded Charge sub-object's `amount` (original total) vs.
 * `amount_refunded` (cumulative refunded so far), which
 * StripeClient::createRefund() always requests via `expand: ['charge']`.
 * These tests exercise that comparison directly against raw payloads —
 * no HTTP mocking needed, since this is pure mapping logic.
 */
final class StripeMapperTest extends TestCase
{
    private StripeMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new StripeMapper();
    }

    /**
     * @param array<string, mixed>|null $charge
     *
     * @return array<string, mixed>
     */
    private function refundPayload(string $status = 'succeeded', ?array $charge = null): array
    {
        return array_filter([
            'id'       => 're_test_001',
            'object'   => 'refund',
            'status'   => $status,
            'amount'   => 1000,
            'currency' => 'usd',
            'charge'   => $charge,
        ], static fn ($value) => $value !== null);
    }

    // =========================================================================
    // Exact boundary: refunded amount equals the original charge amount
    // =========================================================================

    /** @test */
    public function test_amount_refunded_exactly_equal_to_charge_amount_is_fully_refunded(): void
    {
        $response = $this->mapper->toRefundResponse($this->refundPayload(charge: [
            'id'              => 'ch_test_001',
            'amount'          => 1000,
            'amount_refunded' => 1000,
        ]));

        $this->assertSame(PaymentStatus::Refunded, $response->getStatus());
        $this->assertTrue($response->isSuccessful());
        $this->assertFalse($response->isPartial());
        $this->assertSame('Refund processed.', $response->getMessage());
    }

    /** @test */
    public function test_amount_refunded_one_cent_less_than_charge_amount_is_partially_refunded(): void
    {
        $response = $this->mapper->toRefundResponse($this->refundPayload(charge: [
            'id'              => 'ch_test_002',
            'amount'          => 1000,
            'amount_refunded' => 999,
        ]));

        $this->assertSame(PaymentStatus::PartiallyRefunded, $response->getStatus());
        $this->assertTrue($response->isSuccessful());
        $this->assertTrue($response->isPartial());
        $this->assertSame('Partial refund processed.', $response->getMessage());
    }

    /** @test */
    public function test_amount_refunded_far_less_than_charge_amount_is_partially_refunded(): void
    {
        $response = $this->mapper->toRefundResponse($this->refundPayload(charge: [
            'id'              => 'ch_test_003',
            'amount'          => 1000,
            'amount_refunded' => 250,
        ]));

        $this->assertSame(PaymentStatus::PartiallyRefunded, $response->getStatus());
        $this->assertTrue($response->isPartial());
    }

    // =========================================================================
    // Cumulative multi-refund boundary: several partial refunds summing to
    // the total. Charge::amount_refunded is cumulative across ALL refunds on
    // that charge (verified against the SDK's own docblock), so the LAST
    // refund that brings the running total up to the full amount is reported
    // as Refunded — even though its own individual amount was small.
    // =========================================================================

    /** @test */
    public function test_final_partial_refund_that_completes_the_cumulative_total_is_fully_refunded(): void
    {
        // Scenario: a $10.00 charge. A first partial refund of $6.00 already
        // happened (not modelled here — only its cumulative effect matters).
        // This second refund call requests $4.00, but the response payload's
        // charge.amount_refunded (1000) is CUMULATIVE — it reflects both
        // refunds combined, exhausting the charge.
        $response = $this->mapper->toRefundResponse(array_merge(
            $this->refundPayload(charge: [
                'id'              => 'ch_test_004',
                'amount'          => 1000,
                'amount_refunded' => 1000, // cumulative: 600 (earlier) + 400 (this call)
            ]),
            ['amount' => 400], // this specific refund's own amount was only 400
        ));

        $this->assertSame(PaymentStatus::Refunded, $response->getStatus());
        $this->assertFalse($response->isPartial());
        // The RefundResponse's own $amount still reflects THIS refund call,
        // not the cumulative total — that distinction belongs to $status.
        $this->assertSame(400, $response->getAmount()->amount);
    }

    /** @test */
    public function test_intermediate_partial_refund_before_the_total_is_reached_is_partially_refunded(): void
    {
        // Same two-refund scenario, but inspecting the FIRST refund's own
        // response: cumulative amount_refunded (600) is still less than the
        // charge's total (1000) at that point.
        $response = $this->mapper->toRefundResponse(array_merge(
            $this->refundPayload(charge: [
                'id'              => 'ch_test_005',
                'amount'          => 1000,
                'amount_refunded' => 600,
            ]),
            ['amount' => 600],
        ));

        $this->assertSame(PaymentStatus::PartiallyRefunded, $response->getStatus());
        $this->assertTrue($response->isPartial());
    }

    // =========================================================================
    // Defensive fallback: charge not expanded (missing from payload)
    // =========================================================================

    /** @test */
    public function test_missing_charge_expansion_defensively_assumes_full_refund(): void
    {
        // No 'charge' key at all — e.g. an older or synthetic payload.
        // Preserves the pre-expansion behaviour so no existing caller regresses.
        $response = $this->mapper->toRefundResponse($this->refundPayload());

        $this->assertSame(PaymentStatus::Refunded, $response->getStatus());
        $this->assertTrue($response->isSuccessful());
    }

    /** @test */
    public function test_charge_present_but_missing_amount_fields_defensively_assumes_full_refund(): void
    {
        $response = $this->mapper->toRefundResponse($this->refundPayload(charge: [
            'id' => 'ch_test_006',
            // amount / amount_refunded deliberately absent
        ]));

        $this->assertSame(PaymentStatus::Refunded, $response->getStatus());
    }

    // =========================================================================
    // Non-succeeded statuses are unaffected by the charge-comparison logic
    // =========================================================================

    /** @test */
    public function test_pending_status_is_unaffected_by_charge_expansion(): void
    {
        $response = $this->mapper->toRefundResponse(array_merge(
            $this->refundPayload(status: 'pending', charge: [
                'id'              => 'ch_test_007',
                'amount'          => 1000,
                'amount_refunded' => 0,
            ]),
            ['pending_reason' => 'processing'],
        ));

        $this->assertSame(PaymentStatus::Pending, $response->getStatus());
        $this->assertFalse($response->isSuccessful());
        $this->assertFalse($response->isPartial());
    }

    /** @test */
    public function test_failed_status_is_unaffected_by_charge_expansion(): void
    {
        $response = $this->mapper->toRefundResponse(array_merge(
            $this->refundPayload(status: 'failed', charge: [
                'id'              => 'ch_test_008',
                'amount'          => 1000,
                'amount_refunded' => 0,
            ]),
            ['failure_reason' => 'insufficient_funds'],
        ));

        $this->assertSame(PaymentStatus::Failed, $response->getStatus());
        $this->assertFalse($response->isSuccessful());
    }

    // =========================================================================
    // toSaveCardResponse() — SetupIntent shape, distinct from toPaymentResponse()
    // =========================================================================

    /**
     * @return array<string, mixed>
     */
    private function setupIntentPayload(string $status = 'succeeded', ?array $lastSetupError = null): array
    {
        return array_filter([
            'id'               => 'seti_test_001',
            'object'           => 'setup_intent',
            'status'           => $status,
            'customer'         => 'cus_test_001',
            'payment_method'   => 'pm_card_visa',
            'last_setup_error' => $lastSetupError,
        ], static fn ($value) => $value !== null);
    }

    /** @test */
    public function test_succeeded_setup_intent_maps_to_captured_with_zero_amount(): void
    {
        $response = $this->mapper->toSaveCardResponse($this->setupIntentPayload());

        $this->assertTrue($response->isSuccessful());
        $this->assertSame(PaymentStatus::Captured, $response->getStatus());
        $this->assertSame('seti_test_001', $response->getTransactionId()->toString());
        // No amount concept exists on a SetupIntent — always zero.
        $this->assertSame(0, $response->getAmount()->amount);
        $this->assertSame('Card saved.', $response->getMessage());
    }

    /** @test */
    public function test_setup_intent_customer_field_becomes_the_provider_reference(): void
    {
        $response = $this->mapper->toSaveCardResponse($this->setupIntentPayload());

        $this->assertSame('cus_test_001', $response->getProviderReference());
    }

    /** @test */
    public function test_requires_action_setup_intent_maps_to_requires_action(): void
    {
        $response = $this->mapper->toSaveCardResponse($this->setupIntentPayload(status: 'requires_action'));

        $this->assertFalse($response->isSuccessful());
        $this->assertSame(PaymentStatus::RequiresAction, $response->getStatus());
        $this->assertTrue($response->requiresAction());
    }

    /** @test */
    public function test_declined_setup_intent_reads_message_from_last_setup_error_not_last_payment_error(): void
    {
        // The field is genuinely named differently on a SetupIntent —
        // reusing resolvePaymentMessage() unchanged would silently look at
        // the wrong key (last_payment_error) and miss this message entirely.
        $response = $this->mapper->toSaveCardResponse($this->setupIntentPayload(
            status: 'requires_payment_method',
            lastSetupError: ['message' => 'Your card was declined.'],
        ));

        $this->assertFalse($response->isSuccessful());
        $this->assertSame(PaymentStatus::Failed, $response->getStatus());
        $this->assertSame('Your card was declined.', $response->getMessage());
    }

    /** @test */
    public function test_failed_setup_intent_without_last_setup_error_falls_back_to_generic_message(): void
    {
        $response = $this->mapper->toSaveCardResponse($this->setupIntentPayload(status: 'requires_payment_method'));

        $this->assertSame('Card save failed.', $response->getMessage());
    }

    /** @test */
    public function test_setup_intent_missing_customer_field_reports_an_empty_provider_reference(): void
    {
        $payload = $this->setupIntentPayload();
        unset($payload['customer']);

        $response = $this->mapper->toSaveCardResponse($payload);

        $this->assertSame('', $response->getProviderReference());
    }

    // =========================================================================
    // toSubscriptionResponse() — the 8-value Stripe status table
    // =========================================================================

    /**
     * @param array<string, mixed>|null $latestInvoice
     *
     * @return array<string, mixed>
     */
    private function subscriptionPayload(
        string $status = 'active',
        bool $cancelAtPeriodEnd = false,
        ?array $latestInvoice = null,
        ?int $currentPeriodEnd = 1700000000,
    ): array {
        return array_filter([
            'id'                   => 'sub_test_001',
            'object'               => 'subscription',
            'status'               => $status,
            'customer'             => 'cus_test_001',
            'cancel_at_period_end' => $cancelAtPeriodEnd,
            'latest_invoice'       => $latestInvoice,
            'items'                => $currentPeriodEnd !== null
                ? ['data' => [['current_period_end' => $currentPeriodEnd]]]
                : null,
        ], static fn ($value) => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceWithPaymentIntent(string $paymentIntentStatus, ?string $declineMessage = null): array
    {
        return [
            'id'       => 'in_test_001',
            'object'   => 'invoice',
            'payments' => [
                'data' => [
                    [
                        'payment' => [
                            'type'           => 'payment_intent',
                            'payment_intent' => array_filter([
                                'id'                 => 'pi_test_001',
                                'status'             => $paymentIntentStatus,
                                'last_payment_error' => $declineMessage !== null
                                    ? ['message' => $declineMessage]
                                    : null,
                            ], static fn ($value) => $value !== null),
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @test */
    public function test_active_maps_to_captured_and_is_successful(): void
    {
        $response = $this->mapper->toSubscriptionResponse($this->subscriptionPayload('active'));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame(PaymentStatus::Captured, $response->getStatus());
        $this->assertSame('Subscription is active.', $response->getMessage());
        $this->assertTrue($response->hasNextBillingDate());
    }

    /** @test */
    public function test_trialing_maps_to_pending_and_is_successful(): void
    {
        $response = $this->mapper->toSubscriptionResponse($this->subscriptionPayload('trialing'));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame(PaymentStatus::Pending, $response->getStatus());
        $this->assertSame('Subscription created; trial period in progress.', $response->getMessage());
    }

    /** @test */
    public function test_incomplete_with_requires_action_payment_intent_maps_to_requires_action(): void
    {
        $payload = $this->subscriptionPayload(
            'incomplete',
            latestInvoice: $this->invoiceWithPaymentIntent('requires_action'),
        );

        $response = $this->mapper->toSubscriptionResponse($payload);

        $this->assertFalse($response->isSuccessful());
        $this->assertSame(PaymentStatus::RequiresAction, $response->getStatus());
    }

    /** @test */
    public function test_incomplete_with_declined_payment_intent_maps_to_failed_with_decline_message(): void
    {
        $payload = $this->subscriptionPayload(
            'incomplete',
            latestInvoice: $this->invoiceWithPaymentIntent('requires_payment_method', 'Your card was declined.'),
        );

        $response = $this->mapper->toSubscriptionResponse($payload);

        $this->assertFalse($response->isSuccessful());
        $this->assertSame(PaymentStatus::Failed, $response->getStatus());
        $this->assertSame('Your card was declined.', $response->getMessage());
    }

    /** @test */
    public function test_incomplete_without_resolvable_expand_defaults_to_requires_action(): void
    {
        $payload = $this->subscriptionPayload('incomplete', latestInvoice: null);

        $response = $this->mapper->toSubscriptionResponse($payload);

        $this->assertSame(PaymentStatus::RequiresAction, $response->getStatus());
    }

    /** @test */
    public function test_incomplete_with_unexpanded_payment_intent_string_id_defaults_to_requires_action(): void
    {
        // payment_intent came back as a bare id string, not expanded.
        $payload = $this->subscriptionPayload('incomplete', latestInvoice: [
            'id'       => 'in_test_002',
            'payments' => ['data' => [['payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_test_002']]]],
        ]);

        $response = $this->mapper->toSubscriptionResponse($payload);

        $this->assertSame(PaymentStatus::RequiresAction, $response->getStatus());
    }

    /** @test */
    public function test_incomplete_expired_maps_to_expired_and_is_unsuccessful(): void
    {
        $response = $this->mapper->toSubscriptionResponse($this->subscriptionPayload('incomplete_expired'));

        $this->assertFalse($response->isSuccessful());
        $this->assertSame(PaymentStatus::Expired, $response->getStatus());
    }

    /** @test */
    public function test_past_due_maps_to_failed(): void
    {
        $response = $this->mapper->toSubscriptionResponse($this->subscriptionPayload('past_due'));

        $this->assertFalse($response->isSuccessful());
        $this->assertSame(PaymentStatus::Failed, $response->getStatus());
    }

    /** @test */
    public function test_unpaid_maps_to_failed(): void
    {
        $response = $this->mapper->toSubscriptionResponse($this->subscriptionPayload('unpaid'));

        $this->assertFalse($response->isSuccessful());
        $this->assertSame(PaymentStatus::Failed, $response->getStatus());
    }

    /** @test */
    public function test_paused_maps_to_requires_action(): void
    {
        $response = $this->mapper->toSubscriptionResponse($this->subscriptionPayload('paused'));

        $this->assertFalse($response->isSuccessful());
        $this->assertSame(PaymentStatus::RequiresAction, $response->getStatus());
    }

    /** @test */
    public function test_canceled_maps_to_cancelled_and_is_successful(): void
    {
        $response = $this->mapper->toSubscriptionResponse($this->subscriptionPayload('canceled'));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame(PaymentStatus::Cancelled, $response->getStatus());
        $this->assertTrue($response->isCancelled());
        $this->assertSame('Subscription cancelled.', $response->getMessage());
    }

    // =========================================================================
    // cancel_at_period_end message clarification
    // =========================================================================

    /** @test */
    public function test_cancel_at_period_end_true_with_active_status_clarifies_message_without_forcing_cancelled(): void
    {
        $payload = $this->subscriptionPayload('active', cancelAtPeriodEnd: true);

        $response = $this->mapper->toSubscriptionResponse($payload);

        $this->assertSame(PaymentStatus::Captured, $response->getStatus());
        $this->assertFalse($response->isCancelled());
        $this->assertStringContainsString(
            'will be cancelled at the end of the current billing period',
            $response->getMessage(),
        );
    }

    /** @test */
    public function test_cancel_at_period_end_true_with_already_canceled_status_uses_the_plain_cancelled_message(): void
    {
        $payload = $this->subscriptionPayload('canceled', cancelAtPeriodEnd: true);

        $response = $this->mapper->toSubscriptionResponse($payload);

        $this->assertSame('Subscription cancelled.', $response->getMessage());
    }

    // =========================================================================
    // nextBillingDate — per-item current_period_end
    // =========================================================================

    /** @test */
    public function test_missing_items_reports_no_next_billing_date(): void
    {
        $payload = $this->subscriptionPayload('active', currentPeriodEnd: null);

        $response = $this->mapper->toSubscriptionResponse($payload);

        $this->assertFalse($response->hasNextBillingDate());
        $this->assertNull($response->getNextBillingDate());
    }

    /** @test */
    public function test_present_items_current_period_end_becomes_the_next_billing_date(): void
    {
        $payload = $this->subscriptionPayload('active', currentPeriodEnd: 1700000000);

        $response = $this->mapper->toSubscriptionResponse($payload);

        $this->assertTrue($response->hasNextBillingDate());
        $this->assertSame(1700000000, $response->getNextBillingDate()->getTimestamp());
    }

    /** @test */
    public function test_subscription_id_and_raw_response_are_preserved(): void
    {
        $payload  = $this->subscriptionPayload('active');
        $response = $this->mapper->toSubscriptionResponse($payload);

        $this->assertSame('sub_test_001', $response->getSubscriptionId());
        $this->assertSame($payload, $response->getRawResponse());
    }

    // =========================================================================
    // toPaymentLinkResponse()
    // =========================================================================

    /** @test */
    public function test_checkout_session_maps_url_and_id(): void
    {
        $response = $this->mapper->toPaymentLinkResponse([
            'id'     => 'cs_test_001',
            'object' => 'checkout.session',
            'status' => 'open',
            'url'    => 'https://checkout.stripe.com/c/pay/cs_test_001',
        ]);

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_test_001', $response->getPaymentUrl());
        $this->assertSame('cs_test_001', $response->getLinkId());
        $this->assertSame('Payment link created.', $response->getMessage());
    }

    /** @test */
    public function test_checkout_session_with_expires_at_populates_expiry(): void
    {
        $response = $this->mapper->toPaymentLinkResponse([
            'id'         => 'cs_test_002',
            'url'        => 'https://checkout.stripe.com/c/pay/cs_test_002',
            'expires_at' => 1700003600,
        ]);

        $this->assertTrue($response->hasExpiry());
        $this->assertSame(1700003600, $response->getExpiresAt()->getTimestamp());
    }

    /** @test */
    public function test_checkout_session_without_expires_at_has_no_expiry(): void
    {
        $response = $this->mapper->toPaymentLinkResponse([
            'id'  => 'cs_test_003',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_003',
        ]);

        $this->assertFalse($response->hasExpiry());
        $this->assertNull($response->getExpiresAt());
    }

    /** @test */
    public function test_checkout_session_missing_url_reports_an_empty_payment_url(): void
    {
        $response = $this->mapper->toPaymentLinkResponse(['id' => 'cs_test_004']);

        $this->assertSame('', $response->getPaymentUrl());
    }
}
