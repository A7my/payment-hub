<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Drivers\Stripe;

use Illuminate\Contracts\Events\Dispatcher;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Services\RetryServiceContract;
use Mifatoyeh\LaravelPaymentFramework\DTO\CustomerData;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentRequest;
use Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeDriver;
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentFailed;
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentInitiated;
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentSucceeded;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\IdempotencyException;
use Mifatoyeh\LaravelPaymentFramework\Logging\NullLogger;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentResponse;
use Mifatoyeh\LaravelPaymentFramework\Services\RetryService;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * Unit tests for StripeDriver::charge().
 *
 * All Stripe HTTP traffic is intercepted via the Stripe SDK's own
 * {@see ClientInterface} seam, installed globally through
 * {@see ApiRequestor::setHttpClient()} — the same mechanism the Stripe SDK's
 * own test suite uses. No test in this file ever makes a real network call;
 * the fake client is reset after every test.
 */
final class StripeDriverChargeTest extends TestCase
{
    private RecordingDispatcher $events;

    protected function setUp(): void
    {
        parent::setUp();

        $this->events = new RecordingDispatcher();
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    private function makeDriver(?RetryServiceContract $retry = null): StripeDriver
    {
        // StripeDriver builds its own StripeClient/StripeMapper/StripeWebhookVerifier/
        // StripeExceptionMapper internally from this same $config array — they are
        // no longer constructor-injected collaborators.
        return new StripeDriver(
            new NullLogger(),
            $this->events,
            $retry ?? new RetryService(1, 0, true),
            ['secret' => 'sk_test_dummy_key', 'webhook_secret' => 'whsec_dummy'],
        );
    }

    private function makeRequest(string $idempotencyKey = 'idem-key-001'): PaymentRequest
    {
        return new PaymentRequest(
            amount: Money::ofMinor(1000, Currency::USD),
            currency: Currency::USD,
            idempotencyKey: $idempotencyKey,
            customer: new CustomerData('Jane Doe', 'jane@example.com'),
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
    // Successful payment
    // =========================================================================

    /** @test */
    public function test_successful_payment_returns_captured_payment_response(): void
    {
        ApiRequestor::setHttpClient(new FakeStripeHttpClient([
            $this->stripeResponse(200, [
                'id'            => 'pi_success_123',
                'object'        => 'payment_intent',
                'status'        => 'succeeded',
                'amount'        => 1000,
                'currency'      => 'usd',
                'client_secret' => 'pi_success_123_secret_abc',
                'latest_charge' => 'ch_success_123',
            ]),
        ]));

        $response = $this->makeDriver()->charge($this->makeRequest());

        $this->assertInstanceOf(PaymentResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertSame(PaymentStatus::Captured, $response->getStatus());
        $this->assertSame('pi_success_123', $response->getTransactionId()->toString());
        $this->assertSame('ch_success_123', $response->getProviderReference());
        $this->assertSame(1000, $response->getAmount()->amount);
        $this->assertSame(Currency::USD, $response->getAmount()->currency);
        $this->assertSame('pi_success_123_secret_abc', $response->getRawResponse()['client_secret']);
        $this->assertSame('Payment succeeded.', $response->getMessage());

        $this->assertCount(2, $this->events->dispatched);
        $this->assertInstanceOf(PaymentInitiated::class, $this->events->dispatched[0]);
        $this->assertInstanceOf(PaymentSucceeded::class, $this->events->dispatched[1]);
        $this->assertSame($response, $this->events->dispatched[1]->response);
    }

    // =========================================================================
    // Declined payment
    // =========================================================================

    /** @test */
    public function test_declined_payment_returns_unsuccessful_response_without_throwing(): void
    {
        ApiRequestor::setHttpClient(new FakeStripeHttpClient([
            $this->stripeResponse(402, [
                'error' => [
                    'type'            => 'card_error',
                    'code'            => 'card_declined',
                    'decline_code'    => 'generic_decline',
                    'message'         => 'Your card was declined.',
                    'payment_intent'  => [
                        'id'                 => 'pi_declined_456',
                        'object'             => 'payment_intent',
                        'status'             => 'requires_payment_method',
                        'amount'             => 1000,
                        'currency'           => 'usd',
                        'last_payment_error' => ['message' => 'Your card was declined.'],
                    ],
                ],
            ]),
        ]));

        $response = $this->makeDriver()->charge($this->makeRequest());

        $this->assertInstanceOf(PaymentResponse::class, $response);
        $this->assertFalse($response->isSuccessful());
        $this->assertSame(PaymentStatus::Failed, $response->getStatus());
        $this->assertSame('pi_declined_456', $response->getTransactionId()->toString());
        $this->assertSame('Your card was declined.', $response->getMessage());

        $this->assertCount(2, $this->events->dispatched);
        $this->assertInstanceOf(PaymentInitiated::class, $this->events->dispatched[0]);
        $this->assertInstanceOf(PaymentFailed::class, $this->events->dispatched[1]);
        $this->assertSame($response, $this->events->dispatched[1]->response);
        $this->assertNull($this->events->dispatched[1]->exception);
    }

    /** @test */
    public function test_declined_payment_without_payment_intent_in_error_body_falls_back_to_synthetic_payload(): void
    {
        // Some card_error responses omit `error.payment_intent` entirely.
        ApiRequestor::setHttpClient(new FakeStripeHttpClient([
            $this->stripeResponse(402, [
                'error' => [
                    'type'    => 'card_error',
                    'code'    => 'card_declined',
                    'message' => 'Insufficient funds.',
                ],
            ]),
        ]));

        $response = $this->makeDriver()->charge($this->makeRequest('idem-key-fallback'));

        $this->assertFalse($response->isSuccessful());
        $this->assertSame(PaymentStatus::Failed, $response->getStatus());
        $this->assertSame('Insufficient funds.', $response->getMessage());
        $this->assertSame('declined_idem-key-fallback', $response->getTransactionId()->toString());
    }

    // =========================================================================
    // Requires action (3-D Secure / OTP)
    // =========================================================================

    /** @test */
    public function test_requires_action_payment_returns_requires_action_response(): void
    {
        ApiRequestor::setHttpClient(new FakeStripeHttpClient([
            $this->stripeResponse(200, [
                'id'            => 'pi_action_789',
                'object'        => 'payment_intent',
                'status'        => 'requires_action',
                'amount'        => 1000,
                'currency'      => 'usd',
                'client_secret' => 'pi_action_789_secret_xyz',
                'next_action'   => ['type' => 'use_stripe_sdk'],
            ]),
        ]));

        $response = $this->makeDriver()->charge($this->makeRequest());

        $this->assertFalse($response->isSuccessful());
        $this->assertSame(PaymentStatus::RequiresAction, $response->getStatus());
        $this->assertTrue($response->requiresAction());
        $this->assertSame('pi_action_789_secret_xyz', $response->getRawResponse()['client_secret']);

        $this->assertCount(2, $this->events->dispatched);
        $this->assertInstanceOf(PaymentFailed::class, $this->events->dispatched[1]);
        $this->assertSame($response, $this->events->dispatched[1]->response);
    }

    // =========================================================================
    // Idempotency
    // =========================================================================

    /** @test */
    public function test_empty_idempotency_key_throws_before_any_stripe_call(): void
    {
        $client = new FakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'unused', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 1000, 'currency' => 'usd']),
        ]);
        ApiRequestor::setHttpClient($client);

        $driver  = $this->makeDriver();
        $request = $this->makeRequest('   '); // whitespace-only — passes the DTO check, fails the driver guard

        $this->expectException(IdempotencyException::class);

        try {
            $driver->charge($request);
        } finally {
            $this->assertSame(0, $client->callCount, 'Stripe must never be called when the idempotency key is invalid.');
            $this->assertCount(0, $this->events->dispatched, 'No lifecycle event should fire before idempotency validation passes.');
        }
    }

