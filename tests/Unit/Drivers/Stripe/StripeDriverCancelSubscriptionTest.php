<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Drivers\Stripe;

use Illuminate\Contracts\Events\Dispatcher;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Services\RetryServiceContract;
use Mifatoyeh\LaravelPaymentFramework\DTO\CancelSubscriptionRequest;
use Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeDriver;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\Events\SubscriptionCancelled;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\IdempotencyException;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\InvalidConfigurationException;
use Mifatoyeh\LaravelPaymentFramework\Logging\NullLogger;
use Mifatoyeh\LaravelPaymentFramework\Responses\SubscriptionResponse;
use Mifatoyeh\LaravelPaymentFramework\Services\RetryService;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * Unit tests for StripeDriver::cancelSubscription().
 *
 * Per the same explicit decision used for every previous driver-method test
 * file: CancelSubscriptionRecordingDispatcher and
 * CancelSubscriptionFakeStripeHttpClient are duplicated below (renamed to
 * avoid the redeclare fatal) rather than reused — every test file in this
 * package is self-contained.
 *
 * All Stripe HTTP traffic is intercepted via the Stripe SDK's own
 * ClientInterface seam; no test here makes a real network call.
 */
final class StripeDriverCancelSubscriptionTest extends TestCase
{
    private CancelSubscriptionRecordingDispatcher $events;

    protected function setUp(): void
    {
        parent::setUp();

        $this->events = new CancelSubscriptionRecordingDispatcher();
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
        string $idempotencyKey = 'idem-key-cancelsub-001',
        bool $cancelAtPeriodEnd = false,
        string $subscriptionId = 'sub_to_cancel_001',
    ): CancelSubscriptionRequest {
        return new CancelSubscriptionRequest(
            subscriptionId: TransactionId::fromString($subscriptionId),
            idempotencyKey: $idempotencyKey,
            cancelAtPeriodEnd: $cancelAtPeriodEnd,
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
    // Immediate cancellation
    // =========================================================================

    /** @test */
    public function test_immediate_cancel_reports_cancelled_and_dispatches_event(): void
    {
        $client = new CancelSubscriptionFakeStripeHttpClient([
            $this->stripeResponse(200, [
                'id'                   => 'sub_to_cancel_001',
                'object'               => 'subscription',
                'status'               => 'canceled',
                'cancel_at_period_end' => false,
            ]),
        ]);
        ApiRequestor::setHttpClient($client);

        $response = $this->makeDriver()->cancelSubscription($this->makeRequest());

        $this->assertInstanceOf(SubscriptionResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertSame(PaymentStatus::Cancelled, $response->getStatus());
        $this->assertTrue($response->isCancelled());
        $this->assertSame('sub_to_cancel_001', $response->getSubscriptionId());
        $this->assertSame('Subscription cancelled.', $response->getMessage());

        // Verified against the SDK: SubscriptionService::cancel() issues a
        // DELETE to the bare /v1/subscriptions/{id} path — the SAME path
        // update() uses (see the cancel-at-period-end test below); only the
        // HTTP method distinguishes the two calls, there is no /cancel
        // suffix the way PaymentIntent's cancel endpoint has one.
        $this->assertSame('delete', $client->methodsSent[0]);
        $this->assertStringContainsString('sub_to_cancel_001', $client->urlsSent[0]);

        $this->assertCount(1, $this->events->dispatched);
        $this->assertInstanceOf(SubscriptionCancelled::class, $this->events->dispatched[0]);
        $this->assertSame($response, $this->events->dispatched[0]->response);
    }

    // =========================================================================
    // Cancel at period end — genuinely different Stripe call and outcome
    // =========================================================================

    /** @test */
    public function test_cancel_at_period_end_does_not_report_cancelled_status(): void
    {
        // Verified against the SDK: cancel_at_period_end does not itself
        // change `status` — the subscription remains e.g. `active` until
        // the period actually ends. The response must reflect that reality,
        // not force `Cancelled`.
        $client = new CancelSubscriptionFakeStripeHttpClient([
            $this->stripeResponse(200, [
                'id'                   => 'sub_to_cancel_002',
                'object'               => 'subscription',
                'status'               => 'active',
                'cancel_at_period_end' => true,
            ]),
        ]);
        ApiRequestor::setHttpClient($client);

        $response = $this->makeDriver()->cancelSubscription(
            $this->makeRequest(cancelAtPeriodEnd: true, subscriptionId: 'sub_to_cancel_002'),
        );

        $this->assertTrue($response->isSuccessful());
        $this->assertSame(PaymentStatus::Captured, $response->getStatus());
        $this->assertFalse($response->isCancelled());
        $this->assertStringContainsString('will be cancelled at the end of the current billing period', $response->getMessage());

        // Verified against the SDK: SubscriptionService::update() issues a
        // POST to the same bare path cancel() uses — the HTTP method is
        // what distinguishes the two calls (see the immediate-cancel test).
        $this->assertSame('post', $client->methodsSent[0]);

        $this->assertCount(1, $this->events->dispatched);
        $this->assertInstanceOf(SubscriptionCancelled::class, $this->events->dispatched[0]);
    }

    // =========================================================================
    // Not found / unrecoverable
    // =========================================================================

    /** @test */
    public function test_cancel_of_an_unknown_subscription_throws_mapped_exception(): void
    {
        ApiRequestor::setHttpClient(new CancelSubscriptionFakeStripeHttpClient([
            $this->stripeResponse(404, [
                'error' => [
                    'type'    => 'invalid_request_error',
                    'code'    => 'resource_missing',
                    'message' => "No such subscription: 'sub_does_not_exist'",
                ],
            ]),
        ]));

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/No such subscription/');

        try {
            $this->makeDriver()->cancelSubscription($this->makeRequest(subscriptionId: 'sub_does_not_exist'));
        } finally {
            $this->assertCount(0, $this->events->dispatched);
        }
    }

    // =========================================================================
    // Idempotency
    // =========================================================================

    /** @test */
    public function test_cancel_with_whitespace_only_idempotency_key_throws_before_any_stripe_call(): void
    {
        $client = new CancelSubscriptionFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'sub_never', 'object' => 'subscription', 'status' => 'canceled']),
        ]);
        ApiRequestor::setHttpClient($client);

