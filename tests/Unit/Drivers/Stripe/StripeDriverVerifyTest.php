<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Drivers\Stripe;

use Illuminate\Contracts\Events\Dispatcher;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Services\RetryServiceContract;
use Mifatoyeh\LaravelPaymentFramework\DTO\TransactionLookupRequest;
use Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeDriver;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\InvalidConfigurationException;
use Mifatoyeh\LaravelPaymentFramework\Logging\NullLogger;
use Mifatoyeh\LaravelPaymentFramework\Responses\VerificationResponse;
use Mifatoyeh\LaravelPaymentFramework\Services\RetryService;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * Unit tests for StripeDriver::verify().
 *
 * Kept as a SEPARATE file from StripeDriverLookupTest.php rather than
 * combined, even though both share the identical underlying
 * StripeClient::retrievePaymentIntent() call: the two driver methods are
 * NOT near-identical once you look past that shared plumbing — verify()
 * returns a different response type (VerificationResponse, asserting
 * isVerified()/isTrusted() booleans, not a PaymentStatus), and dispatches
 * NO event at all, whereas lookup() dispatches TransactionLookuped. Every
 * other implemented method pair in this package (e.g. refund() vs.
 * partialRefund(), which share even more code — the exact same client AND
 * mapper calls) still gets its own test file, so consistency favours two
 * files here too.
 *
 * Read-only operation: NO idempotency check happens (confirmed —
 * TransactionLookupRequest carries no idempotency key at all), so there is
 * no idempotency-guard or idempotency-header-forwarding test here.
 *
 * Per the same explicit decision used for the previous driver-method test
 * files: VerifyRecordingDispatcher and VerifyFakeStripeHttpClient are
 * duplicated below (renamed to avoid the redeclare fatal) rather than
 * reused — every test file in this package is self-contained.
 *
 * All Stripe HTTP traffic is intercepted via the Stripe SDK's own
 * ClientInterface seam; no test here makes a real network call.
 */
final class StripeDriverVerifyTest extends TestCase
{
    private VerifyRecordingDispatcher $events;

    protected function setUp(): void
    {
        parent::setUp();

        $this->events = new VerifyRecordingDispatcher();
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

    private function makeRequest(string $transactionId = 'pi_to_verify_001'): TransactionLookupRequest
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
    // Found / genuinely successful → verified and trusted
    // =========================================================================

    /** @test */
    public function test_verify_of_a_succeeded_payment_intent_reports_verified_and_trusted(): void
    {
        ApiRequestor::setHttpClient(new VerifyFakeStripeHttpClient([
            $this->stripeResponse(200, [
                'id'       => 'pi_to_verify_001',
                'object'   => 'payment_intent',
                'status'   => 'succeeded',
                'amount'   => 1000,
                'currency' => 'usd',
            ]),
        ]));

        $response = $this->makeDriver()->verify($this->makeRequest());

        $this->assertInstanceOf(VerificationResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertTrue($response->isVerified());
        $this->assertTrue($response->isTrusted());
        $this->assertSame('pi_to_verify_001', $response->getTransactionId()->toString());
        $this->assertSame('Transaction verified as authentic.', $response->getMessage());

        // No event exists for verify() — nothing should be dispatched.
        $this->assertCount(0, $this->events->dispatched);
    }

    /** @test */
    public function test_verify_of_an_authorized_but_uncaptured_payment_intent_reports_verified(): void
    {
        // Authorized (requires_capture) still counts as "genuinely
        // successful" per PaymentStatus::isSuccessful() — the transaction
        // is real and authentic even though funds are not yet captured.
        ApiRequestor::setHttpClient(new VerifyFakeStripeHttpClient([
            $this->stripeResponse(200, [
                'id'       => 'pi_to_verify_002',
                'object'   => 'payment_intent',
                'status'   => 'requires_capture',
                'amount'   => 1000,
                'currency' => 'usd',
            ]),
        ]));

        $response = $this->makeDriver()->verify($this->makeRequest('pi_to_verify_002'));

        $this->assertTrue($response->isVerified());
    }

    // =========================================================================
    // Found / not genuinely successful → not verified, not trusted
    // =========================================================================

    /** @test */
    public function test_verify_of_a_declined_payment_intent_reports_not_verified_without_throwing(): void
    {
        ApiRequestor::setHttpClient(new VerifyFakeStripeHttpClient([
            $this->stripeResponse(200, [
                'id'                 => 'pi_to_verify_003',
                'object'             => 'payment_intent',
                'status'             => 'requires_payment_method',
                'amount'             => 1000,
                'currency'           => 'usd',
                'last_payment_error' => ['message' => 'Your card was declined.'],
            ]),
        ]));

        $response = $this->makeDriver()->verify($this->makeRequest('pi_to_verify_003'));

        // The API call itself succeeded — retrieval worked — but the
        // transaction is not one we can trust to fulfil an order on.
        $this->assertTrue($response->isSuccessful());
        $this->assertFalse($response->isVerified());
        $this->assertFalse($response->isTrusted());
        $this->assertStringContainsString('Your card was declined.', $response->getMessage());
    }

    /** @test */
    public function test_verify_of_a_pending_payment_intent_reports_not_verified(): void
    {
        // Still in flight — not yet safe to trust for order fulfilment.
        ApiRequestor::setHttpClient(new VerifyFakeStripeHttpClient([
            $this->stripeResponse(200, [
                'id'       => 'pi_to_verify_004',
                'object'   => 'payment_intent',
                'status'   => 'processing',
                'amount'   => 1000,
                'currency' => 'usd',
            ]),
        ]));

        $response = $this->makeDriver()->verify($this->makeRequest('pi_to_verify_004'));

        $this->assertFalse($response->isVerified());
        $this->assertFalse($response->isTrusted());
    }

    // =========================================================================
    // Not found
    // =========================================================================

    /** @test */
    public function test_verify_of_an_unknown_transaction_throws_mapped_exception(): void
    {
        ApiRequestor::setHttpClient(new VerifyFakeStripeHttpClient([
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
            $this->makeDriver()->verify($this->makeRequest('pi_does_not_exist'));
        } finally {
            $this->assertCount(0, $this->events->dispatched);
        }
    }

    // =========================================================================
    // Retry invocation
    // =========================================================================

    /** @test */
    public function test_verify_wraps_the_stripe_call_in_with_retry(): void
    {
        ApiRequestor::setHttpClient(new VerifyFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'pi_verify_retry_001', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 1000, 'currency' => 'usd']),
        ]));

        $retry = $this->createMock(RetryServiceContract::class);
        $retry->expects($this->once())
            ->method('execute')
            ->willReturnCallback(fn (callable $operation) => $operation());

        $response = $this->makeDriver($retry)->verify($this->makeRequest('pi_verify_retry_001'));

        $this->assertTrue($response->isVerified());
    }
}

/**
 * Minimal event dispatcher test double that records every dispatched event
 * in call order, so tests can assert both event type and lifecycle ordering.
 *
 * Duplicated (and renamed) from StripeDriverChargeTest.php by explicit
 * decision — every test file in this package is self-contained.
 */
final class VerifyRecordingDispatcher implements Dispatcher
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
final class VerifyFakeStripeHttpClient implements ClientInterface
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
