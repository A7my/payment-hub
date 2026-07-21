<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Mifatoyeh\LaravelPaymentFramework\Checkout\CheckoutContext;
use Mifatoyeh\LaravelPaymentFramework\Checkout\CheckoutTransaction;
use Mifatoyeh\LaravelPaymentFramework\Concerns\IsPayable;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Payable;
use Mifatoyeh\LaravelPaymentFramework\Drivers\Paymob\PaymobClient;
use Mifatoyeh\LaravelPaymentFramework\Drivers\Paymob\PaymobWebhookVerifier;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\Events\CheckoutPaymentConfirmed;
use Mifatoyeh\LaravelPaymentFramework\Providers\PaymentServiceProvider;
use Mifatoyeh\LaravelPaymentFramework\Responses\StatusResponse;
use Orchestra\Testbench\TestCase;

/**
 * End-to-end coverage of the exact scenario this whole feature exists for:
 * a real Paymob checkout (`driver: paymob`), followed by Paymob's own
 * server-to-server "Transaction Processed Callback" GET request — the same
 * shape reported against this package's real production traffic — getting
 * automatically confirmed with NO frontend "confirm" call and NO custom
 * route needed in the host app.
 *
 * `PaymobClient::setTestHttpFactory()` is used (not `Http::fake()`) because
 * PaymobClient always constructs its own `Illuminate\Http\Client\Factory`
 * internally when no override is set — same seam every other Paymob test in
 * this package uses. One fake factory covers both HTTP call sequences this
 * test triggers: checkout()'s order/payment-key creation, and the later
 * webhook-triggered lookup().
 */
