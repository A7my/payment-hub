<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Drivers\Stripe;

use Illuminate\Contracts\Events\Dispatcher;
use InvalidArgumentException;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Services\RetryServiceContract;
use Mifatoyeh\LaravelPaymentFramework\DTO\SaveCardRequest;
use Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeDriver;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\Events\CardSaved;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\IdempotencyException;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\AuthorizationFailedException;
use Mifatoyeh\LaravelPaymentFramework\Logging\NullLogger;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentResponse;
use Mifatoyeh\LaravelPaymentFramework\Services\RetryService;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\CustomerId;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Token;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * Unit tests for StripeDriver::saveCard().
 *
 * saveCard() makes TWO sequential Stripe API calls (customers.create, then
 * setup_intents.create) — {@see SaveCardFakeStripeHttpClient} is seeded with
 * responses in that exact call order for every success-path test.
 *
 * Per the same explicit decision used for every previous driver-method test
 * file: SaveCardRecordingDispatcher and SaveCardFakeStripeHttpClient are
 * duplicated below (renamed to avoid the redeclare fatal) rather than
 * reused — every test file in this package is self-contained.
 *
 * All Stripe HTTP traffic is intercepted via the Stripe SDK's own
 * ClientInterface seam; no test here makes a real network call.
 */
final class StripeDriverSaveCardTest extends TestCase
{
    private SaveCardRecordingDispatcher $events;

    protected function setUp(): void
    {
        parent::setUp();

        $this->events = new SaveCardRecordingDispatcher();
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

    private function makeRequest(string $idempotencyKey = 'idem-key-savecard-001'): SaveCardRequest
    {
        return new SaveCardRequest(
            token: Token::fromString('pm_card_visa'),
            customerId: CustomerId::fromString('host-customer-42'),
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
    // Successful save
    // =========================================================================

    /** @test */
    public function test_save_card_creates_a_customer_and_confirms_a_setup_intent_and_reports_success(): void
    {
        ApiRequestor::setHttpClient(new SaveCardFakeStripeHttpClient([
            $this->stripeResponse(200, [
                'id'     => 'cus_new_001',
                'object' => 'customer',
            ]),
            $this->stripeResponse(200, [
                'id'             => 'seti_001',
                'object'         => 'setup_intent',
                'status'         => 'succeeded',
                'customer'       => 'cus_new_001',
                'payment_method' => 'pm_card_visa',
            ]),
        ]));

        $response = $this->makeDriver()->saveCard($this->makeRequest());

        $this->assertInstanceOf(PaymentResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertSame(PaymentStatus::Captured, $response->getStatus());
        $this->assertSame('seti_001', $response->getTransactionId()->toString());
        $this->assertSame('cus_new_001', $response->getProviderReference());
        $this->assertSame(0, $response->getAmount()->amount);
        $this->assertSame('Card saved.', $response->getMessage());

        $this->assertCount(1, $this->events->dispatched);
        $this->assertInstanceOf(CardSaved::class, $this->events->dispatched[0]);
        $this->assertSame($response, $this->events->dispatched[0]->response);
    }

    /** @test */
    public function test_save_card_sends_host_customer_id_in_customer_metadata(): void
    {
        $client = new SaveCardFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'cus_meta_001', 'object' => 'customer']),
            $this->stripeResponse(200, [
                'id' => 'seti_meta_001', 'object' => 'setup_intent', 'status' => 'succeeded',
                'customer' => 'cus_meta_001', 'payment_method' => 'pm_card_visa',
            ]),
        ]);
        ApiRequestor::setHttpClient($client);

        $this->makeDriver()->saveCard($this->makeRequest());

        $this->assertSame(2, $client->callCount);
        $this->assertStringContainsString('host-customer-42', $client->paramsSent[0] ?? '');
    }

    // =========================================================================
    // Requires action (e.g. 3-D Secure during card verification)
    // =========================================================================

