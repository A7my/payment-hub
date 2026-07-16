<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Drivers\Stripe;

use Illuminate\Contracts\Events\Dispatcher;
use InvalidArgumentException;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Services\RetryServiceContract;
use Mifatoyeh\LaravelPaymentFramework\DTO\CustomerData;
use Mifatoyeh\LaravelPaymentFramework\DTO\SubscriptionRequest;
use Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeDriver;
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\Events\SubscriptionCreated;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\IdempotencyException;
use Mifatoyeh\LaravelPaymentFramework\Logging\NullLogger;
use Mifatoyeh\LaravelPaymentFramework\Responses\SubscriptionResponse;
use Mifatoyeh\LaravelPaymentFramework\Services\RetryService;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Token;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * Unit tests for StripeDriver::createSubscription().
 *
 * Per the same explicit decision used for every previous driver-method test
 * file: CreateSubscriptionRecordingDispatcher and
 * CreateSubscriptionFakeStripeHttpClient are duplicated below (renamed to
 * avoid the redeclare fatal) rather than reused — every test file in this
 * package is self-contained.
 *
 * All Stripe HTTP traffic is intercepted via the Stripe SDK's own
 * ClientInterface seam; no test here makes a real network call.
 */
final class StripeDriverCreateSubscriptionTest extends TestCase
{
    private CreateSubscriptionRecordingDispatcher $events;

