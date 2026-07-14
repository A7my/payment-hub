<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Testing;

use Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\PaymentDriverContract;
use Mifatoyeh\LaravelPaymentFramework\DTO\CaptureRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentLinkRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\RefundRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\SaveCardRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\SubscriptionRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\TokenChargeRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\TransactionLookupRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\VoidRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\WebhookRequest;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\Enums\WebhookEventType;
use Mifatoyeh\LaravelPaymentFramework\Responses\CaptureResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentLinkResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\RefundResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\StatusResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\SubscriptionResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\VerificationResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\VoidResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\WebhookResponse;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;
use PHPUnit\Framework\Assert;

/**
 * In-memory fake driver for use in tests.
 *
 * Records all calls without making any real provider API requests.
 * Returns configurable successful responses by default.
 *
 * Usage:
 *   $fake = Payment::fake();
 *   Payment::charge($request);
 *   $fake->assertCharged(Money::of(1000, Currency::USD));
 *
 * All 15 driver methods store their input and return a default successful
 * response so tests can assert on call counts, amounts, and events without
 * touching any real provider.
 */
final class FakePaymentDriver implements PaymentDriverContract
{
    /** @var array<int, array{request: PaymentRequest, response: PaymentResponse}> */
    private array $charges = [];

    /** @var array<int, array{request: RefundRequest, response: RefundResponse}> */
    private array $refunds = [];

    /** @var array<int, array{request: CaptureRequest, response: CaptureResponse}> */
    private array $captures = [];

    /** @var array<int, array{request: VoidRequest, response: VoidResponse}> */
    private array $voids = [];

    /** @var array<int, array{request: SubscriptionRequest, response: SubscriptionResponse}> */
    private array $subscriptions = [];

    /** @var bool Whether the fake should return failure responses. */
    private bool $shouldFail = false;

    /** @var bool Whether webhook signature verification should fail. */
    private bool $failWebhookVerification = false;

    // -------------------------------------------------------------------------
    // Configuration methods
    // -------------------------------------------------------------------------

    /**
     * Configure the fake to return failure responses for all subsequent calls.
     */
    public function failing(): self
    {
        $this->shouldFail = true;
        return $this;
    }

    /**
     * Configure the fake to fail webhook signature verification.
     */
    public function failingWebhookVerification(): self
    {
        $this->failWebhookVerification = true;
        return $this;
    }

    // -------------------------------------------------------------------------
    // PaymentDriverContract implementations
    // -------------------------------------------------------------------------

    /** {@inheritDoc} */
    public function charge(PaymentRequest $request): PaymentResponse
    {
        // TODO: Build a success/failure PaymentResponse based on $this->shouldFail
        // TODO: Store in $this->charges; return response
        $response = $this->makePaymentResponse($request->amount, $this->shouldFail);
        $this->charges[] = ['request' => $request, 'response' => $response];
        return $response;
    }

    /** {@inheritDoc} */
    public function authorize(PaymentRequest $request): PaymentResponse
    {
        // TODO: Similar to charge() but with Authorized status
        $response = $this->makePaymentResponse($request->amount, $this->shouldFail);
        return $response;
    }

    /** {@inheritDoc} */
    public function capture(CaptureRequest $request): CaptureResponse
    {
        // TODO: Build and store CaptureResponse
        $response = new CaptureResponse(
            successful: ! $this->shouldFail,
            captureId: 'fake-capture-' . uniqid(),
            amount: $request->amount,
            status: $this->shouldFail ? PaymentStatus::Failed : PaymentStatus::Captured,
            message: $this->shouldFail ? 'Fake capture failed' : 'Fake capture successful',
            rawResponse: [],
        );
        $this->captures[] = ['request' => $request, 'response' => $response];
        return $response;
    }

