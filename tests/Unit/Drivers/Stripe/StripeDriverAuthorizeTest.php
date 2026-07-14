<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Drivers\Stripe;

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

/**
 * Unit tests for StripeDriver::authorize().
 *
 * Mirrors StripeDriverChargeTest's scenarios exactly (same orchestration
 * shape), since authorize() is charge() with `capture_method: manual`. Reuses
 * RecordingDispatcher and FakeStripeHttpClient declared in
 * StripeDriverChargeTest.php — both are generic (nothing charge-specific
 * about them) and redeclaring identically-named classes in this file would
 * be a PHP fatal error (both files share this namespace).
 *
 * All Stripe HTTP traffic is intercepted via the Stripe SDK's own
 * ClientInterface seam; no test here makes a real network call.
 */
final class StripeDriverAuthorizeTest extends TestCase
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
        return new StripeDriver(
            new NullLogger(),
            $this->events,
            $retry ?? new RetryService(1, 0, true),
            ['secret' => 'sk_test_dummy_key', 'webhook_secret' => 'whsec_dummy'],
        );
    }

    private function makeRequest(string $idempotencyKey = 'idem-auth-001'): PaymentRequest
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
    // Successful authorization
    // =========================================================================

    /** @test */
    public function test_successful_authorization_returns_authorized_payment_response(): void
    {
        ApiRequestor::setHttpClient(new FakeStripeHttpClient([
            $this->stripeResponse(200, [
                'id'            => 'pi_auth_success_123',
                'object'        => 'payment_intent',
                'status'        => 'requires_capture', // Stripe's manual-capture "authorized, awaiting capture" status
                'amount'        => 1000,
                'currency'      => 'usd',
                'client_secret' => 'pi_auth_success_123_secret',
            ]),
        ]));

        $response = $this->makeDriver()->authorize($this->makeRequest());

        $this->assertInstanceOf(PaymentResponse::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertSame(PaymentStatus::Authorized, $response->getStatus());
        $this->assertSame('pi_auth_success_123', $response->getTransactionId()->toString());
        $this->assertSame(1000, $response->getAmount()->amount);
        $this->assertSame('Payment authorised, awaiting capture.', $response->getMessage());

        $this->assertCount(2, $this->events->dispatched);
        $this->assertInstanceOf(PaymentInitiated::class, $this->events->dispatched[0]);
        $this->assertInstanceOf(PaymentSucceeded::class, $this->events->dispatched[1]);
        $this->assertSame($response, $this->events->dispatched[1]->response);
    }

    // =========================================================================
    // Declined authorization
    // =========================================================================

    /** @test */
    public function test_declined_authorization_returns_unsuccessful_response_without_throwing(): void
    {
        ApiRequestor::setHttpClient(new FakeStripeHttpClient([
            $this->stripeResponse(402, [
                'error' => [
                    'type'           => 'card_error',
                    'code'           => 'card_declined',
                    'decline_code'   => 'generic_decline',
                    'message'        => 'Your card was declined.',
                    'payment_intent' => [
                        'id'                 => 'pi_auth_declined_456',
                        'object'             => 'payment_intent',
                        'status'             => 'requires_payment_method',
                        'amount'             => 1000,
                        'currency'           => 'usd',
                        'last_payment_error' => ['message' => 'Your card was declined.'],
                    ],
                ],
            ]),
        ]));

        $response = $this->makeDriver()->authorize($this->makeRequest());

        $this->assertFalse($response->isSuccessful());
        $this->assertSame(PaymentStatus::Failed, $response->getStatus());
        $this->assertSame('pi_auth_declined_456', $response->getTransactionId()->toString());
        $this->assertSame('Your card was declined.', $response->getMessage());

        $this->assertCount(2, $this->events->dispatched);
        $this->assertInstanceOf(PaymentFailed::class, $this->events->dispatched[1]);
        $this->assertSame($response, $this->events->dispatched[1]->response);
        $this->assertNull($this->events->dispatched[1]->exception);
    }

    // =========================================================================
    // Requires action (3-D Secure / OTP)
    // =========================================================================

    /** @test */
    public function test_requires_action_authorization_returns_requires_action_response(): void
    {
        ApiRequestor::setHttpClient(new FakeStripeHttpClient([
            $this->stripeResponse(200, [
                'id'            => 'pi_auth_action_789',
                'object'        => 'payment_intent',
                'status'        => 'requires_action',
                'amount'        => 1000,
                'currency'      => 'usd',
                'client_secret' => 'pi_auth_action_789_secret',
            ]),
        ]));

        $response = $this->makeDriver()->authorize($this->makeRequest());

        $this->assertFalse($response->isSuccessful());
        $this->assertSame(PaymentStatus::RequiresAction, $response->getStatus());
        $this->assertTrue($response->requiresAction());

        $this->assertInstanceOf(PaymentFailed::class, $this->events->dispatched[1]);
    }

    // =========================================================================
    // Idempotency
    // =========================================================================

    /** @test */
    public function test_empty_idempotency_key_throws_before_any_stripe_call(): void
    {
        $client = new FakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'unused', 'object' => 'payment_intent', 'status' => 'requires_capture', 'amount' => 1000, 'currency' => 'usd']),
        ]);
        ApiRequestor::setHttpClient($client);

        $driver  = $this->makeDriver();
        $request = $this->makeRequest('   '); // whitespace-only — passes the DTO check, fails the driver guard

        $this->expectException(IdempotencyException::class);

        try {
            $driver->authorize($request);
        } finally {
            $this->assertSame(0, $client->callCount, 'Stripe must never be called when the idempotency key is invalid.');
            $this->assertCount(0, $this->events->dispatched, 'No lifecycle event should fire before idempotency validation passes.');
        }
    }

    /** @test */
    public function test_idempotency_key_is_forwarded_to_stripe_as_a_request_header(): void
    {
        $client = new FakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'pi_auth_idem_001', 'object' => 'payment_intent', 'status' => 'requires_capture', 'amount' => 1000, 'currency' => 'usd']),
        ]);
        ApiRequestor::setHttpClient($client);

        $this->makeDriver()->authorize($this->makeRequest('idem-auth-forwarded'));

        $this->assertSame(1, $client->callCount);
        $headers = implode("\n", $client->headersSeen[0]);
        $this->assertStringContainsString('idem-auth-forwarded', $headers);
    }

    // =========================================================================
    // Retry invocation
    // =========================================================================

    /** @test */
    public function test_authorize_wraps_the_stripe_call_in_with_retry(): void
    {
        ApiRequestor::setHttpClient(new FakeStripeHttpClient([
            $this->stripeResponse(200, ['id' => 'pi_auth_retry_001', 'object' => 'payment_intent', 'status' => 'requires_capture', 'amount' => 1000, 'currency' => 'usd']),
        ]));

        $retry = $this->createMock(RetryServiceContract::class);
        $retry->expects($this->once())
            ->method('execute')
            ->willReturnCallback(fn (callable $operation) => $operation());

        $response = $this->makeDriver($retry)->authorize($this->makeRequest());

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('pi_auth_retry_001', $response->getTransactionId()->toString());
    }
}
