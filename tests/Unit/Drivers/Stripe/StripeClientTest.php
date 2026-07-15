<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Drivers\Stripe;

use Mifatoyeh\LaravelPaymentFramework\DTO\CaptureRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\CustomerData;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\RefundRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\TransactionLookupRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\VoidRequest;
use Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeClient;
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * Unit tests for StripeClient::createPaymentIntent(), createAuthorization(),
 * cancelPaymentIntent(), capturePaymentIntent(), and createRefund(), focused
 * on the provider-options pipeline: PaymentRequest::$options must reach
 * Stripe verbatim, and framework-derived values (amount, currency, confirm,
 * capture_method, metadata) must always win over a conflicting option.
 *
 * Stripe HTTP traffic is intercepted via the SDK's own ClientInterface seam
 * (same pattern as StripeDriverChargeTest) — no real network call is made.
 *
 * NOTE: the Stripe SDK's own ApiRequestor::_encodeObjects() rewrites PHP
 * booleans to the strings 'true'/'false' before they reach the HTTP client
 * (recursively, including inside nested arrays). That happens below
 * StripeClient, in the SDK itself, so assertions below expect 'true'/'false'
 * strings for boolean values, not PHP booleans.
 */
final class StripeClientTest extends TestCase
{
    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    private function makeRequest(array $metadata = [], array $options = []): PaymentRequest
    {
        return new PaymentRequest(
            amount: Money::ofMinor(1000, Currency::USD),
            currency: Currency::USD,
            idempotencyKey: 'idem-client-001',
            customer: new CustomerData('Jane Doe', 'jane@example.com'),
            metadata: $metadata,
            options: $options,
        );
    }

    private function makeVoidRequest(string $reason = 'Order cancelled'): VoidRequest
    {
        return new VoidRequest(
            transactionId: TransactionId::fromString('pi_test_cancel_001'),
            reason: $reason,
            idempotencyKey: 'idem-void-client-001',
        );
    }

    private function makeCaptureRequest(int $amount = 1000): CaptureRequest
    {
        return new CaptureRequest(
            transactionId: TransactionId::fromString('pi_test_capture_001'),
            amount: Money::ofMinor($amount, Currency::USD),
            idempotencyKey: 'idem-capture-client-001',
        );
    }

    private function makeRefundRequest(int $amount = 1000, string $reason = 'Customer request'): RefundRequest
    {
        return new RefundRequest(
            transactionId: TransactionId::fromString('pi_test_refund_001'),
            amount: Money::ofMinor($amount, Currency::USD),
            reason: $reason,
            idempotencyKey: 'idem-refund-client-001',
        );
    }

    private function makeLookupRequest(string $transactionId = 'pi_test_lookup_001'): TransactionLookupRequest
    {
        return new TransactionLookupRequest(
            transactionId: TransactionId::fromString($transactionId),
        );
    }

    /**
     * @return array{0: string, 1: int, 2: array<int, string>}
     */
    private function successResponse(): array
    {
        return [
            json_encode([
                'id'       => 'pi_options_test',
                'object'   => 'payment_intent',
                'status'   => 'succeeded',
                'amount'   => 1000,
                'currency' => 'usd',
            ], JSON_THROW_ON_ERROR),
            200,
            [],
        ];
    }

    /**
     * @return array{0: string, 1: int, 2: array<int, string>}
     */
    private function cancelledResponse(): array
    {
        return [
            json_encode([
                'id'     => 'pi_test_cancel_001',
                'object' => 'payment_intent',
                'status' => 'canceled',
            ], JSON_THROW_ON_ERROR),
            200,
            [],
        ];
    }

    /**
     * @return array{0: string, 1: int, 2: array<int, string>}
     */
    private function capturedResponse(int $amountReceived = 1000): array
    {
        return [
            json_encode([
                'id'               => 'pi_test_capture_001',
                'object'           => 'payment_intent',
                'status'           => 'succeeded',
                'amount'           => 1000,
                'amount_received'  => $amountReceived,
                'currency'         => 'usd',
            ], JSON_THROW_ON_ERROR),
            200,
            [],
        ];
    }

    /**
     * @return array{0: string, 1: int, 2: array<int, string>}
     */
    private function refundedResponse(): array
    {
        return [
            json_encode([
                'id'       => 're_test_refund_001',
                'object'   => 'refund',
                'status'   => 'succeeded',
                'amount'   => 1000,
                'currency' => 'usd',
            ], JSON_THROW_ON_ERROR),
            200,
            [],
        ];
    }

