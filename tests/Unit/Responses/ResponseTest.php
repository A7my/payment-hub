<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Responses;

use DateTimeImmutable;
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\Enums\WebhookEventType;
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
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for all Response classes.
 *
 * Verifies contract compliance, immutability, helper methods, and JSON serialisation.
 */
class ResponseTest extends TestCase
{
    private function makeMoney(int $amount = 1000): Money
    {
        return Money::ofMinor($amount, Currency::USD);
    }

    private function makeTransactionId(string $id = 'txn_001'): TransactionId
    {
        return TransactionId::fromString($id);
    }

    // =========================================================================
    // PaymentResponse
    // =========================================================================

    /** @test */
    public function test_payment_response_successful_returns_correct_values(): void
    {
        $response = new PaymentResponse(
            successful: true,
            transactionId: $this->makeTransactionId(),
            status: PaymentStatus::Captured,
            providerReference: 'ch_test_001',
            amount: $this->makeMoney(),
            rawResponse: ['provider' => 'data'],
            message: 'Payment successful',
        );

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('txn_001', $response->getTransactionId()->toString());
        $this->assertSame(PaymentStatus::Captured, $response->getStatus());
        $this->assertSame('ch_test_001', $response->getProviderReference());
        $this->assertSame(1000, $response->getAmount()->amount);
        $this->assertSame(['provider' => 'data'], $response->getRawResponse());
        $this->assertSame('Payment successful', $response->getMessage());
        $this->assertFalse($response->requiresAction());
    }

    /** @test */
    public function test_payment_response_failed_returns_false(): void
    {
        $response = new PaymentResponse(
            false, $this->makeTransactionId(), PaymentStatus::Failed,
            '', $this->makeMoney(), [], 'Card declined',
        );

        $this->assertFalse($response->isSuccessful());
        $this->assertSame(PaymentStatus::Failed, $response->getStatus());
    }

    /** @test */
    public function test_payment_response_requires_action(): void
    {
        $response = new PaymentResponse(
            true, $this->makeTransactionId(), PaymentStatus::RequiresAction,
            '', $this->makeMoney(), [], '3DS required',
        );

        $this->assertTrue($response->requiresAction());
    }

    /** @test */
    public function test_payment_response_json_excludes_raw_response(): void
    {
        $response = new PaymentResponse(
            true, $this->makeTransactionId(), PaymentStatus::Captured,
            'ref_001', $this->makeMoney(), ['internal' => 'secret'], 'OK',
        );

        $decoded = json_decode(json_encode($response), true);

        $this->assertArrayNotHasKey('raw_response', $decoded);
        $this->assertSame('txn_001', $decoded['transaction_id']);
        $this->assertSame('captured', $decoded['status']);
        $this->assertSame('ref_001', $decoded['provider_reference']);
    }

    /** @test */
    public function test_payment_response_implements_contract(): void
    {
        $response = new PaymentResponse(
            true, $this->makeTransactionId(), PaymentStatus::Captured,
            '', $this->makeMoney(), [], 'OK',
        );

        $this->assertInstanceOf(
            \Mifatoyeh\LaravelPaymentFramework\Contracts\Responses\PaymentResponseContract::class,
            $response,
        );
    }

    // =========================================================================
    // RefundResponse
    // =========================================================================

    /** @test */
    public function test_refund_response_constructs_correctly(): void
    {
        $response = new RefundResponse(
            successful: true,
            refundId: 're_001',
            amount: $this->makeMoney(500),
            status: PaymentStatus::Refunded,
            message: 'Refunded',
            rawResponse: [],
        );

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('re_001', $response->getRefundId());
        $this->assertSame(500, $response->getAmount()->amount);
        $this->assertSame(PaymentStatus::Refunded, $response->getStatus());
        $this->assertFalse($response->isPartial());
    }

    /** @test */
    public function test_refund_response_partial_flag(): void
    {
        $response = new RefundResponse(
            true, 're_partial', $this->makeMoney(300),
            PaymentStatus::PartiallyRefunded, 'Partial refund', [],
        );

        $this->assertTrue($response->isPartial());
    }

    /** @test */
    public function test_refund_response_json_serializes(): void
    {
        $response = new RefundResponse(
            true, 're_json', $this->makeMoney(400),
            PaymentStatus::Refunded, 'OK', [],
        );

        $decoded = json_decode(json_encode($response), true);

        $this->assertSame('re_json', $decoded['refund_id']);
        $this->assertSame('refunded', $decoded['status']);
    }

    // =========================================================================
    // CaptureResponse
    // =========================================================================

    /** @test */
    public function test_capture_response_constructs_correctly(): void
    {
        $response = new CaptureResponse(
            successful: true,
            captureId: 'cap_001',
            amount: $this->makeMoney(800),
            status: PaymentStatus::Captured,
            message: 'Captured',
            rawResponse: [],
        );

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('cap_001', $response->getCaptureId());
        $this->assertSame(800, $response->getAmount()->amount);
    }