    /** {@inheritDoc} */
    public function void(VoidRequest $request): VoidResponse
    {
        // TODO: Build and store VoidResponse
        $response = new VoidResponse(
            successful: ! $this->shouldFail,
            transactionId: $request->transactionId,
            status: $this->shouldFail ? PaymentStatus::Failed : PaymentStatus::Voided,
            message: $this->shouldFail ? 'Fake void failed' : 'Fake void successful',
            rawResponse: [],
        );
        $this->voids[] = ['request' => $request, 'response' => $response];
        return $response;
    }

    /** {@inheritDoc} */
    public function refund(RefundRequest $request): RefundResponse
    {
        // TODO: Build and store RefundResponse
        $response = new RefundResponse(
            successful: ! $this->shouldFail,
            refundId: 'fake-refund-' . uniqid(),
            amount: $request->amount,
            status: $this->shouldFail ? PaymentStatus::Failed : PaymentStatus::Refunded,
            message: $this->shouldFail ? 'Fake refund failed' : 'Fake refund successful',
            rawResponse: [],
        );
        $this->refunds[] = ['request' => $request, 'response' => $response];
        return $response;
    }

    /** {@inheritDoc} */
    public function partialRefund(RefundRequest $request): RefundResponse
    {
        // TODO: Similar to refund() but with PartiallyRefunded status
        return $this->refund($request);
    }

    /** {@inheritDoc} */
    public function verify(TransactionLookupRequest $request): VerificationResponse
    {
        // TODO: Return a fake VerificationResponse
        return new VerificationResponse(
            successful: true,
            verified: true,
            transactionId: $request->transactionId,
            message: 'Fake verification successful',
            rawResponse: [],
        );
    }

    /** {@inheritDoc} */
    public function lookup(TransactionLookupRequest $request): StatusResponse
    {
        // TODO: Return a fake StatusResponse
        return new StatusResponse(
            successful: true,
            transactionId: $request->transactionId,
            status: PaymentStatus::Captured,
            message: 'Fake lookup successful',
            rawResponse: [],
        );
    }

    /** {@inheritDoc} */
    public function createPaymentLink(PaymentLinkRequest $request): PaymentLinkResponse
    {
        // TODO: Return a fake PaymentLinkResponse
        return new PaymentLinkResponse(
            successful: ! $this->shouldFail,
            paymentUrl: 'https://fake-payment.example.com/pay/' . uniqid(),
            linkId: 'fake-link-' . uniqid(),
            expiresAt: null,
            message: 'Fake payment link created',
            rawResponse: [],
        );
    }

    /** {@inheritDoc} */
    public function saveCard(SaveCardRequest $request): PaymentResponse
    {
        // TODO: Return a fake PaymentResponse with saved card token
        return $this->makePaymentResponse(
            Money::of(0, \Mifatoyeh\LaravelPaymentFramework\Enums\Currency::USD),
            $this->shouldFail
        );
    }

    /** {@inheritDoc} */
    public function chargeToken(TokenChargeRequest $request): PaymentResponse
    {
        // TODO: Store and return fake PaymentResponse
        $response = $this->makePaymentResponse($request->amount, $this->shouldFail);
        $this->charges[] = ['request' => null, 'response' => $response];
        return $response;
    }

    /** {@inheritDoc} */
    public function createSubscription(SubscriptionRequest $request): SubscriptionResponse
    {
        // TODO: Build and store SubscriptionResponse
        $response = new SubscriptionResponse(
            successful: ! $this->shouldFail,
            subscriptionId: 'fake-sub-' . uniqid(),
            status: $this->shouldFail ? PaymentStatus::Failed : PaymentStatus::Captured,
            nextBillingDate: new \DateTimeImmutable('+1 month'),
            message: 'Fake subscription created',
            rawResponse: [],
        );
        $this->subscriptions[] = ['request' => $request, 'response' => $response];
        return $response;
    }

    /** {@inheritDoc} */
    public function cancelSubscription(TransactionId $subscriptionId): SubscriptionResponse
    {
        // TODO: Return a fake cancellation SubscriptionResponse
        return new SubscriptionResponse(
            successful: true,
            subscriptionId: $subscriptionId->toString(),
            status: PaymentStatus::Cancelled,
            nextBillingDate: null,
            message: 'Fake subscription cancelled',
            rawResponse: [],
        );
    }

