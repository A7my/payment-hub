<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Mifatoyeh\LaravelPaymentFramework\Checkout\CheckoutContext;
use Mifatoyeh\LaravelPaymentFramework\Checkout\CheckoutTransaction;
use Mifatoyeh\LaravelPaymentFramework\Concerns\IsPayable;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\PaymentDriverContract;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\SupportsSdkCheckout;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Payable;
use Mifatoyeh\LaravelPaymentFramework\DTO\CancelSubscriptionRequest;
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
use Mifatoyeh\LaravelPaymentFramework\Events\CheckoutPaymentConfirmed;
use Mifatoyeh\LaravelPaymentFramework\Managers\PaymentManager;
use Mifatoyeh\LaravelPaymentFramework\Providers\PaymentServiceProvider;
use Mifatoyeh\LaravelPaymentFramework\Responses\CaptureResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentLinkResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\RefundResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\SdkCheckoutResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\StatusResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\SubscriptionResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\VerificationResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\VoidResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\WebhookResponse;
use Orchestra\Testbench\TestCase;

/**
 * Feature tests for `driver_type: sdk` and `POST {route}/confirm` — the two
 * pieces added on top of the already-covered webview checkout flow (see
 * {@see CheckoutControllerTest}).
 *
 * Uses a hand-written {@see FakeSdkCapableDriver} test double registered via
 * `PaymentManager::extend()` (NOT `Payment::fake()` — that stub always
 * returns the same `FakePaymentDriver`, which deliberately does NOT
 * implement {@see SupportsSdkCheckout}, so it cannot exercise the sdk-mode
 * success path this file tests). `extend()`-registered drivers are never
 * wrapped in `PaymentDriverProxy` (see that class's own docblock), so
 * `instanceof SupportsSdkCheckout` is checked directly against it —
 * confirming `CheckoutService::unwrap()`'s "already unwrapped" branch, the
 * opposite branch from the config-resolved-driver case covered implicitly
 * by every other Feature test in this package.
 */
final class CheckoutSdkConfirmTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PaymentServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        $app['config']->set('payment.payables', [
            'order' => CheckoutSdkTestOrder::class,
        ]);

        $app['config']->set('payment.checkout.middleware', ['web']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('checkout_sdk_test_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedInteger('amount');
            $table->string('currency');
            $table->timestamps();
        });

        // Reuses the package's own migration file directly so this test
        // stays in sync with the real checkout_transactions schema.
        (require __DIR__ . '/../../database/migrations/2024_01_02_000000_create_checkout_transactions_table.php')->up();

        $this->app->make(PaymentManager::class)->extend('fake_sdk', fn () => new FakeSdkCapableDriver());

        CheckoutSdkTestOrder::$callbackInvocations = [];
    }

    private function makeOrder(int $amount = 1000, string $currency = 'USD', ?int $userId = 1): CheckoutSdkTestOrder
    {
        return CheckoutSdkTestOrder::create([
            'user_id'  => $userId,
            'amount'   => $amount,
            'currency' => $currency,
        ]);
    }

    // =========================================================================
    // driver_type: sdk
    // =========================================================================

    /** @test */
    public function test_sdk_checkout_against_a_capable_driver_returns_client_secret(): void
    {
        $order = $this->makeOrder();

        $response = $this->actingAs(new CheckoutSdkTestUser(1))->postJson('/payment/checkout', [
            'model_type'  => 'order',
            'model_id'    => (string) $order->id,
            'driver'      => 'fake_sdk',
            'driver_type' => 'sdk',
            'os'          => 'mobile',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status'                => 'success',
            'driver_type'           => 'sdk',
            'transaction_reference' => 'fake-sdk-txn-001',
            'client_secret'         => 'fake_client_secret_001',
            'publishable_key'       => 'fake_pk_001',
        ]);
    }

    // =========================================================================
    // POST {route}/confirm
    // =========================================================================

    /** @test */
    public function test_confirm_persists_a_checkout_transaction_and_fires_events(): void
    {
        Event::fake([CheckoutPaymentConfirmed::class]);
        $order = $this->makeOrder();

        $response = $this->actingAs(new CheckoutSdkTestUser(1))->postJson('/payment/checkout/confirm', [
            'model_type'             => 'order',
            'model_id'               => (string) $order->id,
            'driver'                 => 'fake_sdk',
            'transaction_reference'  => 'fake-sdk-txn-001',
            'driver_type'            => 'sdk',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status'         => 'success',
            'payment_status' => PaymentStatus::Captured->value,
            'transaction_id' => 'fake-sdk-txn-001',
        ]);

        $this->assertDatabaseCount('checkout_transactions', 1);
        $this->assertDatabaseHas('checkout_transactions', [
            'model_type'             => 'order',
            'model_id'               => (string) $order->id,
            'driver'                 => 'fake_sdk',
            'driver_type'            => 'sdk',
            'transaction_reference'  => 'fake-sdk-txn-001',
            'status'                 => PaymentStatus::Captured->value,
            'successful'             => 1,
            'amount'                 => $order->amount,
            'currency'               => $order->currency,
        ]);

        $this->assertCount(1, CheckoutSdkTestOrder::$callbackInvocations);
        $this->assertSame(PaymentStatus::Captured, CheckoutSdkTestOrder::$callbackInvocations[0]->getStatus());

        Event::assertDispatched(CheckoutPaymentConfirmed::class, fn (CheckoutPaymentConfirmed $event): bool => $event->modelType === 'order'
            && $event->payable->getKey() === $order->id
            && $event->status->getStatus() === PaymentStatus::Captured);
    }

    /** @test */
    public function test_confirming_the_same_transaction_twice_updates_rather_than_duplicates(): void
    {
        $order = $this->makeOrder();

        $payload = [
            'model_type'            => 'order',
            'model_id'              => (string) $order->id,
            'driver'                => 'fake_sdk',
            'transaction_reference' => 'fake-sdk-txn-001',
        ];

        $this->actingAs(new CheckoutSdkTestUser(1))->postJson('/payment/checkout/confirm', $payload)->assertStatus(200);
        $this->actingAs(new CheckoutSdkTestUser(1))->postJson('/payment/checkout/confirm', $payload)->assertStatus(200);

        $this->assertDatabaseCount('checkout_transactions', 1);
        $this->assertCount(2, CheckoutSdkTestOrder::$callbackInvocations);
    }

    /** @test */
    public function test_confirm_is_not_persisted_when_persist_transactions_is_disabled(): void
    {
        $this->app['config']->set('payment.checkout.persist_transactions', false);
        $order = $this->makeOrder();

        $this->actingAs(new CheckoutSdkTestUser(1))->postJson('/payment/checkout/confirm', [
            'model_type'            => 'order',
            'model_id'              => (string) $order->id,
            'driver'                => 'fake_sdk',
            'transaction_reference' => 'fake-sdk-txn-001',
        ])->assertStatus(200);

        $this->assertDatabaseCount('checkout_transactions', 0);
        // The callback/event still fire — persistence is independent of them.
        $this->assertCount(1, CheckoutSdkTestOrder::$callbackInvocations);
    }

    /** @test */
    public function test_confirm_rejects_an_unauthorized_payer(): void
    {
        $order = $this->makeOrder(userId: 1);

        $response = $this->actingAs(new CheckoutSdkTestUser(2))->postJson('/payment/checkout/confirm', [
            'model_type'            => 'order',
            'model_id'              => (string) $order->id,
            'driver'                => 'fake_sdk',
            'transaction_reference' => 'fake-sdk-txn-001',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('checkout_transactions', 0);
    }
}

/**
 * Test-only Payable Eloquent model — records every `onPaymentCompleted()`
 * call statically so tests can assert on it without a second round-trip
 * through the database. (onPaymentCompleted() is part of Payable itself,
 * not a separate interface.)
 */
final class CheckoutSdkTestOrder extends Model implements Payable
{
    use IsPayable;

    /** @var array<int, StatusResponse> */
    public static array $callbackInvocations = [];

    protected $table = 'checkout_sdk_test_orders';

    protected $guarded = [];

    public function getSupportedPaymentDrivers(): array
    {
        return ['fake_sdk'];
    }

    public function authorizePayment(?Authenticatable $payer): bool
    {
        return $payer !== null && (int) $payer->getAuthIdentifier() === (int) $this->user_id;
    }

    public function onPaymentCompleted(StatusResponse $status, CheckoutContext $context): void
    {
        self::$callbackInvocations[] = $status;
    }
}

/**
 * Minimal Authenticatable test double — mirrors CheckoutControllerTest's own.
 */
final class CheckoutSdkTestUser implements Authenticatable
{
    public function __construct(
        private readonly int $id,
    ) {
    }

    public function getAuthIdentifierName()
    {
        return 'id';
    }

    public function getAuthIdentifier()
    {
        return $this->id;
    }

    public function getAuthPasswordName()
    {
        return 'password';
    }

    public function getAuthPassword()
    {
        return '';
    }

    public function getRememberToken()
    {
        return null;
    }

    public function setRememberToken($value)
    {
    }

    public function getRememberTokenName()
    {
        return '';
    }
}

/**
 * Minimal PaymentDriverContract + SupportsSdkCheckout test double.
 *
 * Only createSdkIntent() and lookup() have meaningful behaviour — everything
 * else throws, since nothing in this file exercises them.
 */
final class FakeSdkCapableDriver implements PaymentDriverContract, SupportsSdkCheckout
{
    public function createSdkIntent(PaymentLinkRequest $request): SdkCheckoutResponse
    {
        return new SdkCheckoutResponse(
            successful: true,
            transactionReference: 'fake-sdk-txn-001',
            clientSecret: 'fake_client_secret_001',
            publishableKey: 'fake_pk_001',
            message: 'Fake SDK intent created.',
            rawResponse: ['fake' => true],
        );
    }

    public function lookup(TransactionLookupRequest $request): StatusResponse
    {
        return new StatusResponse(
            successful: true,
            transactionId: $request->transactionId,
            status: PaymentStatus::Captured,
            message: 'Fake lookup successful.',
            rawResponse: ['fake' => true],
        );
    }

    public function authorize(PaymentRequest $request): PaymentResponse
    {
        throw new \LogicException('Not needed for this test double.');
    }

    public function capture(CaptureRequest $request): CaptureResponse
    {
        throw new \LogicException('Not needed for this test double.');
    }

    public function charge(PaymentRequest $request): PaymentResponse
    {
        throw new \LogicException('Not needed for this test double.');
    }

    public function void(VoidRequest $request): VoidResponse
    {
        throw new \LogicException('Not needed for this test double.');
    }

    public function refund(RefundRequest $request): RefundResponse
    {
        throw new \LogicException('Not needed for this test double.');
    }

    public function partialRefund(RefundRequest $request): RefundResponse
    {
        throw new \LogicException('Not needed for this test double.');
    }

    public function verify(TransactionLookupRequest $request): VerificationResponse
    {
        throw new \LogicException('Not needed for this test double.');
    }

    public function createPaymentLink(PaymentLinkRequest $request): PaymentLinkResponse
    {
        throw new \LogicException('Not needed for this test double.');
    }

    public function saveCard(SaveCardRequest $request): PaymentResponse
    {
        throw new \LogicException('Not needed for this test double.');
    }

    public function chargeToken(TokenChargeRequest $request): PaymentResponse
    {
        throw new \LogicException('Not needed for this test double.');
    }

    public function createSubscription(SubscriptionRequest $request): SubscriptionResponse
    {
        throw new \LogicException('Not needed for this test double.');
    }

    public function cancelSubscription(CancelSubscriptionRequest $request): SubscriptionResponse
    {
        throw new \LogicException('Not needed for this test double.');
    }

    public function processWebhook(WebhookRequest $request): WebhookResponse
    {
        throw new \LogicException('Not needed for this test double.');
    }

    public function verifyWebhookSignature(WebhookRequest $request): bool
    {
        throw new \LogicException('Not needed for this test double.');
    }
}
