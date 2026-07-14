<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\DTO;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Immutable DTO carrying customer identity information.
 *
 * Passed as part of PaymentRequest, SubscriptionRequest, TokenChargeRequest,
 * and other request DTOs to provide the provider with customer context for
 * risk scoring, receipts, and saved payment methods.
 *
 * Validation rules:
 * - $name and $email are required and must be non-empty.
 * - Email format is NOT validated here — format validation belongs in the
 *   application layer. The framework only ensures a value is present.
 * - $phone and $externalId are optional.
 */
final readonly class CustomerData implements JsonSerializable
{
    /**
     * @param string      $name       Customer's full name. Required.
     * @param string      $email      Customer's email address. Required.
     * @param string|null $phone      Customer's phone number in E.164 or local format. Optional.
     * @param string|null $externalId Host application's customer identifier (e.g. user UUID). Optional.
     *
     * @throws InvalidArgumentException When $name or $email is empty.
     */
    public function __construct(
        public string $name,
        public string $email,
        public ?string $phone = null,
        public ?string $externalId = null,
    ) {
        if ($this->name === '') {
            throw new InvalidArgumentException('CustomerData $name must not be empty.');
        }

        if ($this->email === '') {
            throw new InvalidArgumentException('CustomerData $email must not be empty.');
        }
    }

    /**
     * Whether a phone number is present.
     *
     * @return bool
     */
    public function hasPhone(): bool
    {
        return $this->phone !== null && $this->phone !== '';
    }

    /**
     * Whether a host-application customer identifier is present.
     *
     * @return bool
     */
    public function hasExternalId(): bool
    {
        return $this->externalId !== null && $this->externalId !== '';
    }

    /**
     * Serialise to a JSON-compatible array.
     *
     * @return array<string, string|null>
     */
    public function jsonSerialize(): array
    {
        return [
            'name'        => $this->name,
            'email'       => $this->email,
            'phone'       => $this->phone,
            'external_id' => $this->externalId,
        ];
    }
}
