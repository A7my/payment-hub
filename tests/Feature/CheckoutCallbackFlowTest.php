<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mifatoyeh\LaravelPaymentFramework\Checkout\CheckoutContext;
use Mifatoyeh\LaravelPaymentFramework\Checkout\CheckoutTransaction;
use Mifatoyeh\LaravelPaymentFramework\Concerns\IsPayable;
use Mifatoyeh\LaravelPaymentFramework\Contracts\CapturesCheckoutContext;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\PaymentDriverContract;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\SupportsCallbackHook;
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
use Mifatoyeh\LaravelPaymentFramework\Managers\PaymentManager;
use Mifatoyeh\LaravelPaymentFramework\Providers\PaymentServiceProvider;
use Mifatoyeh\LaravelPaymentFramework\Responses\CaptureResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentLinkResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\RefundResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\StatusResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\SubscriptionResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\VerificationResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\VoidResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\WebhookResponse;
use Orchestra\Testbench\TestCase;

/**
 * Feature tests for the new webview + os:web callback route
 * ({checkout.route}/callback/{driver} — see routes/callback.php and
 * CheckoutCallbackController) and the SupportsCallbackHook driver-level
 * extension point, using a hand-written fake driver
 * ({@see FakeWebviewCallbackDriver}) registered via `PaymentManager::extend()`
 * — same reasoning as {@see CheckoutSdkConfirmTest}'s own fake driver: this
 * needs a driver that actually implements `createPaymentLink()` and the new
 * `SupportsCallbackHook` interface, which neither built-in `FakePaymentDriver`
 * nor the sdk-only fake from that other test file provide.
 */
