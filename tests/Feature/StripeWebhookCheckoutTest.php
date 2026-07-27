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
use Mifatoyeh\LaravelPaymentFramework\Contracts\Payable;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\Events\CheckoutPaymentConfirmed;
use Mifatoyeh\LaravelPaymentFramework\Providers\PaymentServiceProvider;
use Mifatoyeh\LaravelPaymentFramework\Responses\StatusResponse;
use Orchestra\Testbench\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * End-to-end coverage of a real Stripe checkout (`driver: stripe`, `driver_type:
 * webview`), followed by Stripe's genuine server-to-server webhook
 * (`checkout.session.completed`) — mirroring
 * {@see PaymobWebhookCheckoutTest}'s shape for Paymob, now that
 * {@see \Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeDriver::processWebhook()}/
 * {@see \Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeDriver::verifyWebhookSignature()}
 * are genuinely implemented (were `// TODO` stubs before).
 *
 * Unlike Paymob, Stripe does NOT implement `SupportsTrustedWebhookStatus` —
 * `CheckoutService::resolveAndConfirm()` always falls through to a live
 * `lookup()` call for Stripe, so the Stripe SDK's HTTP transport is faked
 * TWICE via `ApiRequestor::setHttpClient()`: once for `checkout()`'s own
 * Checkout Session creation, and again (a fresh fake, swapped in after the
 * pending row exists) for the webhook-triggered `lookup()` — a Checkout
 * Session id (`cs_...`) resolved via its expanded PaymentIntent, same
 * mechanic already covered unit-level by `StripeClientTest`.
 */
