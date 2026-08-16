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
 * Base URL: resolved like the official MyFatoorah library from
 * `sandbox` + `country_code` (SAU → api-sa, …), or an explicit `base_url`.
 * Amounts are sent in **major** units (decimal), converted from the
 * framework's minor-unit {@see Money}.
 *
 * @see https://docs.myfatoorah.com/docs/api-key
 * @see https://portal.myfatoorah.com/Files/API/mf-config.json
 */
final class MyFatoorahClient
{
    /**
     * Live v2 hosts keyed by MyFatoorah vendor country code (vcCode).
     * Sandbox always uses {@see self::SANDBOX_BASE_URL} for every country.
     *
     * @var array<string, string>
     */
    private const LIVE_BASE_URLS = [
        'KWT' => 'https://api.myfatoorah.com',
        'BHR' => 'https://api.myfatoorah.com',
        'OMN' => 'https://api.myfatoorah.com',
        'JOR' => 'https://api.myfatoorah.com',
        'SAU' => 'https://api-sa.myfatoorah.com',
        'ARE' => 'https://api-ae.myfatoorah.com',
        'QAT' => 'https://api-qa.myfatoorah.com',
        'EGY' => 'https://api-eg.myfatoorah.com',
    ];

    private const SANDBOX_BASE_URL = 'https://apitest.myfatoorah.com';

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

        [$mobileCountryCode, $customerMobile] = $this->splitPhone($request->customer?->phone);

