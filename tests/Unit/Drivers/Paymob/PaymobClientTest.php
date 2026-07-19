<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Drivers\Paymob;

use Illuminate\Http\Client\Factory as HttpFactory;
use Mifatoyeh\LaravelPaymentFramework\Drivers\Paymob\PaymobApiException;
use Mifatoyeh\LaravelPaymentFramework\Drivers\Paymob\PaymobClient;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PaymobClient.
 *
 * Uses `Illuminate\Http\Client\Factory::fake()` directly (not the `Http`
 * facade) — a `Factory` instance is entirely standalone and testable
 * without an Orchestra Testbench / full framework bootstrap, keeping this
 * consistent with the plain-PHPUnit-TestCase convention used for the
 * Stripe driver's tests (which use the Stripe SDK's own swappable
 * ClientInterface seam for the same reason).
 *
 * UNVERIFIED AGAINST LIVE PAYMOB DOCS — see PaymobClient's own class
 * docblock. These tests confirm THIS client's behaviour is internally
 * consistent (right endpoint, right params, right error handling), not
 * that it matches Paymob's real API.
 */
final class PaymobClientTest extends TestCase
{
    private function config(): array
    {
        return [
            'api_key'        => 'test-api-key',
            'integration_id' => 12345,
            'iframe_id'      => '999',
            'base_url'       => 'https://accept.paymob.com/api',
            'timeout'        => 30,
        ];
    }

    // =========================================================================
    // authenticate()
    // =========================================================================

    /** @test */
    public function test_authenticate_returns_the_token(): void
    {
        $http = new HttpFactory();
        $http->fake(['*/auth/tokens' => $http::response(['token' => 'auth_abc123'], 200)]);

        $token = (new PaymobClient($this->config(), $http))->authenticate();

        $this->assertSame('auth_abc123', $token);
    }

    /** @test */
    public function test_authenticate_sends_the_configured_api_key(): void
    {
        $http = new HttpFactory();
        $http->fake(['*/auth/tokens' => $http::response(['token' => 'auth_abc123'], 200)]);

        (new PaymobClient($this->config(), $http))->authenticate();

        $http->assertSent(function ($request) {
            return str_contains($request->url(), 'auth/tokens')
                && $request['api_key'] === 'test-api-key';
        });
    }

    /** @test */
    public function test_authenticate_with_invalid_api_key_throws_paymob_api_exception(): void
    {
        $http = new HttpFactory();
        $http->fake(['*/auth/tokens' => $http::response(['detail' => 'Invalid API key.'], 401)]);

        $this->expectException(PaymobApiException::class);
        $this->expectExceptionMessage('Invalid API key.');

        (new PaymobClient($this->config(), $http))->authenticate();
    }

    /** @test */
    public function test_authenticate_with_blank_api_key_throws_before_any_http_call(): void
    {
        $http = new HttpFactory();
        $http->fake(['*' => $http::response(['token' => 'should_never_be_called'], 200)]);

        $config = $this->config();
        $config['api_key'] = '';

        $this->expectException(PaymobApiException::class);
        $this->expectExceptionMessageMatches('/PAYMOB_API_KEY is empty/');

        try {
            (new PaymobClient($config, $http))->authenticate();
        } finally {
            $http->assertNothingSent();
        }
    }

    /** @test */
    public function test_authenticate_ksa_mode_with_blank_secret_key_throws_before_any_http_call(): void
    {
        $http = new HttpFactory();
        $http->fake(['*' => $http::response(['id' => 1], 200)]);

        $config = $this->config();
        $config['base_url']  = 'https://ksa.paymob.com/api';
        $config['secret_key'] = '';

        $this->expectException(PaymobApiException::class);
        $this->expectExceptionMessageMatches('/PAYMOB_SECRET_KEY is empty/');

        try {
            (new PaymobClient($config, $http))->authenticate();
        } finally {
            $http->assertNothingSent();
        }
    }

    /** @test */
    public function test_authenticate_ksa_mode_with_valid_secret_key_returns_it_without_any_http_call(): void
    {
        $http = new HttpFactory();
        $http->fake(['*' => $http::response(['id' => 1], 200)]);

        $config = $this->config();
        $config['base_url']  = 'https://ksa.paymob.com/api';
        $config['secret_key'] = 'sau_sk_test_abc123';

        $token = (new PaymobClient($config, $http))->authenticate();

        $this->assertSame('sau_sk_test_abc123', $token);
        $http->assertNothingSent();
    }

