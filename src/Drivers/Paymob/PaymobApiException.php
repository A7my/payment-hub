<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Drivers\Paymob;

use RuntimeException;
use Throwable;

/**
 * Thrown by {@see PaymobClient} when Paymob returns a non-2xx HTTP response.
 *
 * Paymob has no official SDK and therefore no typed exception hierarchy the
 * way Stripe's SDK provides (e.g. {@see \Stripe\Exception\CardException}).
 * This is a minimal, homemade stand-in carrying just enough information
 * (HTTP status + decoded body) for {@see PaymobExceptionMapper} to translate
 * into the framework's own exception hierarchy — the same role
 * `\Stripe\Exception\ApiErrorException` plays for the Stripe driver.
 */
final class PaymobApiException extends RuntimeException
{
    /**
     * @param array<string, mixed> $body The decoded JSON error body, if any.
     */
    public function __construct(
        string $message,
        private readonly int $httpStatus,
        private readonly array $body = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * @return array<string, mixed>
     */
    public function getBody(): array
    {
        return $this->body;
    }
}
