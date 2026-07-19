<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Drivers\Paymob;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use InvalidArgumentException;
use Mifatoyeh\LaravelPaymentFramework\DTO\CancelSubscriptionRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\CaptureRequest;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\SupportsSdkCheckout;
use Mifatoyeh\LaravelPaymentFramework\DTO\CustomerData;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentLinkRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\RefundRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\SaveCardRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\SubscriptionRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\TokenChargeRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\TransactionLookupRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\VoidRequest;
use Mifatoyeh\LaravelPaymentFramework\Drivers\Paymob\PaymobClient;
use Mifatoyeh\LaravelPaymentFramework\Drivers\Paymob\PaymobDriver;
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\Events\CardSaved;
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentCaptured;
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentFailed;
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentInitiated;
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentLinkCreated;
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentRefunded;
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentSucceeded;
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentVoided;
use Mifatoyeh\LaravelPaymentFramework\Events\TokenCharged;
use Mifatoyeh\LaravelPaymentFramework\Events\TransactionLookuped;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\IdempotencyException;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\UnsupportedOperationException;
use Mifatoyeh\LaravelPaymentFramework\Logging\NullLogger;
use Mifatoyeh\LaravelPaymentFramework\Services\RetryService;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\CustomerId;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Token;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PaymobDriver — the full charge/void/capture/refund/verify/
 * lookup/saveCard/chargeToken/createPaymentLink/subscriptions orchestration.
 *
 * Deliberately lighter coverage than the Stripe driver's per-method test
 * files (2-4 tests per method here vs. 8-15 for Stripe) — this entire
 * driver is a first-pass implementation built without an SDK to verify
 * against (see PaymobDriver's own class docblock), so these tests confirm
 * internal consistency (right orchestration, right event dispatch, right
 * guard behaviour), not correctness against Paymob's real API.
 *
 * All Paymob HTTP traffic is intercepted via PaymobClient::setTestHttpFactory()
 * — a global override PaymobDriver's internally-constructed PaymobClient
 * picks up, mirroring the Stripe driver tests' use of
 * \Stripe\ApiRequestor::setHttpClient(). Reset in tearDown().
 */
final class PaymobDriverTest extends TestCase
{
    private PaymobDriverRecordingDispatcher $events;

    protected function setUp(): void
    {
        parent::setUp();

        $this->events = new PaymobDriverRecordingDispatcher();
    }

    protected function tearDown(): void
    {
        PaymobClient::setTestHttpFactory(null);

        parent::tearDown();
    }

    private function makeDriver(): PaymobDriver
    {
        return new PaymobDriver(
            new NullLogger(),
            $this->events,
            new RetryService(1, 0, true),
            ['api_key' => 'test-key', 'integration_id' => 12345, 'iframe_id' => '999'],
        );
    }

    private function makeKsaDriver(): PaymobDriver
    {
        return new PaymobDriver(
            new NullLogger(),
            $this->events,
            new RetryService(1, 0, true),
            [
                'secret_key'     => 'sau_sk_test_001',
                'public_key'     => 'sau_pk_test_001',
                'integration_id' => 12345,
                'base_url'       => 'https://ksa.paymob.com/api',
            ],
        );
    }

    /** @param array<string, array{0: array<string, mixed>, 1: int}> $responses keyed by URL pattern => [body, status] */
    private function fakeHttp(array $responses): void
    {
        $http = new HttpFactory();
        $fakes = [];

        foreach ($responses as $pattern => [$body, $status]) {
            $fakes[$pattern] = $http::response($body, $status);
        }

        $http->fake($fakes);

        PaymobClient::setTestHttpFactory($http);
    }

    private function makePaymentRequest(?Token $token = new Token('card_token_001')): PaymentRequest
    {
        return new PaymentRequest(
            amount: Money::ofMinor(1000, Currency::EGP),
            currency: Currency::EGP,
            idempotencyKey: 'idem-key-001',
            customer: new CustomerData('Mohamed Azmy', 'azmy@example.com', '+201234567890'),
            token: $token,
        );
    }

    // =========================================================================
    // charge()
    // =========================================================================

    /** @test */
    public function test_charge_success_dispatches_initiated_and_succeeded(): void
    {
        $this->fakeHttp([
            '*/auth/tokens'               => [['token' => 'auth_1'], 200],
            '*/ecommerce/orders'          => [['id' => 555], 200],
            '*/acceptance/payment_keys'   => [['token' => 'pk_1'], 200],
            '*/acceptance/payments/pay'   => [['id' => 999, 'success' => true, 'pending' => false, 'amount_cents' => 1000, 'currency' => 'EGP'], 200],
        ]);

        $response = $this->makeDriver()->charge($this->makePaymentRequest());

        $this->assertTrue($response->isSuccessful());
        $this->assertSame(PaymentStatus::Captured, $response->getStatus());
        $this->assertSame('999', $response->getTransactionId()->toString());

        $this->assertCount(2, $this->events->dispatched);
        $this->assertInstanceOf(PaymentInitiated::class, $this->events->dispatched[0]);
        $this->assertInstanceOf(PaymentSucceeded::class, $this->events->dispatched[1]);
    }

    /** @test */
    public function test_charge_declined_reports_unsuccessful_without_throwing(): void
    {
        $this->fakeHttp([
            '*/auth/tokens'             => [['token' => 'auth_1'], 200],
            '*/ecommerce/orders'        => [['id' => 555], 200],
            '*/acceptance/payment_keys' => [['token' => 'pk_1'], 200],
            '*/acceptance/payments/pay' => [['id' => 999, 'success' => false, 'pending' => false, 'amount_cents' => 1000, 'currency' => 'EGP'], 200],
        ]);

        $response = $this->makeDriver()->charge($this->makePaymentRequest());

        $this->assertFalse($response->isSuccessful());
        $this->assertSame(PaymentStatus::Failed, $response->getStatus());
        $this->assertInstanceOf(PaymentFailed::class, $this->events->dispatched[1]);
    }

    /** @test */
    public function test_charge_without_token_throws_before_any_call(): void
    {
        $this->fakeHttp(['*' => [['token' => 'never'], 200]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/token/');

        try {
            $this->makeDriver()->charge($this->makePaymentRequest(token: null));
        } finally {
            $this->assertCount(0, $this->events->dispatched);
        }
    }

    /** @test */
    public function test_charge_with_whitespace_idempotency_key_throws_before_any_call(): void
    {
        $this->fakeHttp(['*' => [['token' => 'never'], 200]]);

        $request = new PaymentRequest(
            amount: Money::ofMinor(1000, Currency::EGP),
            currency: Currency::EGP,
            idempotencyKey: '   ',
            customer: new CustomerData('Mohamed Azmy', 'azmy@example.com'),
            token: new Token('card_token_001'),
        );

        $this->expectException(IdempotencyException::class);

        try {
            $this->makeDriver()->charge($request);
        } finally {
            $this->assertCount(0, $this->events->dispatched);
        }
    }

    // =========================================================================
    // authorize() — currently identical to charge(), see PaymobDriver's docblock
    // =========================================================================

    /** @test */
    public function test_authorize_success(): void
    {
        $this->fakeHttp([
            '*/auth/tokens'              => [['token' => 'auth_1'], 200],
            '*/ecommerce/orders'         => [['id' => 555], 200],
            '*/acceptance/payment_keys'  => [['token' => 'pk_1'], 200],
            '*/acceptance/payments/pay'  => [['id' => 999, 'success' => true, 'is_auth' => true, 'is_capture' => false, 'amount_cents' => 1000, 'currency' => 'EGP'], 200],
        ]);

        $response = $this->makeDriver()->authorize($this->makePaymentRequest());

        $this->assertSame(PaymentStatus::Authorized, $response->getStatus());
    }

    // =========================================================================
    // void()
    // =========================================================================

    /** @test */
    public function test_void_success_dispatches_payment_voided(): void
    {
        $this->fakeHttp([
            '*/auth/tokens'                 => [['token' => 'auth_1'], 200],
            '*/acceptance/void_refund/void' => [['id' => 999, 'is_voided' => true], 200],
        ]);

        $response = $this->makeDriver()->void(new VoidRequest(
            transactionId: TransactionId::fromString('999'),
            reason: 'Order cancelled',
            idempotencyKey: 'idem-void-001',
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame(PaymentStatus::Voided, $response->getStatus());
        $this->assertInstanceOf(PaymentVoided::class, $this->events->dispatched[0]);
    }

    // =========================================================================
    // capture()
    // =========================================================================

    /** @test */
    public function test_capture_success_dispatches_payment_captured(): void
    {
        $this->fakeHttp([
            '*/auth/tokens'           => [['token' => 'auth_1'], 200],
            '*/acceptance/capture'    => [['id' => 999, 'success' => true, 'amount_cents' => 500, 'currency' => 'EGP'], 200],
        ]);

        $response = $this->makeDriver()->capture(new CaptureRequest(
            transactionId: TransactionId::fromString('999'),
            amount: Money::ofMinor(500, Currency::EGP),
            idempotencyKey: 'idem-capture-001',
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertInstanceOf(PaymentCaptured::class, $this->events->dispatched[0]);
    }

    // =========================================================================
    // refund() / partialRefund()
    // =========================================================================

    /** @test */
    public function test_refund_success_dispatches_payment_refunded(): void
    {
        $this->fakeHttp([
            '*/auth/tokens'                    => [['token' => 'auth_1'], 200],
            '*/acceptance/void_refund/refund'  => [['id' => 999, 'is_refunded' => true, 'amount_cents' => 1000, 'currency' => 'EGP'], 200],
        ]);

        $response = $this->makeDriver()->refund(new RefundRequest(
            transactionId: TransactionId::fromString('999'),
            amount: Money::ofMinor(1000, Currency::EGP),
            reason: 'Customer request',
            idempotencyKey: 'idem-refund-001',
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame(PaymentStatus::Refunded, $response->getStatus());
        $this->assertInstanceOf(PaymentRefunded::class, $this->events->dispatched[0]);
    }

    /** @test */
    public function test_partial_refund_with_less_than_total_reports_partially_refunded(): void
    {
        $this->fakeHttp([
            '*/auth/tokens'                   => [['token' => 'auth_1'], 200],
            '*/acceptance/void_refund/refund' => [['id' => 999, 'is_refunded' => true, 'amount_cents' => 1000, 'refunded_amount_cents' => 300, 'currency' => 'EGP'], 200],
        ]);

        $response = $this->makeDriver()->partialRefund(new RefundRequest(
            transactionId: TransactionId::fromString('999'),
            amount: Money::ofMinor(300, Currency::EGP),
            reason: 'Partial',
            idempotencyKey: 'idem-partial-001',
        ));

        $this->assertSame(PaymentStatus::PartiallyRefunded, $response->getStatus());
    }

    // =========================================================================
    // verify() / lookup()
    // =========================================================================

    /** @test */
    public function test_verify_of_a_successful_transaction_reports_verified(): void
    {
        $this->fakeHttp([
            '*/auth/tokens'                => [['token' => 'auth_1'], 200],
            '*/acceptance/transactions/*'  => [['id' => 999, 'success' => true, 'amount_cents' => 1000, 'currency' => 'EGP'], 200],
        ]);

        $response = $this->makeDriver()->verify(new TransactionLookupRequest(
            transactionId: TransactionId::fromString('999'),
        ));

        $this->assertTrue($response->isVerified());
        $this->assertCount(0, $this->events->dispatched);
    }

    /** @test */
    public function test_lookup_dispatches_transaction_lookuped(): void
    {
        $this->fakeHttp([
            '*/auth/tokens'                => [['token' => 'auth_1'], 200],
            '*/acceptance/transactions/*'  => [['id' => 999, 'success' => true, 'amount_cents' => 1000, 'currency' => 'EGP'], 200],
        ]);

        $response = $this->makeDriver()->lookup(new TransactionLookupRequest(
            transactionId: TransactionId::fromString('999'),
        ));

        $this->assertSame(PaymentStatus::Captured, $response->getStatus());
        $this->assertInstanceOf(TransactionLookuped::class, $this->events->dispatched[0]);
    }

    // =========================================================================
    // saveCard() / chargeToken()
    // =========================================================================

    /** @test */
    public function test_save_card_success_dispatches_card_saved(): void
    {
        $this->fakeHttp([
            '*/auth/tokens'              => [['token' => 'auth_1'], 200],
            '*/ecommerce/orders'         => [['id' => 555], 200],
            '*/acceptance/payment_keys'  => [['token' => 'pk_1'], 200],
            '*/acceptance/payments/pay'  => [['id' => 999, 'success' => true, 'amount_cents' => 100, 'currency' => 'EGP'], 200],
        ]);

        $response = $this->makeDriver()->saveCard(new SaveCardRequest(
            token: new Token('one_time_token'),
            customerId: CustomerId::fromString('host-customer-1'),
            idempotencyKey: 'idem-savecard-001',
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertInstanceOf(CardSaved::class, $this->events->dispatched[0]);
    }

    /** @test */
    public function test_charge_token_success_dispatches_token_charged(): void
    {
        $this->fakeHttp([
            '*/auth/tokens'              => [['token' => 'auth_1'], 200],
            '*/ecommerce/orders'         => [['id' => 555], 200],
            '*/acceptance/payment_keys'  => [['token' => 'pk_1'], 200],
            '*/acceptance/payments/pay'  => [['id' => 999, 'success' => true, 'amount_cents' => 1000, 'currency' => 'EGP'], 200],
        ]);

        $response = $this->makeDriver()->chargeToken(new TokenChargeRequest(
            token: new Token('saved_token'),
            amount: Money::ofMinor(1000, Currency::EGP),
            currency: Currency::EGP,
            idempotencyKey: 'idem-chargetoken-001',
            customer: new CustomerData('Mohamed Azmy', 'azmy@example.com'),
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertInstanceOf(TokenCharged::class, $this->events->dispatched[0]);
    }

    // =========================================================================
    // createPaymentLink()
    // =========================================================================

    /** @test */
    public function test_create_payment_link_returns_the_iframe_url(): void
    {
        $this->fakeHttp([
            '*/auth/tokens'              => [['token' => 'auth_1'], 200],
            '*/ecommerce/orders'         => [['id' => 555], 200],
            '*/acceptance/payment_keys'  => [['token' => 'pk_1'], 200],
        ]);

        $response = $this->makeDriver()->createPaymentLink(new PaymentLinkRequest(
            amount: Money::ofMinor(10000, Currency::EGP),
            currency: Currency::EGP,
            description: 'Sandbox test payment',
            customer: new CustomerData('Mohamed Azmy', 'azmy@example.com'),
            returnUrl: null,
            cancelUrl: null,
            expiresAt: null,
            idempotencyKey: 'idem-paylink-001',
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame(
            'https://accept.paymob.com/api/acceptance/iframes/999?payment_token=pk_1',
            $response->getPaymentUrl(),
        );
        $this->assertInstanceOf(PaymentLinkCreated::class, $this->events->dispatched[0]);
    }

    // =========================================================================
    // createSdkIntent()
    // =========================================================================

    /** @test */
    public function test_driver_implements_supports_sdk_checkout(): void
    {
        $this->assertInstanceOf(SupportsSdkCheckout::class, $this->makeDriver());
    }

    /** @test */
    public function test_create_sdk_intent_returns_the_payment_key_as_client_secret_in_egypt_mode(): void
    {
        $this->fakeHttp([
            '*/auth/tokens'              => [['token' => 'auth_1'], 200],
            '*/ecommerce/orders'         => [['id' => 555], 200],
            '*/acceptance/payment_keys'  => [['token' => 'pk_1'], 200],
        ]);

        $response = $this->makeDriver()->createSdkIntent(new PaymentLinkRequest(
            amount: Money::ofMinor(10000, Currency::EGP),
            currency: Currency::EGP,
            description: 'Sandbox test payment',
            customer: new CustomerData('Mohamed Azmy', 'azmy@example.com'),
            returnUrl: null,
            cancelUrl: null,
            expiresAt: null,
            idempotencyKey: 'idem-sdk-001',
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('pk_1', $response->getClientSecret());
        $this->assertSame('555', $response->getTransactionReference());
        $this->assertNull($response->getPublishableKey());
    }

    /** @test */
    public function test_create_sdk_intent_returns_the_intention_client_secret_in_ksa_mode(): void
    {
        $this->fakeHttp([
            '*/v1/intention/' => [
                ['client_secret' => 'sau_csk_test_001', 'intention_order_id' => 777],
                200,
            ],
        ]);

        $response = $this->makeKsaDriver()->createSdkIntent(new PaymentLinkRequest(
            amount: Money::ofMinor(10000, Currency::SAR),
            currency: Currency::SAR,
            description: 'Sandbox test payment',
            customer: new CustomerData('Mohamed Azmy', 'azmy@example.com'),
            returnUrl: null,
            cancelUrl: null,
            expiresAt: null,
            idempotencyKey: 'idem-sdk-ksa-001',
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('sau_csk_test_001', $response->getClientSecret());
        $this->assertSame('777', $response->getTransactionReference());
        $this->assertSame('sau_pk_test_001', $response->getPublishableKey());
    }

    // =========================================================================
    // Subscriptions — genuinely unsupported, not faked
    // =========================================================================

    /** @test */
    public function test_create_subscription_throws_unsupported_operation_exception(): void
    {
        $this->expectException(UnsupportedOperationException::class);
        $this->expectExceptionMessageMatches('/paymob.*createSubscription/');

        $this->makeDriver()->createSubscription(new SubscriptionRequest(
            amount: Money::ofMinor(1000, Currency::EGP),
            currency: Currency::EGP,
            interval: 'monthly',
            intervalCount: 1,
            trialDays: null,
            customer: new CustomerData('Mohamed Azmy', 'azmy@example.com'),
            planId: null,
            idempotencyKey: 'idem-sub-001',
        ));
    }

    /** @test */
    public function test_cancel_subscription_throws_unsupported_operation_exception(): void
    {
        $this->expectException(UnsupportedOperationException::class);

        $this->makeDriver()->cancelSubscription(new CancelSubscriptionRequest(
            subscriptionId: TransactionId::fromString('sub_never'),
            idempotencyKey: 'idem-cancelsub-001',
        ));
    }

    /** @test */
    public function test_supports_reports_subscription_as_unsupported(): void
    {
        $this->assertFalse($this->makeDriver()->supports('subscription'));
        $this->assertTrue($this->makeDriver()->supports('refund'));
    }
}

/**
 * Minimal event dispatcher test double that records every dispatched event
 * in call order.
 *
 * Duplicated (and renamed) matching the Stripe driver test files' own
 * explicit convention — every test file in this package is self-contained.
 */
final class PaymobDriverRecordingDispatcher implements Dispatcher
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
