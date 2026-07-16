<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Drivers\Stripe;

use Illuminate\Contracts\Events\Dispatcher;
use InvalidArgumentException;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Services\RetryServiceContract;
use Mifatoyeh\LaravelPaymentFramework\DTO\CustomerData;
use Mifatoyeh\LaravelPaymentFramework\DTO\TokenChargeRequest;
use Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeDriver;
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\Events\TokenCharged;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\IdempotencyException;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\InvalidConfigurationException;
use Mifatoyeh\LaravelPaymentFramework\Logging\NullLogger;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentResponse;
use Mifatoyeh\LaravelPaymentFramework\Services\RetryService;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Token;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * Unit tests for StripeDriver::chargeToken().
 *
 * Per the same explicit decision used for every previous driver-method test
 * file: ChargeTokenRecordingDispatcher and ChargeTokenFakeStripeHttpClient
 * are duplicated below (renamed to avoid the redeclare fatal) rather than
 * reused — every test file in this package is self-contained.
 *
 * All Stripe HTTP traffic is intercepted via the Stripe SDK's own
 * ClientInterface seam; no test here makes a real network call.
 */
final class StripeDriverChargeTokenTest extends TestCase
{
    private ChargeTokenRecordingDispatcher $events;

    protected function setUp(): void
    {
        parent::setUp();

        $this->events = new ChargeTokenRecordingDispatcher();
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    private function makeDriver(?RetryServiceContract $retry = null): StripeDriver
    {
        return new StripeDriver(
            new NullLogger(),
            $this->events,
            $retry ?? new RetryService(1, 0, true),
            ['secret' => 'sk_test_dummy_key', 'webhook_secret' => 'whsec_dummy'],
        );
    }

    private function makeRequest(
        string $idempotencyKey = 'idem-key-chargetoken-001',
        ?string $providerCustomerReference = 'cus_saved_001',
    ): TokenChargeRequest {
        return new TokenChargeRequest(
            token: Token::fromString('pm_card_visa'),
            amount: Money::ofMinor(1500, Currency::USD),
            currency: Currency::USD,
            idempotencyKey: $idempotencyKey,
            customer: new CustomerData(name: 'Jane Doe', email: 'jane@example.com'),
            providerCustomerReference: $providerCustomerReference,
        );
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{0: string, 1: int, 2: array<int, string>}
     */
    private function stripeResponse(int $status, array $body): array
    {
        return [json_encode($body, JSON_THROW_ON_ERROR), $status, []];
    }

    // =========================================================================
    // Successful charge
    // =========================================================================

    /** @test */
    public function test_charge_token_creates_and_confirms_a_customer_scoped_payment_intent(): void
    {
        ApiRequestor::setHttpClient(new ChargeTokenFakeStripeHttpClient([
            $this->stripeResponse(200, [
                'id'            => 'pi_token_001',
                'object'        => 'payment_intent',
                'status'        => 'succeeded',
                'amount'        => 1500,
                'currency'      => 'usd',
                'latest_charge' => 'ch_token_001',
                'customer'      => 'cus_saved_001',
            ]),
        ]));

        $response = $this->makeDriver()->chargeToken($this->makeRequest());

        $this->assertInstanceOf(PaymentResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertSame(PaymentStatus::Captured, $response->getStatus());
        $this->assertSame('pi_token_001', $response->getTransactionId()->toString());
        $this->assertSame('ch_token_001', $response->getProviderReference());
        $this->assertSame(1500, $response->getAmount()->amount);

        $this->assertCount(1, $this->events->dispatched);
        $this->assertInstanceOf(TokenCharged::class, $this->events->dispatched[0]);
        $this->assertSame($response, $this->events->dispatched[0]->response);
    }

    // =========================================================================
    // Missing provider customer reference — framework-level guard
    // =========================================================================

    /** @test */
    public function test_charge_token_without_provider_customer_reference_throws_before_any_stripe_call(): void
    {
        $client = new ChargeTokenFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'pi_never', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 1500, 'currency' => 'usd']),
        ]);
        ApiRequestor::setHttpClient($client);