    /**
     * @return array{0: string, 1: int, 2: array<int, string>}
     */
    private function retrievedResponse(): array
    {
        return [
            json_encode([
                'id'       => 'pi_test_lookup_001',
                'object'   => 'payment_intent',
                'status'   => 'succeeded',
                'amount'   => 1000,
                'currency' => 'usd',
            ], JSON_THROW_ON_ERROR),
            200,
            [],
        ];
    }

    // =========================================================================
    // Options are forwarded unchanged
    // =========================================================================

    /** @test */
    public function test_provider_options_are_forwarded_to_stripe(): void
    {
        $client = new CapturingHttpClient($this->successResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->createPaymentIntent(
            $this->makeRequest(options: [
                'automatic_payment_methods' => ['enabled' => true, 'allow_redirects' => 'never'],
                'capture_method'            => 'manual',
                'setup_future_usage'        => 'off_session',
            ]),
        );

        $sent = $client->paramsSent[0];

        $this->assertSame(['enabled' => 'true', 'allow_redirects' => 'never'], $sent['automatic_payment_methods']);
        $this->assertSame('manual', $sent['capture_method']);
        $this->assertSame('off_session', $sent['setup_future_usage']);
    }

    /** @test */
    public function test_no_options_produces_the_same_params_as_before_this_feature(): void
    {
        $client = new CapturingHttpClient($this->successResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->createPaymentIntent($this->makeRequest());

        $sent = $client->paramsSent[0];

        // confirm => true is encoded to the string 'true' by the Stripe SDK
        // itself, not by StripeClient.
        $this->assertSame(['amount' => 1000, 'currency' => 'usd', 'confirm' => 'true'], $sent);
    }

    // =========================================================================
    // Framework values always win on collision
    // =========================================================================

    /** @test */
    public function test_framework_values_win_over_conflicting_provider_options(): void
    {
        $client = new CapturingHttpClient($this->successResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->createPaymentIntent(
            $this->makeRequest(
                metadata: ['order_id' => 123],
                options: [
                    // Every one of these collides with a framework-derived
                    // value and must NOT be allowed to win.
                    'amount'   => 999999,
                    'currency' => 'eur',
                    'confirm'  => false,
                    'metadata' => ['hijacked' => true],
                ],
            ),
        );

        $sent = $client->paramsSent[0];

        $this->assertSame(1000, $sent['amount']);
        $this->assertSame('usd', $sent['currency']);
        $this->assertSame('true', $sent['confirm']);
        $this->assertSame(['order_id' => 123], $sent['metadata']);
    }

    /** @test */
    public function test_non_conflicting_options_survive_alongside_framework_values(): void
    {
        $client = new CapturingHttpClient($this->successResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->createPaymentIntent(
            $this->makeRequest(
                metadata: ['order_id' => 123],
                options: ['statement_descriptor' => 'ACME SHOP'],
            ),
        );

        $sent = $client->paramsSent[0];

        $this->assertSame(1000, $sent['amount']);
        $this->assertSame(['order_id' => 123], $sent['metadata']);
        $this->assertSame('ACME SHOP', $sent['statement_descriptor']);
    }

    // =========================================================================
    // createAuthorization() — capture_method: manual
    // =========================================================================

    /** @test */
    public function test_create_authorization_sets_capture_method_to_manual(): void
    {
        $client = new CapturingHttpClient($this->successResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->createAuthorization($this->makeRequest());

        $sent = $client->paramsSent[0];

        $this->assertSame(1000, $sent['amount']);
        $this->assertSame('usd', $sent['currency']);
        $this->assertSame('true', $sent['confirm']);
        $this->assertSame('manual', $sent['capture_method']);
    }

    /** @test */
    public function test_create_authorization_capture_method_cannot_be_overridden_by_options(): void
    {
        $client = new CapturingHttpClient($this->successResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->createAuthorization(
            $this->makeRequest(options: ['capture_method' => 'automatic']),
        );

        $sent = $client->paramsSent[0];

        $this->assertSame('manual', $sent['capture_method']);
    }

    /** @test */
    public function test_create_authorization_forwards_provider_options(): void
    {
        $client = new CapturingHttpClient($this->successResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->createAuthorization(
            $this->makeRequest(options: ['statement_descriptor' => 'ACME HOLD']),
        );

        $sent = $client->paramsSent[0];

        $this->assertSame('ACME HOLD', $sent['statement_descriptor']);
    }

    /** @test */
    public function test_create_payment_intent_never_sets_capture_method(): void
    {
        // Regression guard: createPaymentIntent() (charge) must remain
        // auto-capture — capture_method is exclusive to createAuthorization().
        $client = new CapturingHttpClient($this->successResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->createPaymentIntent($this->makeRequest());

        $this->assertArrayNotHasKey('capture_method', $client->paramsSent[0]);
    }

    // =========================================================================
    // cancelPaymentIntent()
    // =========================================================================

    /** @test */
    public function test_cancel_payment_intent_targets_the_correct_transaction_id(): void
    {
        $client = new CapturingHttpClient($this->cancelledResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->cancelPaymentIntent($this->makeVoidRequest());

        $this->assertCount(1, $client->urlsSent);
        $this->assertStringContainsString('pi_test_cancel_001', $client->urlsSent[0]);
        $this->assertStringEndsWith('/cancel', $client->urlsSent[0]);
    }

    /** @test */
    public function test_cancel_payment_intent_never_forwards_reason_as_a_stripe_param(): void
    {
        // VoidRequest::$reason is free-text; Stripe's cancellation_reason is a
        // fixed enum (duplicate|fraudulent|requested_by_customer|abandoned).
        // Coercing arbitrary text into it would be business logic StripeClient
        // must not contain, so no params are sent to the cancel endpoint at all.
        $client = new CapturingHttpClient($this->cancelledResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->cancelPaymentIntent(
            $this->makeVoidRequest('Customer requested cancellation'),
        );

        $this->assertSame([], $client->paramsSent[0]);
    }

    /** @test */
    public function test_cancel_payment_intent_returns_the_raw_decoded_payload(): void
    {
        $client = new CapturingHttpClient($this->cancelledResponse());
        ApiRequestor::setHttpClient($client);

        $raw = (new StripeClient(['secret' => 'sk_test_dummy']))->cancelPaymentIntent($this->makeVoidRequest());

        $this->assertSame('pi_test_cancel_001', $raw['id']);
        $this->assertSame('canceled', $raw['status']);
    }

    // =========================================================================
    // capturePaymentIntent()
    // =========================================================================

    /** @test */
    public function test_capture_payment_intent_targets_the_correct_transaction_id(): void
    {
        $client = new CapturingHttpClient($this->capturedResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->capturePaymentIntent($this->makeCaptureRequest());

        $this->assertCount(1, $client->urlsSent);
        $this->assertStringContainsString('pi_test_capture_001', $client->urlsSent[0]);
        $this->assertStringEndsWith('/capture', $client->urlsSent[0]);
    }

    /** @test */
    public function test_capture_payment_intent_forwards_amount_as_amount_to_capture(): void
    {
        $client = new CapturingHttpClient($this->capturedResponse(500));
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->capturePaymentIntent($this->makeCaptureRequest(500));

        $this->assertSame(500, $client->paramsSent[0]['amount_to_capture']);
    }

    /** @test */
    public function test_capture_payment_intent_supports_partial_capture_amount(): void
    {
        // A capture amount less than the originally-authorised amount must
        // reach Stripe unchanged — partial capture is a caller decision, not
        // something StripeClient decides or blocks.
        $client = new CapturingHttpClient($this->capturedResponse(250));
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->capturePaymentIntent($this->makeCaptureRequest(250));

        $this->assertSame(250, $client->paramsSent[0]['amount_to_capture']);
    }

    /** @test */
    public function test_capture_payment_intent_returns_the_raw_decoded_payload(): void
    {
        $client = new CapturingHttpClient($this->capturedResponse());
        ApiRequestor::setHttpClient($client);

        $raw = (new StripeClient(['secret' => 'sk_test_dummy']))->capturePaymentIntent($this->makeCaptureRequest());

        $this->assertSame('pi_test_capture_001', $raw['id']);
        $this->assertSame('succeeded', $raw['status']);
    }

    // =========================================================================
    // createRefund()
    // =========================================================================

    /** @test */
    public function test_create_refund_targets_the_correct_payment_intent(): void
    {
        $client = new CapturingHttpClient($this->refundedResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->createRefund($this->makeRefundRequest());

        $this->assertSame('pi_test_refund_001', $client->paramsSent[0]['payment_intent']);
    }

    /** @test */
    public function test_create_refund_forwards_amount(): void
    {
        $client = new CapturingHttpClient($this->refundedResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->createRefund($this->makeRefundRequest(750));

        $this->assertSame(750, $client->paramsSent[0]['amount']);
    }

    /** @test */
    public function test_create_refund_never_forwards_reason_as_a_stripe_param(): void
    {
        // RefundRequest::$reason is free-text; Stripe's reason is a fixed
        // enum (duplicate|fraudulent|requested_by_customer). Coercing
        // arbitrary text into it would be business logic StripeClient must
        // not contain — only payment_intent, amount, and expand are sent.
        $client = new CapturingHttpClient($this->refundedResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->createRefund(
            $this->makeRefundRequest(reason: 'Customer says item never arrived'),
        );

        $this->assertSame(
            ['payment_intent' => 'pi_test_refund_001', 'amount' => 1000, 'expand' => ['charge']],
            $client->paramsSent[0],
        );
    }

    /** @test */
    public function test_create_refund_always_expands_the_charge(): void
    {
        // Required for StripeMapper::toRefundResponse() to distinguish a
        // full refund from a partial one via Charge::amount_refunded — see
        // StripeMapperTest for the actual signal-derivation coverage.
        $client = new CapturingHttpClient($this->refundedResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->createRefund($this->makeRefundRequest());

        $this->assertSame(['charge'], $client->paramsSent[0]['expand']);
    }

    /** @test */
    public function test_sequential_refund_calls_with_different_amounts_do_not_cross_contaminate_params(): void
    {
        // refund() and partialRefund() are both driven by createRefund() —
        // there is no separate "partial" client method to fork on (see
        // StripeDriver's docblocks). This is the regression guard that two
        // calls in sequence (as refund() then partialRefund(), or vice
        // versa, would produce) stay fully independent: each call's amount
        // must reach Stripe as sent, with no leakage from the previous call.
        $client = new CapturingHttpClient($this->refundedResponse());
        ApiRequestor::setHttpClient($client);

        $stripeClient = new StripeClient(['secret' => 'sk_test_dummy']);
        $stripeClient->createRefund($this->makeRefundRequest(1000)); // e.g. refund()
        $stripeClient->createRefund($this->makeRefundRequest(250));  // e.g. partialRefund()

        $this->assertCount(2, $client->paramsSent);
        $this->assertSame(1000, $client->paramsSent[0]['amount']);
        $this->assertSame(250, $client->paramsSent[1]['amount']);
    }

    /** @test */
    public function test_create_refund_returns_the_raw_decoded_payload(): void
    {
        $client = new CapturingHttpClient($this->refundedResponse());
        ApiRequestor::setHttpClient($client);

        $raw = (new StripeClient(['secret' => 'sk_test_dummy']))->createRefund($this->makeRefundRequest());

        $this->assertSame('re_test_refund_001', $raw['id']);
        $this->assertSame('succeeded', $raw['status']);
    }

    // =========================================================================
    // retrievePaymentIntent()
    // =========================================================================

    /** @test */
    public function test_retrieve_payment_intent_targets_the_correct_transaction_id(): void
    {
        $client = new CapturingHttpClient($this->retrievedResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->retrievePaymentIntent($this->makeLookupRequest());

        $this->assertCount(1, $client->urlsSent);
        $this->assertStringContainsString('pi_test_lookup_001', $client->urlsSent[0]);
    }

    /** @test */
    public function test_retrieve_payment_intent_sends_no_expand_param(): void
    {
        // Unlike createRefund(), there is no nested object to expand for a
        // plain retrieve — the base PaymentIntent's `status` is always
        // populated (verified against the SDK).
        $client = new CapturingHttpClient($this->retrievedResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->retrievePaymentIntent($this->makeLookupRequest());

        $this->assertArrayNotHasKey('expand', $client->paramsSent[0]);
    }

    /** @test */
    public function test_retrieve_payment_intent_returns_the_raw_decoded_payload(): void
    {
        $client = new CapturingHttpClient($this->retrievedResponse());
        ApiRequestor::setHttpClient($client);

        $raw = (new StripeClient(['secret' => 'sk_test_dummy']))->retrievePaymentIntent($this->makeLookupRequest());

        $this->assertSame('pi_test_lookup_001', $raw['id']);
        $this->assertSame('succeeded', $raw['status']);
    }
}

/**
 * Fake Stripe HTTP transport that records the (SDK-encoded) $params array
 * for every request, implementing the SDK's own ClientInterface so no real
 * network call is ever made.
 */
final class CapturingHttpClient implements ClientInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $paramsSent = [];

    /** @var array<int, string> */
    public array $urlsSent = [];

    /** @param array{0: string, 1: int, 2: array<int, string>} $response */
    public function __construct(
        private readonly array $response,
    ) {
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->paramsSent[] = $params;
        $this->urlsSent[]   = $absUrl;

        return $this->response;
    }
}
