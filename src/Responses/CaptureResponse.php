<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Responses;

use JsonSerializable;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Responses\CaptureResponseContract;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;

/**
 * Standardised immutable response for the capture() operation.
 *
 * A successful capture transitions a transaction from Authorized to Captured.
 * Some providers return the original transaction ID as the capture ID; others
 * generate a new one. Drivers should use whichever the provider returns.
 *
 * Design decisions:
 * - $rawResponse is excluded from jsonSerialize() output — it may contain
 *   sensitive provider data. Call getRawResponse() when needed.
 */
final class CaptureResponse implements CaptureResponseContract, JsonSerializable
{
    /**
     * @param bool                 $successful  Whether the capture was successfully processed.
     * @param string               $captureId   Provider-assigned capture identifier.
     * @param Money                $amount      The monetary amount captured.
     * @param PaymentStatus        $status      Canonical status after capture (Captured on success).
     * @param string               $message     Human-readable result message.
     * @param array<string, mixed> $rawResponse Complete raw provider API response for debugging.
     */
    public function __construct(
        private readonly bool $successful,
        private readonly string $captureId,
        private readonly Money $amount,
        private readonly PaymentStatus $status,
        private readonly string $message,
        private readonly array $rawResponse,
    ) {
    }

    /** {@inheritDoc} */
    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    /** {@inheritDoc} */
    public function getCaptureId(): string
    {
        return $this->captureId;
    }

    /** {@inheritDoc} */
    public function getAmount(): Money
    {
        return $this->amount;
    }

    /** {@inheritDoc} */
    public function getStatus(): PaymentStatus
    {
        return $this->status;
    }

    /** {@inheritDoc} */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * The raw provider API response payload (for debugging and logging).
     *
     * @return array<string, mixed>
     */
    public function getRawResponse(): array
    {
        return $this->rawResponse;
    }

    /**
     * Serialise to a JSON-compatible array.
     *
     * rawResponse is excluded — it is large and may contain sensitive provider data.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'successful' => $this->successful,
            'capture_id' => $this->captureId,
            'amount'     => $this->amount->jsonSerialize(),
            'status'     => $this->status->value,
            'message'    => $this->message,
        ];
    }
}