    /** @test */
    public function test_capture_response_json_serializes(): void
    {
        $response = new CaptureResponse(
            true, 'cap_json', $this->makeMoney(), PaymentStatus::Captured, 'OK', [],
        );

        $decoded = json_decode(json_encode($response), true);

        $this->assertSame('cap_json', $decoded['capture_id']);
        $this->assertSame('captured', $decoded['status']);
    }

    // =========================================================================
    // VoidResponse
    // =========================================================================

    /** @test */
    public function test_void_response_constructs_correctly(): void
    {
        $response = new VoidResponse(
            successful: true,
            transactionId: $this->makeTransactionId('txn_void'),
            status: PaymentStatus::Voided,
            message: 'Voided',
            rawResponse: [],
        );

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('txn_void', $response->getTransactionId()->toString());
        $this->assertSame(PaymentStatus::Voided, $response->getStatus());
    }

    /** @test */
    public function test_void_response_json_serializes(): void
    {
        $response = new VoidResponse(
            true, $this->makeTransactionId('txn_v'), PaymentStatus::Voided, 'OK', [],
        );

        $decoded = json_decode(json_encode($response), true);

        $this->assertSame('txn_v', $decoded['transaction_id']);
        $this->assertSame('voided', $decoded['status']);
    }

    // =========================================================================
    // StatusResponse
    // =========================================================================

    /** @test */
    public function test_status_response_constructs_correctly(): void
    {
        $response = new StatusResponse(
            successful: true,
            transactionId: $this->makeTransactionId('txn_status'),
            status: PaymentStatus::Captured,
            message: 'Active',
            rawResponse: [],
        );

        $this->assertTrue($response->isSuccessful());
        $this->assertSame(PaymentStatus::Captured, $response->getStatus());
        $this->assertFalse($response->isTerminal());
    }

    /** @test */
    public function test_status_response_terminal_flag(): void
    {
        $response = new StatusResponse(
            true, $this->makeTransactionId(), PaymentStatus::Failed, 'Failed', [],
        );

        $this->assertTrue($response->isTerminal());
    }

    /** @test */
    public function test_status_response_json_serializes(): void
    {
        $response = new StatusResponse(
            true, $this->makeTransactionId('txn_s'), PaymentStatus::Pending, 'Pending', [],
        );

        $decoded = json_decode(json_encode($response), true);

        $this->assertSame('txn_s', $decoded['transaction_id']);
        $this->assertSame('pending', $decoded['status']);
    }

    // =========================================================================
    // VerificationResponse
    // =========================================================================

    /** @test */
    public function test_verification_response_trusted_when_both_true(): void
    {
        $response = new VerificationResponse(
            successful: true,
            verified: true,
            transactionId: $this->makeTransactionId(),
            message: 'Verified',
            rawResponse: [],
        );

        $this->assertTrue($response->isTrusted());
        $this->assertTrue($response->isVerified());
    }

    /** @test */
    public function test_verification_response_not_trusted_when_verified_false(): void
    {
        $response = new VerificationResponse(
            true, false, $this->makeTransactionId(), 'Tampered', [],
        );

        $this->assertFalse($response->isTrusted());
        $this->assertFalse($response->isVerified());
    }

    /** @test */
    public function test_verification_response_not_trusted_when_api_failed(): void
    {
        $response = new VerificationResponse(
            false, true, $this->makeTransactionId(), 'API error', [],
        );

        $this->assertFalse($response->isTrusted());
    }

    /** @test */
    public function test_verification_response_json_serializes(): void
    {
        $response = new VerificationResponse(
            true, true, $this->makeTransactionId('txn_ver'), 'OK', [],
        );

        $decoded = json_decode(json_encode($response), true);

        $this->assertTrue($decoded['verified']);
        $this->assertSame('txn_ver', $decoded['transaction_id']);
    }

    // =========================================================================
    // SubscriptionResponse
    // =========================================================================

    /** @test */
    public function test_subscription_response_active(): void
    {
        $next     = new DateTimeImmutable('+1 month');
        $response = new SubscriptionResponse(
            successful: true,
            subscriptionId: 'sub_001',
            status: PaymentStatus::Captured,
            nextBillingDate: $next,
            message: 'Created',
            rawResponse: [],
        );

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('sub_001', $response->getSubscriptionId());
        $this->assertFalse($response->isCancelled());
        $this->assertTrue($response->hasNextBillingDate());
        $this->assertSame($next, $response->getNextBillingDate());
    }

    /** @test */
    public function test_subscription_response_cancelled_flag(): void
    {
        $response = new SubscriptionResponse(
            true, 'sub_cancelled', PaymentStatus::Cancelled, null, 'Cancelled', [],
        );

        $this->assertTrue($response->isCancelled());
        $this->assertFalse($response->hasNextBillingDate());
    }

    /** @test */
    public function test_subscription_response_json_serializes(): void
    {
        $response = new SubscriptionResponse(
            true, 'sub_json', PaymentStatus::Captured, null, 'OK', [],
        );

        $decoded = json_decode(json_encode($response), true);

        $this->assertSame('sub_json', $decoded['subscription_id']);
        $this->assertSame('captured', $decoded['status']);
        $this->assertNull($decoded['next_billing_date']);
    }

