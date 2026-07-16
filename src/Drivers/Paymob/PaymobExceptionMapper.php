<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Drivers\Paymob;

use Mifatoyeh\LaravelPaymentFramework\Exceptions\AuthorizationFailedException;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\InvalidConfigurationException;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\PaymentException;
use Throwable;

/**
 * Converts thrown exceptions (chiefly {@see PaymobApiException}) into
 * framework {@see PaymentException} subclasses.
 *
 * Paymob-specific counterpart to
 * {@see \Mifatoyeh\LaravelPaymentFramework\Drivers\AbstractDriver::wrapException()}.
 * Unlike {@see \Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeExceptionMapper},
 * which switches on the Stripe SDK's own typed exception classes, this maps
 * on HTTP status code — Paymob has no SDK and therefore no typed exception
 * hierarchy to inspect. UNVERIFIED: the exact status codes Paymob returns
 * for each failure category are assumed from general REST API conventions,
 * not confirmed against Paymob's live API.
 *
 * Mapping table:
 *
 *   | HTTP status         | Framework exception           |
 *   |----------------------|--------------------------------|
 *   | 401, 403             | AuthorizationFailedException  |
 *   | 400, 404, 422        | InvalidConfigurationException |
 *   | 429, 5xx, other      | PaymentException (generic)    |
 *   | Any other Throwable  | PaymentException (generic)    |
 *
 * Every branch preserves the original exception as `$previous`, the original
 * message, and the caller's context array — no debugging information is
 * ever discarded. Already-mapped `PaymentException` instances are returned
 * unchanged (never double-wrapped), mirroring `StripeExceptionMapper`.
 */
final class PaymobExceptionMapper
{
    /**
     * @param array<string, mixed> $context Additional context (e.g. ['operation' => 'refund']).
     */
    public function map(Throwable $e, array $context = []): PaymentException
    {
        if ($e instanceof PaymentException) {
            return $e;
        }

        $message = $this->buildMessage($e, $context);

        if ($e instanceof PaymobApiException) {
            return match (true) {
                in_array($e->getHttpStatus(), [401, 403], true)       => new AuthorizationFailedException($message, 0, $e),
                in_array($e->getHttpStatus(), [400, 404, 422], true)  => new InvalidConfigurationException($message, 0, $e),
                default                                                => new PaymentException($message, 0, $e),
            };
        }

        return new PaymentException($message, (int) $e->getCode(), $e);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function buildMessage(Throwable $e, array $context): string
    {
        $operation = (string) ($context['operation'] ?? 'operation');
        $message   = sprintf('[paymob] %s failed: %s', $operation, $e->getMessage());

        $details = array_diff_key($context, ['operation' => true]);

        if ($e instanceof PaymobApiException) {
            $details['http_status'] = $e->getHttpStatus();
        }

        $details = array_filter(
            $details,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );

        if ($details === []) {
            return $message;
        }

        return $message . ' ' . json_encode($details, JSON_UNESCAPED_SLASHES);
    }
}
