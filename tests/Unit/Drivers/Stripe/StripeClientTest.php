<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Drivers\Stripe;

use Mifatoyeh\LaravelPaymentFramework\DTO\CancelSubscriptionRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\CaptureRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\CustomerData;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentLinkRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\RefundRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\SaveCardRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\SubscriptionRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\TokenChargeRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\TransactionLookupRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\VoidRequest;
use Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeClient;
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\CustomerId;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Token;
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

    private function makeSaveCardRequest(array $metadata = []): SaveCardRequest
    {
        return new SaveCardRequest(
            token: Token::fromString('pm_card_visa'),
            customerId: CustomerId::fromString('host-customer-99'),
            idempotencyKey: 'idem-client-savecard-001',
            metadata: $metadata,
        );
    }

    private function makeTokenChargeRequest(?string $providerCustomerReference = 'cus_client_001'): TokenChargeRequest
    {
        return new TokenChargeRequest(
            token: Token::fromString('pm_card_visa'),
            amount: Money::ofMinor(1000, Currency::USD),
            currency: Currency::USD,
            idempotencyKey: 'idem-client-chargetoken-001',
            customer: new CustomerData('Jane Doe', 'jane@example.com'),
            providerCustomerReference: $providerCustomerReference,
        );
    }

    private function makeSubscriptionRequest(
        ?string $planId = 'price_1N_basic',
        ?int $trialDays = null,
    ): SubscriptionRequest {
        return new SubscriptionRequest(
            amount: Money::ofMinor(2000, Currency::USD),
            currency: Currency::USD,
            interval: 'monthly',
            intervalCount: 1,
            trialDays: $trialDays,
            customer: new CustomerData('Jane Doe', 'jane@example.com'),
            planId: $planId,
            idempotencyKey: 'idem-client-sub-001',
            token: Token::fromString('pm_card_visa'),
            providerCustomerReference: 'cus_client_sub_001',
        );
    }

    private function makeCancelSubscriptionRequest(
        bool $cancelAtPeriodEnd = false,
        ?string $reason = null,
    ): CancelSubscriptionRequest {
        return new CancelSubscriptionRequest(
            subscriptionId: TransactionId::fromString('sub_client_001'),
            idempotencyKey: 'idem-client-cancelsub-001',
            cancelAtPeriodEnd: $cancelAtPeriodEnd,
            reason: $reason,
        );
    }

    private function makePaymentLinkRequest(
        ?string $returnUrl = 'https://example.com/success',
        ?string $cancelUrl = 'https://example.com/cancel',
    ): PaymentLinkRequest {
        return new PaymentLinkRequest(
            amount: Money::ofMinor(10000, Currency::USD),
            currency: Currency::USD,
            description: 'Sandbox test payment',
            customer: new CustomerData('Mohamed Azmy', 'azmy@example.com'),
            returnUrl: $returnUrl,
            cancelUrl: $cancelUrl,
            expiresAt: null,
            idempotencyKey: 'idem-client-paylink-001',
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
    private function customerResponse(): array
    {
        return [
            json_encode([
                'id'     => 'cus_test_client_001',
                'object' => 'customer',
            ], JSON_THROW_ON_ERROR),
            200,
            [],
        ];
    }

    /**
     * @return array{0: string, 1: int, 2: array<int, string>}
     */
    private function setupIntentResponse(): array
    {
        return [
            json_encode([
                'id'             => 'seti_test_client_001',
                'object'         => 'setup_intent',
                'status'         => 'succeeded',
                'customer'       => 'cus_test_client_001',
                'payment_method' => 'pm_card_visa',
            ], JSON_THROW_ON_ERROR),
            200,
            [],
        ];
    }

    /**
     * @return array{0: string, 1: int, 2: array<int, string>}
     */
    private function subscriptionResponse(): array
    {
        return [
            json_encode([
                'id'                   => 'sub_test_client_001',
                'object'               => 'subscription',
                'status'               => 'active',
                'customer'             => 'cus_client_sub_001',
                'cancel_at_period_end' => false,
            ], JSON_THROW_ON_ERROR),
            200,
            [],
        ];
    }

    /**
     * @return array{0: string, 1: int, 2: array<int, string>}
     */
    private function cancelledSubscriptionResponse(): array
    {
        return [
            json_encode([
                'id'                   => 'sub_client_001',
                'object'               => 'subscription',
                'status'               => 'canceled',
                'cancel_at_period_end' => false,
            ], JSON_THROW_ON_ERROR),
            200,
            [],
        ];
    }

    /**
     * @return array{0: string, 1: int, 2: array<int, string>}
     */
    private function checkoutSessionResponse(): array
    {
        return [
            json_encode([
                'id'     => 'cs_test_client_001',
                'object' => 'checkout.session',
                'status' => 'open',
                'url'    => 'https://checkout.stripe.com/c/pay/cs_test_client_001',
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

    // =========================================================================
    // createCustomer()
    // =========================================================================

    /** @test */
    public function test_create_customer_sends_no_identity_fields(): void
    {
        // SaveCardRequest carries no name/email/phone at all (verified) — a
        // bare Customer is created with no identity params.
        $client = new CapturingHttpClient($this->customerResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->createCustomer($this->makeSaveCardRequest());

        $this->assertArrayNotHasKey('email', $client->paramsSent[0]);
        $this->assertArrayNotHasKey('name', $client->paramsSent[0]);
    }

    /** @test */
    public function test_create_customer_forwards_the_host_customer_id_in_metadata(): void
    {
        $client = new CapturingHttpClient($this->customerResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->createCustomer($this->makeSaveCardRequest());

        $this->assertSame('host-customer-99', $client->paramsSent[0]['metadata']['host_customer_id']);
    }

    /** @test */
    public function test_create_customer_merges_caller_metadata_alongside_host_customer_id(): void
    {
        $client = new CapturingHttpClient($this->customerResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->createCustomer(
            $this->makeSaveCardRequest(['campaign' => 'spring_promo']),
        );

        $this->assertSame('host-customer-99', $client->paramsSent[0]['metadata']['host_customer_id']);
        $this->assertSame('spring_promo', $client->paramsSent[0]['metadata']['campaign']);
    }

    /** @test */
    public function test_create_customer_uses_a_customer_suffixed_idempotency_key(): void
    {
        // Suffixed rather than the bare key: createSetupIntent() (a second,
        // distinct Stripe call for the same saveCard() operation) also needs
        // an idempotency key, and reusing the identical key string across
        // two different endpoints is avoided entirely.
        $client = new CapturingHttpClient($this->customerResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->createCustomer($this->makeSaveCardRequest());

        $headers = implode("\n", $client->headersSeen[0]);
        $this->assertStringContainsString('idem-client-savecard-001:customer', $headers);
    }

    /** @test */
    public function test_create_customer_returns_the_raw_decoded_payload(): void
    {
        $client = new CapturingHttpClient($this->customerResponse());
        ApiRequestor::setHttpClient($client);

        $raw = (new StripeClient(['secret' => 'sk_test_dummy']))->createCustomer($this->makeSaveCardRequest());

        $this->assertSame('cus_test_client_001', $raw['id']);
    }

    // =========================================================================
    // createSetupIntent()
    // =========================================================================

    /** @test */
    public function test_create_setup_intent_forwards_the_customer_and_payment_method(): void
    {
        $client = new CapturingHttpClient($this->setupIntentResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->createSetupIntent('cus_test_client_001', $this->makeSaveCardRequest());

        $this->assertSame('cus_test_client_001', $client->paramsSent[0]['customer']);
        $this->assertSame('pm_card_visa', $client->paramsSent[0]['payment_method']);
    }

    /** @test */
    public function test_create_setup_intent_confirms_immediately_for_off_session_usage(): void
    {
        $client = new CapturingHttpClient($this->setupIntentResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->createSetupIntent('cus_test_client_001', $this->makeSaveCardRequest());

        $this->assertSame('true', $client->paramsSent[0]['confirm']);
        $this->assertSame('off_session', $client->paramsSent[0]['usage']);
    }

    /** @test */
    public function test_create_setup_intent_restricts_payment_method_types_to_card(): void
    {
        // Fixed rather than left to Stripe's automatic detection, so
        // confirming synchronously here never requires a return_url.
        $client = new CapturingHttpClient($this->setupIntentResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->createSetupIntent('cus_test_client_001', $this->makeSaveCardRequest());

        $this->assertSame(['card'], $client->paramsSent[0]['payment_method_types']);
    }

    /** @test */
    public function test_create_setup_intent_returns_the_raw_decoded_payload(): void
    {
        $client = new CapturingHttpClient($this->setupIntentResponse());
        ApiRequestor::setHttpClient($client);

        $raw = (new StripeClient(['secret' => 'sk_test_dummy']))->createSetupIntent('cus_test_client_001', $this->makeSaveCardRequest());

        $this->assertSame('seti_test_client_001', $raw['id']);
        $this->assertSame('succeeded', $raw['status']);
    }

    /** @test */
    public function test_create_setup_intent_uses_a_setup_intent_suffixed_idempotency_key(): void
    {
        $client = new CapturingHttpClient($this->setupIntentResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->createSetupIntent('cus_test_client_001', $this->makeSaveCardRequest());

        $headers = implode("\n", $client->headersSeen[0]);
        $this->assertStringContainsString('idem-client-savecard-001:setup_intent', $headers);
    }

    // =========================================================================
    // createTokenCharge()
    // =========================================================================

    /** @test */
    public function test_create_token_charge_forwards_the_provider_customer_reference_as_customer(): void
    {
        $client = new CapturingHttpClient($this->successResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->createTokenCharge($this->makeTokenChargeRequest());

        $this->assertSame('cus_client_001', $client->paramsSent[0]['customer']);
        $this->assertSame('pm_card_visa', $client->paramsSent[0]['payment_method']);
    }

    /** @test */
    public function test_create_token_charge_confirms_immediately_off_session(): void
    {
        $client = new CapturingHttpClient($this->successResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->createTokenCharge($this->makeTokenChargeRequest());

        $this->assertSame('true', $client->paramsSent[0]['confirm']);
        $this->assertSame('true', $client->paramsSent[0]['off_session']);
    }

    /** @test */
    public function test_create_token_charge_forwards_amount_and_currency(): void
    {
        $client = new CapturingHttpClient($this->successResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->createTokenCharge($this->makeTokenChargeRequest());

        $this->assertSame(1000, $client->paramsSent[0]['amount']);
        $this->assertSame('usd', $client->paramsSent[0]['currency']);
    }

    /** @test */
    public function test_create_token_charge_omits_customer_when_provider_customer_reference_is_null(): void
    {
        // StripeClient itself performs no validation (thin wrapper, no
        // business logic) — the framework-level guard rejecting a missing
        // reference lives in StripeDriver::chargeToken(), not here.
        $client = new CapturingHttpClient($this->successResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->createTokenCharge(
            $this->makeTokenChargeRequest(providerCustomerReference: null),
        );

        $this->assertArrayNotHasKey('customer', $client->paramsSent[0]);
    }

    /** @test */
    public function test_create_token_charge_returns_the_raw_decoded_payload(): void
    {
        $client = new CapturingHttpClient($this->successResponse());
        ApiRequestor::setHttpClient($client);

        $raw = (new StripeClient(['secret' => 'sk_test_dummy']))->createTokenCharge($this->makeTokenChargeRequest());

        $this->assertSame('pi_options_test', $raw['id']);
        $this->assertSame('succeeded', $raw['status']);
    }

    /** @test */
    public function test_create_token_charge_uses_the_bare_idempotency_key(): void
    {
        // Unlike createCustomer()/createSetupIntent(), this is the only
        // Stripe call chargeToken() makes — no suffix needed.
        $client = new CapturingHttpClient($this->successResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->createTokenCharge($this->makeTokenChargeRequest());

        $headers = implode("\n", $client->headersSeen[0]);
        $this->assertStringContainsString('idem-client-chargetoken-001', $headers);
        $this->assertStringNotContainsString('idem-client-chargetoken-001:', $headers);
    }

    // =========================================================================
    // createSubscription()
    // =========================================================================

    /** @test */
    public function test_create_subscription_forwards_customer_and_price_item(): void
    {
        $client = new CapturingHttpClient($this->subscriptionResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->createSubscription($this->makeSubscriptionRequest());

        $this->assertSame('cus_client_sub_001', $client->paramsSent[0]['customer']);
        $this->assertSame('price_1N_basic', $client->paramsSent[0]['items'][0]['price']);
    }

    /** @test */
    public function test_create_subscription_forwards_default_payment_method_when_token_present(): void
    {
        $client = new CapturingHttpClient($this->subscriptionResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->createSubscription($this->makeSubscriptionRequest());

        $this->assertSame('pm_card_visa', $client->paramsSent[0]['default_payment_method']);
    }

    /** @test */
    public function test_create_subscription_omits_trial_period_days_when_no_trial_configured(): void
    {
        $client = new CapturingHttpClient($this->subscriptionResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->createSubscription($this->makeSubscriptionRequest());

        $this->assertArrayNotHasKey('trial_period_days', $client->paramsSent[0]);
    }

    /** @test */
    public function test_create_subscription_forwards_trial_period_days_when_configured(): void
    {
        $client = new CapturingHttpClient($this->subscriptionResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->createSubscription($this->makeSubscriptionRequest(trialDays: 14));

        $this->assertSame(14, $client->paramsSent[0]['trial_period_days']);
    }

    /** @test */
    public function test_create_subscription_always_expands_the_first_invoice_payment_intent(): void
    {
        // See StripeMapper::toSubscriptionResponse()'s docblock: this is
        // needed to disambiguate the `incomplete` status, and has not been
        // verified against a live Stripe call.
        $client = new CapturingHttpClient($this->subscriptionResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->createSubscription($this->makeSubscriptionRequest());

        $this->assertSame(
            ['latest_invoice.payments.data.payment.payment_intent'],
            $client->paramsSent[0]['expand'],
        );
    }

    /** @test */
    public function test_create_subscription_returns_the_raw_decoded_payload(): void
    {
        $client = new CapturingHttpClient($this->subscriptionResponse());
        ApiRequestor::setHttpClient($client);

        $raw = (new StripeClient(['secret' => 'sk_test_dummy']))->createSubscription($this->makeSubscriptionRequest());

        $this->assertSame('sub_test_client_001', $raw['id']);
        $this->assertSame('active', $raw['status']);
    }

    // =========================================================================
    // cancelSubscriptionImmediately()
    // =========================================================================

    /** @test */
    public function test_cancel_subscription_immediately_targets_the_correct_subscription_via_delete(): void
    {
        $client = new CapturingHttpClient($this->cancelledSubscriptionResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->cancelSubscriptionImmediately($this->makeCancelSubscriptionRequest());

        $this->assertStringContainsString('sub_client_001', $client->urlsSent[0]);
    }

    /** @test */
    public function test_cancel_subscription_immediately_forwards_invoice_now_and_prorate(): void
    {
        $client = new CapturingHttpClient($this->cancelledSubscriptionResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->cancelSubscriptionImmediately($this->makeCancelSubscriptionRequest());

        // Booleans arrive as 'true'/'false' strings — the Stripe SDK's own
        // ApiRequestor::_encodeObjects() rewrites them before the HTTP layer.
        $this->assertSame('false', $client->paramsSent[0]['invoice_now']);
        $this->assertSame('true', $client->paramsSent[0]['prorate']);
    }

    /** @test */
    public function test_cancel_subscription_immediately_forwards_reason_as_cancellation_details_comment(): void
    {
        // Unlike cancelPaymentIntent()/createRefund(), this DTO's $reason IS
        // forwarded — see CancelSubscriptionRequest's own docblock for why.
        $client = new CapturingHttpClient($this->cancelledSubscriptionResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->cancelSubscriptionImmediately(
            $this->makeCancelSubscriptionRequest(reason: 'Customer requested cancellation'),
        );

        $this->assertSame(
            'Customer requested cancellation',
            $client->paramsSent[0]['cancellation_details']['comment'],
        );
    }

    /** @test */
    public function test_cancel_subscription_immediately_returns_the_raw_decoded_payload(): void
    {
        $client = new CapturingHttpClient($this->cancelledSubscriptionResponse());
        ApiRequestor::setHttpClient($client);

        $raw = (new StripeClient(['secret' => 'sk_test_dummy']))->cancelSubscriptionImmediately($this->makeCancelSubscriptionRequest());

        $this->assertSame('canceled', $raw['status']);
    }

    // =========================================================================
    // scheduleSubscriptionCancellation()
    // =========================================================================

    /** @test */
    public function test_schedule_subscription_cancellation_forwards_cancel_at_period_end(): void
    {
        $client = new CapturingHttpClient($this->subscriptionResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->scheduleSubscriptionCancellation(
            $this->makeCancelSubscriptionRequest(cancelAtPeriodEnd: true),
        );

        $this->assertSame('true', $client->paramsSent[0]['cancel_at_period_end']);
    }

    /** @test */
    public function test_schedule_subscription_cancellation_does_not_forward_invoice_now_or_prorate(): void
    {
        // Verified against the SDK: neither is a valid update() param —
        // only meaningful for the immediate-cancel path.
        $client = new CapturingHttpClient($this->subscriptionResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->scheduleSubscriptionCancellation(
            $this->makeCancelSubscriptionRequest(cancelAtPeriodEnd: true),
        );

        $this->assertArrayNotHasKey('invoice_now', $client->paramsSent[0]);
        $this->assertArrayNotHasKey('prorate', $client->paramsSent[0]);
    }

    /** @test */
    public function test_schedule_subscription_cancellation_returns_the_raw_decoded_payload(): void
    {
        $client = new CapturingHttpClient($this->subscriptionResponse());
        ApiRequestor::setHttpClient($client);

        $raw = (new StripeClient(['secret' => 'sk_test_dummy']))->scheduleSubscriptionCancellation(
            $this->makeCancelSubscriptionRequest(cancelAtPeriodEnd: true),
        );

        $this->assertSame('sub_test_client_001', $raw['id']);
    }

    // =========================================================================
    // createCheckoutSession()
    // =========================================================================

    /** @test */
    public function test_create_checkout_session_forwards_success_and_cancel_urls(): void
    {
        $client = new CapturingHttpClient($this->checkoutSessionResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->createCheckoutSession($this->makePaymentLinkRequest());

        // success_url gets Stripe's {CHECKOUT_SESSION_ID} placeholder appended
        // (see StripeClient::withSessionIdPlaceholder()) so a caller can
        // identify which session a customer returned from — verified
        // against Stripe's own documented success_url convention.
        $this->assertSame(
            'https://example.com/success?session_id={CHECKOUT_SESSION_ID}',
            $client->paramsSent[0]['success_url'],
        );
        $this->assertSame('https://example.com/cancel', $client->paramsSent[0]['cancel_url']);
    }

    /** @test */
    public function test_create_checkout_session_does_not_duplicate_an_existing_session_id_param(): void
    {
        $client = new CapturingHttpClient($this->checkoutSessionResponse());
        ApiRequestor::setHttpClient($client);

        $request = $this->makePaymentLinkRequest(returnUrl: 'https://example.com/success?session_id={CHECKOUT_SESSION_ID}');
        (new StripeClient(['secret' => 'sk_test_dummy']))->createCheckoutSession($request);

        $this->assertSame(
            'https://example.com/success?session_id={CHECKOUT_SESSION_ID}',
            $client->paramsSent[0]['success_url'],
        );
    }

    /** @test */
    public function test_create_checkout_session_omits_cancel_url_when_absent(): void
    {
        $client = new CapturingHttpClient($this->checkoutSessionResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->createCheckoutSession(
            $this->makePaymentLinkRequest(cancelUrl: null),
        );

        $this->assertArrayNotHasKey('cancel_url', $client->paramsSent[0]);
    }

    /** @test */
    public function test_create_checkout_session_always_uses_payment_mode(): void
    {
        $client = new CapturingHttpClient($this->checkoutSessionResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->createCheckoutSession($this->makePaymentLinkRequest());

        $this->assertSame('payment', $client->paramsSent[0]['mode']);
    }

    /** @test */
    public function test_create_checkout_session_builds_an_inline_line_item_with_no_pre_existing_price_or_product(): void
    {
        $client = new CapturingHttpClient($this->checkoutSessionResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->createCheckoutSession($this->makePaymentLinkRequest());

        $lineItem = $client->paramsSent[0]['line_items'][0];
        $this->assertSame(10000, $lineItem['price_data']['unit_amount']);
        $this->assertSame('usd', $lineItem['price_data']['currency']);
        $this->assertSame('Sandbox test payment', $lineItem['price_data']['product_data']['name']);
        $this->assertArrayNotHasKey('price', $lineItem);
    }

    /** @test */
    public function test_create_checkout_session_forwards_customer_email_without_creating_a_customer(): void
    {
        $client = new CapturingHttpClient($this->checkoutSessionResponse());
        ApiRequestor::setHttpClient($client);

        (new StripeClient(['secret' => 'sk_test_dummy']))->createCheckoutSession($this->makePaymentLinkRequest());

        $this->assertSame('azmy@example.com', $client->paramsSent[0]['customer_email']);
        $this->assertArrayNotHasKey('customer', $client->paramsSent[0]);
    }

    /** @test */
    public function test_create_checkout_session_returns_the_raw_decoded_payload(): void
    {
        $client = new CapturingHttpClient($this->checkoutSessionResponse());
        ApiRequestor::setHttpClient($client);

        $raw = (new StripeClient(['secret' => 'sk_test_dummy']))->createCheckoutSession($this->makePaymentLinkRequest());

        $this->assertSame('cs_test_client_001', $raw['id']);
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_test_client_001', $raw['url']);
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

    /** @var array<int, array<int, string>> */
    public array $headersSeen = [];

    /** @param array{0: string, 1: int, 2: array<int, string>} $response */
    public function __construct(
        private readonly array $response,
    ) {
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->paramsSent[]  = $params;
        $this->urlsSent[]    = $absUrl;
        $this->headersSeen[] = $headers;

        return $this->response;
    }
}
