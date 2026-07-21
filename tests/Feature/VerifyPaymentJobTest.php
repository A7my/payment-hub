<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mifatoyeh\LaravelPaymentFramework\Concerns\IsPayable;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Payable;
use Mifatoyeh\LaravelPaymentFramework\Checkout\CheckoutService;
use Mifatoyeh\LaravelPaymentFramework\Checkout\CheckoutTransaction;
use Mifatoyeh\LaravelPaymentFramework\Checkout\Jobs\VerifyPaymentJob;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\PaymentDriverContract;
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
 * Tests {@see VerifyPaymentJob} directly (not via the queue) — constructs it
 * and calls `handle()` itself, so each scenario is a single deterministic
 * step. The one thing that DOES need `Queue::fake()` is confirming whether
 * it reschedules itself — {@see VerifyPaymentJob::rescheduleOrGiveUp()}
 * calls `self::dispatch()`, which is asserted via `Queue::assertPushed()`
 * rather than actually re-running (that would just recurse this test).
 */
final class VerifyPaymentJobTest extends TestCase
{
    private VerifyJobTestOrder $order;

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

        $app['config']->set('payment.payables', ['order' => VerifyJobTestOrder::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('verify_job_test_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('amount');
            $table->string('currency');
            $table->timestamps();
        });

        (require __DIR__ . '/../../database/migrations/2024_01_02_000000_create_checkout_transactions_table.php')->up();

        $this->app->make(PaymentManager::class)->extend('fake_lookup', fn () => new FakeLookupOnlyDriver());

        FakeLookupOnlyDriver::$nextStatus = PaymentStatus::Captured;
        VerifyJobTestOrder::$callbackInvocations = [];

        $this->order = VerifyJobTestOrder::create(['amount' => 5000, 'currency' => 'USD']);
    }

    private function makePendingTransaction(?string $transactionReference = 'pi_test_001'): CheckoutTransaction
    {
        return CheckoutTransaction::create([
            'model_type'             => 'order',
            'model_id'               => (string) $this->order->id,
            'driver'                 => 'fake_lookup',
            'driver_type'            => 'sdk',
            'merchant_order_id'      => 'idem-001',
            'transaction_reference'  => $transactionReference,
            'status'                 => PaymentStatus::Pending->value,
            'successful'             => false,
            'amount'                 => 5000,
            'currency'               => 'USD',
        ]);
    }

    /** @test */
    public function test_job_does_nothing_when_the_transaction_row_no_longer_exists(): void
    {
        Queue::fake();

        (new VerifyPaymentJob('fake_lookup', 999999))
            ->handle($this->app->make(CheckoutService::class), $this->app->make(PaymentManager::class));

        Queue::assertNothingPushed();
    }

    /** @test */
    public function test_job_does_nothing_when_the_transaction_is_already_resolved(): void
    {
        Queue::fake();
        $transaction = $this->makePendingTransaction();
        $transaction->update(['status' => PaymentStatus::Captured->value]);

        (new VerifyPaymentJob('fake_lookup', $transaction->id))
            ->handle($this->app->make(CheckoutService::class), $this->app->make(PaymentManager::class));

        Queue::assertNothingPushed();
    }

    /** @test */
    public function test_job_confirms_and_does_not_reschedule_on_a_terminal_status(): void
    {
        Queue::fake();
        FakeLookupOnlyDriver::$nextStatus = PaymentStatus::Captured;
        $transaction = $this->makePendingTransaction();

        (new VerifyPaymentJob('fake_lookup', $transaction->id))
            ->handle($this->app->make(CheckoutService::class), $this->app->make(PaymentManager::class));

        $transaction->refresh();
        $this->assertSame(PaymentStatus::Captured->value, $transaction->status);
        $this->assertCount(1, VerifyJobTestOrder::$callbackInvocations);
        Queue::assertNothingPushed();
    }

    /** @test */
    public function test_job_reschedules_itself_on_a_non_terminal_status(): void
    {
        Queue::fake();
        FakeLookupOnlyDriver::$nextStatus = PaymentStatus::Pending;
        $transaction = $this->makePendingTransaction();

        (new VerifyPaymentJob('fake_lookup', $transaction->id, attempt: 1))
            ->handle($this->app->make(CheckoutService::class), $this->app->make(PaymentManager::class));

        Queue::assertPushed(VerifyPaymentJob::class, fn (VerifyPaymentJob $job): bool => $job->checkoutTransactionId === $transaction->id
            && $job->attempt === 2);
    }

    /** @test */
    public function test_job_gives_up_at_max_attempts_without_rescheduling(): void
    {
        Queue::fake();
        config(['payment.verification.job.max_attempts' => 3]);
        FakeLookupOnlyDriver::$nextStatus = PaymentStatus::Pending;
        $transaction = $this->makePendingTransaction();

        (new VerifyPaymentJob('fake_lookup', $transaction->id, attempt: 3))
            ->handle($this->app->make(CheckoutService::class), $this->app->make(PaymentManager::class));

        Queue::assertNothingPushed();
    }

    /** @test */
    public function test_job_reschedules_without_looking_up_when_no_transaction_reference_is_stored_yet(): void
    {
        Queue::fake();
        $transaction = $this->makePendingTransaction(transactionReference: null);

        (new VerifyPaymentJob('fake_lookup', $transaction->id, attempt: 1))
            ->handle($this->app->make(CheckoutService::class), $this->app->make(PaymentManager::class));

        $transaction->refresh();
        $this->assertSame(PaymentStatus::Pending->value, $transaction->status); // untouched — nothing to look up
        Queue::assertPushed(VerifyPaymentJob::class, fn (VerifyPaymentJob $job): bool => $job->attempt === 2);
    }
}

final class VerifyJobTestOrder extends Model implements Payable
{
    use IsPayable;

    /** @var array<int, StatusResponse> */
    public static array $callbackInvocations = [];

    protected $table = 'verify_job_test_orders';

    protected $guarded = [];

    public function getSupportedPaymentDrivers(): array
    {
        return ['fake_lookup'];
    }

    public function authorizePayment(?Authenticatable $payer): bool
    {
        return true;
    }

    public function onPaymentCompleted(StatusResponse $status): void
    {
        self::$callbackInvocations[] = $status;
    }
}

/**
 * Minimal PaymentDriverContract test double — only lookup() has real
 * behaviour (a configurable static status), everything else throws.
 */
final class FakeLookupOnlyDriver implements PaymentDriverContract
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
