<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Drivers\Stripe;

use Illuminate\Contracts\Events\Dispatcher;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Services\RetryServiceContract;
use Mifatoyeh\LaravelPaymentFramework\DTO\RefundRequest;
use Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeDriver;
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentRefunded;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\IdempotencyException;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\InvalidConfigurationException;
use Mifatoyeh\LaravelPaymentFramework\Logging\NullLogger;
use Mifatoyeh\LaravelPaymentFramework\Responses\RefundResponse;
use Mifatoyeh\LaravelPaymentFramework\Services\RetryService;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * Unit tests for StripeDriver::partialRefund().
 *
 * Mirrors StripeDriverRefundTest.php's scenario coverage exactly, since
 * partialRefund() shares the identical StripeClient::createRefund() call
 * and StripeMapper::toRefundResponse() mapping as refund() — the only
 * difference is the log/operation-context label. The Refunded-vs-
 * PartiallyRefunded distinction itself is exercised end-to-end here (not
 * just at the mapper-unit level in StripeMapperTest.php) by returning a
 * response payload whose expanded Charge shows the amount fully exhausted,
 * proving a request amount smaller than the original charge can still
 * correctly resolve to PaymentStatus::PartiallyRefunded via the driver.
 *
 * Per the same explicit decision used for StripeDriverVoidTest.php/
 * StripeDriverCaptureTest.php/StripeDriverRefundTest.php:
 * PartialRefundRecordingDispatcher and PartialRefundFakeStripeHttpClient
 * are duplicated below (renamed to avoid the redeclare fatal) rather than
 * reused — every test file in this package is self-contained.
 *
 * All Stripe HTTP traffic is intercepted via the Stripe SDK's own
 * ClientInterface seam; no test here makes a real network call.
 */
final class StripeDriverPartialRefundTest extends TestCase
{
    private PartialRefundRecordingDispatcher $events;