final class CheckoutCallbackFlowTest extends TestCase
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
            'order' => CheckoutCallbackTestOrder::class,
        ]);

        $app['config']->set('payment.checkout.middleware', ['web']);
        $app['config']->set('payment.checkout.callback_middleware', []);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('checkout_callback_test_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedInteger('amount');
            $table->string('currency');
            $table->timestamps();
        });

        (require __DIR__ . '/../../database/migrations/2024_01_02_000000_create_checkout_transactions_table.php')->up();

        $this->app->make(PaymentManager::class)->extend('fake_webview', fn () => new FakeWebviewCallbackDriver());

        FakeWebviewCallbackDriver::$hookCalls = [];
        CheckoutCallbackTestOrder::$callbackInvocations = [];
        CheckoutCallbackTestOrder::$contextsReceived    = [];
    }

    private function makeOrder(int $amount = 5000, string $currency = 'USD', ?int $userId = 1): CheckoutCallbackTestOrder
    {
        return CheckoutCallbackTestOrder::create(['user_id' => $userId, 'amount' => $amount, 'currency' => $currency]);
    }

    // =========================================================================
    // checkout() validation
    // =========================================================================

    /** @test */
    public function test_webview_web_checkout_without_return_url_is_rejected(): void
    {
        $order = $this->makeOrder();

        $response = $this->actingAs(new CheckoutCallbackTestUser(1))->postJson('/payment/checkout', [
            'model_type'  => 'order',
            'model_id'    => (string) $order->id,
            'driver'      => 'fake_webview',
            'driver_type' => 'webview',
            'os'          => 'web',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('return_url', (string) $response->json('message'));
    }

    /** @test */
    public function test_invalid_os_value_is_rejected(): void
    {
        $response = $this->postJson('/payment/checkout', [
            'model_type'  => 'order',
            'model_id'    => '1',
            'driver'      => 'fake_webview',
            'driver_type' => 'webview',
            'os'          => 'desktop',
        ]);

        $response->assertStatus(422);
    }

    // =========================================================================
    // The callback route itself
    // =========================================================================

    /** @test */
    public function test_webview_web_checkout_redirects_to_the_packages_own_callback_route(): void
    {
        $order = $this->makeOrder();

        $response = $this->actingAs(new CheckoutCallbackTestUser(1))->postJson('/payment/checkout', [
            'model_type'  => 'order',
            'model_id'    => (string) $order->id,
            'driver'      => 'fake_webview',
            'driver_type' => 'webview',
            'os'          => 'web',
            'return_url'  => 'https://merchant.example.com/thank-you',
            'cancel_url'  => 'https://merchant.example.com/cancelled',
        ]);

        $response->assertStatus(200);

        // The DRIVER never receives the merchant's own return_url — it
        // receives the package's own callback route instead (see
        // CheckoutService::buildCallbackUrl()). FakeWebviewCallbackDriver
        // records whatever PaymentLinkRequest it was given.
        $this->assertNotNull(FakeWebviewCallbackDriver::$lastRequest);
        $this->assertStringStartsWith(
            route('payment.checkout.callback', ['driver' => 'fake_webview']),
            (string) FakeWebviewCallbackDriver::$lastRequest->returnUrl,
        );
        $this->assertStringContainsString('merchant_order_id=', (string) FakeWebviewCallbackDriver::$lastRequest->returnUrl);

        // The merchant's real return_url is instead stored on the pending row.
        $pending = CheckoutTransaction::query()->where('model_type', 'order')->where('model_id', (string) $order->id)->first();
        $this->assertNotNull($pending);
        $this->assertSame('https://merchant.example.com/thank-you', $pending->metadata['return_url']);
        $this->assertSame('https://merchant.example.com/cancelled', $pending->metadata['cancel_url']);
        $this->assertSame('web', $pending->metadata['os']);
    }

    /** @test */
    public function test_callback_route_for_os_web_verifies_and_redirects_to_the_merchants_return_url(): void
    {
        $order = $this->makeOrder();

        $this->actingAs(new CheckoutCallbackTestUser(1))->postJson('/payment/checkout', [
            'model_type'  => 'order',
            'model_id'    => (string) $order->id,
            'driver'      => 'fake_webview',
            'driver_type' => 'webview',
            'os'          => 'web',
            'return_url'  => 'https://merchant.example.com/thank-you',
        ])->assertStatus(200);

        $pending = CheckoutTransaction::query()->where('model_type', 'order')->where('model_id', (string) $order->id)->first();

        $response = $this->get(
            '/payment/checkout/callback/fake_webview'
            . '?merchant_order_id=' . urlencode($pending->merchant_order_id)
            . '&session_id=cs_test_from_provider_001',
        );

        $response->assertStatus(302);
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://merchant.example.com/thank-you?', (string) $location);
        $this->assertStringContainsString('checkout_status=success', (string) $location);
        $this->assertStringContainsString('payment_status=captured', (string) $location);
        $this->assertStringContainsString('transaction_id=cs_test_from_provider_001', (string) $location);

        // Driver-level hook fired, with the raw callback payload and source.
        $this->assertCount(1, FakeWebviewCallbackDriver::$hookCalls);
        $this->assertSame('callback', FakeWebviewCallbackDriver::$hookCalls[0][1]);
        $this->assertSame($pending->merchant_order_id, FakeWebviewCallbackDriver::$hookCalls[0][0]['merchant_order_id']);

        // Model-level callback fired too.
        $this->assertCount(1, CheckoutCallbackTestOrder::$callbackInvocations);
        $this->assertSame(PaymentStatus::Captured, CheckoutCallbackTestOrder::$callbackInvocations[0]->getStatus());

        // The whole point of CheckoutContext: the payer id captured back in
        // the ORIGINAL authenticated checkout() request survives all the
        // way through to onPaymentCompleted(), even though THIS request
        // (the provider's own callback) has no authenticated user at all.
        $this->assertCount(1, CheckoutCallbackTestOrder::$contextsReceived);
        $context = CheckoutCallbackTestOrder::$contextsReceived[0];
        $this->assertSame('1', $context->payerId);
        $this->assertSame('fake_webview', $context->driver);
        $this->assertSame('webview', $context->driverType);
        $this->assertSame('web', $context->os);
        $this->assertSame($pending->merchant_order_id, $context->merchantOrderId);

        // CapturesCheckoutContext: snapshotted at checkout() time, read back
        // here even though this whole request is the provider's callback,
        // not the original authenticated one.
        $this->assertSame('ORDER-' . $order->id, $context->custom['sku']);
        $this->assertSame('captured at checkout time', $context->get('note'));
        $this->assertSame($pending->metadata['custom']['sku'], $context->custom['sku']);

        $pending->refresh();
        $this->assertSame(PaymentStatus::Captured->value, $pending->status);
        $this->assertSame('cs_test_from_provider_001', $pending->transaction_reference);
    }

    /** @test */
    public function test_callback_route_for_os_mobile_returns_json_matching_confirms_shape(): void
    {
        $order = $this->makeOrder();

        // driver_type webview + os mobile: ALSO routes through the
        // package's own callback route now (not just os: web) — closing a
        // real gap where a mobile webview checkout with no return_url would
        // otherwise either fail outright (Stripe requires success_url
        // unconditionally) or, if one WAS supplied, bypass verification
        // entirely by redirecting straight to it.
        $this->actingAs(new CheckoutCallbackTestUser(1))->postJson('/payment/checkout', [
            'model_type'  => 'order',
            'model_id'    => (string) $order->id,
            'driver'      => 'fake_webview',
            'driver_type' => 'webview',
            'os'          => 'mobile',
            // Deliberately no return_url — only required for webview+web.
        ])->assertStatus(200);

        // Proves the fix: the DRIVER received the package's own callback
        // URL, not a null/empty return_url.
        $this->assertNotNull(FakeWebviewCallbackDriver::$lastRequest);
        $this->assertStringStartsWith(
            route('payment.checkout.callback', ['driver' => 'fake_webview']),
            (string) FakeWebviewCallbackDriver::$lastRequest->returnUrl,
        );

        $pending = CheckoutTransaction::query()->where('model_type', 'order')->where('model_id', (string) $order->id)->first();
        $this->assertSame('mobile', $pending->metadata['os']);

        $response = $this->getJson(
            '/payment/checkout/callback/fake_webview'
            . '?merchant_order_id=' . urlencode($pending->merchant_order_id)
            . '&session_id=cs_test_mobile_001',
        );

        $response->assertStatus(200);
        $response->assertJson([
            'status'         => 'success',
            'payment_status' => PaymentStatus::Captured->value,
            'transaction_id' => 'cs_test_mobile_001',
        ]);
    }

    /** @test */
    public function test_callback_route_with_unknown_merchant_order_id_returns_404_json(): void
    {
        $response = $this->getJson('/payment/checkout/callback/fake_webview?merchant_order_id=no-such-id&session_id=cs_1');

        $response->assertStatus(404);
        $response->assertJson(['status' => 'fail']);
    }
}

/**
 * Test-only Payable model recording onPaymentCompleted() calls — also
 * implements CapturesCheckoutContext to prove the opt-in snapshot mechanism
 * flows through checkout() -> the pending row -> CheckoutContext.custom.
 */
final class CheckoutCallbackTestOrder extends Model implements Payable, CapturesCheckoutContext
{
    use IsPayable;

    /** @var array<int, StatusResponse> */
    public static array $callbackInvocations = [];

    /** @var array<int, CheckoutContext> */
    public static array $contextsReceived = [];

    protected $table = 'checkout_callback_test_orders';

    protected $guarded = [];

    public function getSupportedPaymentDrivers(): array
    {
        return ['fake_webview'];
    }

    public function authorizePayment(?Authenticatable $payer): bool
    {
        return $payer !== null && (int) $payer->getAuthIdentifier() === (int) $this->user_id;
    }

    public function onPaymentCompleted(StatusResponse $status, CheckoutContext $context): void
    {
        self::$callbackInvocations[] = $status;
        self::$contextsReceived[]    = $context;
    }

    public function captureCheckoutContext(): array
    {
        return ['sku' => 'ORDER-' . $this->id, 'note' => 'captured at checkout time'];
    }
}

final class CheckoutCallbackTestUser implements Authenticatable
{
    public function __construct(private readonly int $id)
    {
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
 * Fake driver supporting createPaymentLink() (webview) and lookup(), plus
 * SupportsCallbackHook — everything CheckoutCallbackFlowTest needs;
 * everything else throws, unused by these tests.
 */
final class FakeWebviewCallbackDriver implements PaymentDriverContract, SupportsCallbackHook
{
    public static ?PaymentLinkRequest $lastRequest = null;

    /** @var array<int, array{0: array<string, mixed>, 1: string}> */
    public static array $hookCalls = [];

    public function createPaymentLink(PaymentLinkRequest $request): PaymentLinkResponse
    {
        self::$lastRequest = $request;

        return new PaymentLinkResponse(
            successful: true,
            paymentUrl: 'https://fake-provider.example.com/pay/xyz',
            linkId: 'link_001',
            expiresAt: null,
            message: 'Payment link created.',
            rawResponse: [],
        );
    }

    public function lookup(TransactionLookupRequest $request): StatusResponse
    {
        return new StatusResponse(
            successful: true,
            transactionId: $request->transactionId,
            status: PaymentStatus::Captured,
            message: 'Captured.',
            rawResponse: ['fake' => true],
        );
    }

    public function onCallbackReceived(array $rawPayload, string $source): void
    {
        self::$hookCalls[] = [$rawPayload, $source];
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