    protected function setUp(): void
    {
        parent::setUp();

        $this->events = new CreateSubscriptionRecordingDispatcher();
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
        string $idempotencyKey = 'idem-key-createsub-001',
        ?string $providerCustomerReference = 'cus_sub_001',
        ?string $planId = 'price_1N_basic',
        ?int $trialDays = null,
    ): SubscriptionRequest {
        return new SubscriptionRequest(
            amount: Money::ofMinor(2000, Currency::USD),
            currency: Currency::USD,
            interval: 'monthly',
            intervalCount: 1,
            trialDays: $trialDays,
            customer: new CustomerData(name: 'Jane Doe', email: 'jane@example.com'),
            planId: $planId,
            idempotencyKey: $idempotencyKey,
            token: Token::fromString('pm_card_visa'),
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
    // Successful creation (active)
    // =========================================================================

    /** @test */
    public function test_create_subscription_active_reports_captured_and_dispatches_created(): void
    {
        ApiRequestor::setHttpClient(new CreateSubscriptionFakeStripeHttpClient([
            $this->stripeResponse(200, [
                'id'                    => 'sub_001',
                'object'                => 'subscription',
                'status'                => 'active',
                'customer'              => 'cus_sub_001',
                'cancel_at_period_end'  => false,
                'items'                 => ['data' => [['current_period_end' => 1700000000]]],
            ]),
        ]));

        $response = $this->makeDriver()->createSubscription($this->makeRequest());

        $this->assertInstanceOf(SubscriptionResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertSame(PaymentStatus::Captured, $response->getStatus());
        $this->assertSame('sub_001', $response->getSubscriptionId());
        $this->assertTrue($response->hasNextBillingDate());
        $this->assertSame('Subscription is active.', $response->getMessage());

        $this->assertCount(1, $this->events->dispatched);
        $this->assertInstanceOf(SubscriptionCreated::class, $this->events->dispatched[0]);
        $this->assertSame($response, $this->events->dispatched[0]->response);
    }

    // =========================================================================
    // Trialing (first charge deferred)
    // =========================================================================

    /** @test */
    public function test_create_subscription_trialing_reports_pending_and_dispatches_created(): void
    {
        ApiRequestor::setHttpClient(new CreateSubscriptionFakeStripeHttpClient([
            $this->stripeResponse(200, [
                'id'       => 'sub_trial_001',
                'object'   => 'subscription',
                'status'   => 'trialing',
                'customer' => 'cus_sub_001',
            ]),
        ]));

        $response = $this->makeDriver()->createSubscription($this->makeRequest(trialDays: 14));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame(PaymentStatus::Pending, $response->getStatus());
        $this->assertSame('Subscription created; trial period in progress.', $response->getMessage());

        $this->assertCount(1, $this->events->dispatched);
        $this->assertInstanceOf(SubscriptionCreated::class, $this->events->dispatched[0]);
    }

    // =========================================================================
    // Incomplete / declined first charge
    // =========================================================================

    /** @test */
    public function test_create_subscription_incomplete_with_resolvable_decline_reports_failed_without_throwing(): void
    {
        ApiRequestor::setHttpClient(new CreateSubscriptionFakeStripeHttpClient([
            $this->stripeResponse(200, [
                'id'       => 'sub_incomplete_001',
                'object'   => 'subscription',
                'status'   => 'incomplete',
                'customer' => 'cus_sub_001',
                'latest_invoice' => [
                    'id'       => 'in_001',
                    'object'   => 'invoice',
                    'payments' => [
                        'data' => [
                            [
                                'payment' => [
                                    'type'           => 'payment_intent',
                                    'payment_intent' => [
                                        'id'                 => 'pi_incomplete_001',
                                        'status'             => 'requires_payment_method',
                                        'last_payment_error' => ['message' => 'Your card was declined.'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]));

        $response = $this->makeDriver()->createSubscription($this->makeRequest());

        $this->assertFalse($response->isSuccessful());
        $this->assertSame(PaymentStatus::Failed, $response->getStatus());
        $this->assertSame('Your card was declined.', $response->getMessage());

        // Not a "created" outcome — no event dispatched.
        $this->assertCount(0, $this->events->dispatched);
    }

    /** @test */
    public function test_create_subscription_incomplete_requiring_action_reports_requires_action(): void
    {
        ApiRequestor::setHttpClient(new CreateSubscriptionFakeStripeHttpClient([
            $this->stripeResponse(200, [
                'id'       => 'sub_incomplete_002',
                'object'   => 'subscription',
                'status'   => 'incomplete',
                'customer' => 'cus_sub_001',
                'latest_invoice' => [
                    'id'       => 'in_002',
                    'object'   => 'invoice',
                    'payments' => [
                        'data' => [
                            [
                                'payment' => [
                                    'type'           => 'payment_intent',
                                    'payment_intent' => [
                                        'id'     => 'pi_incomplete_002',
                                        'status' => 'requires_action',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]));

        $response = $this->makeDriver()->createSubscription($this->makeRequest());

        $this->assertFalse($response->isSuccessful());
        $this->assertSame(PaymentStatus::RequiresAction, $response->getStatus());
        $this->assertCount(0, $this->events->dispatched);
    }

    /** @test */
    public function test_create_subscription_incomplete_without_resolvable_expand_defaults_to_requires_action(): void
    {
        // The deep expand path may not resolve (unverified live) — this
        // must default to RequiresAction, not Failed, per the design
        // decision: the safer, more actionable signal when the cause is
        // genuinely unknown.
        ApiRequestor::setHttpClient(new CreateSubscriptionFakeStripeHttpClient([
            $this->stripeResponse(200, [
                'id'       => 'sub_incomplete_003',
                'object'   => 'subscription',
                'status'   => 'incomplete',
                'customer' => 'cus_sub_001',
                // latest_invoice omitted entirely / unresolved expand.
            ]),
        ]));

        $response = $this->makeDriver()->createSubscription($this->makeRequest());

        $this->assertSame(PaymentStatus::RequiresAction, $response->getStatus());
    }

    // =========================================================================
    // Framework-level guards
    // =========================================================================

    /** @test */
    public function test_create_subscription_without_provider_customer_reference_throws_before_any_stripe_call(): void
    {
        $client = new CreateSubscriptionFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'sub_never', 'object' => 'subscription', 'status' => 'active']),
        ]);
        ApiRequestor::setHttpClient($client);

        $driver  = $this->makeDriver();
        $request = $this->makeRequest(providerCustomerReference: null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/providerCustomerReference/');

        try {
            $driver->createSubscription($request);
        } finally {
            $this->assertSame(0, $client->callCount);
            $this->assertCount(0, $this->events->dispatched);
        }
    }

    /** @test */
    public function test_create_subscription_without_plan_id_throws_before_any_stripe_call(): void
    {
        $client = new CreateSubscriptionFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'sub_never', 'object' => 'subscription', 'status' => 'active']),
        ]);
        ApiRequestor::setHttpClient($client);

        $driver  = $this->makeDriver();
        $request = $this->makeRequest(planId: null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/planId/');

        try {
            $driver->createSubscription($request);
        } finally {
            $this->assertSame(0, $client->callCount);
        }
    }

    /** @test */
    public function test_create_subscription_with_blank_plan_id_throws_before_any_stripe_call(): void
    {
        $client = new CreateSubscriptionFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'sub_never', 'object' => 'subscription', 'status' => 'active']),
        ]);
        ApiRequestor::setHttpClient($client);

        $driver  = $this->makeDriver();
        $request = $this->makeRequest(planId: '');

        $this->expectException(InvalidArgumentException::class);

        try {
            $driver->createSubscription($request);
        } finally {
            $this->assertSame(0, $client->callCount);
        }
    }

    // =========================================================================
    // Idempotency
    // =========================================================================

    /** @test */
    public function test_create_subscription_with_whitespace_only_idempotency_key_throws_before_any_stripe_call(): void
    {
        $client = new CreateSubscriptionFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'sub_never', 'object' => 'subscription', 'status' => 'active']),
        ]);
        ApiRequestor::setHttpClient($client);

        $driver  = $this->makeDriver();
        $request = $this->makeRequest('   ');

        $this->expectException(IdempotencyException::class);

        try {
            $driver->createSubscription($request);
        } finally {
            $this->assertSame(0, $client->callCount);
            $this->assertCount(0, $this->events->dispatched);
        }
    }

    /** @test */
    public function test_create_subscription_forwards_the_idempotency_key_to_stripe_as_a_request_header(): void
    {
        $client = new CreateSubscriptionFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'sub_idem_001', 'object' => 'subscription', 'status' => 'active']),
        ]);
        ApiRequestor::setHttpClient($client);

        $this->makeDriver()->createSubscription($this->makeRequest('idem-key-sub-forwarded'));

        $this->assertSame(1, $client->callCount);
        $headers = implode("\n", $client->headersSeen[0]);
        $this->assertStringContainsString('idem-key-sub-forwarded', $headers);
    }

    // =========================================================================
    // Retry invocation
    // =========================================================================

    /** @test */
    public function test_create_subscription_wraps_the_stripe_call_in_with_retry(): void
    {
        ApiRequestor::setHttpClient(new CreateSubscriptionFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'sub_retry_001', 'object' => 'subscription', 'status' => 'active']),
        ]));

        $retry = $this->createMock(RetryServiceContract::class);
        $retry->expects($this->once())
            ->method('execute')
            ->willReturnCallback(fn (callable $operation) => $operation());

        $response = $this->makeDriver($retry)->createSubscription($this->makeRequest());

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
final class CreateSubscriptionRecordingDispatcher implements Dispatcher
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
final class CreateSubscriptionFakeStripeHttpClient implements ClientInterface
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
