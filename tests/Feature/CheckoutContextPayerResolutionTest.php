<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Feature;

use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mifatoyeh\LaravelPaymentFramework\Checkout\CheckoutContext;
use Orchestra\Testbench\TestCase;

/**
 * Tests {@see CheckoutContext::payer()} — the lazy resolver that turns
 * `payerId` into a real `Authenticatable` model, via Laravel's own auth
 * guard/provider system (never a hardcoded `User` class).
 */
final class CheckoutContextPayerResolutionTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        $app['config']->set('auth.guards.web', ['driver' => 'session', 'provider' => 'users']);
        $app['config']->set('auth.providers.users', ['driver' => 'eloquent', 'model' => PayerResolutionTestUser::class]);
        $app['config']->set('auth.defaults.guard', 'web');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('payer_resolution_test_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    /** @test */
    public function test_payer_resolves_the_real_model_via_the_default_guard(): void
    {
        $user = PayerResolutionTestUser::create(['name' => 'Mohamed Azmy']);

        $context = new CheckoutContext(
            payerId: (string) $user->id,
            driver: 'stripe',
            driverType: 'webview',
            os: 'web',
            merchantOrderId: 'idem-001',
        );

        $resolved = $context->payer();

        $this->assertInstanceOf(PayerResolutionTestUser::class, $resolved);
        $this->assertSame($user->id, $resolved->id);
    }

    /** @test */
    public function test_payer_returns_null_when_there_is_no_payer_id(): void
    {
        $context = CheckoutContext::withoutTransaction('stripe', 'webview');

        $this->assertNull($context->payer());
    }

    /** @test */
    public function test_payer_returns_null_for_a_nonexistent_id_rather_than_throwing(): void
    {
        $context = new CheckoutContext(
            payerId: '999999',
            driver: 'stripe',
            driverType: 'webview',
            os: 'web',
            merchantOrderId: 'idem-002',
        );

        $this->assertNull($context->payer());
    }

    /** @test */
    public function test_payer_accepts_an_explicit_guard_name(): void
    {
        $app = $this->app;
        $app['config']->set('auth.guards.api', ['driver' => 'session', 'provider' => 'users']);

        $user = PayerResolutionTestUser::create(['name' => 'Second User']);

        $context = new CheckoutContext(
            payerId: (string) $user->id,
            driver: 'paymob',
            driverType: 'webview',
            os: 'web',
            merchantOrderId: 'idem-003',
        );

        $this->assertSame($user->id, $context->payer('api')?->id);
    }
}

final class PayerResolutionTestUser extends Model implements Authenticatable
{
    use AuthenticatableTrait;

    protected $table = 'payer_resolution_test_users';

    protected $guarded = [];
}
