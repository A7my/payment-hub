<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Drivers\Paymob;

use Illuminate\Http\Client\Factory as HttpFactory;
use Mifatoyeh\LaravelPaymentFramework\Drivers\Paymob\PaymobClient;
use PHPUnit\Framework\TestCase;

/**
 * Property 2: Preservation — Egypt/Accept Auth Flow Unchanged
 *
 * This test is written BEFORE implementing the fix using the observation-first
 * methodology: observed the unfixed PaymobClient's exact HTTP behaviour for
 * Egypt/Accept configs and encodes those observations as assertions.
 *
 * Preservation condition (¬isBugCondition): any config where
 *   - base_url does NOT contain 'ksa.paymob.com', AND
 *   - secret_key does NOT start with 'sau_sk_test_' or 'sau_sk_live_'
 *
 * Observed behaviors that MUST be preserved after the fix:
 *   - authenticate() POSTs { api_key } to /auth/tokens and returns the token
 *   - createOrder(), requestPaymentKey(), voidTransaction(), captureTransaction(),
 *     refundTransaction() all include auth_token in their request body
 *   - retrieveTransaction() sends token as a query param, NOT in body
 *   - No Authorization: Bearer header is added to any request
 *
 * Run on UNFIXED code  → PASSES (confirms baseline behavior).
 * Run on FIXED code    → PASSES (confirms no regressions).
 */
final class PaymobEgyptPreservationTest extends TestCase
{
    /**
     * Egypt/Accept config variants that satisfy ¬isBugCondition.
     * Covers: standard config, no secret_key, empty secret_key,
     * secret_key with a non-KSA prefix, and a non-standard api_key value.
     *
     * @return array<string, array{config: array<string, mixed>}>
     */
    public static function egyptConfigProvider(): array
    {
        return [
            'standard_egypt_config' => [
                'config' => [
                    'base_url'       => 'https://accept.paymob.com/api',
                    'api_key'        => 'egypt-api-key-001',
                    'integration_id' => 12345,
                    'iframe_id'      => '999',
                    'timeout'        => 30,
                ],
            ],
            'no_secret_key' => [
                'config' => [
                    'base_url'       => 'https://accept.paymob.com/api',
                    'api_key'        => 'egypt-api-key-002',
                    'integration_id' => 12345,
                    'timeout'        => 30,
                    // secret_key absent entirely
                ],
            ],
            'empty_secret_key' => [
                'config' => [
                    'base_url'       => 'https://accept.paymob.com/api',
                    'api_key'        => 'egypt-api-key-003',
                    'secret_key'     => '',  // empty string is NOT a KSA key
                    'integration_id' => 12345,
                    'timeout'        => 30,
                ],
            ],
            'non_ksa_secret_key_prefix' => [
                'config' => [
                    'base_url'       => 'https://accept.paymob.com/api',
                    'api_key'        => 'egypt-api-key-004',
                    'secret_key'     => 'some_other_prefix_key',  // not sau_sk_*
                    'integration_id' => 12345,
                    'timeout'        => 30,
                ],
            ],
            'default_base_url_only' => [
                'config' => [
                    // base_url key absent — should default to accept.paymob.com
                    'api_key'        => 'egypt-api-key-005',
                    'integration_id' => 12345,
                    'timeout'        => 30,
                ],
            ],
        ];
    }

    /**
     * **Property 2: Preservation** — authenticate() must POST to /auth/tokens
     * with api_key for all Egypt/Accept configs and return the token from
     * the response.
     *
     * Observed on unfixed code: always fires POST, always returns body['token'].
     *
     * @dataProvider egyptConfigProvider
     */
    public function test_egypt_authenticate_posts_to_auth_tokens_and_returns_token(array $config): void
    {
        $http = new HttpFactory();
        $http->fake(['*/auth/tokens' => $http::response(['token' => 'auth_tok_egypt'], 200)]);

        $client = new PaymobClient($config, $http);
        $token  = $client->authenticate();

        $this->assertSame('auth_tok_egypt', $token);

        $http->assertSent(function ($request) use ($config) {
            return str_contains($request->url(), '/auth/tokens')
                && $request->method() === 'POST'
                && $request->data()['api_key'] === ($config['api_key'] ?? '');
        });
    }

    /**
     * **Property 2: Preservation** — createOrder() must include auth_token
     * in its request body for all Egypt/Accept configs.
     *
     * Observed on unfixed code: auth_token always present in body.
     *
     * @dataProvider egyptConfigProvider
     */
    public function test_egypt_create_order_includes_auth_token_in_body(array $config): void
    {
        $http = new HttpFactory();
        $http->fake(['*/ecommerce/orders' => $http::response(['id' => 1], 200)]);

        $client = new PaymobClient($config, $http);
        $client->createOrder('egypt_token_abc', 1000, 'EGP', 'ord-eg-1');

        $http->assertSent(fn ($request) =>
            str_contains($request->url(), 'ecommerce/orders')
            && $request->data()['auth_token'] === 'egypt_token_abc'
        );
    }