        $payload = array_filter(
            [
                // Official samples / Laravel package use "Lnk" (not "LNK").
                'NotificationOption' => 'Lnk',
                'InvoiceValue'       => (float) $amount->toDecimalString(),
                'DisplayCurrencyIso' => $request->currency->value,
                'CustomerName'       => $this->truncate($request->customer?->name, 100),
                'CustomerEmail'      => $request->customer?->email,
                'MobileCountryCode'  => $mobileCountryCode,
                'CustomerMobile'     => $customerMobile,
                'Language'           => 'en',
                'CustomerReference'  => $request->idempotencyKey,
                'UserDefinedField'   => $request->idempotencyKey,
                'CallBackUrl'        => $callBackUrl ?? $request->returnUrl,
                'ErrorUrl'           => $errorUrl ?? $request->cancelUrl ?? $callBackUrl ?? $request->returnUrl,
                'WebhookUrl'         => $webhookUrl,
                'InvoiceItems'       => [[
                    'ItemName'  => $this->truncate(
                        $request->description !== '' ? $request->description : 'Order ' . $request->idempotencyKey,
                        100,
                    ),
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
        [$mobileCountryCode, $mobile] = $this->splitPhone($customerMobile);

        $payload = array_filter(
            array_merge(
                [
                    'PaymentMethodId'    => $paymentMethodId,
                    'InvoiceValue'       => (float) $amount->toDecimalString(),
                    'DisplayCurrencyIso' => $currencyIso,
                    'CustomerName'       => $this->truncate($customerName, 100),
                    'CustomerEmail'      => $customerEmail,
                    'MobileCountryCode'  => $mobileCountryCode,
                    'CustomerMobile'     => $mobile,
                    'Language'           => 'en',
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
        return $this->http
            ->baseUrl(rtrim($this->baseUrl(), '/'))
            ->timeout((int) ($this->config['timeout'] ?? 30))
            ->acceptJson()
            ->withToken($this->apiToken(), 'Bearer');
    }

    /**
     * Resolve and normalise the portal API token.
     *
     * Trims whitespace/newlines (common when pasting into `.env`) and strips
     * an accidental leading `Bearer ` if the user copied the full header
     * value into `MYFATOORAH_API_KEY`.
     *
     * @throws MyFatoorahApiException When the key is missing — fail before
     *                                  any HTTP call so the message is clear.
     */
    private function apiToken(): string
    {
        $token = trim((string) ($this->config['api_key'] ?? ''));

        if (str_starts_with(strtolower($token), 'bearer ')) {
            $token = trim(substr($token, 7));
        }

        if ($token === '') {
            throw new MyFatoorahApiException(
                'MyFatoorah api_key is empty. Set MYFATOORAH_API_KEY in your .env to the ' .
                'API token from the MyFatoorah portal (Integration Settings → API Key), ' .
                'then run `php artisan config:clear`.',
                401,
            );
        }

        return $token;
    }

    /**
     * Resolve the API host the same way as official `MyFatoorahPayment`:
     * `isTest` / sandbox → apitest; live → host from {@see self::LIVE_BASE_URLS}
     * keyed by `MYFATOORAH_COUNTRY_CODE` (SAU, KWT, …).
     *
     * `MYFATOORAH_BASE_URL` still wins when set (escape hatch).
     *
     * Config keys accepted for country (first non-empty wins):
     * `country_code`, `country_iso`, `countryCode`, `vcCode`.
     */
    private function baseUrl(): string
    {
        $explicit = $this->configString('base_url')
            ?? $this->envString('MYFATOORAH_BASE_URL');

        if ($explicit !== null) {
            return rtrim($explicit, '/');
        }

        $sandbox = filter_var($this->config['sandbox'] ?? true, FILTER_VALIDATE_BOOLEAN);

        if ($sandbox) {
            return self::SANDBOX_BASE_URL;
        }

        return $this->liveBaseUrlForCountry($this->countryCode());
    }

    /**
     * @throws MyFatoorahApiException When country code is missing or unknown.
     */
    private function liveBaseUrlForCountry(?string $code): string
    {
        $allowed = implode(', ', array_keys(self::LIVE_BASE_URLS));

        if ($code === null) {
            throw new MyFatoorahApiException(
                'MyFatoorah live mode requires MYFATOORAH_COUNTRY_CODE in .env ' .
                "(one of: {$allowed}). Example for Saudi Arabia: MYFATOORAH_COUNTRY_CODE=SAU",
                400,
            );
        }

        if (! isset(self::LIVE_BASE_URLS[$code])) {
            throw new MyFatoorahApiException(
                "Unknown MyFatoorah country code [{$code}]. Supported: {$allowed}.",
                400,
            );
        }

        return self::LIVE_BASE_URLS[$code];
    }

    /**
     * MyFatoorah vendor country code (e.g. SAU, KWT), or null if unset.
     *
     * Also reads .env directly when a published `config/payment.php` is missing
     * the newer `country_code` key (Laravel shallow-merges the whole `drivers` block).
     */
    private function countryCode(): ?string
    {
        foreach (['country_code', 'country_iso', 'countryCode', 'vcCode'] as $key) {
            $raw = $this->configString($key);

            if ($raw !== null) {
                return strtoupper($raw);
            }
        }

        foreach (['MYFATOORAH_COUNTRY_CODE', 'MYFATOORAH_COUNTRY_ISO'] as $envKey) {
            $raw = $this->envString($envKey);

            if ($raw !== null) {
                return strtoupper($raw);
            }
        }

        return null;
    }

    private function configString(string $key): ?string
    {
        $raw = $this->config[$key] ?? null;

        if (! is_string($raw)) {
            return null;
        }

        $raw = trim($raw);

        return $raw !== '' ? $raw : null;
    }

    private function envString(string $key): ?string
    {
        $raw = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if (! is_string($raw)) {
            return null;
        }

        $raw = trim($raw);

        return $raw !== '' ? $raw : null;
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

    /**
     * Split a phone number into MyFatoorah's `MobileCountryCode` + `CustomerMobile`.
     *
     * MyFatoorah validates the two parts separately and rejects the whole
     * invoice with "Invalid data" when a dialling code is baked into
     * `CustomerMobile`, so an international number (`+966…` / `00966…`) is
     * split the same way the official library's helper does — the leading
     * three digits become the country code. A plain local number is sent
     * as-is with no country code.
     *
     * Numbers outside MyFatoorah's 3–14 digit range are dropped rather than
     * failing the request, since the mobile is optional for invoice links.
     *
     * @return array{0: ?string, 1: ?string} [MobileCountryCode, CustomerMobile]
     */
    private function splitPhone(?string $phone): array
    {
        if ($phone === null || trim($phone) === '') {
            return [null, null];
        }

        $normalised    = preg_replace('/[\s\-()]+/', '', trim($phone)) ?? '';
        $isInternational = str_starts_with($normalised, '+') || str_starts_with($normalised, '00');

        $digits = preg_replace('/\D+/', '', $normalised) ?? '';

        if ($isInternational && str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if ($digits === '' || strlen($digits) < 3 || strlen($digits) > 14) {
            return [null, null];
        }

        if ($isInternational && strlen(substr($digits, 3)) > 3) {
            return ['+' . substr($digits, 0, 3), substr($digits, 3)];
        }

        return [null, $digits];
    }

    private function truncate(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
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
                $this->extractErrorMessage($body, $operation, $response->status()),
                $response->status(),
                $body,
            );
        }

        if (array_key_exists('IsSuccess', $body) && $body['IsSuccess'] === false) {
            throw new MyFatoorahApiException(
                $this->extractErrorMessage($body, $operation, $response->status() ?: 422),
                $response->status() ?: 422,
                $body,
            );
        }

        $data = $body['Data'] ?? $body;

        return is_array($data) ? $data : [];
    }

    /**
     * Build a diagnostic message from a failed MyFatoorah response body.
     *
     * MyFatoorah pairs a generic top-level `Message` ("Invalid data") with the
     * per-field reason in `ValidationErrors` / `FieldsErrors`, so reading
     * `Message` first throws away the only useful part. The official library
     * reads the field errors first — this mirrors that order, then falls back
     * to dumping the raw body verbatim (as PaymobClient does) rather than
     * returning a message with no diagnostic value.
     *
     * @param array<string, mixed> $body
     */
    private function extractErrorMessage(array $body, string $operation, int $httpStatus = 0): string
    {
        $message = is_string($body['Message'] ?? null) && $body['Message'] !== ''
            ? $body['Message']
            : null;

        $fieldErrors = $this->extractFieldErrors($body);

        if ($fieldErrors !== null) {
            return $message !== null
                ? "{$message}: {$fieldErrors}"
                : "MyFatoorah {$operation} validation failed: {$fieldErrors}";
        }

        $dataError = $body['Data']['ErrorMessage'] ?? null;

        if (is_string($dataError) && $dataError !== '') {
            return $dataError;
        }

        if ($message !== null) {
            // Keep the raw body when the message alone says nothing actionable.
            return count($body) > 1
                ? "{$message} — MyFatoorah {$operation} response: " . json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : $message;
        }

        if ($body !== []) {
            return "MyFatoorah {$operation} request failed: " . json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        // MyFatoorah's own sample clients treat an empty 401 body as "API key
        // is not correct" — usually a missing/wrong token, or a live token
        // hitting the sandbox host (or the reverse).
        if ($httpStatus === 401) {
            return sprintf(
                'MyFatoorah rejected the API token (HTTP 401, empty body) against [%s]. ' .
                'Match the token to the host: test token → PAYMENT_SANDBOX=true; live Saudi token → ' .
                'PAYMENT_SANDBOX=false and MYFATOORAH_COUNTRY_CODE=SAU (→ api-sa.myfatoorah.com). ' .
                'Reuse the same apiKey + isTest + countryCode values that work with the official ' .
                'MyFatoorah Laravel package. Then run `php artisan config:clear`.',
                $this->baseUrl(),
            );
        }

        // Empty 403 is usually Azure Application Gateway / WAF in front of
        // MyFatoorah (blocked IP, or request blocked before the API runs).
        if ($httpStatus === 403) {
            return sprintf(
                'MyFatoorah blocked the request (HTTP 403, empty body) against [%s]. ' .
                'This is usually IP / WAF blocking on the live host — not a bad payload. ' .
                'Confirm MYFATOORAH_COUNTRY_CODE matches your portal (SAU → api-sa.myfatoorah.com), ' .
                'whitelist your server public IP, and use a public HTTPS CallBackUrl (not localhost).',
                $this->baseUrl(),
            );
        }

        return "MyFatoorah {$operation} request failed with an empty response body.";
    }

    /**
     * Flatten `ValidationErrors` / `FieldsErrors` into "Field: reason" pairs.
     *
     * Entries look like `{"Name": "invoiceCreate.InvoiceItems", "Error": "..."}`,
     * and `Error` is sometimes empty — in that case the field name alone is the
     * only signal available, so it is still reported.
     *
     * @param array<string, mixed> $body
     */
    private function extractFieldErrors(array $body): ?string
    {
        $errors = $body['ValidationErrors'] ?? $body['FieldsErrors'] ?? null;

        if (! is_array($errors) || $errors === []) {
            return null;
        }

        $pairs = [];

        foreach ($errors as $error) {
            if (! is_array($error)) {
                continue;
            }

            $name   = is_string($error['Name'] ?? null) ? trim($error['Name']) : '';
            $reason = is_string($error['Error'] ?? null) ? trim($error['Error']) : '';

            if ($name !== '' && $reason !== '') {
                $pairs[] = "{$name}: {$reason}";
            } elseif ($name !== '') {
                $pairs[] = $name;
            } elseif ($reason !== '') {
                $pairs[] = $reason;
            }
        }

        return $pairs !== [] ? implode(', ', $pairs) : null;
    }
}
