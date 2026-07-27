<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Drivers\MyFatoorah;

use Mifatoyeh\LaravelPaymentFramework\Exceptions\AuthorizationFailedException;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\InvalidConfigurationException;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\PaymentException;
use Throwable;

/**
 * Converts thrown exceptions into framework {@see PaymentException} subclasses.
 *
 * Maps on HTTP status (MyFatoorah has no official typed PHP SDK exception hierarchy).
 */
final class MyFatoorahExceptionMapper
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

        if ($e instanceof MyFatoorahApiException) {
            return match (true) {
                in_array($e->getHttpStatus(), [401, 403], true)      => new AuthorizationFailedException($message, 0, $e),
                in_array($e->getHttpStatus(), [400, 404, 422], true) => new InvalidConfigurationException($message, 0, $e),
                default                                               => new PaymentException($message, 0, $e),
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
        $message   = sprintf('[myfatoorah] %s failed: %s', $operation, $e->getMessage());

        $details = array_diff_key($context, ['operation' => true]);

        if ($e instanceof MyFatoorahApiException) {
            $details['http_status'] = $e->getHttpStatus();
        }

        $details = array_filter(
            $details,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );

        if ($details !== []) {
            $message .= ' ' . json_encode($details, JSON_UNESCAPED_SLASHES);
        }

        return $message;
    }
}
