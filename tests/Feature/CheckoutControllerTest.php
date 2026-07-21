<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mifatoyeh\LaravelPaymentFramework\Concerns\IsPayable;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Payable;
use Mifatoyeh\LaravelPaymentFramework\Facades\Payment;
use Mifatoyeh\LaravelPaymentFramework\Providers\PaymentServiceProvider;
use Orchestra\Testbench\TestCase;

/**
 * Feature tests for the generic checkout endpoint (CheckoutController +
 * CheckoutService), registered via routes/checkout.php.
 *
 * Uses Orchestra\Testbench\TestCase — same as WebhookControllerTest — since
 * this genuinely needs real routing/middleware/container behaviour, unlike
 * the plain-PHPUnit Unit tests used everywhere else in this package.
 *
 * CheckoutTestOrder/CheckoutTestUser are duplicated-in-spirit test doubles
 * declared at the bottom of this file, matching this package's established
 * self-contained-per-file test convention.
 */
final class CheckoutControllerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PaymentServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Payment' => Payment::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Required for the 'web' middleware group (session/CSRF) to boot.
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        $app['config']->set('payment.payables', [
            'order' => CheckoutTestOrder::class,
        ]);

        // Deliberately no 'auth' here — these tests exercise
        // Payable::authorizePayment() directly (the defense-in-depth
        // check), independent of whatever middleware a host app configures.
        $app['config']->set('payment.checkout.middleware', ['web']);

        // This file tests the checkout-initiation HTTP contract only, not
        // persistence — it doesn't create a checkout_transactions table.
        // See CheckoutSdkConfirmTest for pending/confirmed transaction
        // persistence coverage (it DOES create that table).
        $app['config']->set('payment.checkout.persist_transactions', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('checkout_test_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedInteger('amount');
            $table->string('currency');
            $table->timestamps();
        });
    }

    private function makeOrder(int $amount = 1000, string $currency = 'USD', ?int $userId = null): CheckoutTestOrder
    {
        return CheckoutTestOrder::create([
            'user_id'  => $userId,
            'amount'   => $amount,
            'currency' => $currency,
        ]);
    }

    // =========================================================================
    // Successful checkout
    // =========================================================================

    /** @test */
    public function test_successful_webview_checkout_returns_checkout_url(): void
    {
        Payment::fake();
        $order = $this->makeOrder(userId: 1);

        $response = $this->actingAs(new CheckoutTestUser(1))->postJson('/payment/checkout', [
            'model_type'  => 'order',
            'model_id'    => (string) $order->id,
            'driver'      => 'stripe',
            'driver_type' => 'webview',
            'os'          => 'web',
            'return_url'  => 'https://example.com/success',
            'cancel_url'  => 'https://example.com/cancel',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['status', 'checkout_url', 'link_id', 'message']);
        $this->assertSame('success', $response->json('status'));
        $this->assertNotEmpty($response->json('checkout_url'));
    }

    // =========================================================================
    // model_type / model_id resolution
    // =========================================================================

    /** @test */
    public function test_unregistered_model_type_returns_422(): void
    {
        Payment::fake();

        $response = $this->postJson('/payment/checkout', [
            'model_type'  => 'invoice', // not registered in payment.payables
            'model_id'    => '1',
            'driver'      => 'stripe',
            'driver_type' => 'webview',
            'os'          => 'mobile',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('invoice', (string) $response->json('message'));
    }

    /** @test */
    public function test_missing_record_returns_404(): void
    {
        Payment::fake();

        $response = $this->actingAs(new CheckoutTestUser(1))->postJson('/payment/checkout', [
            'model_type'  => 'order',
            'model_id'    => '999999',
            'driver'      => 'stripe',
            'driver_type' => 'webview',
            'os'          => 'mobile',
        ]);

        $response->assertStatus(404);
    }

    // =========================================================================
    // Per-model driver allowlist
    // =========================================================================

    /** @test */
    public function test_driver_not_supported_by_this_model_returns_422(): void
    {
        Payment::fake();
        $order = $this->makeOrder(userId: 1);

        $response = $this->actingAs(new CheckoutTestUser(1))->postJson('/payment/checkout', [
            'model_type'  => 'order',
            'model_id'    => (string) $order->id,
            'driver'      => 'paypal', // CheckoutTestOrder only allows 'stripe'
            'driver_type' => 'webview',
            'os'          => 'mobile',
        ]);

        $response->assertStatus(422);
    }

    // =========================================================================
    // Authorization — defense in depth, independent of middleware
    // =========================================================================

    /** @test */
    public function test_unauthorized_payer_returns_403_even_without_auth_middleware(): void
    {
        // payment.checkout.middleware is just ['web'] in this test's config
        // (no 'auth') — Payable::authorizePayment() must still block a
        // mismatched user entirely on its own.
        Payment::fake();
        $order = $this->makeOrder(userId: 1);

        $response = $this->actingAs(new CheckoutTestUser(2))->postJson('/payment/checkout', [
            'model_type'  => 'order',
            'model_id'    => (string) $order->id,
            'driver'      => 'stripe',
            'driver_type' => 'webview',
            'os'          => 'mobile',
        ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function test_unauthenticated_request_is_rejected_by_the_models_own_authorization(): void
    {
        Payment::fake();
        $order = $this->makeOrder(userId: 1);

        // No actingAs() — $request->user() is null.
        $response = $this->postJson('/payment/checkout', [
            'model_type'  => 'order',
            'model_id'    => (string) $order->id,
            'driver'      => 'stripe',
            'driver_type' => 'webview',
            'os'          => 'mobile',
        ]);

        $response->assertStatus(403);
    }

    // =========================================================================
    // driver_type
    // =========================================================================

    /** @test */
    public function test_sdk_driver_type_returns_422_not_yet_supported(): void
    {
        Payment::fake();
        $order = $this->makeOrder(userId: 1);

        $response = $this->actingAs(new CheckoutTestUser(1))->postJson('/payment/checkout', [
            'model_type'  => 'order',
            'model_id'    => (string) $order->id,
            'driver'      => 'stripe',
            'driver_type' => 'sdk',
            'os'          => 'mobile',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('sdk', strtolower((string) $response->json('message')));
    }

    /** @test */
    public function test_invalid_driver_type_value_fails_request_validation_with_422(): void
    {
        $response = $this->postJson('/payment/checkout', [
            'model_type'  => 'order',
            'model_id'    => '1',
            'driver'      => 'stripe',
            'driver_type' => 'bogus',
        ]);

        $response->assertStatus(422);
    }
}

/**
 * Test-only Payable Eloquent model.
 */
final class CheckoutTestOrder extends Model implements Payable
{
    use IsPayable;

    protected $table = 'checkout_test_orders';

    protected $guarded = [];

    public function getSupportedPaymentDrivers(): array
    {
        return ['stripe'];
    }

    public function authorizePayment(?Authenticatable $payer): bool
    {
        return $payer !== null && (int) $payer->getAuthIdentifier() === (int) $this->user_id;
    }
}

/**
 * Minimal Authenticatable test double — no need for a full Eloquent User
 * model/migration just to exercise actingAs().
 */
final class CheckoutTestUser implements Authenticatable
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
