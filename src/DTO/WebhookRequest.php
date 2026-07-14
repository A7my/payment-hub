<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\DTO;

use InvalidArgumentException;
use JsonSerializable;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\WebhookSignature;

/**
 * Immutable DTO representing a normalised inbound webhook request.
 *
 * Built by WebhookController from the raw HTTP request before it is passed
 * to WebhookVerifier and subsequently to the Driver's processWebhook() method.
 *
 * Design decisions:
 * - $rawBody is the unmodified request body as received from the provider.
 *   Drivers use this for HMAC signature verification (the raw bytes matter).
 * - $headers contains the HTTP request headers with lowercased names.
 * - $signature wraps the provider's signature header value; it may be empty
 *   (see WebhookSignature design notes).
 * - $driver is the driver name extracted from the {driver} route parameter.
 */
final readonly class WebhookRequest implements JsonSerializable
{
    /**
     * @param string                $driver    The driver name from the route parameter (e.g. "stripe", "paymob").
     * @param string                $rawBody   The raw HTTP request body as received from the provider.
     * @param array<string, mixed>  $headers   HTTP request headers with lowercased names.
     * @param WebhookSignature      $signature The provider's signature header value (may be empty).
     * @param array<string, mixed>  $metadata  Arbitrary key-value metadata attached during processing.
     *
     * @throws InvalidArgumentException When $driver is empty.
     */
    public function __construct(
        public string $driver,
        public string $rawBody,
        public array $headers,
        public WebhookSignature $signature,
        public array $metadata = [],
    ) {
        if ($this->driver === '') {
            throw new InvalidArgumentException(
                'WebhookRequest $driver must not be empty.',
            );
        }
    }

    /**
     * Whether a signature header was present in the request.
     *
     * @return bool
     */
    public function hasSignature(): bool
    {
        return $this->signature->isPresent();
    }

    /**
     * Retrieve a specific header value by (lowercased) name.
     *
     * @param string $name    The lowercased header name (e.g. "x-signature", "content-type").
     * @param string $default Default value if the header is not present.
     *
     * @return string
     */
    public function header(string $name, string $default = ''): string
    {
        $value = $this->headers[strtolower($name)] ?? $default;

        return is_array($value) ? ($value[0] ?? $default) : (string) $value;
    }

    /**
     * Serialise to a JSON-compatible array.
     *
     * The raw body is excluded from serialisation (it may be large and contains
     * provider-specific binary/JSON data). The signature is included in its
     * truncated form for safe logging.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'driver'          => $this->driver,
            'signature'       => $this->signature->jsonSerialize(),
            'has_signature'   => $this->hasSignature(),
            'metadata'        => $this->metadata,
        ];
    }
}
