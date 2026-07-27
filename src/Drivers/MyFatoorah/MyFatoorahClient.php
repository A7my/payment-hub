<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Drivers\MyFatoorah;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Mifatoyeh\LaravelPaymentFramework\DTO\CaptureRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentLinkRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\RefundRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\TransactionLookupRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\VoidRequest;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;

/**
 * Thin HTTP transport for MyFatoorah's v2 REST API.
 *
 * Auth: `Authorization: Bearer {api_key}` on every request.
 * Base URL: sandbox → https://apitest.myfatoorah.com ; live → configurable
 * (default https://api.myfatoorah.com). Amounts are sent in **major** units
 * (decimal), converted from the framework's minor-unit {@see Money}.
 *
 * @see https://docs.myfatoorah.com/docs/api-key
 */
final class MyFatoorahClient
{
    private static ?HttpFactory $testHttpFactory = null;

    private readonly HttpFactory $http;

    /**
     * @param array<string, mixed> $config payment.drivers.myfatoorah block.
     */
    public function __construct(
        private readonly array $config = [],
        ?HttpFactory $http = null,
    ) {
        $this->http = $http ?? self::$testHttpFactory ?? new HttpFactory();
    }

    /**
     * Override the HTTP factory for tests (mirrors PaymobClient).
     */
    public static function setTestHttpFactory(?HttpFactory $factory): void
    {
        self::$testHttpFactory = $factory;
    }

    /**
     * Hosted invoice link — {@see MyFatoorahDriver::createPaymentLink()}.
     *
     * POST /v2/SendPayment
     *
     * @return array<string, mixed> Data payload (InvoiceId, InvoiceURL, …).
     */
    public function sendPayment(
        PaymentLinkRequest $request,
        ?string $callBackUrl = null,
        ?string $errorUrl = null,
        ?string $webhookUrl = null,
    ): array {
        $amount = Money::ofMinor($request->amount->amount, $request->currency);

        $payload = array_filter(
            [
                'NotificationOption' => 'LNK',
                'InvoiceValue'       => (float) $amount->toDecimalString(),
                'DisplayCurrencyIso' => $request->currency->value,
                'CustomerName'       => $request->customer?->name,
                'CustomerEmail'      => $request->customer?->email,
                'CustomerMobile'     => $this->digitsOnly($request->customer?->phone),
                'Language'           => 'EN',
                'CustomerReference'  => $request->idempotencyKey,
                'UserDefinedField'   => $request->idempotencyKey,
                'CallBackUrl'        => $callBackUrl ?? $request->returnUrl,
                'ErrorUrl'           => $errorUrl ?? $request->cancelUrl ?? $callBackUrl ?? $request->returnUrl,
                'WebhookUrl'         => $webhookUrl,
                'InvoiceItems'       => [[
                    'ItemName'  => $request->description !== '' ? $request->description : 'Order ' . $request->idempotencyKey,
                    'Quantity'  => 1,
                    'UnitPrice' => (float) $amount->toDecimalString(),
                ]],
            ],
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );

        return $this->post('/v2/SendPayment', $payload, 'sendPayment');
    }

    /**
     * Gateway ExecutePayment — used for sdk intents, charge, and authorize.
     *
     * POST /v2/ExecutePayment
     *
     * @param array<string, mixed> $extra Extra body fields (ProcessingDetails, RecurringModel, …).
     *
     * @return array<string, mixed>
     */
    public function executePayment(
        Money $amount,
        string $currencyIso,
        string $customerReference,
        int $paymentMethodId,
        ?string $customerName = null,
        ?string $customerEmail = null,
        ?string $customerMobile = null,
        ?string $callBackUrl = null,
        ?string $errorUrl = null,
        ?string $webhookUrl = null,
        array $extra = [],
    ): array {
        $payload = array_filter(
            array_merge(
                [
                    'PaymentMethodId'    => $paymentMethodId,
                    'InvoiceValue'       => (float) $amount->toDecimalString(),
                    'DisplayCurrencyIso' => $currencyIso,
                    'CustomerName'       => $customerName,
                    'CustomerEmail'      => $customerEmail,
                    'CustomerMobile'     => $this->digitsOnly($customerMobile),
                    'Language'           => 'EN',
                    'CustomerReference'  => $customerReference,
                    'UserDefinedField'   => $customerReference,
                    'CallBackUrl'        => $callBackUrl,
                    'ErrorUrl'           => $errorUrl ?? $callBackUrl,
                    'WebhookUrl'         => $webhookUrl,
                ],
                $extra,
            ),
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );

        return $this->post('/v2/ExecutePayment', $payload, 'executePayment');
    }

    /**
     * ExecutePayment from a framework {@see PaymentRequest}.
     *
     * @return array<string, mixed>
     */
    public function executePaymentFromRequest(PaymentRequest $request, bool $autoCapture): array
    {
        return $this->executePayment(
            amount: $request->amount,
            currencyIso: $request->currency->value,
            customerReference: $request->idempotencyKey,
            paymentMethodId: $this->paymentMethodId(),
            customerName: $request->customer->name,
            customerEmail: $request->customer->email,
            customerMobile: $request->customer->phone,
            callBackUrl: $request->returnUrl,
            errorUrl: $request->cancelUrl,
            extra: [
                'ProcessingDetails' => [
                    'AutoCapture' => $autoCapture,
                ],
            ],
        );
    }

