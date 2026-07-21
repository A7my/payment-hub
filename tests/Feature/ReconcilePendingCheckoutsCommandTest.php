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
use Mifatoyeh\LaravelPaymentFramework\Console\Commands\ReconcilePendingCheckoutsCommand;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\PaymentDriverContract;
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
 * Tests {@see ReconcilePendingCheckoutsCommand} — the universal 12h (by
 * default) reconciliation sweep, the backstop regardless of driver/
 * driver_type/os. Confirms it only touches rows that are both `pending`
 * AND older than the configured interval AND have a transaction_reference
 * to look up, and that it reuses the same confirmation pipeline as every
 * other path (via `CheckoutService::confirmTransaction()`).
 */
final class ReconcilePendingCheckoutsCommandTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PaymentServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        $app['config']->set('payment.payables', ['order' => SweepTestOrder::class]);
        $app['config']->set('payment.verification.sweep_interval_hours', 12);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('sweep_test_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('amount');
            $table->string('currency');
            $table->timestamps();
        });

        (require __DIR__ . '/../../database/migrations/2024_01_02_000000_create_checkout_transactions_table.php')->up();

        $this->app->make(PaymentManager::class)->extend('fake_sweep', fn () => new FakeSweepLookupDriver());

        SweepTestOrder::$callbackInvocations = [];
    }

    private function makeOrder(): SweepTestOrder
    {
        return SweepTestOrder::create(['amount' => 3000, 'currency' => 'USD']);
    }

    private function makeTransaction(array $overrides = []): CheckoutTransaction
    {
        $transaction = CheckoutTransaction::create(array_merge([
            'model_type'             => 'order',
            'model_id'               => (string) $this->makeOrder()->id,
            'driver'                 => 'fake_sweep',
            'driver_type'            => 'sdk',
            'merchant_order_id'      => 'idem-' . uniqid(),
            'transaction_reference'  => 'ref-001',
            'status'                 => PaymentStatus::Pending->value,
            'successful'             => false,
            'amount'                 => 3000,
            'currency'               => 'USD',
        ], $overrides));

        // updated_at is what the sweep filters on — a raw query update
        // bypasses Eloquent's auto-touch-on-save, which would otherwise
        // overwrite it back to "now" on every ->update() call above.
        if (isset($overrides['updated_at'])) {
            CheckoutTransaction::query()->where('id', $transaction->id)->update(['updated_at' => $overrides['updated_at']]);
            $transaction->refresh();
        }

        return $transaction;
    }

    /** @test */
    public function test_sweep_reconciles_a_stale_pending_row_with_a_reference(): void
    {
        FakeSweepLookupDriver::$nextStatus = PaymentStatus::Captured;
        $transaction = $this->makeTransaction(['updated_at' => now()->subHours(13)]);

        $this->artisan(ReconcilePendingCheckoutsCommand::class)->assertSuccessful();

        $transaction->refresh();
        $this->assertSame(PaymentStatus::Captured->value, $transaction->status);
        $this->assertCount(1, SweepTestOrder::$callbackInvocations);
    }

    /** @test */
    public function test_sweep_ignores_a_pending_row_within_the_interval(): void
    {
        $transaction = $this->makeTransaction(['updated_at' => now()->subHours(2)]);

        $this->artisan(ReconcilePendingCheckoutsCommand::class)->assertSuccessful();

        $transaction->refresh();
        $this->assertSame(PaymentStatus::Pending->value, $transaction->status);
        $this->assertCount(0, SweepTestOrder::$callbackInvocations);
    }

    /** @test */
    public function test_sweep_ignores_a_stale_row_with_no_transaction_reference_yet(): void
    {
        $transaction = $this->makeTransaction(['transaction_reference' => null, 'updated_at' => now()->subHours(20)]);

        $this->artisan(ReconcilePendingCheckoutsCommand::class)->assertSuccessful();

        $transaction->refresh();
        $this->assertSame(PaymentStatus::Pending->value, $transaction->status);
    }

    /** @test */
    public function test_sweep_ignores_an_already_resolved_row_even_if_stale(): void
    {
        $transaction = $this->makeTransaction(['status' => PaymentStatus::Captured->value, 'successful' => true, 'updated_at' => now()->subHours(20)]);

        $this->artisan(ReconcilePendingCheckoutsCommand::class)->assertSuccessful();

        $this->assertCount(0, SweepTestOrder::$callbackInvocations);
    }
}

final class SweepTestOrder extends Model implements Payable
{
    use IsPayable;

    /** @var array<int, StatusResponse> */
    public static array $callbackInvocations = [];

    protected $table = 'sweep_test_orders';

    protected $guarded = [];

    public function getSupportedPaymentDrivers(): array
    {
        return ['fake_sweep'];
    }

    public function authorizePayment(?Authenticatable $payer): bool
    {
        return true;
    }

    public function onPaymentCompleted(StatusResponse $status, CheckoutContext $context): void
    {
        self::$callbackInvocations[] = $status;
    }
}

final class FakeSweepLookupDriver implements PaymentDriverContract
{
    public static PaymentStatus $nextStatus = PaymentStatus::Captured;

    public function lookup(TransactionLookupRequest $request): StatusResponse
    {
        return new StatusResponse(
            successful: true,
            transactionId: $request->transactionId,
            status: self::$nextStatus,
            message: 'Fake status.',
            rawResponse: [],
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
