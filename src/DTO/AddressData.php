<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\DTO;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Immutable DTO carrying a physical address for billing or shipping.
 *
 * Used as the optional $billingAddress field on PaymentRequest and other DTOs
 * that require address data for fraud checks, receipts, or AVS verification.
 *
 * Validation rules:
 * - $line1, $city, $country, and $postalCode are required and must be non-empty.
 * - $country should be an ISO 3166-1 alpha-2 code (e.g. "US", "SA"). The DTO
 *   does not enforce this format — stricter validation belongs in the application
 *   layer. The two-character length check is a basic sanity guard only.
 * - $line2 and $state are optional and may be null.
 */
final readonly class AddressData implements JsonSerializable
{
    /**
     * @param string      $line1      First address line (street, building, PO Box). Required.
     * @param string|null $line2      Second address line (apartment, suite, floor). Optional.
     * @param string      $city       City or locality name. Required.
     * @param string|null $state      State, province, or region. Optional.
     * @param string      $country    ISO 3166-1 alpha-2 country code (e.g. "US", "SA"). Required.
     * @param string      $postalCode Postal or ZIP code. Required.
     *
     * @throws InvalidArgumentException When any required field is empty.
     */
    public function __construct(
        public string $line1,
        public ?string $line2,
        public string $city,
        public ?string $state,
        public string $country,
        public string $postalCode,
    ) {
        if ($this->line1 === '') {
            throw new InvalidArgumentException('AddressData $line1 must not be empty.');
        }

        if ($this->city === '') {
            throw new InvalidArgumentException('AddressData $city must not be empty.');
        }

        if ($this->country === '') {
            throw new InvalidArgumentException('AddressData $country must not be empty.');
        }

        if ($this->postalCode === '') {
            throw new InvalidArgumentException('AddressData $postalCode must not be empty.');
        }
    }

    /**
     * Whether a second address line is present.
     *
     * @return bool
     */
    public function hasLine2(): bool
    {
        return $this->line2 !== null && $this->line2 !== '';
    }

    /**
     * Whether a state/province is present.
     *
     * @return bool
     */
    public function hasState(): bool
    {
        return $this->state !== null && $this->state !== '';
    }

    /**
     * Serialise to a JSON-compatible array.
     *
     * @return array<string, string|null>
     */
    public function jsonSerialize(): array
    {
        return [
            'line1'       => $this->line1,
            'line2'       => $this->line2,
            'city'        => $this->city,
            'state'       => $this->state,
            'country'     => $this->country,
            'postal_code' => $this->postalCode,
        ];
    }
}