    protected function setUp(): void
    {
        parent::setUp();

        $this->events = new PartialRefundRecordingDispatcher();
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

    private function makeRequest(string $idempotencyKey = 'idem-partial-refund-001', int $amount = 250): RefundRequest
    {
        return new RefundRequest(
            transactionId: TransactionId::fromString('pi_to_partial_refund_001'),
            amount: Money::ofMinor($amount, Currency::USD),
            reason: 'Customer request',
            idempotencyKey: $idempotencyKey,
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
    // Successful partial refund
    // =========================================================================

    /** @test */
    public function test_successful_partial_refund_returns_partially_refunded_response(): void
    {
        ApiRequestor::setHttpClient(new PartialRefundFakeStripeHttpClient([
            $this->stripeResponse(200, [
                'id'       => 're_partial_001',
                'object'   => 'refund',
                'status'   => 'succeeded',
                'amount'   => 250,
                'currency' => 'usd',
                'charge'   => [
                    'id'              => 'ch_partial_001',
                    'amount'          => 1000,
                    'amount_refunded' => 250, // less than the 1000 total — genuinely partial
                ],
            ]),
        ]));

        $response = $this->makeDriver()->partialRefund($this->makeRequest());

        $this->assertInstanceOf(RefundResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertSame(PaymentStatus::PartiallyRefunded, $response->getStatus());
        $this->assertTrue($response->isPartial());
        $this->assertSame('re_partial_001', $response->getRefundId());
        $this->assertSame(250, $response->getAmount()->amount);
        $this->assertSame('Partial refund processed.', $response->getMessage());

        $this->assertCount(1, $this->events->dispatched);
        $this->assertInstanceOf(PaymentRefunded::class, $this->events->dispatched[0]);
        $this->assertSame($response, $this->events->dispatched[0]->response);
    }

    /** @test */
    public function test_partial_refund_that_exhausts_the_full_charge_reports_fully_refunded(): void
    {
        // Calling partialRefund() does not force PartiallyRefunded — if the
        // response shows the charge is now fully exhausted (e.g. this was
        // the final one of several partial refunds), the driver reports
        // exactly what the mapper resolves, not what the method name implies.
        ApiRequestor::setHttpClient(new PartialRefundFakeStripeHttpClient([
            $this->stripeResponse(200, [
                'id'       => 're_partial_002',
                'object'   => 'refund',
                'status'   => 'succeeded',
                'amount'   => 400,
                'currency' => 'usd',
                'charge'   => [
                    'id'              => 'ch_partial_002',
                    'amount'          => 1000,
                    'amount_refunded' => 1000, // cumulative total now fully exhausted
                ],
            ]),
        ]));

        $response = $this->makeDriver()->partialRefund($this->makeRequest(amount: 400));

        $this->assertSame(PaymentStatus::Refunded, $response->getStatus());
        $this->assertFalse($response->isPartial());
    }

    // =========================================================================
    // Failure (amount exceeds remaining refundable balance — not a card decline)
    // =========================================================================

    /** @test */
    public function test_partial_refund_exceeding_remaining_balance_throws_mapped_exception(): void
    {
        ApiRequestor::setHttpClient(new PartialRefundFakeStripeHttpClient([
            $this->stripeResponse(400, [
                'error' => [
                    'type'    => 'invalid_request_error',
                    'code'    => 'amount_too_large',
                    'message' => 'Refund amount ($9.00) is greater than the unrefunded amount left on the charge ($2.50).',
                ],
            ]),
        ]));

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/greater than the unrefunded amount/');

        try {
            $this->makeDriver()->partialRefund($this->makeRequest(amount: 900));
        } finally {
            $this->assertCount(0, $this->events->dispatched);
        }
    }

    // =========================================================================
    // Pending partial refund (accepted by Stripe, not yet settled)
    // =========================================================================

    /** @test */
    public function test_pending_partial_refund_returns_pending_response_without_throwing(): void
    {
        ApiRequestor::setHttpClient(new PartialRefundFakeStripeHttpClient([
            $this->stripeResponse(200, [
                'id'             => 're_partial_pending_001',
                'object'         => 'refund',
                'status'         => 'pending',
                'amount'         => 250,
                'currency'       => 'usd',
                'pending_reason' => 'processing',
                'charge'         => [
                    'id'              => 'ch_partial_pending_001',
                    'amount'          => 1000,
                    'amount_refunded' => 0,
                ],
            ]),
        ]));

        $response = $this->makeDriver()->partialRefund($this->makeRequest());

        $this->assertFalse($response->isSuccessful());
        $this->assertSame(PaymentStatus::Pending, $response->getStatus());
        $this->assertSame('Refund pending: processing.', $response->getMessage());

        $this->assertCount(1, $this->events->dispatched);
        $this->assertInstanceOf(PaymentRefunded::class, $this->events->dispatched[0]);
    }

    // =========================================================================
    // Idempotency
    // =========================================================================

    /** @test */
    public function test_empty_idempotency_key_throws_before_any_stripe_call(): void
    {
        $client = new PartialRefundFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'unused', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 250, 'currency' => 'usd']),
        ]);
        ApiRequestor::setHttpClient($client);

        $driver  = $this->makeDriver();
        $request = $this->makeRequest('   '); // whitespace-only — passes the DTO check, fails the driver guard

        $this->expectException(IdempotencyException::class);

        try {
            $driver->partialRefund($request);
        } finally {
            $this->assertSame(0, $client->callCount, 'Stripe must never be called when the idempotency key is invalid.');
            $this->assertCount(0, $this->events->dispatched, 'No lifecycle event should fire before idempotency validation passes.');
        }
    }

    /** @test */
    public function test_idempotency_key_is_forwarded_to_stripe_as_a_request_header(): void
    {
        $client = new PartialRefundFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 're_partial_idem_001', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 250, 'currency' => 'usd']),
        ]);
        ApiRequestor::setHttpClient($client);

        $this->makeDriver()->partialRefund($this->makeRequest('idem-partial-refund-forwarded'));

        $this->assertSame(1, $client->callCount);
        $headers = implode("\n", $client->headersSeen[0]);
        $this->assertStringContainsString('idem-partial-refund-forwarded', $headers);
    }

    // =========================================================================
    // Retry invocation
    // =========================================================================

    /** @test */
    public function test_partial_refund_wraps_the_stripe_call_in_with_retry(): void
    {
        ApiRequestor::setHttpClient(new PartialRefundFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 're_partial_retry_001', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 250, 'currency' => 'usd']),
        ]));

        $retry = $this->createMock(RetryServiceContract::class);
        $retry->expects($this->once())
            ->method('execute')
            ->willReturnCallback(fn (callable $operation) => $operation());

        $response = $this->makeDriver($retry)->partialRefund($this->makeRequest());

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('re_partial_retry_001', $response->getRefundId());
    }
}

/**
 * Minimal event dispatcher test double that records every dispatched event
 * in call order, so tests can assert both event type and lifecycle ordering.
 *
 * Duplicated (and renamed) from StripeDriverChargeTest.php by explicit
 * decision — every test file in this package is self-contained.
 */
final class PartialRefundRecordingDispatcher implements Dispatcher
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
final class PartialRefundFakeStripeHttpClient implements ClientInterface
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