        $driver  = $this->makeDriver();
        $request = $this->makeRequest('   ');

        $this->expectException(IdempotencyException::class);

        try {
            $driver->cancelSubscription($request);
        } finally {
            $this->assertSame(0, $client->callCount);
            $this->assertCount(0, $this->events->dispatched);
        }
    }

    /** @test */
    public function test_cancel_forwards_the_idempotency_key_to_stripe_as_a_request_header(): void
    {
        $client = new CancelSubscriptionFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'sub_idem_001', 'object' => 'subscription', 'status' => 'canceled']),
        ]);
        ApiRequestor::setHttpClient($client);

        $this->makeDriver()->cancelSubscription($this->makeRequest('idem-key-cancel-forwarded'));

        $this->assertSame(1, $client->callCount);
        $headers = implode("\n", $client->headersSeen[0]);
        $this->assertStringContainsString('idem-key-cancel-forwarded', $headers);
    }

    // =========================================================================
    // Retry invocation
    // =========================================================================

    /** @test */
    public function test_cancel_wraps_the_stripe_call_in_with_retry(): void
    {
        ApiRequestor::setHttpClient(new CancelSubscriptionFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'sub_retry_001', 'object' => 'subscription', 'status' => 'canceled']),
        ]));

        $retry = $this->createMock(RetryServiceContract::class);
        $retry->expects($this->once())
            ->method('execute')
            ->willReturnCallback(fn (callable $operation) => $operation());

        $response = $this->makeDriver($retry)->cancelSubscription($this->makeRequest());

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
final class CancelSubscriptionRecordingDispatcher implements Dispatcher
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
final class CancelSubscriptionFakeStripeHttpClient implements ClientInterface
{
    public int $callCount = 0;

    /** @var array<int, array<int, string>> */
    public array $headersSeen = [];

    /** @var array<int, string> */
    public array $urlsSent = [];

    /** @var array<int, string> */
    public array $methodsSent = [];

    /** @param array<int, array{0: string, 1: int, 2: array<int, string>}> $responses */
    public function __construct(
        private readonly array $responses,
    ) {
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->headersSeen[$this->callCount]  = $headers;
        $this->urlsSent[$this->callCount]     = $absUrl;
        $this->methodsSent[$this->callCount]  = $method;

        $index = min($this->callCount, count($this->responses) - 1);
        $this->callCount++;

        return $this->responses[$index];
    }
}
