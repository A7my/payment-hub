<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Drivers\Paymob;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Thin transport wrapper around Paymob's Accept API.
 *
 * UNVERIFIED AGAINST LIVE PAYMOB DOCS: Paymob has no official Composer/PHP
 * SDK (unlike {@see \Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeClient},
 * which wraps `stripe/stripe-php` and could be verified by reading real SDK
 * source). Every endpoint path, request field name, and response shape below
 * is built from general knowledge of Paymob's Accept API and has NOT been
 * checked against Paymob's current live documentation or a real API call.
 * Treat every method's docblock claim about field names as a hypothesis to
 * confirm before this touches production traffic.
 *
 * Responsible ONLY for HTTP communication with Paymob — no business logic,
 * no status interpretation, no framework Response construction (that is
 * {@see PaymobDriver}'s and {@see PaymobMapper}'s job respectively). Every
 * method returns the raw, JSON-decoded Paymob payload as an array.
 *
 * Paymob's API requires a short-lived auth token (via {@see self::authenticate()})
 * on almost every subsequent call, and most operations (charge, save a card,
 * generate a hosted payment link) require first creating an "order" and then
 * requesting a "payment key" scoped to that order — a 3-call sequence before
 * any actual charge happens. Mirroring {@see StripeClient}'s own convention
 * (one Paymob endpoint per method; multi-call sequencing is orchestration and
 * lives in {@see PaymobDriver}, not here), each method below wraps exactly
 * one Paymob endpoint.
 *
 * HTTP transport is `Illuminate\Http\Client\Factory` (bundled with
 * `laravel/framework`, already a direct dependency of this package) rather
 * than raw Guzzle or the `Http` facade — this keeps testing consistent with
 * the plain-PHPUnit-TestCase convention used throughout this package (no
 * Orchestra Testbench / full framework bootstrap required): a `Factory`
 * instance can be constructed and faked (`Factory::fake()`) entirely
 * standalone, verified directly against this exact class/version.
 */
final class PaymobClient
{
    /**
     * Test-only global override, checked by every new instance's
     * constructor when no explicit `$httpFactory` is passed in. Mirrors the
     * Stripe SDK's own `\Stripe\ApiRequestor::setHttpClient()` global-swap
     * seam: {@see \Mifatoyeh\LaravelPaymentFramework\Drivers\Paymob\PaymobDriver}
     * builds a `PaymobClient` internally from just `$config` (no injectable
     * collaborators, by design — see `StripeDriver`'s own docblock for why),
     * so driver-level tests need a way to intercept HTTP traffic without a
     * constructor parameter to hook into. Tests MUST reset this to `null`
     * in `tearDown()`.
     */
    private static ?HttpFactory $testHttpFactory = null;

    private readonly HttpFactory $http;

    /**
     * @param array<string, mixed> $config The driver's config block from payment.drivers.paymob
     *                                      (api_key, integration_id, base_url, timeout, etc.).
     */
    public function __construct(
        private readonly array $config = [],
        ?HttpFactory $httpFactory = null,
    ) {
        $this->http = $httpFactory ?? self::$testHttpFactory ?? new HttpFactory();
    }

    /**
     * Install (or clear, with `null`) a global fake HTTP factory used by
     * every `PaymobClient` instance constructed afterwards that doesn't
     * receive its own `$httpFactory` explicitly. Test-only — see
     * {@see self::$testHttpFactory}'s own docblock.
     */
    public static function setTestHttpFactory(?HttpFactory $factory): void
    {
        self::$testHttpFactory = $factory;
    }

    /**
     * Exchange the configured API key for a short-lived auth token.
     *
     * Egypt/Accept mode: POSTs `{"api_key": "..."}` to `/auth/tokens` and
     * returns the short-lived token for use in subsequent request bodies.
     *
     * KSA mode: skips the HTTP call entirely — the KSA platform uses the
     * Intention API with static Bearer auth. Returns the secret_key directly
     * so the driver call-sites stay unchanged.
     *
     * @return string The auth token (Egypt) or secret_key passthrough (KSA).
     *
     * @throws PaymobApiException When the required credential is blank/missing.
     */
    public function authenticate(): string
    {
        if ($this->isKsaMode()) {
            $secretKey = (string) ($this->config['secret_key'] ?? '');

            if ($secretKey === '') {
                throw new PaymobApiException(
                    'PAYMOB_SECRET_KEY is empty. Set it to your Paymob KSA secret key (sau_sk_test_... or sau_sk_live_...).',
                    401,
                );
            }

            return $secretKey;
        }

        $apiKey = (string) ($this->config['api_key'] ?? '');

        if ($apiKey === '') {
            throw new PaymobApiException(
                'PAYMOB_API_KEY is empty. Set it to your Paymob Egypt/Accept dashboard API key.',
                401,
            );
        }

        $response = $this->request()->post('/auth/tokens', [
            'api_key' => $apiKey,
        ]);

        $body = $this->decode($response, 'authenticate');

        return (string) ($body['token'] ?? '');
    }

