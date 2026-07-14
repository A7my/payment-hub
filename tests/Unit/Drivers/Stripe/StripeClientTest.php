<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Drivers\Stripe;

use Mifatoyeh\LaravelPaymentFramework\DTO\CustomerData;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentRequest;
use Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeClient;
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * Unit tests for StripeClient::createPaymentIntent(), focused on the
 * provider-options pipeline: PaymentRequest::$options must reach Stripe
 * verbatim, and framework-derived values (amount, currency, confirm,
 * metadata) must always win over a conflicting option.
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

    /** @param array{0: string, 1: int, 2: array<int, string>} $response */
    public function __construct(
        private readonly array $response,
    ) {
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->paramsSent[] = $params;

        return $this->response;
    }
}