    /**
     * POST /v2/GetPaymentStatus
     *
     * @return array<string, mixed>
     */
    public function getPaymentStatus(TransactionLookupRequest $request): array
    {
        $key = $request->transactionId->toString();

        return $this->post('/v2/GetPaymentStatus', [
            'Key'     => $key,
            'KeyType' => $this->detectKeyType($key),
        ], 'getPaymentStatus');
    }

    /**
     * POST /v2/MakeRefund
     *
     * @return array<string, mixed>
     */
    public function makeRefund(RefundRequest $request): array
    {
        $key = $request->transactionId->toString();

        return $this->post('/v2/MakeRefund', [
            'KeyType'                 => $this->detectKeyType($key),
            'Key'                     => $key,
            'ServiceChargeOnCustomer' => false,
            'Amount'                  => (float) $request->amount->toDecimalString(),
            'Comment'                 => $request->reason !== '' ? $request->reason : 'Refund',
            'ExternalIdentifier'      => $request->idempotencyKey,
        ], 'makeRefund');
    }

    /**
     * POST /v2/UpdatePaymentStatus — Capture.
     *
     * @return array<string, mixed>
     */
    public function capturePayment(CaptureRequest $request): array
    {
        $key = $request->transactionId->toString();

        return $this->post('/v2/UpdatePaymentStatus', [
            'KeyType'   => $this->detectKeyType($key),
            'Key'       => $key,
            'Operation' => 'Capture',
            'Amount'    => (float) $request->amount->toDecimalString(),
        ], 'capturePayment');
    }

    /**
     * POST /v2/UpdatePaymentStatus — Release (void).
     *
     * @return array<string, mixed>
     */
    public function releasePayment(VoidRequest $request): array
    {
        $key = $request->transactionId->toString();

        return $this->post('/v2/UpdatePaymentStatus', [
            'KeyType'   => $this->detectKeyType($key),
            'Key'       => $key,
            'Operation' => 'Release',
        ], 'releasePayment');
    }

    public function paymentMethodId(): int
    {
        return (int) ($this->config['payment_method_id'] ?? 2);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload, string $operation): array
    {
        $response = $this->request()->post($path, $payload);

        return $this->decode($response, $operation);
    }

    private function request(): PendingRequest
    {
        $token = (string) ($this->config['api_key'] ?? '');

        return $this->http
            ->baseUrl(rtrim($this->baseUrl(), '/'))
            ->timeout((int) ($this->config['timeout'] ?? 30))
            ->acceptJson()
            ->withToken($token, 'Bearer');
    }

    private function baseUrl(): string
    {
        if (! empty($this->config['base_url'])) {
            return (string) $this->config['base_url'];
        }

        $sandbox = (bool) ($this->config['sandbox'] ?? true);

        return $sandbox
            ? 'https://apitest.myfatoorah.com'
            : 'https://api.myfatoorah.com';
    }

    /**
     * Heuristic KeyType for GetPaymentStatus / Refund / UpdatePaymentStatus.
     *
     * InvoiceIds are relatively short integers; PaymentIds are long digit strings.
     */
    private function detectKeyType(string $key): string
    {
        if (ctype_digit($key) && strlen($key) <= 10) {
            return 'InvoiceId';
        }

        if (ctype_digit($key)) {
            return 'PaymentId';
        }

        return 'CustomerReference';
    }

    private function digitsOnly(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        return $digits !== '' ? $digits : null;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws MyFatoorahApiException
     */
    private function decode(Response $response, string $operation): array
    {
        $body = (array) $response->json();

        if ($response->failed()) {
            throw new MyFatoorahApiException(
                $this->extractErrorMessage($body, $operation),
                $response->status(),
                $body,
            );
        }

        if (array_key_exists('IsSuccess', $body) && $body['IsSuccess'] === false) {
            throw new MyFatoorahApiException(
                $this->extractErrorMessage($body, $operation),
                $response->status() ?: 422,
                $body,
            );
        }

        $data = $body['Data'] ?? $body;

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function extractErrorMessage(array $body, string $operation): string
    {
        if (is_string($body['Message'] ?? null) && $body['Message'] !== '') {
            return $body['Message'];
        }

        $errors = $body['ValidationErrors'] ?? null;

        if (is_array($errors) && $errors !== []) {
            return "MyFatoorah {$operation} validation failed: " . json_encode($errors, JSON_UNESCAPED_SLASHES);
        }

        if ($body !== []) {
            return "MyFatoorah {$operation} request failed: " . json_encode($body, JSON_UNESCAPED_SLASHES);
        }

        return "MyFatoorah {$operation} request failed with an empty response body.";
    }
}