    /** @test */
    public function test_idempotency_key_is_forwarded_to_stripe_as_a_request_header(): void
    {
        $client = new FakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'pi_idem_001', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 1000, 'currency' => 'usd']),
        ]);
        ApiRequestor::setHttpClient($client);

        $this->makeDriver()->charge($this->makeRequest('idem-key-forwarded'));

        $this->assertSame(1, $client->callCount);
        $headers = implode("\n", $client->headersSeen[0]);
        $this->assertStringContainsString('idem-key-forwarded', $headers);
    }

    // =========================================================================
    // Retry invocation
    // =========================================================================

    /** @test */
    public function test_charge_wraps_the_stripe_call_in_with_retry(): void
    {
        ApiRequestor::setHttpClient(new FakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'pi_retry_001', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 1000, 'currency' => 'usd']),
        ]));

        $retry = $this->createMock(RetryServiceContract::class);
        $retry->expects($this->once())
            ->method('execute')
            ->willReturnCallback(fn (callable $operation) => $operation());

        $response = $this->makeDriver($retry)->charge($this->makeRequest());

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('pi_retry_001', $response->getTransactionId()->toString());
    }

    /** @test */
    public function test_retry_service_receives_the_exact_stripe_client_invocation(): void
    {
        // Proves withRetry() wraps the real StripeClient call (not a decoy) —
        // if the mock never invokes the callable, no Stripe call is made and
        // the assertion on callCount below fails.
        $client = new FakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'pi_retry_002', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 1000, 'currency' => 'usd']),
        ]);
        ApiRequestor::setHttpClient($client);

        $retry = $this->createMock(RetryServiceContract::class);
        $retry->expects($this->once())->method('execute')->willReturnCallback(fn (callable $operation) => $operation());

        $this->makeDriver($retry)->charge($this->makeRequest());

        $this->assertSame(1, $client->callCount);
    }
}

/**
 * Minimal event dispatcher test double that records every dispatched event
 * in call order, so tests can assert both event type and lifecycle ordering.
 */
final class RecordingDispatcher implements Dispatcher
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
 */
final class FakeStripeHttpClient implements ClientInterface
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
