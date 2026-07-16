<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Drivers\Stripe;

use Illuminate\Contracts\Events\Dispatcher;
use InvalidArgumentException;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Services\RetryServiceContract;
use Mifatoyeh\LaravelPaymentFramework\DTO\CustomerData;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentLinkRequest;
use Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeDriver;
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentLinkCreated;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\IdempotencyException;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\InvalidConfigurationException;
use Mifatoyeh\LaravelPaymentFramework\Logging\NullLogger;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentLinkResponse;
use Mifatoyeh\LaravelPaymentFramework\Services\RetryService;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * Unit tests for StripeDriver::createPaymentLink().
 *
 * Per the same explicit decision used for every previous driver-method test
 * file: CreatePaymentLinkRecordingDispatcher and
 * CreatePaymentLinkFakeStripeHttpClient are duplicated below (renamed to
 * avoid the redeclare fatal) rather than reused — every test file in this
 * package is self-contained.
 *
 * All Stripe HTTP traffic is intercepted via the Stripe SDK's own
 * ClientInterface seam; no test here makes a real network call.
 */
final class StripeDriverCreatePaymentLinkTest extends TestCase
{
    private CreatePaymentLinkRecordingDispatcher $events;

    protected function setUp(): void
    {
        parent::setUp();

        $this->events = new CreatePaymentLinkRecordingDispatcher();
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
        string $idempotencyKey = 'idem-key-paylink-001',
        ?string $returnUrl = 'https://example.com/success',
        ?string $cancelUrl = 'https://example.com/cancel',
    ): PaymentLinkRequest {
        return new PaymentLinkRequest(
            amount: Money::ofMinor(10000, Currency::USD),
            currency: Currency::USD,
            description: 'Sandbox test payment',
            customer: new CustomerData(name: 'Mohamed Azmy', email: 'azmy@example.com'),
            returnUrl: $returnUrl,
            cancelUrl: $cancelUrl,
            expiresAt: null,
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
    // Successful creation
    // =========================================================================

    /** @test */
    public function test_create_payment_link_returns_the_checkout_url_and_dispatches_event(): void
    {
        ApiRequestor::setHttpClient(new CreatePaymentLinkFakeStripeHttpClient([
            $this->stripeResponse(200, [
                'id'         => 'cs_test_001',
                'object'     => 'checkout.session',
                'status'     => 'open',
                'url'        => 'https://checkout.stripe.com/c/pay/cs_test_001',
                'expires_at' => 1700003600,
            ]),
        ]));

        $response = $this->makeDriver()->createPaymentLink($this->makeRequest());

        $this->assertInstanceOf(PaymentLinkResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_test_001', $response->getPaymentUrl());
        $this->assertSame('cs_test_001', $response->getLinkId());
        $this->assertTrue($response->hasExpiry());
        $this->assertSame('Payment link created.', $response->getMessage());

        $this->assertCount(1, $this->events->dispatched);
        $this->assertInstanceOf(PaymentLinkCreated::class, $this->events->dispatched[0]);
        $this->assertSame($response, $this->events->dispatched[0]->response);
    }

    // =========================================================================
    // Framework-level guard
    // =========================================================================

    /** @test */
    public function test_create_payment_link_without_return_url_throws_before_any_stripe_call(): void
    {
        $client = new CreatePaymentLinkFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'cs_never', 'object' => 'checkout.session', 'url' => 'https://x']),
        ]);
        ApiRequestor::setHttpClient($client);

        $driver  = $this->makeDriver();
        $request = $this->makeRequest(returnUrl: null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/returnUrl/');

        try {
            $driver->createPaymentLink($request);
        } finally {
            $this->assertSame(0, $client->callCount);
            $this->assertCount(0, $this->events->dispatched);
        }
    }

