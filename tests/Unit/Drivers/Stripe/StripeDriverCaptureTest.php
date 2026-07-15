<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Drivers\Stripe;

use Illuminate\Contracts\Events\Dispatcher;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Services\RetryServiceContract;
use Mifatoyeh\LaravelPaymentFramework\DTO\CaptureRequest;
use Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeDriver;
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentCaptured;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\IdempotencyException;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\InvalidConfigurationException;
use Mifatoyeh\LaravelPaymentFramework\Logging\NullLogger;
use Mifatoyeh\LaravelPaymentFramework\Responses\CaptureResponse;
use Mifatoyeh\LaravelPaymentFramework\Services\RetryService;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * Unit tests for StripeDriver::capture().
 *
 * capture() has the same narrow failure-mode shape as void(): capturing an
 * already-authorised PaymentIntent does not touch a card network, so there
 * is no CardException / soft-decline scenario to test here — an invalid-
 * state capture attempt (e.g. authorisation window expired, already
 * captured) surfaces as InvalidRequestException and is simply thrown
 * (mapped via StripeExceptionMapper).
 *
 * Per the same explicit decision used for StripeDriverVoidTest.php:
 * CaptureRecordingDispatcher and CaptureFakeStripeHttpClient are duplicated
 * below (renamed to avoid the redeclare fatal — this namespace already
 * declares RecordingDispatcher/FakeStripeHttpClient in
 * StripeDriverChargeTest.php and VoidRecordingDispatcher/
 * VoidFakeStripeHttpClient in StripeDriverVoidTest.php) rather than reused
 * — every test file in this package is self-contained.
 *
 * All Stripe HTTP traffic is intercepted via the Stripe SDK's own
 * ClientInterface seam; no test here makes a real network call.
 */
final class StripeDriverCaptureTest extends TestCase
{
    private CaptureRecordingDispatcher $events;

    protected function setUp(): void
    {
        parent::setUp();

        $this->events = new CaptureRecordingDispatcher();
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

    private function makeRequest(string $idempotencyKey = 'idem-capture-001', int $amount = 1000): CaptureRequest
    {
        return new CaptureRequest(
            transactionId: TransactionId::fromString('pi_to_capture_001'),
            amount: Money::ofMinor($amount, Currency::USD),
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
    // Successful capture
    // =========================================================================

    /** @test */
    public function test_successful_capture_returns_captured_response(): void
    {
        ApiRequestor::setHttpClient(new CaptureFakeStripeHttpClient([
            $this->stripeResponse(200, [
                'id'              => 'pi_to_capture_001',
                'object'          => 'payment_intent',
                'status'          => 'succeeded',
                'amount'          => 1000,
                'amount_received' => 1000,
                'currency'        => 'usd',
            ]),
        ]));

        $response = $this->makeDriver()->capture($this->makeRequest());

        $this->assertInstanceOf(CaptureResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertSame(PaymentStatus::Captured, $response->getStatus());
        $this->assertSame('pi_to_capture_001', $response->getCaptureId());
        $this->assertSame(1000, $response->getAmount()->amount);
        $this->assertSame('Payment captured.', $response->getMessage());

        $this->assertCount(1, $this->events->dispatched);
        $this->assertInstanceOf(PaymentCaptured::class, $this->events->dispatched[0]);
        $this->assertSame($response, $this->events->dispatched[0]->response);
    }

    /** @test */
    public function test_partial_capture_returns_amount_actually_received(): void
    {
        ApiRequestor::setHttpClient(new CaptureFakeStripeHttpClient([
            $this->stripeResponse(200, [
                'id'              => 'pi_to_capture_001',
                'object'          => 'payment_intent',
                'status'          => 'succeeded',
                'amount'          => 1000,
                'amount_received' => 400,
                'currency'        => 'usd',
            ]),
        ]));

        $response = $this->makeDriver()->capture($this->makeRequest(amount: 400));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame(400, $response->getAmount()->amount);
    }

    // =========================================================================
    // Failure (invalid state transition — not a card decline)
    // =========================================================================

    /** @test */
    public function test_capture_of_a_non_capturable_payment_intent_throws_mapped_exception(): void
    {
        ApiRequestor::setHttpClient(new CaptureFakeStripeHttpClient([
            $this->stripeResponse(400, [
                'error' => [
                    'type'    => 'invalid_request_error',
                    'code'    => 'payment_intent_unexpected_state',
                    'message' => 'This PaymentIntent could not be captured because it has already been captured.',
                ],
            ]),
        ]));

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/already been captured/');

        try {
            $this->makeDriver()->capture($this->makeRequest());
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
        $client = new CaptureFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'unused', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 1000, 'amount_received' => 1000, 'currency' => 'usd']),
        ]);
        ApiRequestor::setHttpClient($client);

        $driver  = $this->makeDriver();
        $request = $this->makeRequest('   '); // whitespace-only — passes the DTO check, fails the driver guard

        $this->expectException(IdempotencyException::class);

        try {
            $driver->capture($request);
        } finally {
            $this->assertSame(0, $client->callCount, 'Stripe must never be called when the idempotency key is invalid.');
            $this->assertCount(0, $this->events->dispatched, 'No lifecycle event should fire before idempotency validation passes.');
        }
    }

    /** @test */
    public function test_idempotency_key_is_forwarded_to_stripe_as_a_request_header(): void
    {
        $client = new CaptureFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'pi_capture_idem_001', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 1000, 'amount_received' => 1000, 'currency' => 'usd']),
        ]);
        ApiRequestor::setHttpClient($client);

        $this->makeDriver()->capture($this->makeRequest('idem-capture-forwarded'));

        $this->assertSame(1, $client->callCount);
        $headers = implode("\n", $client->headersSeen[0]);
        $this->assertStringContainsString('idem-capture-forwarded', $headers);
    }

    // =========================================================================
    // Retry invocation
    // =========================================================================

    /** @test */
    public function test_capture_wraps_the_stripe_call_in_with_retry(): void
    {
        ApiRequestor::setHttpClient(new CaptureFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'pi_capture_retry_001', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 1000, 'amount_received' => 1000, 'currency' => 'usd']),
        ]));

        $retry = $this->createMock(RetryServiceContract::class);
        $retry->expects($this->once())
            ->method('execute')
            ->willReturnCallback(fn (callable $operation) => $operation());

        $response = $this->makeDriver($retry)->capture($this->makeRequest());

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('pi_capture_retry_001', $response->getCaptureId());
    }
}

/**
 * Minimal event dispatcher test double that records every dispatched event
 * in call order, so tests can assert both event type and lifecycle ordering.
 *
 * Duplicated (and renamed) from StripeDriverChargeTest.php by explicit
 * decision — every test file in this package is self-contained.
 */
final class CaptureRecordingDispatcher implements Dispatcher
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
final class CaptureFakeStripeHttpClient implements ClientInterface
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