    /** @test */
    public function test_save_card_requiring_action_reports_requires_action_without_dispatching_card_saved(): void
    {
        ApiRequestor::setHttpClient(new SaveCardFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'cus_action_001', 'object' => 'customer']),
            $this->stripeResponse(200, [
                'id' => 'seti_action_001', 'object' => 'setup_intent', 'status' => 'requires_action',
                'customer' => 'cus_action_001', 'payment_method' => 'pm_card_visa',
            ]),
        ]));

        $response = $this->makeDriver()->saveCard($this->makeRequest());

        $this->assertFalse($response->isSuccessful());
        $this->assertSame(PaymentStatus::RequiresAction, $response->getStatus());
        $this->assertTrue($response->requiresAction());
        $this->assertSame('cus_action_001', $response->getProviderReference());

        // No CardSaved event — the card was not actually saved successfully.
        $this->assertCount(0, $this->events->dispatched);
    }

    // =========================================================================
    // Declined card — soft failure, not thrown
    // =========================================================================

    /** @test */
    public function test_save_card_declined_returns_unsuccessful_response_without_throwing(): void
    {
        ApiRequestor::setHttpClient(new SaveCardFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'cus_declined_001', 'object' => 'customer']),
            $this->stripeResponse(402, [
                'error' => [
                    'type'         => 'card_error',
                    'code'         => 'card_declined',
                    'decline_code' => 'generic_decline',
                    'message'      => 'Your card was declined.',
                    'setup_intent' => [
                        'id'               => 'seti_declined_001',
                        'object'           => 'setup_intent',
                        'status'           => 'requires_payment_method',
                        'customer'         => 'cus_declined_001',
                        'last_setup_error' => ['message' => 'Your card was declined.'],
                    ],
                ],
            ]),
        ]));

        $response = $this->makeDriver()->saveCard($this->makeRequest());

        $this->assertInstanceOf(PaymentResponse::class, $response);
        $this->assertFalse($response->isSuccessful());
        $this->assertSame(PaymentStatus::Failed, $response->getStatus());
        $this->assertSame('seti_declined_001', $response->getTransactionId()->toString());
        // The already-created Customer reference is preserved despite the decline.
        $this->assertSame('cus_declined_001', $response->getProviderReference());
        $this->assertSame('Your card was declined.', $response->getMessage());

        $this->assertCount(0, $this->events->dispatched);
    }

    /** @test */
    public function test_save_card_declined_without_setup_intent_in_error_body_falls_back_to_synthetic_payload_and_keeps_customer_reference(): void
    {
        ApiRequestor::setHttpClient(new SaveCardFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'cus_fallback_001', 'object' => 'customer']),
            $this->stripeResponse(402, [
                'error' => [
                    'type'    => 'card_error',
                    'code'    => 'card_declined',
                    'message' => 'Insufficient funds.',
                ],
            ]),
        ]));

        $response = $this->makeDriver()->saveCard($this->makeRequest('idem-key-savecard-fallback'));

        $this->assertFalse($response->isSuccessful());
        $this->assertSame('Insufficient funds.', $response->getMessage());
        $this->assertSame('declined_idem-key-savecard-fallback', $response->getTransactionId()->toString());
        // Even with no setup_intent in the error body, the Customer created
        // just before the decline is not lost.
        $this->assertSame('cus_fallback_001', $response->getProviderReference());
    }

    // =========================================================================
    // Unrecoverable errors
    // =========================================================================

    /** @test */
    public function test_save_card_when_customer_creation_itself_fails_throws_mapped_exception(): void
    {
        ApiRequestor::setHttpClient(new SaveCardFakeStripeHttpClient([
            $this->stripeResponse(401, [
                'error' => [
                    'type'    => 'authentication_error',
                    'message' => 'Invalid API Key provided.',
                ],
            ]),
        ]));

        // authentication_error maps to AuthorizationFailedException per
        // StripeExceptionMapper's table — not InvalidConfigurationException
        // (that's reserved for invalid_request_error, e.g. an unknown id).
        $this->expectException(AuthorizationFailedException::class);

        try {
            $this->makeDriver()->saveCard($this->makeRequest());
        } finally {
            $this->assertCount(0, $this->events->dispatched);
        }
    }

    // =========================================================================
    // Idempotency
    // =========================================================================

    /** @test */
    public function test_save_card_with_whitespace_only_idempotency_key_throws_before_any_stripe_call(): void
    {
        $client = new SaveCardFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'cus_never', 'object' => 'customer']),
        ]);
        ApiRequestor::setHttpClient($client);

        $driver  = $this->makeDriver();
        $request = $this->makeRequest('   ');

        $this->expectException(IdempotencyException::class);

        try {
            $driver->saveCard($request);
        } finally {
            $this->assertSame(0, $client->callCount, 'Stripe must never be called when the idempotency key is invalid.');
            $this->assertCount(0, $this->events->dispatched);
        }
    }

    /** @test */
    public function test_save_card_forwards_distinct_suffixed_idempotency_keys_for_each_call(): void
    {
        $client = new SaveCardFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'cus_idem_001', 'object' => 'customer']),
            $this->stripeResponse(200, [
                'id' => 'seti_idem_001', 'object' => 'setup_intent', 'status' => 'succeeded',
                'customer' => 'cus_idem_001', 'payment_method' => 'pm_card_visa',
            ]),
        ]);
        ApiRequestor::setHttpClient($client);

        $this->makeDriver()->saveCard($this->makeRequest('idem-key-distinct'));

        $this->assertSame(2, $client->callCount);
        $customerHeaders    = implode("\n", $client->headersSeen[0]);
        $setupIntentHeaders = implode("\n", $client->headersSeen[1]);
        $this->assertStringContainsString('idem-key-distinct:customer', $customerHeaders);
        $this->assertStringContainsString('idem-key-distinct:setup_intent', $setupIntentHeaders);
    }

    // =========================================================================
    // Retry invocation
    // =========================================================================

    /** @test */
    public function test_save_card_wraps_each_stripe_call_in_with_retry(): void
    {
        ApiRequestor::setHttpClient(new SaveCardFakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'cus_retry_001', 'object' => 'customer']),
            $this->stripeResponse(200, [
                'id' => 'seti_retry_001', 'object' => 'setup_intent', 'status' => 'succeeded',
                'customer' => 'cus_retry_001', 'payment_method' => 'pm_card_visa',
            ]),
        ]));

        $retry = $this->createMock(RetryServiceContract::class);
        $retry->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(fn (callable $operation) => $operation());

        $response = $this->makeDriver($retry)->saveCard($this->makeRequest());

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
final class SaveCardRecordingDispatcher implements Dispatcher
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
 * were queued. Also records the raw request body sent for each call, so
 * tests can assert on the exact params Stripe received (e.g. metadata).
 *
 * Duplicated (and renamed) from StripeDriverChargeTest.php by explicit
 * decision — every test file in this package is self-contained.
 */
final class SaveCardFakeStripeHttpClient implements ClientInterface
{
    public int $callCount = 0;

    /** @var array<int, array<int, string>> */
    public array $headersSeen = [];

    /** @var array<int, string> */
    public array $paramsSent = [];

    /** @param array<int, array{0: string, 1: int, 2: array<int, string>}> $responses */
    public function __construct(
        private readonly array $responses,
    ) {
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->headersSeen[$this->callCount] = $headers;
        $this->paramsSent[$this->callCount]  = is_array($params) ? http_build_query($params) : (string) $params;

        $index = min($this->callCount, count($this->responses) - 1);
        $this->callCount++;

        return $this->responses[$index];
    }
}