    /**
     * **Property 2: Preservation** — requestPaymentKey() must include auth_token
     * in its request body for all Egypt/Accept configs.
     *
     * @dataProvider egyptConfigProvider
     */
    public function test_egypt_request_payment_key_includes_auth_token_in_body(array $config): void
    {
        $http = new HttpFactory();
        $http->fake(['*/acceptance/payment_keys' => $http::response(['token' => 'pk_1'], 200)]);

        $client = new PaymobClient($config, $http);
        $client->requestPaymentKey('egypt_token_abc', 1, 1000, 'EGP', ['first_name' => 'NA']);

        $http->assertSent(fn ($request) =>
            str_contains($request->url(), 'payment_keys')
            && $request->data()['auth_token'] === 'egypt_token_abc'
        );
    }

    /**
     * **Property 2: Preservation** — voidTransaction() must include auth_token
     * in its request body for all Egypt/Accept configs.
     *
     * @dataProvider egyptConfigProvider
     */
    public function test_egypt_void_transaction_includes_auth_token_in_body(array $config): void
    {
        $http = new HttpFactory();
        $http->fake(['*/acceptance/void_refund/void' => $http::response(['id' => 1, 'is_voided' => true], 200)]);

        $client = new PaymobClient($config, $http);
        $client->voidTransaction('egypt_token_abc', '123');

        $http->assertSent(fn ($request) =>
            str_contains($request->url(), 'void_refund/void')
            && $request->data()['auth_token'] === 'egypt_token_abc'
        );
    }

    /**
     * **Property 2: Preservation** — captureTransaction() must include auth_token
     * in its request body for all Egypt/Accept configs.
     *
     * @dataProvider egyptConfigProvider
     */
    public function test_egypt_capture_transaction_includes_auth_token_in_body(array $config): void
    {
        $http = new HttpFactory();
        $http->fake(['*/acceptance/capture' => $http::response(['id' => 1, 'success' => true], 200)]);

        $client = new PaymobClient($config, $http);
        $client->captureTransaction('egypt_token_abc', '123', 500);

        $http->assertSent(fn ($request) =>
            str_contains($request->url(), 'acceptance/capture')
            && $request->data()['auth_token'] === 'egypt_token_abc'
        );
    }

    /**
     * **Property 2: Preservation** — refundTransaction() must include auth_token
     * in its request body for all Egypt/Accept configs.
     *
     * @dataProvider egyptConfigProvider
     */
    public function test_egypt_refund_transaction_includes_auth_token_in_body(array $config): void
    {
        $http = new HttpFactory();
        $http->fake(['*/acceptance/void_refund/refund' => $http::response(['id' => 1, 'is_refunded' => true], 200)]);

        $client = new PaymobClient($config, $http);
        $client->refundTransaction('egypt_token_abc', '123', 300);

        $http->assertSent(fn ($request) =>
            str_contains($request->url(), 'void_refund/refund')
            && $request->data()['auth_token'] === 'egypt_token_abc'
        );
    }

    /**
     * **Property 2: Preservation** — retrieveTransaction() must send the
     * auth token as a 'token' query param (not in a POST body) for all
     * Egypt/Accept configs.
     *
     * @dataProvider egyptConfigProvider
     */
    public function test_egypt_retrieve_transaction_sends_token_as_query_param(array $config): void
    {
        $http = new HttpFactory();
        $http->fake(['*/acceptance/transactions/*' => $http::response(['id' => 999], 200)]);

        $client = new PaymobClient($config, $http);
        $client->retrieveTransaction('egypt_token_abc', '999');

        $http->assertSent(function ($request) {
            return $request->method() === 'GET'
                && str_contains($request->url(), 'acceptance/transactions/999')
                && $request->data()['token'] === 'egypt_token_abc';
        });
    }

    /**
     * **Property 2: Preservation** — no Authorization: Bearer header must be
     * added to any request for Egypt/Accept configs.
     *
     * Observed on unfixed code: no Authorization header present.
     *
     * @dataProvider egyptConfigProvider
     */
    public function test_egypt_requests_have_no_authorization_bearer_header(array $config): void
    {
        $http = new HttpFactory();
        $http->fake(['*/ecommerce/orders' => $http::response(['id' => 1], 200)]);

        $client = new PaymobClient($config, $http);
        $client->createOrder('egypt_token_abc', 1000, 'EGP', 'ord-eg-1');

        $http->assertSent(function ($request) {
            $authHeader = $request->header('Authorization')[0] ?? '';
            $this->assertEmpty(
                $authHeader,
                "No Authorization header should be set for Egypt/Accept configs. Got: '{$authHeader}'.",
            );

            return true;
        });
    }
}
