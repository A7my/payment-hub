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
 * Unit tests for StripeDriver::refund().
 *
 * Unlike void()/capture(), refund() has a genuinely richer non-exception
 * outcome space: Stripe's Refund object can come back with status
 * succeeded, pending, requires_action, failed, or canceled, all on a 200
 * response (no exception) — this file specifically covers the `pending`
 * case, since a refund can be accepted by Stripe and only settle
 * asynchronously. There is still no CardException / soft-decline branch:
 * a refund is a money-movement operation, not a new card-network
 * authorisation, so genuine failures (e.g. already refunded, amount
 * exceeds refundable balance) surface as InvalidRequestException.
 *
 * Per the same explicit decision used for StripeDriverVoidTest.php:
 * RefundRecordingDispatcher and RefundFakeStripeHttpClient are duplicated
 * below (renamed to avoid the redeclare fatal) rather than reused — every
 * test file in this package is self-contained.
 *
 * All Stripe HTTP traffic is intercepted via the Stripe SDK's own
 * ClientInterface seam; no test here makes a real network call.
 */
final class StripeDriverRefundTest extends TestCase
{
    private RefundRecordingDispatcher $events;

    protected function setUp(): void
    {
        parent::setUp();

        $this->events = new RefundRecordingDispatcher();
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

    private function makeRequest(string $idempotencyKey = 'idem-refund-001'): RefundRequest
    {
        return new RefundRequest(
            transactionId: TransactionId::fromString('pi_to_refund_001'),
            amount: Money::ofMinor(1000, Currency::USD),
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
    // Successful refund
    // =========================================================================

    /** @test */
    public function test_successful_refund_returns_refunded_response(): void
    {
        ApiRequestor::setHttpClient(new RefundFakeStripeHttpClient([
            $this->stripeResponse(200, [
                'id'       => 're_to_refund_001',
                'object'   => 'refund',
                'status'   => 'succeeded',
                'amount'   => 1000,
                'currency' => 'usd',
            ]),
        ]));

        $response = $this->makeDriver()->refund($this->makeRequest());

        $this->assertInstanceOf(RefundResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertSame(PaymentStatus::Refunded, $response->getStatus());
        $this->assertSame('re_to_refund_001', $response->getRefundId());
        $this->assertSame(1000, $response->getAmount()->amount);
        $this->assertSame('Refund processed.', $response->getMessage());
        $this->assertFalse($response->isPartial());

        $this->assertCount(1, $this->events->dispatched);
        $this->assertInstanceOf(PaymentRefunded::class, $this->events->dispatched[0]);
        $this->assertSame($response, $this->events->dispatched[0]->response);
    }

    // =========================================================================
    // Pending refund (accepted by Stripe, not yet settled — not an exception)
    // =========================================================================

    /** @test */
    public function test_pending_refund_returns_pending_response_without_throwing(): void
    {
        ApiRequestor::setHttpClient(new RefundFakeStripeHttpClient([
            $this->stripeResponse(200, [
                'id'             => 're_pending_001',
                'object'         => 'refund',
                'status'         => 'pending',
                'amount'         => 1000,
                'currency'       => 'usd',
                'pending_reason' => 'processing',
            ]),
        ]));

        $response = $this->makeDriver()->refund($this->makeRequest());

        $this->assertFalse($response->isSuccessful());
        $this->assertSame(PaymentStatus::Pending, $response->getStatus());
        $this->assertSame('Refund pending: processing.', $response->getMessage());

        // Still dispatched — Stripe accepted and is processing the refund;
        // there is no distinct "refund pending" event to dispatch instead.
        $this->assertCount(1, $this->events->dispatched);
        $this->assertInstanceOf(PaymentRefunded::class, $this->events->dispatched[0]);
    }

    // =========================================================================
    // Failure (invalid request — not a card decline)
    // =========================================================================

    /** @test */
    public function test_refund_of_an_already_refunded_charge_throws_mapped_exception(): void
    {
        ApiRequestor::setHttpClient(new RefundFakeStripeHttpClient([
            $this->stripeResponse(400, [
                'error' => [
                    'type'    => 'invalid_request_error',
                    'code'    => 'charge_already_refunded',
                    'message' => 'Charge pi_to_refund_001 has already been refunded.',
                ],
            ]),
        ]));

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/already been refunded/');

        try {
            $this->makeDriver()->refund($this->makeRequest());
        } finally {
            $this->assertCount(0, $this->events->dispatched);
        }
    }

    // =========================================================================
    // Idempotency
    // =========================================================================

    /** @test */
    public function test_empty_idempotency_key_throws_before_any_stripe_call(): void
    {
        $client = new RefundFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'unused', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 1000, 'currency' => 'usd']),
        ]);
        ApiRequestor::setHttpClient($client);

        $driver  = $this->makeDriver();
        $request = $this->makeRequest('   '); // whitespace-only — passes the DTO check, fails the driver guard

        $this->expectException(IdempotencyException::class);

        try {
            $driver->refund($request);
        } finally {
            $this->assertSame(0, $client->callCount, 'Stripe must never be called when the idempotency key is invalid.');
            $this->assertCount(0, $this->events->dispatched, 'No lifecycle event should fire before idempotency validation passes.');
        }
    }

    /** @test */
    public function test_idempotency_key_is_forwarded_to_stripe_as_a_request_header(): void
    {
        $client = new RefundFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 're_idem_001', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 1000, 'currency' => 'usd']),
        ]);
        ApiRequestor::setHttpClient($client);

        $this->makeDriver()->refund($this->makeRequest('idem-refund-forwarded'));

        $this->assertSame(1, $client->callCount);
        $headers = implode("\n", $client->headersSeen[0]);
        $this->assertStringContainsString('idem-refund-forwarded', $headers);
    }

    // =========================================================================
    // Retry invocation
    // =========================================================================

    /** @test */
    public function test_refund_wraps_the_stripe_call_in_with_retry(): void
    {
        ApiRequestor::setHttpClient(new RefundFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 're_retry_001', 'object' => 'refund', 'status' => 'succeeded', 'amount' => 1000, 'currency' => 'usd']),
        ]));

        $retry = $this->createMock(RetryServiceContract::class);
        $retry->expects($this->once())
            ->method('execute')
            ->willReturnCallback(fn (callable $operation) => $operation());

        $response = $this->makeDriver($retry)->refund($this->makeRequest());

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('re_retry_001', $response->getRefundId());
    }
}

/**
 * Minimal event dispatcher test double that records every dispatched event
 * in call order, so tests can assert both event type and lifecycle ordering.
 *
 * Duplicated (and renamed) from StripeDriverChargeTest.php by explicit
 * decision — every test file in this package is self-contained.
 */
final class RefundRecordingDispatcher implements Dispatcher
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
final class RefundFakeStripeHttpClient implements ClientInterface
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
