<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Schema;
use Mifatoyeh\LaravelPaymentFramework\Checkout\CheckoutContext;
use Mifatoyeh\LaravelPaymentFramework\Checkout\CheckoutTransaction;
use Mifatoyeh\LaravelPaymentFramework\Concerns\IsPayable;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Payable;
use Mifatoyeh\LaravelPaymentFramework\Drivers\Paymob\PaymobClient;
use Mifatoyeh\LaravelPaymentFramework\Drivers\Paymob\PaymobWebhookVerifier;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\Providers\PaymentServiceProvider;
use Mifatoyeh\LaravelPaymentFramework\Responses\StatusResponse;
use Orchestra\Testbench\TestCase;

/**
 * Confirms the fix for the live bug reported against KSA mode: `lookup()`'s
 * `retrieveTransaction()` call 404s/401s against Paymob's KSA platform (no
 * confirmed working legacy endpoint there — see
 * {@see \Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\SupportsTrustedWebhookStatus}'s
 * own docblock), so `confirmFromWebhook()` must NOT make that call at all
 * for a KSA-mode driver — it trusts the already-HMAC-verified webhook
 * payload directly instead.
 *
 * Deliberately does NOT fake `/acceptance/transactions/*` or `/auth/tokens`
 * — if `confirmFromWebhook()` regresses and calls `lookup()` for KSA mode
 * again, this test fails via `assertNothingSent()` below, not via a
 * misleadingly-passing fake response.
 */
final class PaymobKsaWebhookTrustedStatusTest extends TestCase
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
            'order' => PaymobKsaWebhookTestOrder::class,
        ]);

        $app['config']->set('payment.checkout.middleware', ['web']);
        $app['config']->set('payment.webhook.middleware', ['api']);

        $app['config']->set('payment.drivers.paymob', [
            'class'          => \Mifatoyeh\LaravelPaymentFramework\Drivers\Paymob\PaymobDriver::class,
            'secret_key'     => 'sau_sk_test_001',
            'public_key'     => 'sau_pk_test_001',
            'integration_id' => 30075,
            'hmac_secret'    => 'whsec_test_secret',
            'base_url'       => 'https://ksa.paymob.com/api',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('paymob_ksa_webhook_test_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedInteger('amount');
            $table->string('currency');
            $table->timestamps();
        });

        (require __DIR__ . '/../../database/migrations/2024_01_02_000000_create_checkout_transactions_table.php')->up();

        PaymobKsaWebhookTestOrder::$callbackInvocations = [];
    }

    protected function tearDown(): void
    {
        PaymobClient::setTestHttpFactory(null);

        parent::tearDown();
    }

    /** @test */
    public function test_ksa_webhook_confirms_from_the_payload_alone_with_no_lookup_call(): void
    {
        $http = new HttpFactory();
        $http->fake([
            '*/v1/intention/*' => $http::response(
                ['client_secret' => 'sau_csk_test_001', 'intention_order_id' => 6976637],
                200,
            ),
        ]);
        PaymobClient::setTestHttpFactory($http);

        $order = PaymobKsaWebhookTestOrder::create(['user_id' => 1, 'amount' => 4000, 'currency' => 'SAR']);

        $this->actingAs(new PaymobKsaWebhookTestUser(1))->postJson('/payment/checkout', [
            'model_type'  => 'order',
            'model_id'    => (string) $order->id,
            'driver'      => 'paymob',
            'driver_type' => 'webview',
            'os'          => 'mobile',
        ])->assertStatus(200);

        $pending = CheckoutTransaction::query()->where('model_type', 'order')->where('model_id', (string) $order->id)->first();
        $this->assertNotNull($pending);
        $this->assertSame(PaymentStatus::Pending->value, $pending->status);

        $payload = [
            'amount_cents'            => '4000',
            'created_at'              => '2026-07-19T12:47:16.351233+03:00',
            'currency'                => 'SAR',
            'error_occured'           => 'false',
            'has_parent_transaction'  => 'false',
            'id'                      => '7776382',
            'integration_id'          => '30075',
            'is_3d_secure'            => 'true',
            'is_auth'                 => 'false',
            'is_capture'              => 'true',
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
            'merchant_order_id'       => $pending->merchant_order_id,
            'data.message'            => 'Approved',
        ];
        $hmac  = (new PaymobWebhookVerifier(['hmac_secret' => 'whsec_test_secret']))->compute($payload, 'whsec_test_secret');
        $query = http_build_query([...$payload, 'hmac' => $hmac]);

        $this->get('/payment/webhook/paymob?' . $query)->assertStatus(200);

        $pending->refresh();
        $this->assertSame(PaymentStatus::Captured->value, $pending->status);
        $this->assertSame('7776382', $pending->transaction_reference);
        $this->assertTrue((bool) $pending->successful);
        $this->assertCount(1, PaymobKsaWebhookTestOrder::$callbackInvocations);

        // The whole point of this test: no /acceptance/transactions or
        // /auth/tokens call happened — only the one intention call from
        // checkout() itself.
        $http->assertSentCount(1);
        $http->assertNotSent(fn ($request) => str_contains($request->url(), 'acceptance/transactions'));
        $http->assertNotSent(fn ($request) => str_contains($request->url(), 'auth/tokens'));
    }
}

final class PaymobKsaWebhookTestOrder extends Model implements Payable
{
    use IsPayable;

    /** @var array<int, StatusResponse> */
    public static array $callbackInvocations = [];

    protected $table = 'paymob_ksa_webhook_test_orders';

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

final class PaymobKsaWebhookTestUser implements Authenticatable
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