    // =========================================================================
    // createOrder()
    // =========================================================================

    /** @test */
    public function test_create_order_forwards_amount_currency_and_merchant_order_id(): void
    {
        $http = new HttpFactory();
        $http->fake(['*/ecommerce/orders' => $http::response(['id' => 555], 200)]);

        (new PaymobClient($this->config(), $http))->createOrder('auth_token', 1000, 'EGP', 'idem-key-001');

        $http->assertSent(function ($request) {
            return str_contains($request->url(), 'ecommerce/orders')
                && $request['auth_token'] === 'auth_token'
                && $request['amount_cents'] === 1000
                && $request['currency'] === 'EGP'
                && $request['merchant_order_id'] === 'idem-key-001';
        });
    }

    /** @test */
    public function test_create_order_returns_the_raw_decoded_payload(): void
    {
        $http = new HttpFactory();
        $http->fake(['*/ecommerce/orders' => $http::response(['id' => 555, 'created_at' => '2026-01-01'], 200)]);

        $raw = (new PaymobClient($this->config(), $http))->createOrder('auth_token', 1000, 'EGP', 'idem-key-001');

        $this->assertSame(555, $raw['id']);
    }

    // =========================================================================
    // requestPaymentKey()
    // =========================================================================

    /** @test */
    public function test_request_payment_key_forwards_order_id_and_configured_integration_id(): void
    {
        $http = new HttpFactory();
        $http->fake(['*/acceptance/payment_keys' => $http::response(['token' => 'pk_abc'], 200)]);

        (new PaymobClient($this->config(), $http))->requestPaymentKey(
            'auth_token',
            555,
            1000,
            'EGP',
            ['first_name' => 'NA'],
        );

        $http->assertSent(function ($request) {
            return $request['order_id'] === 555
                && $request['integration_id'] === 12345
                && $request['billing_data']['first_name'] === 'NA';
        });
    }

    // =========================================================================
    // payWithToken()
    // =========================================================================

    /** @test */
    public function test_pay_with_token_sends_source_subtype_token_never_raw_card(): void
    {
        $http = new HttpFactory();
        $http->fake(['*/acceptance/payments/pay' => $http::response(['id' => 999, 'success' => true], 200)]);

        (new PaymobClient($this->config(), $http))->payWithToken('pk_abc', 'card_token_xyz');

        $http->assertSent(function ($request) {
            return $request['source']['subtype'] === 'TOKEN'
                && $request['source']['identifier'] === 'card_token_xyz'
                && $request['payment_token'] === 'pk_abc';
        });
    }

    /** @test */
    public function test_pay_with_token_returns_the_raw_transaction_payload(): void
    {
        $http = new HttpFactory();
        $http->fake(['*/acceptance/payments/pay' => $http::response(['id' => 999, 'success' => true], 200)]);

        $raw = (new PaymobClient($this->config(), $http))->payWithToken('pk_abc', 'card_token_xyz');

        $this->assertSame(999, $raw['id']);
        $this->assertTrue($raw['success']);
    }

    /** @test */
    public function test_pay_with_token_declined_still_decodes_the_200_response(): void
    {
        // A decline is HTTP 200 with success: false — not an HTTP error.
        $http = new HttpFactory();
        $http->fake(['*/acceptance/payments/pay' => $http::response(['id' => 999, 'success' => false], 200)]);

        $raw = (new PaymobClient($this->config(), $http))->payWithToken('pk_abc', 'card_token_xyz');

        $this->assertFalse($raw['success']);
    }

    // =========================================================================
    // voidTransaction() / captureTransaction() / refundTransaction()
    // =========================================================================

    /** @test */
    public function test_void_transaction_forwards_the_transaction_id(): void
    {
        $http = new HttpFactory();
        $http->fake(['*/acceptance/void_refund/void' => $http::response(['id' => 999, 'is_voided' => true], 200)]);

        (new PaymobClient($this->config(), $http))->voidTransaction('auth_token', '999');

        $http->assertSent(fn ($request) => $request['transaction_id'] === '999');
    }

    /** @test */
    public function test_capture_transaction_forwards_the_amount(): void
    {
        $http = new HttpFactory();
        $http->fake(['*/acceptance/capture' => $http::response(['id' => 999, 'success' => true], 200)]);

        (new PaymobClient($this->config(), $http))->captureTransaction('auth_token', '999', 500);

        $http->assertSent(fn ($request) => $request['amount_cents'] === 500);
    }

