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
}