    /**
     * Create a KSA Intention — the single API call that replaces the
     * Egypt three-step sequence (createOrder → requestPaymentKey → iframe).
     *
     * POST https://ksa.paymob.com/v1/intention/
     * Auth: Bearer <secret_key>
     *
     * Returns the full intention payload. Key fields:
     *  - client_secret  (sau_csk_...) → used to build the hosted checkout URL
     *  - payment_keys[0].key          → legacy payment token (also usable)
     *  - intention_order_id           → Paymob order reference
     *
     * @param int                  $amountCents
     * @param string               $currency
     * @param array<string, mixed> $billingData
     * @param string               $merchantOrderId
     *
     * @return array<string, mixed>
     *
     * @throws PaymobApiException
     */
    public function createIntention(
        int $amountCents,
        string $currency,
        array $billingData,
        string $merchantOrderId,
    ): array {
        $response = $this->request()->post('/v1/intention/', [
            'amount'           => $amountCents,
            'currency'         => $currency,
            'payment_methods'  => [(int) ($this->config['integration_id'] ?? 0)],
            'items'            => [[
                'name'        => 'Order ' . $merchantOrderId,
                'amount'      => $amountCents,
                'description' => 'Order ' . $merchantOrderId,
                'quantity'    => 1,
            ]],
            'billing_data'     => $billingData,
            'special_reference' => $merchantOrderId,
        ]);

        return $this->decode($response, 'createIntention');
    }

    /**
     * Build the KSA hosted checkout URL from a client_secret.
     *
     * https://ksa.paymob.com/unifiedcheckout/?publicKey=<pub>&clientSecret=<csk>
     *
     * @param string $clientSecret  The `client_secret` (sau_csk_...) from createIntention().
     *
     * @return string
     */
    public function buildKsaCheckoutUrl(string $clientSecret): string
    {
        $publicKey = (string) ($this->config['public_key'] ?? '');

        return sprintf(
            'https://ksa.paymob.com/unifiedcheckout/?publicKey=%s&clientSecret=%s',
            urlencode($publicKey),
            urlencode($clientSecret),
        );
    }

    /**
     * Register a Paymob "order" — required before requesting a payment key.
     *
     * UNVERIFIED: `POST {base_url}/ecommerce/orders`.
     *
     * @param string $authToken       From {@see self::authenticate()}.
     * @param int    $amountCents     Amount in the smallest currency unit.
     * @param string $currency        ISO 4217 currency code.
     * @param string $merchantOrderId Caller-supplied reference (this package forwards the
     *                                framework request's idempotency key here) — Paymob
     *                                uses this for dashboard reconciliation; UNVERIFIED
     *                                whether Paymob itself treats it as an idempotency
     *                                guarantee the way Stripe's `idempotency_key` header does.
     *
     * @return array<string, mixed> The raw, decoded Paymob order payload (needs `id`).
     *
     * @throws PaymobApiException On any non-2xx Paymob response.
     */
    public function createOrder(string $authToken, int $amountCents, string $currency, string $merchantOrderId): array
    {
        $response = $this->request()->post('/ecommerce/orders', [
            ...$this->authBody($authToken),
            'delivery_needed'   => false,
            'amount_cents'      => $amountCents,
            'currency'          => $currency,
            'merchant_order_id' => $merchantOrderId,
            'items'             => [],
        ]);

        return $this->decode($response, 'createOrder');
    }

