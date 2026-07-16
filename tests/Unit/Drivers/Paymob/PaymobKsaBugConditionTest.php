<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Drivers\Paymob;

use Illuminate\Http\Client\Factory as HttpFactory;
use Mifatoyeh\LaravelPaymentFramework\Drivers\Paymob\PaymobClient;
use PHPUnit\Framework\TestCase;

/**
 * Property 1: Bug Condition — KSA Config Fires POST /auth/tokens (Exploration Test)
 *
 * This test is written BEFORE implementing the fix to surface counterexamples
 * that demonstrate the bug exists. It MUST FAIL on unfixed code.
 *
 * Bug Condition (isBugCondition): any config where
 *   - base_url contains 'ksa.paymob.com', OR
 *   - secret_key starts with 'sau_sk_test_', OR
 *   - secret_key starts with 'sau_sk_live_'
 *
 * Expected (fixed) behavior for all such configs:
 *   - authenticate() returns secret_key WITHOUT calling POST /auth/tokens
 *   - Every outgoing HTTP request carries Authorization: Token <secret_key>
 *     (scheme corrected from Bearer after a live 401 — see
 *     test_ksa_requests_carry_authorization_token_header()'s own docblock)
 *   - auth_token is absent from all request bodies
 *
 * Run on UNFIXED code → FAILS (proves bug exists).
 * Run on FIXED code  → PASSES (proves bug is resolved).
 */
final class PaymobKsaBugConditionTest extends TestCase
{
    /**
     * KSA config variants that satisfy isBugCondition.
     *
     * @return array<string, array{config: array<string, mixed>}>
     */
    public static function ksaConfigProvider(): array
    {
        return [
            'ksa_base_url' => [
                'config' => [
                    'base_url'       => 'https://ksa.paymob.com/api',
                    'api_key'        => 'some-api-key',
                    'secret_key'     => 'sau_sk_test_abc123',
                    'integration_id' => 999,
                    'timeout'        => 30,
                ],
            ],
            'sau_sk_test_prefix' => [
                'config' => [
                    'base_url'       => 'https://accept.paymob.com/api',
                    'api_key'        => 'some-api-key',
                    'secret_key'     => 'sau_sk_test_abc123',
                    'integration_id' => 999,
                    'timeout'        => 30,
                ],
            ],
            'sau_sk_live_prefix' => [
                'config' => [
                    'base_url'       => 'https://accept.paymob.com/api',
                    'api_key'        => 'some-api-key',
                    'secret_key'     => 'sau_sk_live_xyz789',
                    'integration_id' => 999,
                    'timeout'        => 30,
                ],
            ],
        ];
    }

    /**
     * **Property 1: Bug Condition** — authenticate() must NOT call POST /auth/tokens
     * for any KSA config; it must return the secret_key directly.
     *
     * EXPECTED OUTCOME on unfixed code: FAILS.
     * Counterexample: authenticate() fires POST /auth/tokens regardless of KSA config.
     *
     * @dataProvider ksaConfigProvider
     */
    public function test_ksa_authenticate_returns_secret_key_without_calling_auth_tokens(array $config): void
    {
        $http = new HttpFactory();

        // If the bug is present, the unfixed code will call /auth/tokens and
        // receive this fake 403 (matching real KSA behaviour).
        $http->fake([
            '*/auth/tokens' => $http::response(['detail' => 'KSA does not expose /auth/tokens'], 403),
            '*'             => $http::response([], 200),
        ]);

        $client = new PaymobClient($config, $http);
        $token  = $client->authenticate();

        // On fixed code: token === secret_key, no /auth/tokens call made.
        $this->assertSame(
            $config['secret_key'],
            $token,
            "authenticate() should return secret_key directly in KSA mode, not call /auth/tokens. " .
            "Counterexample: config with base_url='{$config['base_url']}' and secret_key='{$config['secret_key']}' still POSTed to /auth/tokens.",
        );

        $http->assertNotSent(function ($request) {
            return str_contains($request->url(), 'auth/tokens');
        });
    }

    /**
     * **Property 1: Bug Condition** — createOrder() must NOT include auth_token
     * in its request body when operating in KSA mode.
     *
     * EXPECTED OUTCOME on unfixed code: FAILS.
     * Counterexample: auth_token appears in createOrder body for all KSA configs.
     *
     * @dataProvider ksaConfigProvider
     */
    public function test_ksa_create_order_has_no_auth_token_in_body(array $config): void
    {
        $http = new HttpFactory();
        $http->fake([
            '*/ecommerce/orders' => $http::response(['id' => 555], 200),
            '*'                  => $http::response([], 200),
        ]);

        $client = new PaymobClient($config, $http);
        $client->createOrder('__any_token__', 1000, 'SAR', 'ord-1');

        $http->assertSent(function ($request) use ($config) {
            $authToken = $request->data()['auth_token'] ?? null;
            $this->assertNull(
                $authToken,
                "createOrder() should NOT include auth_token in request body in KSA mode. " .
                "Counterexample: auth_token='{$authToken}' found in body for config with secret_key='{$config['secret_key']}'.",
            );

            return true;
        });
    }

    /**
     * **Property 1: Bug Condition** — every outgoing request must carry
     * Authorization: Token <secret_key> in KSA mode.
     *
     * Scheme corrected from `Bearer` to `Token` after a live call against
     * Paymob's real KSA API returned HTTP 401 with the message
     * "Authentication credentials were not provided." — the exact stock
     * error Django REST Framework's `TokenAuthentication` returns when no
     * credentials are found in a scheme it recognises (DRF's
     * `TokenAuthentication` expects `Token`, not `Bearer`). See
     * {@see \Mifatoyeh\LaravelPaymentFramework\Drivers\Paymob\PaymobClient::request()}'s
     * own docblock for the same note.
     *
     * EXPECTED OUTCOME on unfixed code: FAILS.
     * Counterexample: no Authorization header is set for KSA configs.
     *
     * @dataProvider ksaConfigProvider
     */
    public function test_ksa_requests_carry_authorization_token_header(array $config): void
    {
        $http = new HttpFactory();
        $http->fake([
            '*/ecommerce/orders' => $http::response(['id' => 555], 200),
            '*'                  => $http::response([], 200),
        ]);

        $client = new PaymobClient($config, $http);
        $client->createOrder('__any_token__', 1000, 'SAR', 'ord-1');

        $expectedHeader = 'Token ' . $config['secret_key'];

        $http->assertSent(function ($request) use ($expectedHeader, $config) {
            $authHeader = $request->header('Authorization')[0] ?? '';
            $this->assertSame(
                $expectedHeader,
                $authHeader,
                "createOrder() request should carry 'Authorization: {$expectedHeader}' header in KSA mode. " .
                "Counterexample: header was '{$authHeader}' for config with secret_key='{$config['secret_key']}'.",
            );

            return true;
        });
    }
}