    /** @test */
    public function test_create_payment_link_with_blank_return_url_throws_before_any_stripe_call(): void
    {
        $client = new CreatePaymentLinkFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'cs_never', 'object' => 'checkout.session', 'url' => 'https://x']),
        ]);
        ApiRequestor::setHttpClient($client);

        $driver  = $this->makeDriver();
        $request = $this->makeRequest(returnUrl: '');

        $this->expectException(InvalidArgumentException::class);

        try {
            $driver->createPaymentLink($request);
        } finally {
            $this->assertSame(0, $client->callCount);
        }
    }

    /** @test */
    public function test_create_payment_link_without_cancel_url_does_not_throw(): void
    {
        // cancelUrl is genuinely optional — only returnUrl is guarded.
        ApiRequestor::setHttpClient(new CreatePaymentLinkFakeStripeHttpClient([
            $this->stripeResponse(200, [
                'id'     => 'cs_no_cancel_001',
                'object' => 'checkout.session',
                'url'    => 'https://checkout.stripe.com/c/pay/cs_no_cancel_001',
            ]),
        ]));

        $response = $this->makeDriver()->createPaymentLink($this->makeRequest(cancelUrl: null));

        $this->assertTrue($response->isSuccessful());
    }

    // =========================================================================
    // Unrecoverable errors
    // =========================================================================

    /** @test */
    public function test_create_payment_link_stripe_api_error_throws_mapped_exception(): void
    {
        ApiRequestor::setHttpClient(new CreatePaymentLinkFakeStripeHttpClient([
            $this->stripeResponse(400, [
                'error' => [
                    'type'    => 'invalid_request_error',
                    'message' => 'Invalid currency: xyz',
                ],
            ]),
        ]));

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/Invalid currency/');

        try {
            $this->makeDriver()->createPaymentLink($this->makeRequest());
        } finally {
            $this->assertCount(0, $this->events->dispatched);
        }
    }

    // =========================================================================
    // Idempotency
    // =========================================================================

    /** @test */
    public function test_create_payment_link_with_whitespace_only_idempotency_key_throws_before_any_stripe_call(): void
    {
        $client = new CreatePaymentLinkFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'cs_never', 'object' => 'checkout.session', 'url' => 'https://x']),
        ]);
        ApiRequestor::setHttpClient($client);

        $driver  = $this->makeDriver();
        $request = $this->makeRequest('   ');

        $this->expectException(IdempotencyException::class);

        try {
            $driver->createPaymentLink($request);
        } finally {
            $this->assertSame(0, $client->callCount);
            $this->assertCount(0, $this->events->dispatched);
        }
    }

    /** @test */
    public function test_create_payment_link_forwards_the_idempotency_key_to_stripe_as_a_request_header(): void
    {
        $client = new CreatePaymentLinkFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'cs_idem_001', 'object' => 'checkout.session', 'url' => 'https://x']),
        ]);
        ApiRequestor::setHttpClient($client);

        $this->makeDriver()->createPaymentLink($this->makeRequest('idem-key-paylink-forwarded'));

        $this->assertSame(1, $client->callCount);
        $headers = implode("\n", $client->headersSeen[0]);
        $this->assertStringContainsString('idem-key-paylink-forwarded', $headers);
    }

    // =========================================================================
    // Retry invocation
    // =========================================================================

    /** @test */
    public function test_create_payment_link_wraps_the_stripe_call_in_with_retry(): void
    {
        ApiRequestor::setHttpClient(new CreatePaymentLinkFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'cs_retry_001', 'object' => 'checkout.session', 'url' => 'https://x']),
        ]));

        $retry = $this->createMock(RetryServiceContract::class);
        $retry->expects($this->once())
            ->method('execute')
            ->willReturnCallback(fn (callable $operation) => $operation());

        $response = $this->makeDriver($retry)->createPaymentLink($this->makeRequest());

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
final class CreatePaymentLinkRecordingDispatcher implements Dispatcher
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
final class CreatePaymentLinkFakeStripeHttpClient implements ClientInterface
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
