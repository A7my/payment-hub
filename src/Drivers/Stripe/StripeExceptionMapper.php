<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe;

use Mifatoyeh\LaravelPaymentFramework\Exceptions\AuthorizationFailedException;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\InvalidConfigurationException;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\PaymentException;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\WebhookVerificationException;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\PermissionException;
use Stripe\Exception\RateLimitException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException as StripeUnexpectedValueException;
use Throwable;

/**
 * Converts Stripe SDK exceptions into framework {@see PaymentException} subclasses.
 *
 * Stripe-specific counterpart to {@see \Mifatoyeh\LaravelPaymentFramework\Drivers\AbstractDriver::wrapException()}.
 * Where `wrapException()` provides a generic fallback (wrap-and-preserve),
 * this mapper inspects the concrete Stripe SDK exception type and maps it to
 * the most specific framework exception available.
 *
 * Mapping table:
 *
 *   | Stripe exception                | Framework exception          |
 *   |----------------------------------|-------------------------------|
 *   | CardException                    | AuthorizationFailedException |
 *   | AuthenticationException          | AuthorizationFailedException |
 *   | PermissionException              | AuthorizationFailedException |
 *   | RateLimitException                | PaymentException (generic)   |
 *   | InvalidRequestException          | InvalidConfigurationException|
 *   | ApiConnectionException           | PaymentException (generic)   |
 *   | SignatureVerificationException   | WebhookVerificationException |
 *   | UnexpectedValueException (Stripe) | PaymentException (generic)   |
 *   | ApiErrorException (any other)     | PaymentException (generic)   |
 *   | Any other Throwable               | PaymentException (generic)   |
 *
 * Every branch preserves the original exception as `$previous`, the original
 * message (embedded in the new message), the original code, and the caller's
 * context array — no debugging information is ever discarded. Already-mapped
 * `PaymentException` instances are returned unchanged (never double-wrapped),
 * mirroring `AbstractDriver::wrapException()`.
 *
 * Contains ONLY exception translation — no HTTP communication, no response
 * mapping, and no lifecycle orchestration.
 */
final class StripeExceptionMapper
{
    /**
     * Map a caught exception to the appropriate framework PaymentException subclass.
     *
     * @param Throwable            $e       The original exception thrown by the Stripe SDK.
     * @param array<string, mixed> $context Additional context (e.g. ['operation' => 'refund']).
     *
     * @return PaymentException The mapped framework exception, wrapping $e as $previous.
     */
    public function map(Throwable $e, array $context = []): PaymentException
    {
        if ($e instanceof PaymentException) {
            return $e;
        }

        $message = $this->buildMessage($e, $context);
        $code    = (int) $e->getCode();

        // NOTE: RateLimitException extends InvalidRequestException, so it MUST
        // be checked before InvalidRequestException below.
        return match (true) {
            $e instanceof CardException                 => new AuthorizationFailedException($message, $code, $e),
            $e instanceof RateLimitException             => new PaymentException($message, $code, $e),
            $e instanceof InvalidRequestException        => new InvalidConfigurationException($message, $code, $e),
            $e instanceof AuthenticationException        => new AuthorizationFailedException($message, $code, $e),
            $e instanceof PermissionException            => new AuthorizationFailedException($message, $code, $e),
            $e instanceof ApiConnectionException         => new PaymentException($message, $code, $e),
            $e instanceof SignatureVerificationException => new WebhookVerificationException($message, $code, $e),
            $e instanceof StripeUnexpectedValueException => new PaymentException($message, $code, $e),
            $e instanceof ApiErrorException              => new PaymentException($message, $code, $e),
            default                                       => new PaymentException($message, $code, $e),
        };
    }

    /**
     * Build a descriptive message that preserves the original message and context.
     *
     * Includes Stripe-specific diagnostic fields (request id, Stripe error
     * code, HTTP status) when available, so no debugging information present
     * on the original exception is lost in translation.
     *
     * @param Throwable            $e       The original exception.
     * @param array<string, mixed> $context Additional caller-supplied context.
     */
    private function buildMessage(Throwable $e, array $context): string
    {
        $operation = (string) ($context['operation'] ?? 'operation');
        $message   = sprintf('[stripe] %s failed: %s', $operation, $e->getMessage());

        $details = array_filter(
            array_merge(
                array_diff_key($context, ['operation' => true]),
                $this->stripeDiagnostics($e),
            ),
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );

        if ($details === []) {
            return $message;
        }

        return $message . ' ' . json_encode($details, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Extract Stripe-specific diagnostic fields from an ApiErrorException.
     *
     * @return array<string, mixed>
     */
    private function stripeDiagnostics(Throwable $e): array
    {
        if (! $e instanceof ApiErrorException) {
            return [];
        }

        return [
            'stripe_request_id' => $e->getRequestId(),
            'stripe_code'       => $e->getStripeCode(),
            'http_status'       => $e->getHttpStatus(),
        ];
    }
}
