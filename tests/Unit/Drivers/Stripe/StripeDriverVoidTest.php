<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Drivers\Stripe;

use Illuminate\Contracts\Events\Dispatcher;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Services\RetryServiceContract;
use Mifatoyeh\LaravelPaymentFramework\DTO\VoidRequest;
use Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeDriver;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentVoided;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\IdempotencyException;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\InvalidConfigurationException;
use Mifatoyeh\LaravelPaymentFramework\Logging\NullLogger;
use Mifatoyeh\LaravelPaymentFramework\Responses\VoidResponse;
use Mifatoyeh\LaravelPaymentFramework\Services\RetryService;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * Unit tests for StripeDriver::void().
 *
 * void() has narrower failure modes than charge()/authorize(): Stripe's
 * PaymentIntent cancel endpoint never touches a card network, so there is
 * no CardException / soft-decline scenario to test here — an invalid-state
 * cancel attempt (e.g. already captured) surfaces as InvalidRequestException
 * and is simply thrown (mapped via StripeExceptionMapper), matching the
 * simpler "no soft failure branch" shape of void()'s implementation.
 *
 * Per an explicit decision for this file: VoidRecordingDispatcher and
 * VoidFakeStripeHttpClient are duplicated below rather than reused from
 * StripeDriverChargeTest.php / StripeDriverAuthorizeTest.php, even though
 * they are byte-for-byte identical — every test file in this package is
 * self-contained.
 *
 * All Stripe HTTP traffic is intercepted via the Stripe SDK's own
 * ClientInterface seam; no test here makes a real network call.
 */
final class StripeDriverVoidTest extends TestCase
{
    private VoidRecordingDispatcher $events;

    protected function setUp(): void
    {
        parent::setUp();

        $this->events = new VoidRecordingDispatcher();
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

    private function makeRequest(string $idempotencyKey = 'idem-void-001', string $transactionId = 'pi_to_void_001'): VoidRequest
    {
        return new VoidRequest(
            transactionId: TransactionId::fromString($transactionId),
            reason: 'Order cancelled',
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
    // Successful void
    // =========================================================================

    /** @test */
    public function test_successful_void_returns_voided_response(): void
    {
        ApiRequestor::setHttpClient(new VoidFakeStripeHttpClient([
            $this->stripeResponse(200, [
                'id'     => 'pi_to_void_001',
                'object' => 'payment_intent',
                'status' => 'canceled',
            ]),
        ]));

        $response = $this->makeDriver()->void($this->makeRequest());

        $this->assertInstanceOf(VoidResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertSame(PaymentStatus::Voided, $response->getStatus());
        $this->assertSame('pi_to_void_001', $response->getTransactionId()->toString());
        $this->assertSame('Payment voided.', $response->getMessage());

        $this->assertCount(1, $this->events->dispatched);
        $this->assertInstanceOf(PaymentVoided::class, $this->events->dispatched[0]);
        $this->assertSame($response, $this->events->dispatched[0]->response);
    }

    // =========================================================================
    // Failure (invalid state transition — not a card decline)
    // =========================================================================

    /** @test */
    public function test_void_of_a_non_cancellable_payment_intent_throws_mapped_exception(): void
    {
        ApiRequestor::setHttpClient(new VoidFakeStripeHttpClient([
            $this->stripeResponse(400, [
                'error' => [
                    'type'    => 'invalid_request_error',
                    'code'    => 'payment_intent_unexpected_state',
                    'message' => 'You cannot cancel this PaymentIntent because it has a status of succeeded.',
                ],
            ]),
        ]));

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/cannot cancel this PaymentIntent/');

        try {
            $this->makeDriver()->void($this->makeRequest());
        } finally {
            // No PaymentVoided event on failure — there is no soft-failure
            // response to dispatch it with, and no failure-event exists for void().
            $this->assertCount(0, $this->events->dispatched);
        }
    }

    // =========================================================================
    // Idempotency
    // =========================================================================

    /** @test */
    public function test_empty_idempotency_key_throws_before_any_stripe_call(): void
    {
        $client = new VoidFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'unused', 'object' => 'payment_intent', 'status' => 'canceled']),
        ]);
        ApiRequestor::setHttpClient($client);

        $driver  = $this->makeDriver();
        $request = $this->makeRequest('   '); // whitespace-only — passes the DTO check, fails the driver guard

        $this->expectException(IdempotencyException::class);

        try {
            $driver->void($request);
        } finally {
            $this->assertSame(0, $client->callCount, 'Stripe must never be called when the idempotency key is invalid.');
            $this->assertCount(0, $this->events->dispatched, 'No lifecycle event should fire before idempotency validation passes.');
        }
    }

    /** @test */
    public function test_idempotency_key_is_forwarded_to_stripe_as_a_request_header(): void
    {
        $client = new VoidFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'pi_void_idem_001', 'object' => 'payment_intent', 'status' => 'canceled']),
        ]);
        ApiRequestor::setHttpClient($client);

        $this->makeDriver()->void($this->makeRequest('idem-void-forwarded'));

        $this->assertSame(1, $client->callCount);
        $headers = implode("\n", $client->headersSeen[0]);
        $this->assertStringContainsString('idem-void-forwarded', $headers);
    }

    // =========================================================================
    // Retry invocation
    // =========================================================================

    /** @test */
    public function test_void_wraps_the_stripe_call_in_with_retry(): void
    {
        ApiRequestor::setHttpClient(new VoidFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'pi_void_retry_001', 'object' => 'payment_intent', 'status' => 'canceled']),
        ]));

        $retry = $this->createMock(RetryServiceContract::class);
        $retry->expects($this->once())
            ->method('execute')
            ->willReturnCallback(fn (callable $operation) => $operation());

        $response = $this->makeDriver($retry)->void($this->makeRequest());

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('pi_void_retry_001', $response->getTransactionId()->toString());
    }
}

/**
 * Minimal event dispatcher test double that records every dispatched event
 * in call order, so tests can assert both event type and lifecycle ordering.
 *
 * Duplicated from StripeDriverChargeTest.php by explicit decision — every
 * test file in this package is self-contained.
 */
final class VoidRecordingDispatcher implements Dispatcher
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
 * Duplicated from StripeDriverChargeTest.php by explicit decision — every
 * test file in this package is self-contained.
 */
final class VoidFakeStripeHttpClient implements ClientInterface
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