    /**
     * Request a payment key scoped to a specific order — the token actually
     * used to charge a card or build a hosted checkout URL.
     *
     * UNVERIFIED: `POST {base_url}/acceptance/payment_keys`. `billing_data`
     * is documented (per general knowledge) as required with a fixed set of
     * keys; Paymob is known to accept the literal string `"NA"` for fields
     * the caller doesn't have — {@see PaymobDriver} fills gaps this way
     * rather than rejecting a request over optional framework fields Paymob
     * happens to require.
     *
     * @param string               $authToken   From {@see self::authenticate()}.
     * @param int                  $orderId     From {@see self::createOrder()}'s response `id`.
     * @param int                  $amountCents Amount in the smallest currency unit — must match the order.
     * @param string               $currency    ISO 4217 currency code.
     * @param array<string, mixed> $billingData Paymob's required billing_data shape (first_name, last_name,
     *                                          email, phone_number, and address fields — "NA" for unknowns).
     *
     * @return array<string, mixed> The raw, decoded Paymob payload (needs `token`).
     *
     * @throws PaymobApiException On any non-2xx Paymob response.
     */
    public function requestPaymentKey(
        string $authToken,
        int $orderId,
        int $amountCents,
        string $currency,
        array $billingData,
    ): array {
        $response = $this->request()->post('/acceptance/payment_keys', [
            ...$this->authBody($authToken),
            'amount_cents'    => $amountCents,
            'expiration'      => 3600,
            'order_id'        => $orderId,
            'billing_data'    => $billingData,
            'currency'        => $currency,
            'integration_id'  => (int) ($this->config['integration_id'] ?? 0),
        ]);

        return $this->decode($response, 'requestPaymentKey');
    }

    /**
     * Charge (or authorise) a payment key against a previously-tokenised
     * Paymob payment method — never raw card data.
     *
     * UNVERIFIED: `POST {base_url}/acceptance/payments/pay` with
     * `source.subtype: "TOKEN"`. See {@see PaymobDriver}'s class docblock
     * for why this driver only ever sends `TOKEN`, never `CARD` (raw PAN) —
     * a deliberate, flagged design decision, not an oversight.
     *
     * @param string $paymentKey From {@see self::requestPaymentKey()}.
     * @param string $token      A Paymob-issued reusable card token (never raw card data).
     *
     * @return array<string, mixed> The raw, decoded Paymob Transaction payload.
     *
     * @throws PaymobApiException On any non-2xx Paymob response.
     */
    public function payWithToken(string $paymentKey, string $token): array
    {
        $response = $this->request()->post('/acceptance/payments/pay', [
            'source' => [
                'identifier' => $token,
                'subtype'    => 'TOKEN',
            ],
            'payment_token' => $paymentKey,
        ]);

        return $this->decode($response, 'payWithToken');
    }

    /**
     * Void a previously authorised (not yet captured) transaction.
     *
     * UNVERIFIED: `POST {base_url}/acceptance/void_refund/void`.
     *
     * @return array<string, mixed> The raw, decoded Paymob Transaction payload reflecting the voided state.
     *
     * @throws PaymobApiException On any non-2xx Paymob response.
     */
    public function voidTransaction(string $authToken, string $transactionId): array
    {
        $response = $this->request()->post('/acceptance/void_refund/void', [
            ...$this->authBody($authToken),
            'transaction_id' => $transactionId,
        ]);

        return $this->decode($response, 'voidTransaction');
    }

    /**
     * Capture funds from a previously authorised transaction.
     *
     * UNVERIFIED — the LEAST confident endpoint in this client: Paymob's
     * distinction between "authorise-only" and "immediate capture" is not
     * confirmed here (it may require an additional flag on
     * {@see self::payWithToken()}'s request, not just a separate capture
     * call). `POST {base_url}/acceptance/capture` is a best-effort guess at
     * the endpoint shape, modelled after {@see self::voidTransaction()}'s
     * and {@see self::refundTransaction()}'s pattern.
     *
     * @return array<string, mixed> The raw, decoded Paymob Transaction payload.
     *
     * @throws PaymobApiException On any non-2xx Paymob response.
     */
    public function captureTransaction(string $authToken, string $transactionId, int $amountCents): array
    {
        $response = $this->request()->post('/acceptance/capture', [
            ...$this->authBody($authToken),
            'transaction_id' => $transactionId,
            'amount_cents'   => $amountCents,
        ]);

        return $this->decode($response, 'captureTransaction');
    }