        $driver  = $this->makeDriver();
        $request = $this->makeRequest(providerCustomerReference: null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/providerCustomerReference/');

        try {
            $driver->chargeToken($request);
        } finally {
            $this->assertSame(0, $client->callCount, 'Stripe must never be called without a provider customer reference.');
            $this->assertCount(0, $this->events->dispatched);
        }
    }

    /** @test */
    public function test_charge_token_with_whitespace_only_provider_customer_reference_throws_before_any_stripe_call(): void
    {
        $client = new ChargeTokenFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'pi_never', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 1500, 'currency' => 'usd']),
        ]);
        ApiRequestor::setHttpClient($client);

        $driver  = $this->makeDriver();
        $request = $this->makeRequest(providerCustomerReference: '   ');

        $this->expectException(InvalidArgumentException::class);

        try {
            $driver->chargeToken($request);
        } finally {
            $this->assertSame(0, $client->callCount);
        }
    }

    // =========================================================================
    // Requires action
    // =========================================================================

    /** @test */
    public function test_charge_token_requiring_action_reports_requires_action_without_dispatching_token_charged(): void
    {
        ApiRequestor::setHttpClient(new ChargeTokenFakeStripeHttpClient([
            $this->stripeResponse(200, [
                'id' => 'pi_action_001', 'object' => 'payment_intent', 'status' => 'requires_action',
                'amount' => 1500, 'currency' => 'usd', 'customer' => 'cus_saved_001',
            ]),
        ]));

        $response = $this->makeDriver()->chargeToken($this->makeRequest());

        $this->assertFalse($response->isSuccessful());
        $this->assertSame(PaymentStatus::RequiresAction, $response->getStatus());
        $this->assertTrue($response->requiresAction());

        $this->assertCount(0, $this->events->dispatched);
    }

    // =========================================================================
    // Declined card — soft failure, not thrown
    // =========================================================================

    /** @test */
    public function test_charge_token_declined_returns_unsuccessful_response_without_throwing(): void
    {
        ApiRequestor::setHttpClient(new ChargeTokenFakeStripeHttpClient([
            $this->stripeResponse(402, [
                'error' => [
                    'type'            => 'card_error',
                    'code'            => 'card_declined',
                    'decline_code'    => 'generic_decline',
                    'message'         => 'Your card was declined.',
                    'payment_intent'  => [
                        'id'                 => 'pi_token_declined_001',
                        'object'             => 'payment_intent',
                        'status'             => 'requires_payment_method',
                        'amount'             => 1500,
                        'currency'           => 'usd',
                        'last_payment_error' => ['message' => 'Your card was declined.'],
                    ],
                ],
            ]),
        ]));

        $response = $this->makeDriver()->chargeToken($this->makeRequest());

        $this->assertInstanceOf(PaymentResponse::class, $response);
        $this->assertFalse($response->isSuccessful());
        $this->assertSame(PaymentStatus::Failed, $response->getStatus());
        $this->assertSame('pi_token_declined_001', $response->getTransactionId()->toString());
        $this->assertSame('Your card was declined.', $response->getMessage());

        $this->assertCount(0, $this->events->dispatched);
    }

    /** @test */
    public function test_charge_token_declined_without_payment_intent_in_error_body_falls_back_to_synthetic_payload(): void
    {
        ApiRequestor::setHttpClient(new ChargeTokenFakeStripeHttpClient([
            $this->stripeResponse(402, [
                'error' => [
                    'type'    => 'card_error',
                    'code'    => 'card_declined',
                    'message' => 'Insufficient funds.',
                ],
            ]),
        ]));

        $response = $this->makeDriver()->chargeToken($this->makeRequest('idem-key-chargetoken-fallback'));

        $this->assertFalse($response->isSuccessful());
        $this->assertSame('Insufficient funds.', $response->getMessage());
        $this->assertSame('declined_idem-key-chargetoken-fallback', $response->getTransactionId()->toString());
    }

    // =========================================================================
    // Not found / unrecoverable
    // =========================================================================

    /** @test */
    public function test_charge_token_against_a_mismatched_customer_throws_mapped_exception(): void
    {
        ApiRequestor::setHttpClient(new ChargeTokenFakeStripeHttpClient([
            $this->stripeResponse(400, [
                'error' => [
                    'type'    => 'invalid_request_error',
                    'code'    => 'resource_missing',
                    'message' => 'The payment method is not attached to the specified customer.',
                ],
            ]),
        ]));

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/not attached to the specified customer/');

        try {
            $this->makeDriver()->chargeToken($this->makeRequest());
        } finally {
            $this->assertCount(0, $this->events->dispatched);
        }
    }

    // =========================================================================
    // Idempotency
    // =========================================================================

    /** @test */
    public function test_charge_token_with_whitespace_only_idempotency_key_throws_before_any_stripe_call(): void
    {
        $client = new ChargeTokenFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'pi_never', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 1500, 'currency' => 'usd']),
        ]);
        ApiRequestor::setHttpClient($client);

        $driver  = $this->makeDriver();
        $request = $this->makeRequest('   ');

        $this->expectException(IdempotencyException::class);

        try {
            $driver->chargeToken($request);
        } finally {
            $this->assertSame(0, $client->callCount);
            $this->assertCount(0, $this->events->dispatched);
        }
    }

    /** @test */
    public function test_charge_token_forwards_the_idempotency_key_to_stripe_as_a_request_header(): void
    {
        $client = new ChargeTokenFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'pi_idem_001', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 1500, 'currency' => 'usd']),
        ]);
        ApiRequestor::setHttpClient($client);

        $this->makeDriver()->chargeToken($this->makeRequest('idem-key-token-forwarded'));

        $this->assertSame(1, $client->callCount);
        $headers = implode("\n", $client->headersSeen[0]);
        $this->assertStringContainsString('idem-key-token-forwarded', $headers);
    }

    // =========================================================================
    // Retry invocation
    // =========================================================================

    /** @test */
    public function test_charge_token_wraps_the_stripe_call_in_with_retry(): void
    {
        ApiRequestor::setHttpClient(new ChargeTokenFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'pi_retry_001', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 1500, 'currency' => 'usd']),
        ]));

        $retry = $this->createMock(RetryServiceContract::class);
        $retry->expects($this->once())
            ->method('execute')
            ->willReturnCallback(fn (callable $operation) => $operation());

        $response = $this->makeDriver($retry)->chargeToken($this->makeRequest());

        $this->assertTrue($response->isSuccessful());
    }
}