final class StripeWebhookCheckoutTest extends TestCase
{
    private const WEBHOOK_SECRET = 'whsec_test_stripe_secret';

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
            'order' => StripeWebhookTestOrder::class,
        ]);

        $app['config']->set('payment.checkout.middleware', ['web']);
        $app['config']->set('payment.webhook.middleware', ['api']);

        $app['config']->set('payment.drivers.stripe', [
            'class'          => \Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeDriver::class,
            'secret'         => 'sk_test_dummy_key',
            'webhook_secret' => self::WEBHOOK_SECRET,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('stripe_webhook_test_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedInteger('amount');
            $table->string('currency');
            $table->timestamps();
        });

        (require __DIR__ . '/../../database/migrations/2024_01_02_000000_create_checkout_transactions_table.php')->up();

        StripeWebhookTestOrder::$callbackInvocations = [];
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    private function makeOrder(int $amount = 4000, string $currency = 'USD', ?int $userId = 1): StripeWebhookTestOrder
    {
        return StripeWebhookTestOrder::create([
            'user_id'  => $userId,
            'amount'   => $amount,
            'currency' => $currency,
        ]);
    }

    /** @param array{0: string, 1: int, 2: array<int, string>} $response */
    private function fakeStripeResponse(array $response): void
    {
        ApiRequestor::setHttpClient(new SingleResponseStripeHttpClient($response));
    }

    private function stripeJsonResponse(int $status, array $body): array
    {
        return [json_encode($body, JSON_THROW_ON_ERROR), $status, []];
    }

    private function signatureHeader(string $payload, int $timestamp): string
    {
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", self::WEBHOOK_SECRET);

        return "t={$timestamp},v1={$signature}";
    }

    /** @test */
    public function test_stripe_webhook_automatically_confirms_a_checkout_with_no_frontend_call(): void
    {
        Event::fake([CheckoutPaymentConfirmed::class]);

        // Step 1: checkout() creates a real Checkout Session.
        $this->fakeStripeResponse($this->stripeJsonResponse(200, [
            'id'  => 'cs_test_checkout_001',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_checkout_001',
        ]));

        $order = $this->makeOrder();

        $this->actingAs(new StripeWebhookTestUser(1))->postJson('/payment/checkout', [
            'model_type'  => 'order',
            'model_id'    => (string) $order->id,
            'driver'      => 'stripe',
            'driver_type' => 'webview',
            'os'          => 'mobile',
        ])->assertStatus(200);

        $pending = CheckoutTransaction::query()->where('model_type', 'order')->where('model_id', (string) $order->id)->first();
        $this->assertNotNull($pending);
        $this->assertSame(PaymentStatus::Pending->value, $pending->status);
        $this->assertNotEmpty($pending->merchant_order_id);

        // Step 2: swap in a fresh fake for the webhook-triggered lookup() —
        // a real integration would hit a genuinely different Stripe API
        // call at this point, so a single queued response can't cover both.
        $this->fakeStripeResponse($this->stripeJsonResponse(200, [
            'id'             => 'cs_test_checkout_001',
            'object'         => 'checkout.session',
            'payment_intent' => [
                'id'       => 'pi_test_from_webhook_001',
                'object'   => 'payment_intent',
                'status'   => 'succeeded',
                'amount'   => 4000,
                'currency' => 'usd',
            ],
        ]));

        $eventPayload = json_encode([
            'id'     => 'evt_test_checkout_001',
            'object' => 'event',
            'type'   => 'checkout.session.completed',
            'data'   => [
                'object' => [
                    'id'             => 'cs_test_checkout_001',
                    'payment_status' => 'paid',
                    'metadata'       => ['merchant_order_id' => $pending->merchant_order_id],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $timestamp = time();

        $webhookResponse = $this->call(
            'POST',
            '/payment/webhook/stripe',
            [],
            [],
            [],
            ['HTTP_STRIPE_SIGNATURE' => $this->signatureHeader($eventPayload, $timestamp), 'CONTENT_TYPE' => 'application/json'],
            $eventPayload,
        );

        $webhookResponse->assertStatus(200);

        $pending->refresh();
        $this->assertSame(PaymentStatus::Captured->value, $pending->status);
        $this->assertSame('pi_test_from_webhook_001', $pending->transaction_reference);
        $this->assertTrue((bool) $pending->successful);

        $this->assertCount(1, StripeWebhookTestOrder::$callbackInvocations);
        $this->assertSame(PaymentStatus::Captured, StripeWebhookTestOrder::$callbackInvocations[0]->getStatus());

        Event::assertDispatched(CheckoutPaymentConfirmed::class, fn (CheckoutPaymentConfirmed $event): bool => $event->modelType === 'order'
            && $event->payable->getKey() === $order->id);
    }

    /** @test */
    public function test_stripe_webhook_with_an_invalid_signature_is_rejected_and_does_not_confirm(): void
    {
        $this->fakeStripeResponse($this->stripeJsonResponse(200, [
            'id'  => 'cs_test_checkout_002',
            'url' => 'https://checkout.stripe.com/c/pay/cs_test_checkout_002',
        ]));

        $order = $this->makeOrder();

        $this->actingAs(new StripeWebhookTestUser(1))->postJson('/payment/checkout', [
            'model_type'  => 'order',
            'model_id'    => (string) $order->id,
            'driver'      => 'stripe',
            'driver_type' => 'webview',
            'os'          => 'mobile',
        ])->assertStatus(200);

        $pending = CheckoutTransaction::query()->where('model_type', 'order')->where('model_id', (string) $order->id)->first();

        $eventPayload = json_encode([
            'id'   => 'evt_test_checkout_002',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id'             => 'cs_test_checkout_002',
                'payment_status' => 'paid',
                'metadata'       => ['merchant_order_id' => $pending->merchant_order_id],
            ]],
        ], JSON_THROW_ON_ERROR);

        // Signed with the WRONG secret — a forged/unauthorised sender.
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$eventPayload}", 'whsec_wrong_secret');

        $webhookResponse = $this->call(
            'POST',
            '/payment/webhook/stripe',
            [],
            [],
            [],
            ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}", 'CONTENT_TYPE' => 'application/json'],
            $eventPayload,
        );

        $webhookResponse->assertStatus(400);

        $pending->refresh();
        $this->assertSame(PaymentStatus::Pending->value, $pending->status);
        $this->assertCount(0, StripeWebhookTestOrder::$callbackInvocations);
    }
}

/**
 * Test-only Payable Eloquent model.
 */
final class StripeWebhookTestOrder extends Model implements Payable
{
    use IsPayable;

    /** @var array<int, StatusResponse> */
    public static array $callbackInvocations = [];

    protected $table = 'stripe_webhook_test_orders';

    protected $guarded = [];

    public function getSupportedPaymentDrivers(): array
    {
        return ['stripe'];
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
 * Minimal Authenticatable test double.
 */
final class StripeWebhookTestUser implements Authenticatable
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
 * Fake Stripe HTTP transport returning ONE fixed response for every request
 * — sufficient here since each phase of the test (checkout, then the
 * webhook-triggered lookup) swaps in its own fresh instance rather than
 * queuing multiple responses upfront.
 */
final class SingleResponseStripeHttpClient implements ClientInterface
{
    /** @param array{0: string, 1: int, 2: array<int, string>} $response */
    public function __construct(
        private readonly array $response,
    ) {
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        return $this->response;
    }
}
