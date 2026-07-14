<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Drivers\Stripe;

use Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeExceptionMapper;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\AuthorizationFailedException;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\InvalidConfigurationException;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\PaymentException;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\WebhookVerificationException;
use PHPUnit\Framework\TestCase;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\PermissionException;
use Stripe\Exception\RateLimitException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException as StripeUnexpectedValueException;
use Stripe\Exception\UnknownApiErrorException;

/**
 * Unit tests for StripeExceptionMapper.
 *
 * Covers every Stripe SDK exception type the mapper is required to handle,
 * plus the generic fallback, the already-mapped passthrough, and the
 * debugging-information preservation rules (previous exception, message,
 * code, context array, Stripe diagnostics).
 */
final class StripeExceptionMapperTest extends TestCase
{
    private StripeExceptionMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new StripeExceptionMapper();
    }

    // =========================================================================
    // Mapping table
    // =========================================================================

    /** @test */
    public function test_card_exception_maps_to_authorization_failed_exception(): void
    {
        $original = CardException::factory('Your card was declined.', 402, null, null, null, 'card_declined');

        $mapped = $this->mapper->map($original, ['operation' => 'charge']);

        $this->assertInstanceOf(AuthorizationFailedException::class, $mapped);
    }

    /** @test */
    public function test_authentication_exception_maps_to_authorization_failed_exception(): void
    {
        $original = AuthenticationException::factory('Invalid API key provided.', 401, null, null, null, 'invalid_api_key');

        $mapped = $this->mapper->map($original, ['operation' => 'charge']);

        $this->assertInstanceOf(AuthorizationFailedException::class, $mapped);
    }

    /** @test */
    public function test_permission_exception_maps_to_authorization_failed_exception(): void
    {
        $original = PermissionException::factory('The API key does not have permission.', 403, null, null, null, 'permission_error');

        $mapped = $this->mapper->map($original, ['operation' => 'refund']);

        $this->assertInstanceOf(AuthorizationFailedException::class, $mapped);
    }

    /** @test */
    public function test_invalid_request_exception_maps_to_invalid_configuration_exception(): void
    {
        $original = InvalidRequestException::factory('Missing required param: amount.', 400, null, null, null, 'parameter_missing', 'amount');

        $mapped = $this->mapper->map($original, ['operation' => 'charge']);

        $this->assertInstanceOf(InvalidConfigurationException::class, $mapped);
    }

    /** @test */
    public function test_rate_limit_exception_maps_to_generic_payment_exception(): void
    {
        $original = RateLimitException::factory('Too many requests.', 429, null, null, null, 'rate_limit');

        $mapped = $this->mapper->map($original, ['operation' => 'charge']);

        $this->assertInstanceOf(PaymentException::class, $mapped);
    }

    /**
     * RateLimitException extends InvalidRequestException in the Stripe SDK,
     * so the mapper must check it FIRST or it would be misclassified as an
     * InvalidConfigurationException. This is a regression guard for exactly
     * that ordering bug.
     *
     * @test
     */
    public function test_rate_limit_exception_is_not_misclassified_as_invalid_configuration(): void
    {
        $original = RateLimitException::factory('Too many requests.', 429, null, null, null, 'rate_limit');

        $mapped = $this->mapper->map($original);

        $this->assertNotInstanceOf(InvalidConfigurationException::class, $mapped);
        $this->assertInstanceOf(PaymentException::class, $mapped);
    }

    /** @test */
    public function test_api_connection_exception_maps_to_generic_payment_exception(): void
    {
        $original = ApiConnectionException::factory('Could not connect to Stripe.');

        $mapped = $this->mapper->map($original, ['operation' => 'charge']);

        $this->assertInstanceOf(PaymentException::class, $mapped);
        $this->assertNotInstanceOf(AuthorizationFailedException::class, $mapped);
        $this->assertNotInstanceOf(InvalidConfigurationException::class, $mapped);
    }

    /** @test */
    public function test_signature_verification_exception_maps_to_webhook_verification_exception(): void
    {
        $original = SignatureVerificationException::factory('Unable to verify signature.', '{}', 't=1,v1=abc');

        $mapped = $this->mapper->map($original, ['operation' => 'verifyWebhookSignature']);

        $this->assertInstanceOf(WebhookVerificationException::class, $mapped);
    }

    /** @test */
    public function test_stripe_unexpected_value_exception_maps_to_generic_payment_exception(): void
    {
        $original = new StripeUnexpectedValueException('Unexpected response shape.');

        $mapped = $this->mapper->map($original, ['operation' => 'charge']);

        $this->assertInstanceOf(PaymentException::class, $mapped);
    }

    /** @test */
    public function test_unknown_api_error_exception_maps_to_generic_payment_exception(): void
    {
        // Any ApiErrorException subtype not explicitly named in the mapping
        // table (e.g. Stripe's own UnknownApiErrorException) must still fall
        // through to a generic PaymentException — never LogicException.
        $original = UnknownApiErrorException::factory('An unknown error occurred.', 500, null, null, null, null);

        $mapped = $this->mapper->map($original, ['operation' => 'charge']);

        $this->assertInstanceOf(PaymentException::class, $mapped);
    }

    /** @test */
    public function test_generic_throwable_maps_to_generic_payment_exception(): void
    {
        $original = new \RuntimeException('Something completely unrelated to Stripe.');

        $mapped = $this->mapper->map($original, ['operation' => 'charge']);

        $this->assertInstanceOf(PaymentException::class, $mapped);
    }

    // =========================================================================
    // Never throws LogicException
    // =========================================================================

    /** @test */
    public function test_map_never_throws_logic_exception_for_any_supported_type(): void
    {
        $exceptions = [
            CardException::factory('declined', 402),
            AuthenticationException::factory('bad key', 401),
            PermissionException::factory('forbidden', 403),
            InvalidRequestException::factory('bad param', 400),
            RateLimitException::factory('rate limited', 429),
            ApiConnectionException::factory('connection failed'),
            SignatureVerificationException::factory('bad signature', '{}', 'sig'),
            new StripeUnexpectedValueException('unexpected'),
            UnknownApiErrorException::factory('unknown', 500),
            new \RuntimeException('generic'),
            new \Exception('plain exception'),
        ];

        foreach ($exceptions as $exception) {
            $mapped = $this->mapper->map($exception);
            $this->assertInstanceOf(PaymentException::class, $mapped);
        }

        $this->addToAssertionCount(1);
    }

    // =========================================================================
    // Debugging information preservation
    // =========================================================================

    /** @test */
    public function test_previous_exception_is_preserved(): void
    {
        $original = new \RuntimeException('boom');

        $mapped = $this->mapper->map($original);

        $this->assertSame($original, $mapped->getPrevious());
    }

    /** @test */
    public function test_original_code_is_preserved(): void
    {
        $original = new \RuntimeException('boom', 503);

        $mapped = $this->mapper->map($original);

        $this->assertSame(503, $mapped->getCode());
    }

    /** @test */
    public function test_original_message_is_embedded_in_mapped_message(): void
    {
        $original = new \RuntimeException('Card was declined for insufficient funds.');

        $mapped = $this->mapper->map($original, ['operation' => 'charge']);

        $this->assertStringContainsString('Card was declined for insufficient funds.', $mapped->getMessage());
        $this->assertStringContainsString('charge', $mapped->getMessage());
    }

    /** @test */
    public function test_context_array_is_embedded_in_mapped_message(): void
    {
        $original = new \RuntimeException('boom');

        $mapped = $this->mapper->map($original, [
            'operation'      => 'refund',
            'transaction_id' => 'txn_abc123',
        ]);

        $this->assertStringContainsString('refund', $mapped->getMessage());
        $this->assertStringContainsString('txn_abc123', $mapped->getMessage());
    }

    /** @test */
    public function test_stripe_diagnostics_are_embedded_for_api_error_exceptions(): void
    {
        $original = InvalidRequestException::factory(
            'Missing required param: amount.',
            400,
            null,
            ['request_id' => 'req_test_123'],
            ['Request-Id' => 'req_test_123'],
            'parameter_missing',
            'amount',
        );

        $mapped = $this->mapper->map($original, ['operation' => 'charge']);

        $this->assertStringContainsString('req_test_123', $mapped->getMessage());
        $this->assertStringContainsString('parameter_missing', $mapped->getMessage());
        $this->assertStringContainsString('400', $mapped->getMessage());
    }

    // =========================================================================
    // Already-mapped passthrough
    // =========================================================================

    /** @test */
    public function test_already_mapped_payment_exception_is_returned_unchanged(): void
    {
        $original = new AuthorizationFailedException('Already a framework exception.');

        $mapped = $this->mapper->map($original, ['operation' => 'charge']);

        $this->assertSame($original, $mapped);
    }
}