    /** @test */
    public function test_refund_transaction_forwards_the_amount(): void
    {
        $http = new HttpFactory();
        $http->fake(['*/acceptance/void_refund/refund' => $http::response(['id' => 999, 'is_refunded' => true], 200)]);

        (new PaymobClient($this->config(), $http))->refundTransaction('auth_token', '999', 300);

        $http->assertSent(fn ($request) => $request['amount_cents'] === 300);
    }

    // =========================================================================
    // retrieveTransaction()
    // =========================================================================

    /** @test */
    public function test_retrieve_transaction_sends_a_get_request_with_the_token_as_a_query_param(): void
    {
        $http = new HttpFactory();
        $http->fake(['*/acceptance/transactions/*' => $http::response(['id' => 999], 200)]);

        (new PaymobClient($this->config(), $http))->retrieveTransaction('auth_token', '999');

        $http->assertSent(function ($request) {
            return $request->method() === 'GET'
                && str_contains($request->url(), 'acceptance/transactions/999')
                && $request['token'] === 'auth_token';
        });
    }

    // =========================================================================
    // buildIframeUrl() / billingDataFrom()
    // =========================================================================

    /** @test */
    public function test_build_iframe_url_embeds_the_iframe_id_and_payment_token(): void
    {
        $url = (new PaymobClient($this->config()))->buildIframeUrl('pk_abc');

        $this->assertSame('https://accept.paymob.com/api/acceptance/iframes/999?payment_token=pk_abc', $url);
    }

    /** @test */
    public function test_billing_data_from_splits_name_into_first_and_last(): void
    {
        $data = (new PaymobClient($this->config()))->billingDataFrom('Mohamed Azmy', 'azmy@example.com', '+201234567890');

        $this->assertSame('Mohamed', $data['first_name']);
        $this->assertSame('Azmy', $data['last_name']);
        $this->assertSame('azmy@example.com', $data['email']);
        $this->assertSame('+201234567890', $data['phone_number']);
    }

    /** @test */
    public function test_billing_data_from_fills_missing_phone_with_na(): void
    {
        $data = (new PaymobClient($this->config()))->billingDataFrom('Solo', 'solo@example.com', null);

        $this->assertSame('NA', $data['phone_number']);
        $this->assertSame('NA', $data['city']);
    }

    // =========================================================================
    // Error handling
    // =========================================================================

    /** @test */
    public function test_a_failed_response_throws_paymob_api_exception_with_status_and_body(): void
    {
        $http = new HttpFactory();
        $http->fake(['*/ecommerce/orders' => $http::response(['detail' => 'Order validation failed.'], 400)]);

        try {
            (new PaymobClient($this->config(), $http))->createOrder('auth_token', 1000, 'EGP', 'idem-key-001');
            $this->fail('Expected PaymobApiException was not thrown.');
        } catch (PaymobApiException $e) {
            $this->assertSame(400, $e->getHttpStatus());
            $this->assertSame('Order validation failed.', $e->getMessage());
            $this->assertSame('Order validation failed.', $e->getBody()['detail']);
        }
    }

    /** @test */
    public function test_a_failed_response_without_detail_or_message_still_surfaces_the_raw_body(): void
    {
        // Regression guard: previously fell back to a generic "request
        // failed" string with no diagnostic value whenever Paymob's error
        // body didn't use 'detail' or 'message' — which is exactly what a
        // real 400 from the newer Intention API did, hiding the actual
        // cause. The raw body must now always be visible in the message.
        $http = new HttpFactory();
        $http->fake(['*/ecommerce/orders' => $http::response(['field_errors' => ['amount' => ['must be positive']]], 400)]);

        try {
            (new PaymobClient($this->config(), $http))->createOrder('auth_token', 1000, 'EGP', 'idem-key-001');
            $this->fail('Expected PaymobApiException was not thrown.');
        } catch (PaymobApiException $e) {
            $this->assertStringContainsString('field_errors', $e->getMessage());
            $this->assertStringContainsString('must be positive', $e->getMessage());
        }
    }

    /** @test */
    public function test_a_failed_response_with_an_empty_body_reports_that_explicitly(): void
    {
        $http = new HttpFactory();
        $http->fake(['*/ecommerce/orders' => $http::response([], 400)]);

        try {
            (new PaymobClient($this->config(), $http))->createOrder('auth_token', 1000, 'EGP', 'idem-key-001');
            $this->fail('Expected PaymobApiException was not thrown.');
        } catch (PaymobApiException $e) {
            $this->assertStringContainsString('empty response body', $e->getMessage());
        }
    }
}
