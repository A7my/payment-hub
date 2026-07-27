<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Drivers\MyFatoorah;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use Mifatoyeh\LaravelPaymentFramework\DTO\CustomerData;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentLinkRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\RefundRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\TransactionLookupRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\WebhookRequest;
use Mifatoyeh\LaravelPaymentFramework\Drivers\MyFatoorah\MyFatoorahClient;
use Mifatoyeh\LaravelPaymentFramework\Drivers\MyFatoorah\MyFatoorahDriver;
use Mifatoyeh\LaravelPaymentFramework\Drivers\MyFatoorah\MyFatoorahWebhookVerifier;
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\Enums\WebhookEventType;
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentInitiated;
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentLinkCreated;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\UnsupportedOperationException;
use Mifatoyeh\LaravelPaymentFramework\Logging\NullLogger;
use Mifatoyeh\LaravelPaymentFramework\Services\RetryService;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\WebhookSignature;
use PHPUnit\Framework\TestCase;

final class MyFatoorahDriverTest extends TestCase
{
    private MyFatoorahRecordingDispatcher $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->events = new MyFatoorahRecordingDispatcher();
    }

    protected function tearDown(): void
    {
        MyFatoorahClient::setTestHttpFactory(null);
        parent::tearDown();
    }

    private function makeDriver(array $config = []): MyFatoorahDriver
    {
        return new MyFatoorahDriver(
            new NullLogger(),
            $this->events,
            new RetryService(1, 0, true),
            array_merge([
                'api_key'           => 'test-token',
                'webhook_secret'    => 'whsec_test',
                'payment_method_id' => 2,
                'sandbox'           => true,
            ], $config),
        );
    }

    /** @param array<string, array{0: array<string, mixed>, 1: int}> $responses */
    private function fakeHttp(array $responses): HttpFactory
    {
        $http  = new HttpFactory();
        $fakes = [];

        foreach ($responses as $pattern => [$body, $status]) {
            $fakes[$pattern] = $http::response($body, $status);
        }

        $http->fake($fakes);
        MyFatoorahClient::setTestHttpFactory($http);

        return $http;
    }

    /** @test */
    public function test_create_payment_link_uses_send_payment_and_forwards_callback_url(): void
    {
        $http = $this->fakeHttp([
            '*/v2/SendPayment' => [[
                'IsSuccess' => true,
                'Data'      => [
                    'InvoiceId'         => 300034,
                    'InvoiceURL'        => 'https://demo.myfatoorah.com/ie/0106230003434',
                    'CustomerReference' => 'idem-1',
                ],
            ], 200],
        ]);

        $callback = 'https://example.com/payment/checkout/callback/myfatoorah?merchant_order_id=idem-1';

        $response = $this->makeDriver()->createPaymentLink(new PaymentLinkRequest(
            amount: Money::ofMinor(1000, Currency::SAR),
            currency: Currency::SAR,
            description: 'Order',
            customer: new CustomerData('Azmy', 'a@example.com'),
            returnUrl: $callback,
            cancelUrl: null,
            expiresAt: null,
            idempotencyKey: 'idem-1',
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('https://demo.myfatoorah.com/ie/0106230003434', $response->getPaymentUrl());
        $this->assertSame('300034', $response->getLinkId());
        $this->assertInstanceOf(PaymentLinkCreated::class, $this->events->dispatched[0]);

        $http->assertSent(function ($request) use ($callback): bool {
            $body = $request->data();

            return str_contains($request->url(), '/v2/SendPayment')
                && ($body['CallBackUrl'] ?? null) === $callback
                && ($body['CustomerReference'] ?? null) === 'idem-1'
                && ($body['NotificationOption'] ?? null) === 'Lnk'
                && ! array_key_exists('WebhookUrl', $body);
        });
    }

    /** @test */
    public function test_live_saudi_country_code_hits_api_sa_host(): void
    {
        $http = $this->fakeHttp([
            'https://api-sa.myfatoorah.com/v2/SendPayment' => [[
                'IsSuccess' => true,
                'Data'      => [
                    'InvoiceId'  => 300034,
                    'InvoiceURL' => 'https://sa.myfatoorah.com/ie/x',
                ],
            ], 200],
        ]);

        $this->makeDriver([
            'sandbox'      => false,
            'country_code' => 'SAU',
        ])->createPaymentLink(new PaymentLinkRequest(
            amount: Money::ofMinor(1000, Currency::SAR),
            currency: Currency::SAR,
            description: 'Order',
            customer: new CustomerData('Azmy', 'a@example.com'),
            returnUrl: 'https://example.com/ok',
            cancelUrl: null,
            expiresAt: null,
            idempotencyKey: 'idem-sa',
        ));

        $http->assertSent(function ($request): bool {
            return $request->url() === 'https://api-sa.myfatoorah.com/v2/SendPayment';
        });
    }

    /** @test */
    public function test_create_sdk_intent_uses_execute_payment(): void
    {
        $this->fakeHttp([
            '*/v2/ExecutePayment' => [[
                'IsSuccess' => true,
                'Data'      => [
                    'InvoiceId'  => 927972,
                    'PaymentURL' => 'https://demo.myfatoorah.com/PayInvoice/Checkout?invoiceKey=abc',
                ],
            ], 200],
        ]);

        $response = $this->makeDriver()->createSdkIntent(new PaymentLinkRequest(
            amount: Money::ofMinor(2500, Currency::KWD),
            currency: Currency::KWD,
            description: 'SDK order',
            customer: new CustomerData('Azmy', 'a@example.com'),
            returnUrl: null,
            cancelUrl: null,
            expiresAt: null,
            idempotencyKey: 'idem-sdk-1',
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('927972', $response->getTransactionReference());
        $this->assertStringContainsString('PayInvoice', $response->getClientSecret());
    }

    /** @test */
    public function test_charge_dispatches_initiated_and_returns_requires_action(): void
    {
        $this->fakeHttp([
            '*/v2/ExecutePayment' => [[
                'IsSuccess' => true,
                'Data'      => [
                    'InvoiceId'  => 111,
                    'PaymentURL' => 'https://demo.myfatoorah.com/pay',
                ],
            ], 200],
        ]);

        $response = $this->makeDriver()->charge(new PaymentRequest(
            amount: Money::ofMinor(1000, Currency::SAR),
            currency: Currency::SAR,
            idempotencyKey: 'idem-charge-1',
            customer: new CustomerData('Azmy', 'a@example.com'),
        ));

        $this->assertSame(PaymentStatus::RequiresAction, $response->getStatus());
        $this->assertFalse($response->isSuccessful());
        $this->assertInstanceOf(PaymentInitiated::class, $this->events->dispatched[0]);
    }

    /** @test */
    public function test_lookup_maps_paid_invoice(): void
    {
        $this->fakeHttp([
            '*/v2/GetPaymentStatus' => [[
                'IsSuccess' => true,
                'Data'      => [
                    'InvoiceId'     => 915102,
                    'InvoiceStatus' => 'Paid',
                    'InvoiceValue'  => 10.5,
                    'InvoiceTransactions' => [[
                        'TransactionStatus' => 'Succss',
                        'Currency'          => 'SAR',
                    ]],
                ],
            ], 200],
        ]);

        $response = $this->makeDriver()->lookup(new TransactionLookupRequest(
            transactionId: TransactionId::fromString('915102'),
        ));

        $this->assertSame(PaymentStatus::Captured, $response->getStatus());
        $this->assertSame('915102', $response->getTransactionId()->toString());
    }

    /** @test */
    public function test_refund_success(): void
    {
        $this->fakeHttp([
            '*/v2/MakeRefund' => [[
                'IsSuccess' => true,
                'Data'      => [
                    'RefundId'        => 246275,
                    'RefundReference' => '2026000012',
                    'Amount'          => 1.0,
                ],
            ], 200],
        ]);

        $response = $this->makeDriver()->refund(new RefundRequest(
            transactionId: TransactionId::fromString('6424767'),
            amount: Money::ofMinor(100, Currency::SAR),
            reason: 'partial refund',
            idempotencyKey: 'idem-refund-1',
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('246275', $response->getRefundId());
    }

    /** @test */
    public function test_verify_webhook_signature_accepts_valid_hmac(): void
    {
        $payload = [
            'Event' => ['Name' => 'PAYMENT_STATUS_CHANGED'],
            'Data'  => [
                'Invoice' => [
                    'Id'                 => '6409988',
                    'Status'             => 'PAID',
                    'ExternalIdentifier' => 'order-1',
                ],
                'Transaction' => [
                    'Status'    => 'SUCCESS',
                    'PaymentId' => '07076409988323998875',
                ],
            ],
        ];

        $verifier = new MyFatoorahWebhookVerifier(['webhook_secret' => 'whsec_test']);
        $canonical = $verifier->canonicalString('PAYMENT_STATUS_CHANGED', $payload['Data']);
        $signature = base64_encode(hash_hmac('sha256', $canonical, 'whsec_test', true));

        $ok = $this->makeDriver()->verifyWebhookSignature(new WebhookRequest(
            driver: 'myfatoorah',
            rawBody: json_encode($payload, JSON_THROW_ON_ERROR),
            headers: ['myfatoorah-signature' => [$signature]],
            signature: WebhookSignature::fromString($signature),
        ));

        $this->assertTrue($ok);
    }

    /** @test */
    public function test_process_webhook_flattens_correlation_keys(): void
    {
        $payload = [
            'Event' => ['Name' => 'PAYMENT_STATUS_CHANGED'],
            'Data'  => [
                'Invoice' => [
                    'Id'                 => '6409988',
                    'Status'             => 'PAID',
                    'ExternalIdentifier' => 'order-1',
                ],
                'Transaction' => [
                    'Status'    => 'SUCCESS',
                    'PaymentId' => '07076409988323998875',
                ],
            ],
        ];

        $response = $this->makeDriver()->processWebhook(new WebhookRequest(
            driver: 'myfatoorah',
            rawBody: json_encode($payload, JSON_THROW_ON_ERROR),
            headers: [],
            signature: WebhookSignature::fromString(''),
        ));

        $this->assertSame(WebhookEventType::PaymentSucceeded, $response->getEventType());
        $this->assertSame('order-1', $response->getRawPayload()['merchant_order_id']);
        $this->assertSame('6409988', $response->getRawPayload()['id']);
    }

    /** @test */
    public function test_save_card_throws_unsupported(): void
    {
        $this->expectException(UnsupportedOperationException::class);
        $this->makeDriver()->saveCard(new \Mifatoyeh\LaravelPaymentFramework\DTO\SaveCardRequest(
            token: new \Mifatoyeh\LaravelPaymentFramework\ValueObjects\Token('tok'),
            customerId: new \Mifatoyeh\LaravelPaymentFramework\ValueObjects\CustomerId('1'),
            idempotencyKey: 'idem',
        ));
    }

    /** @test */
    public function test_supports_webhook_but_not_subscription(): void
    {
        $driver = $this->makeDriver();
        $this->assertTrue($driver->supports('webhook'));
        $this->assertFalse($driver->supports('subscription'));
    }
}

final class MyFatoorahRecordingDispatcher implements Dispatcher
{
    /** @var list<object> */
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
        return null;
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