    // =========================================================================
    // PaymentLinkResponse
    // =========================================================================

    /** @test */
    public function test_payment_link_response_constructs_correctly(): void
    {
        $expires  = new DateTimeImmutable('+2 hours');
        $response = new PaymentLinkResponse(
            successful: true,
            paymentUrl: 'https://pay.example.com/link/abc',
            linkId: 'link_001',
            expiresAt: $expires,
            message: 'Link created',
            rawResponse: [],
        );

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('https://pay.example.com/link/abc', $response->getPaymentUrl());
        $this->assertSame('link_001', $response->getLinkId());
        $this->assertTrue($response->hasExpiry());
        $this->assertFalse($response->isExpired());
    }

    /** @test */
    public function test_payment_link_response_expired_link(): void
    {
        $past     = new DateTimeImmutable('-1 hour');
        $response = new PaymentLinkResponse(
            true, 'https://expired.com', 'link_exp', $past, 'Expired', [],
        );

        $this->assertTrue($response->isExpired());
    }

    /** @test */
    public function test_payment_link_response_no_expiry(): void
    {
        $response = new PaymentLinkResponse(
            true, 'https://no-expiry.com', 'link_no_exp', null, 'OK', [],
        );

        $this->assertFalse($response->hasExpiry());
        $this->assertFalse($response->isExpired());
    }

    /** @test */
    public function test_payment_link_response_json_serializes(): void
    {
        $response = new PaymentLinkResponse(
            true, 'https://pay.example.com', 'lnk_json', null, 'OK', [],
        );

        $decoded = json_decode(json_encode($response), true);

        $this->assertSame('https://pay.example.com', $decoded['payment_url']);
        $this->assertSame('lnk_json', $decoded['link_id']);
        $this->assertNull($decoded['expires_at']);
    }

    // =========================================================================
    // WebhookResponse
    // =========================================================================

    /** @test */
    public function test_webhook_response_known_event(): void
    {
        $response = new WebhookResponse(
            successful: true,
            eventType: WebhookEventType::PaymentSucceeded,
            message: 'Processed',
            rawPayload: ['event' => 'payment.succeeded'],
        );

        $this->assertTrue($response->isSuccessful());
        $this->assertSame(WebhookEventType::PaymentSucceeded, $response->getEventType());
        $this->assertTrue($response->isKnownEvent());
        $this->assertTrue($response->isPaymentSuccess());
        $this->assertSame(['event' => 'payment.succeeded'], $response->getRawPayload());
    }

    /** @test */
    public function test_webhook_response_unknown_event(): void
    {
        $response = new WebhookResponse(
            true, WebhookEventType::Unknown, 'Unknown event', [],
        );

        $this->assertFalse($response->isKnownEvent());
        $this->assertFalse($response->isPaymentSuccess());
    }

    /** @test */
    public function test_webhook_response_json_excludes_raw_payload(): void
    {
        $response = new WebhookResponse(
            true,
            WebhookEventType::RefundSucceeded,
            'Refund webhook',
            ['internal_data' => 'large_payload'],
        );

        $decoded = json_decode(json_encode($response), true);

        $this->assertArrayNotHasKey('raw_payload', $decoded);
        $this->assertSame('refund.succeeded', $decoded['event_type']);
    }

    // =========================================================================
    // Immutability check
    // =========================================================================

    /** @test */
    public function test_all_responses_have_readonly_properties(): void
    {
        $responseClasses = [
            new PaymentResponse(true, $this->makeTransactionId(), PaymentStatus::Captured, '', $this->makeMoney(), [], 'OK'),
            new RefundResponse(true, 're_1', $this->makeMoney(), PaymentStatus::Refunded, 'OK', []),
            new CaptureResponse(true, 'cap_1', $this->makeMoney(), PaymentStatus::Captured, 'OK', []),
            new VoidResponse(true, $this->makeTransactionId(), PaymentStatus::Voided, 'OK', []),
            new StatusResponse(true, $this->makeTransactionId(), PaymentStatus::Pending, 'OK', []),
            new VerificationResponse(true, true, $this->makeTransactionId(), 'OK', []),
            new SubscriptionResponse(true, 'sub_1', PaymentStatus::Captured, null, 'OK', []),
            new PaymentLinkResponse(true, 'https://example.com', 'lnk_1', null, 'OK', []),
            new WebhookResponse(true, WebhookEventType::PaymentSucceeded, 'OK', []),
        ];

        foreach ($responseClasses as $response) {
            $reflection = new \ReflectionClass($response);
            foreach ($reflection->getProperties() as $prop) {
                $this->assertTrue(
                    $prop->isReadOnly(),
                    sprintf(
                        'Property %s::$%s should be readonly.',
                        $reflection->getName(),
                        $prop->getName(),
                    ),
                );
            }
        }
    }
}