final class PaymobWebhookCheckoutTest extends TestCase
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
            'order' => PaymobWebhookTestOrder::class,
        ]);

        $app['config']->set('payment.checkout.middleware', ['web']);
        $app['config']->set('payment.webhook.middleware', ['api']);

        $app['config']->set('payment.drivers.paymob', [
            'class'          => \Mifatoyeh\LaravelPaymentFramework\Drivers\Paymob\PaymobDriver::class,
            'api_key'        => 'test-api-key',
            'integration_id' => 30075,
            'iframe_id'      => '999',
            'hmac_secret'    => 'whsec_test_secret',
            'base_url'       => 'https://accept.paymob.com/api',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('paymob_webhook_test_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedInteger('amount');
            $table->string('currency');
            $table->timestamps();
        });

        (require __DIR__ . '/../../database/migrations/2024_01_02_000000_create_checkout_transactions_table.php')->up();

        PaymobWebhookTestOrder::$callbackInvocations = [];
    }

    protected function tearDown(): void
    {
        PaymobClient::setTestHttpFactory(null);

        parent::tearDown();
    }

    private function makeOrder(int $amount = 10000, string $currency = 'EGP', ?int $userId = 1): PaymobWebhookTestOrder
    {
        return PaymobWebhookTestOrder::create([
            'user_id'  => $userId,
            'amount'   => $amount,
            'currency' => $currency,
        ]);
    }

    /** @param array<string, array{0: array<string, mixed>, 1: int}> $responses */
    private function fakeHttp(array $responses): void
    {
        $http  = new HttpFactory();
        $fakes = [];

        foreach ($responses as $pattern => [$body, $status]) {
            $fakes[$pattern] = $http::response($body, $status);
        }

        $http->fake($fakes);

        PaymobClient::setTestHttpFactory($http);
    }

    /** @return array<string, mixed> */
    private function webhookPayload(string $merchantOrderId, array $overrides = []): array
    {
        return array_merge([
            'amount_cents'            => '10000',
            'created_at'              => '2026-07-19T12:47:16.351233+03:00',
            'currency'                => 'EGP',
            'error_occured'           => 'false',
            'has_parent_transaction'  => 'false',
            'id'                      => '7773107',
            'integration_id'          => '30075',
            'is_3d_secure'            => 'true',
            'is_auth'                 => 'false',
            'is_capture'              => 'false',
            'is_refunded'             => 'false',
            'is_standalone_payment'   => 'true',
            'is_voided'               => 'false',
            'order'                   => '6976637',
            'owner'                   => '23938',
            'pending'                 => 'false',
            'source_data.pan'         => '1111',
            'source_data.sub_type'    => 'Visa',
            'source_data.type'        => 'card',
            'success'                 => 'true',
            'merchant_order_id'       => $merchantOrderId,
            'data.message'            => 'Approved',
        ], $overrides);
    }

    private function signedWebhookQuery(array $payload): string
    {
        $hmac = (new PaymobWebhookVerifier(['hmac_secret' => 'whsec_test_secret']))
            ->compute($payload, 'whsec_test_secret');

        return http_build_query([...$payload, 'hmac' => $hmac]);
    }

    /** @test */
    public function test_paymob_webhook_automatically_confirms_a_checkout_with_no_frontend_call(): void
    {
        Event::fake([CheckoutPaymentConfirmed::class]);

        $this->fakeHttp([
            '*/auth/tokens'             => [['token' => 'auth_1'], 200],
            '*/ecommerce/orders'        => [['id' => 555], 200],
            '*/acceptance/payment_keys' => [['token' => 'pk_1'], 200],
            '*/acceptance/transactions/*' => [
                [
                    'id'           => 7773107,
                    'success'      => true,
                    'pending'      => false,
                    'is_voided'    => false,
                    'is_refunded'  => false,
                    'is_auth'      => false,
                    'is_capture'   => true,
                    'amount_cents' => 10000,
                    'currency'     => 'EGP',
                    'order'        => ['id' => 6976637],
                ],
                200,
            ],
        ]);

        $order = $this->makeOrder();

        $checkoutResponse = $this->actingAs(new PaymobWebhookTestUser(1))->postJson('/payment/checkout', [
            'model_type'  => 'order',
            'model_id'    => (string) $order->id,
            'driver'      => 'paymob',
            'driver_type' => 'webview',
            'os'          => 'mobile',
        ]);
        $checkoutResponse->assertStatus(200);

        // checkout() persisted a PENDING row keyed by the merchant_order_id
        // it generated internally and forwarded to Paymob as the order
        // reference — that's the only thing a webhook can correlate by.
        $pending = CheckoutTransaction::query()->where('model_type', 'order')->where('model_id', (string) $order->id)->first();
        $this->assertNotNull($pending);
        $this->assertSame(PaymentStatus::Pending->value, $pending->status);
        $this->assertNotEmpty($pending->merchant_order_id);

        $query = $this->signedWebhookQuery($this->webhookPayload($pending->merchant_order_id));

        $webhookResponse = $this->get('/payment/webhook/paymob?' . $query);
        $webhookResponse->assertStatus(200);

        $pending->refresh();
        $this->assertSame(PaymentStatus::Captured->value, $pending->status);
        $this->assertSame('7773107', $pending->transaction_reference);
        $this->assertTrue((bool) $pending->successful);

        $this->assertCount(1, PaymobWebhookTestOrder::$callbackInvocations);
        $this->assertSame(PaymentStatus::Captured, PaymobWebhookTestOrder::$callbackInvocations[0]->getStatus());

        Event::assertDispatched(CheckoutPaymentConfirmed::class, fn (CheckoutPaymentConfirmed $event): bool => $event->modelType === 'order'
            && $event->payable->getKey() === $order->id);
    }

    /** @test */
    public function test_paymob_webhook_with_an_invalid_hmac_is_rejected_and_does_not_confirm(): void
    {
        $this->fakeHttp([
            '*/auth/tokens'             => [['token' => 'auth_1'], 200],
            '*/ecommerce/orders'        => [['id' => 555], 200],
            '*/acceptance/payment_keys' => [['token' => 'pk_1'], 200],
        ]);

        $order = $this->makeOrder();

        $this->actingAs(new PaymobWebhookTestUser(1))->postJson('/payment/checkout', [
            'model_type'  => 'order',
            'model_id'    => (string) $order->id,
            'driver'      => 'paymob',
            'driver_type' => 'webview',
            'os'          => 'mobile',
        ])->assertStatus(200);

        $pending = CheckoutTransaction::query()->where('model_type', 'order')->where('model_id', (string) $order->id)->first();

        $payload = $this->webhookPayload($pending->merchant_order_id);
        $query   = http_build_query([...$payload, 'hmac' => 'not-a-real-hmac']);

        $this->get('/payment/webhook/paymob?' . $query)->assertStatus(400);

        $pending->refresh();
        $this->assertSame(PaymentStatus::Pending->value, $pending->status);
        $this->assertCount(0, PaymobWebhookTestOrder::$callbackInvocations);
    }

    /** @test */
    public function test_paymob_webhook_for_an_unknown_merchant_order_id_is_accepted_but_does_nothing(): void
    {
        $query = $this->signedWebhookQuery($this->webhookPayload('no-such-checkout-attempt'));

        $response = $this->get('/payment/webhook/paymob?' . $query);

        $response->assertStatus(200);
        $this->assertSame(0, CheckoutTransaction::query()->count());
        $this->assertCount(0, PaymobWebhookTestOrder::$callbackInvocations);
    }
}

/**
 * Test-only Payable Eloquent model (onPaymentCompleted() is now part of
 * Payable itself, not a separate interface).
 */
final class PaymobWebhookTestOrder extends Model implements Payable
{
    use IsPayable;

    /** @var array<int, StatusResponse> */
    public static array $callbackInvocations = [];

    protected $table = 'paymob_webhook_test_orders';

    protected $guarded = [];

    public function getSupportedPaymentDrivers(): array
    {
        return ['paymob'];
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
final class PaymobWebhookTestUser implements Authenticatable
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