    /**
     * Refund (fully or partially) a previously captured transaction.
     *
     * UNVERIFIED: `POST {base_url}/acceptance/void_refund/refund`. Shared
     * by both refund() and partialRefund() at the driver level, the same
     * way {@see is — `$amountCents` alone
     * distinguishes a full vs. partial refund; Paymob itself decides which
     * happened server-side.
     *
     * @return array<string, mixed> The raw, decoded Paymob Transaction payload.
     *
     * @throws PaymobApiException On any non-2xx Paymob response.
     */
    public function refundTransaction(string $authToken, string $transactionId, int $amountCents): array
    {
        $response = $this->request()->post('/acceptance/void_refund/refund', [
            ...$this->authBody($authToken),
            'transaction_id' => $transactionId,
            'amount_cents'   => $amountCents,
        ]);

        return $this->decode($response, 'refundTransaction');
    }

    /**
     * Retrieve a transaction's current state.
     *
     * UNVERIFIED: `GET {base_url}/acceptance/transactions/{id}?token={authToken}`
     * — modelled on Paymob's documented convention (per general knowledge)
     * of GET endpoints taking the auth token as a query parameter rather
     * than a JSON body, since GET requests carry no body.
     *
     * In KSA mode the `token` query parameter is omitted — authentication is
     * handled by the `Authorization: Bearer` header injected by
     * {@see self::request()}.
     *
     * @return array<string, mixed> The raw, decoded Paymob Transaction payload.
     *
     * @throws PaymobApiException On any non-2xx Paymob response.
     */
    public function retrieveTransaction(string $authToken, string $transactionId): array
    {
        $queryParams = $this->isKsaMode() ? [] : ['token' => $authToken];

        $response = $this->request()->get("/acceptance/transactions/{$transactionId}", $queryParams);

        return $this->decode($response, 'retrieveTransaction');
    }

    /**
     * Build the hosted Paymob iframe checkout URL for a payment key.
     *
     * UNVERIFIED: `{base_url}/acceptance/iframes/{iframe_id}?payment_token={paymentKey}`
     * — no API call, just URL construction (this is how a customer's browser
     * is redirected to Paymob's own hosted card-entry page).
     *
     * @return string The hosted checkout URL to redirect the customer to.
     */
    public function buildIframeUrl(string $paymentKey): string
    {
        $iframeId = (string) ($this->config['iframe_id'] ?? '');
        $baseUrl  = rtrim((string) ($this->config['base_url'] ?? 'https://accept.paymob.com/api'), '/');

        return sprintf('%s/acceptance/iframes/%s?payment_token=%s', $baseUrl, $iframeId, $paymentKey);
    }

