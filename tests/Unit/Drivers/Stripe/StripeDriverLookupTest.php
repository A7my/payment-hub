<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Drivers\Stripe;

use Illuminate\Contracts\Events\Dispatcher;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Services\RetryServiceContract;
use Mifatoyeh\LaravelPaymentFramework\DTO\TransactionLookupRequest;
use Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeDriver;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\Events\TransactionLookuped;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\InvalidConfigurationException;
use Mifatoyeh\LaravelPaymentFramework\Logging\NullLogger;
use Mifatoyeh\LaravelPaymentFramework\Responses\StatusResponse;
use Mifatoyeh\LaravelPaymentFramework\Services\RetryService;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * Unit tests for StripeDriver::lookup().
 *
 * Read-only operation: NO idempotency check happens (confirmed —
 * TransactionLookupRequest carries no idempotency key at all, so there is
 * no idempotency-guard or idempotency-header-forwarding test here; adding
 * one would test something that cannot occur). DOES dispatch
 * TransactionLookuped on success, since that event exists specifically for
 * this operation (see StripeDriverVerifyTest.php's docblock for why
 * verify() dispatches nothing).
 *
 * Per the same explicit decision used for the previous driver-method test
 * files: LookupRecordingDispatcher and LookupFakeStripeHttpClient are
 * duplicated below (renamed to avoid the redeclare fatal) rather than
 * reused — every test file in this package is self-contained.
 *
 * All Stripe HTTP traffic is intercepted via the Stripe SDK's own
 * ClientInterface seam; no test here makes a real network call.
 */
final class StripeDriverLookupTest extends TestCase
{
    private LookupRecordingDispatcher $events;

    protected function setUp(): void
    {
        parent::setUp();

        $this->events = new LookupRecordingDispatcher();
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

    private function makeRequest(string $transactionId = 'pi_to_lookup_001'): TransactionLookupRequest
    {
        return new TransactionLookupRequest(
            transactionId: TransactionId::fromString($transactionId),
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
    // Found / succeeded
    // =========================================================================

    /** @test */
    public function test_lookup_of_a_succeeded_payment_intent_returns_captured_status(): void
    {
        ApiRequestor::setHttpClient(new LookupFakeStripeHttpClient([
            $this->stripeResponse(200, [
                'id'       => 'pi_to_lookup_001',
                'object'   => 'payment_intent',
                'status'   => 'succeeded',
                'amount'   => 1000,
                'currency' => 'usd',
            ]),
        ]));

        $response = $this->makeDriver()->lookup($this->makeRequest());

        $this->assertInstanceOf(StatusResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertSame(PaymentStatus::Captured, $response->getStatus());
        $this->assertSame('pi_to_lookup_001', $response->getTransactionId()->toString());
        $this->assertSame('Payment succeeded.', $response->getMessage());

        $this->assertCount(1, $this->events->dispatched);
        $this->assertInstanceOf(TransactionLookuped::class, $this->events->dispatched[0]);
        $this->assertSame($response, $this->events->dispatched[0]->response);
    }

    // =========================================================================
    // Found / pending (not yet a terminal outcome)
    // =========================================================================

    /** @test */
    public function test_lookup_of_a_processing_payment_intent_returns_pending_status(): void
    {
        ApiRequestor::setHttpClient(new LookupFakeStripeHttpClient([
            $this->stripeResponse(200, [
                'id'       => 'pi_to_lookup_002',
                'object'   => 'payment_intent',
                'status'   => 'processing',
                'amount'   => 1000,
                'currency' => 'usd',
            ]),
        ]));

        $response = $this->makeDriver()->lookup($this->makeRequest('pi_to_lookup_002'));

        // isSuccessful() reports the API call succeeded, not the payment
        // outcome — StatusResponse has no separate business-outcome flag,
        // matching its own documented "read-only report" contract.
        $this->assertTrue($response->isSuccessful());
        $this->assertSame(PaymentStatus::Pending, $response->getStatus());
        $this->assertFalse($response->isTerminal());
    }

    // =========================================================================
    // Found / failed
    // =========================================================================

    /** @test */
    public function test_lookup_of_a_failed_payment_intent_returns_failed_status(): void
    {
        ApiRequestor::setHttpClient(new LookupFakeStripeHttpClient([
            $this->stripeResponse(200, [
                'id'                 => 'pi_to_lookup_003',
                'object'             => 'payment_intent',
                'status'             => 'requires_payment_method',
                'amount'             => 1000,
                'currency'           => 'usd',
                'last_payment_error' => ['message' => 'Your card was declined.'],
            ]),
        ]));

        $response = $this->makeDriver()->lookup($this->makeRequest('pi_to_lookup_003'));

        $this->assertSame(PaymentStatus::Failed, $response->getStatus());
        $this->assertSame('Your card was declined.', $response->getMessage());
        $this->assertTrue($response->isTerminal());
    }

    // =========================================================================
    // Not found
    // =========================================================================

    /** @test */
    public function test_lookup_of_an_unknown_transaction_throws_mapped_exception(): void
    {
        ApiRequestor::setHttpClient(new LookupFakeStripeHttpClient([
            $this->stripeResponse(404, [
                'error' => [
                    'type'    => 'invalid_request_error',
                    'code'    => 'resource_missing',
                    'message' => "No such payment_intent: 'pi_does_not_exist'",
                ],
            ]),
        ]));

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/No such payment_intent/');

        try {
            $this->makeDriver()->lookup($this->makeRequest('pi_does_not_exist'));
        } finally {
            $this->assertCount(0, $this->events->dispatched);
        }
    }

    // =========================================================================
    // Retry invocation
    // =========================================================================

    /** @test */
    public function test_lookup_wraps_the_stripe_call_in_with_retry(): void
    {
        ApiRequestor::setHttpClient(new LookupFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'pi_lookup_retry_001', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 1000, 'currency' => 'usd']),
        ]));

        $retry = $this->createMock(RetryServiceContract::class);
        $retry->expects($this->once())
            ->method('execute')
            ->willReturnCallback(fn (callable $operation) => $operation());

        $response = $this->makeDriver($retry)->lookup($this->makeRequest('pi_lookup_retry_001'));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('pi_lookup_retry_001', $response->getTransactionId()->toString());
    }
}

/**
 * Minimal event dispatcher test double that records every dispatched event
 * in call order, so tests can assert both event type and lifecycle ordering.
 *
 * Duplicated (and renamed) from StripeDriverChargeTest.php by explicit
 * decision — every test file in this package is self-contained.
 */
final class LookupRecordingDispatcher implements Dispatcher
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
final class LookupFakeStripeHttpClient implements ClientInterface
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