    /** {@inheritDoc} */
    public function processWebhook(WebhookRequest $request): WebhookResponse
    {
        // TODO: Return a fake WebhookResponse
        return new WebhookResponse(
            successful: true,
            eventType: WebhookEventType::PaymentSucceeded,
            message: 'Fake webhook processed',
            rawPayload: [],
        );
    }

    /** {@inheritDoc} */
    public function verifyWebhookSignature(WebhookRequest $request): bool
    {
        // TODO: Return false if $this->failWebhookVerification, true otherwise
        return ! $this->failWebhookVerification;
    }

    // -------------------------------------------------------------------------
    // Assertion helpers
    // -------------------------------------------------------------------------

    /**
     * Assert that a charge was made for the given amount.
     *
     * @param Money $amount The expected charged amount.
     *
     * @throws \PHPUnit\Framework\AssertionFailedError When no matching charge is found.
     */
    public function assertCharged(Money $amount): void
    {
        // TODO: Search $this->charges for a matching amount; fail if not found
        $found = false;
        foreach ($this->charges as $charge) {
            if ($charge['request'] !== null
                && $charge['request']->amount->amount === $amount->amount
                && $charge['request']->amount->currency === $amount->currency) {
                $found = true;
                break;
            }
        }
        Assert::assertTrue($found, "Expected a charge of {$amount->amount} {$amount->currency->value} but none was recorded.");
    }

    /**
     * Assert that a refund was issued for the given transaction.
     *
     * @param TransactionId $id The transaction identifier that should have been refunded.
     *
     * @throws \PHPUnit\Framework\AssertionFailedError When no matching refund is found.
     */
    public function assertRefunded(TransactionId $id): void
    {
        // TODO: Search $this->refunds for matching transaction ID; fail if not found
        $found = false;
        foreach ($this->refunds as $refund) {
            if ($refund['request']->transactionId->toString() === $id->toString()) {
                $found = true;
                break;
            }
        }
        Assert::assertTrue($found, "Expected a refund for transaction [{$id->toString()}] but none was recorded.");
    }

    /**
     * Assert that no charges have been made.
     *
     * @throws \PHPUnit\Framework\AssertionFailedError When one or more charges are recorded.
     */
    public function assertNotCharged(): void
    {
        // TODO: Assert $this->charges is empty
        $realCharges = array_filter($this->charges, fn ($c) => $c['request'] !== null);
        Assert::assertEmpty($realCharges, 'Expected no charges to be made, but ' . count($realCharges) . ' charge(s) were recorded.');
    }

    /**
     * Assert that a specific event class was dispatched.
     *
     * @param string $eventClass The fully-qualified event class name.
     *
     * @throws \PHPUnit\Framework\AssertionFailedError When the event was not dispatched.
     */
    public function assertEventDispatched(string $eventClass): void
    {
        // TODO: Check Laravel's Event::assertDispatched($eventClass)
        // TODO: This delegates to Event::fake() assertions — ensure Event::fake() was called in the test
        \Illuminate\Support\Facades\Event::assertDispatched($eventClass);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build a generic PaymentResponse for charge/authorize operations.
     *
     * @param Money $amount  The requested amount.
     * @param bool  $failing Whether to return a failure response.
     */
    private function makePaymentResponse(Money $amount, bool $failing): PaymentResponse
    {
        return new PaymentResponse(
            successful: ! $failing,
            transactionId: TransactionId::fromString('fake-txn-' . uniqid()),
            status: $failing ? PaymentStatus::Failed : PaymentStatus::Captured,
            providerReference: 'fake-ref-' . uniqid(),
            amount: $amount,
            rawResponse: ['fake' => true],
            message: $failing ? 'Fake payment failed' : 'Fake payment successful',
        );
    }
}