    /**
     * The configured Paymob public key, if any — used by
     * {@see self::buildKsaCheckoutUrl()} (KSA hosted checkout URL) and by
     * {@see PaymobDriver::createSdkIntent()} (returned to the caller so a
     * native/unified-checkout SDK can initialise with it). Egypt/Accept
     * integrations do not always configure one, so this is nullable rather
     * than falling back to an empty string.
     */
    public function publicKey(): ?string
    {
        $value = $this->config['public_key'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Build a Paymob billing_data array, filling gaps with "NA" — verified
     * only in the sense that Paymob is documented (per general knowledge)
     * to accept literal "NA" strings for unknown optional-in-practice
     * fields it nonetheless requires present in the request body.
     *
     * @return array<string, string>
     */
    public function billingDataFrom(string $name, string $email, ?string $phone): array
    {
        $parts     = array_filter(explode(' ', trim($name), 2));
        $firstName = $parts[0] ?? 'NA';
        $lastName  = $parts[1] ?? 'NA';

        return [
            'first_name'      => $firstName,
            'last_name'       => $lastName,
            'email'           => $email,
            'phone_number'    => $phone !== null && $phone !== '' ? $phone : 'NA',
            'apartment'       => 'NA',
            'floor'           => 'NA',
            'street'          => 'NA',
            'building'        => 'NA',
            'shipping_method' => 'NA',
            'postal_code'     => 'NA',
            'city'            => 'NA',
            'country'         => 'NA',
            'state'           => 'NA',
        ];
    }

    /**
     * Return true when the current config targets Paymob's KSA (Saudi Arabia)
     * platform — detected by either:
     *  - `base_url` containing `ksa.paymob.com`, OR
     *  - `secret_key` starting with the `sau_sk_test_` or `sau_sk_live_` prefix.
     *
     * This is the single authoritative definition of the bug condition in
     * production code. When true, {@see self::authenticate()} skips the
     * `/auth/tokens` call, {@see self::request()} injects an
     * `Authorization: Bearer` header, and {@see self::authBody()} returns
     * an empty array so no `auth_token` field appears in any request body.
     */
    public function isKsaMode(): bool
    {
        $baseUrl   = (string) ($this->config['base_url'] ?? '');
        $secretKey = (string) ($this->config['secret_key'] ?? '');

        return str_contains($baseUrl, 'ksa.paymob.com')
            || str_starts_with($secretKey, 'sau_sk_test_')
            || str_starts_with($secretKey, 'sau_sk_live_');
    }

    /**
     * Build the `auth_token` body fragment for mutating requests.
     *
     * Egypt/Accept mode: returns `['auth_token' => $authToken]` to be spread
     * into the request body.
     * KSA mode: returns `[]` — the KSA API authenticates via the
     * `Authorization: Bearer` header injected by {@see self::request()} and
     * does not accept a body-level token.
     *
     * @return array<string, string>
     */
    private function authBody(string $authToken): array
    {
        return $this->isKsaMode() ? [] : ['auth_token' => $authToken];
    }

    /**
     * Lazily build a pre-configured pending HTTP request.
     *
     * Egypt mode: base_url is e.g. https://accept.paymob.com/api — auth via body fields.
     * KSA mode:   base_url is https://ksa.paymob.com/api for legacy paths, but the
     *             Intention API lives at the root https://ksa.paymob.com — so we strip
     *             the /api suffix for KSA and let each method provide its full path
     *             (e.g. /v1/intention/, /ecommerce/orders, etc.).
     *             Auth is via Authorization: Bearer <secret_key>.
     */
    private function request(): PendingRequest
    {
        $baseUrl = rtrim((string) ($this->config['base_url'] ?? 'https://accept.paymob.com/api'), '/');

        if ($this->isKsaMode()) {
            // Strip /api suffix — KSA Intention API lives at root
            $baseUrl = rtrim(str_replace('/api', '', $baseUrl), '/');
        }

        $pending = $this->http
            ->baseUrl($baseUrl)
            ->timeout((int) ($this->config['timeout'] ?? 30))
            ->acceptJson();

        if ($this->isKsaMode()) {
            $pending = $pending->withToken((string) ($this->config['secret_key'] ?? ''), 'Bearer');
        }

        return $pending;
    }

    /**
     * Decode a Paymob HTTP response, throwing {@see PaymobApiException} on
     * any non-2xx status rather than letting a malformed/failed response
     * silently propagate as an empty array.
     *
     * @return array<string, mixed>
     *
     * @throws PaymobApiException
     */
    private function decode(Response $response, string $operation): array
    {
        $body = (array) $response->json();

        if ($response->failed()) {
            throw new PaymobApiException($this->extractErrorMessage($body, $operation), $response->status(), $body);
        }

        return $body;
    }

    /**
     * Build a diagnostic message from a failed Paymob response body.
     *
     * UNVERIFIED: Paymob's error body shape is not confirmed across every
     * endpoint (the legacy Accept API and the newer KSA Intention API may
     * not even agree with each other). Rather than only checking `detail`/
     * `message` and silently discarding everything else when neither is
     * present (which produced an unhelpful generic "request failed" message
     * with no diagnostic value on a real failure), this falls back to
     * dumping the raw body verbatim — the caller should never see a bare
     * "request failed" with no indication of why when Paymob actually sent
     * a body back.
     *
     * @param array<string, mixed> $body
     */
    private function extractErrorMessage(array $body, string $operation): string
    {
        if (is_string($body['detail'] ?? null) && $body['detail'] !== '') {
            return $body['detail'];
        }

        if (is_string($body['message'] ?? null) && $body['message'] !== '') {
            return $body['message'];
        }

        if ($body !== []) {
            return "Paymob {$operation} request failed: " . json_encode($body, JSON_UNESCAPED_SLASHES);
        }

        return "Paymob {$operation} request failed with an empty response body.";
    }
}