/**
 * Minimal event dispatcher test double that records every dispatched event
 * in call order, so tests can assert both event type and lifecycle ordering.
 *
 * Duplicated (and renamed) from StripeDriverChargeTest.php by explicit
 * decision — every test file in this package is self-contained.
 */
final class ChargeTokenRecordingDispatcher implements Dispatcher
{
    /** @var array<int, object> */
    public array $dispatched = [];

    public function listen($events, $listener = null)
    {
    }

    public function hasListeners($eventName)
    {
        return false;
    }

    public function subscribe($subscriber)
    {
    }

    public function until($event, $payload = [])
    {
    }

    public function dispatch($event, $payload = [], $halt = false)
    {
        $this->dispatched[] = $event;

        return null;
    }

    public function push($event, $payload = [])
    {
    }

    public function flush($event)
    {
    }

    public function forget($event)
    {
    }

    public function forgetPushed()
    {
    }
}

/**
 * Fake Stripe HTTP transport implementing the SDK's own {@see ClientInterface}
 * so no real network call is ever made. Returns queued [body, status, headers]
 * tuples in order; the last queued response repeats if more calls occur than
 * were queued.
 *
 * Duplicated (and renamed) from StripeDriverChargeTest.php by explicit
 * decision — every test file in this package is self-contained.
 */
final class ChargeTokenFakeStripeHttpClient implements ClientInterface
{
    public int $callCount = 0;

    /** @var array<int, array<int, string>> */
    public array $headersSeen = [];

    /** @param array<int, array{0: string, 1: int, 2: array<int, string>}> $responses */
    public function __construct(
        private readonly array $responses,
    ) {
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->headersSeen[$this->callCount] = $headers;

        $index = min($this->callCount, count($this->responses) - 1);
        $this->callCount++;

        return $this->responses[$index];
    }
}
