<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Drivers\MyFatoorah;

use RuntimeException;
use Throwable;

/**
 * Thrown by {@see MyFatoorahClient} when MyFatoorah returns a failed HTTP
 * response or `IsSuccess: false` body.
 */
final class MyFatoorahApiException extends RuntimeException
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
