<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe;

/**
 * Verifies the authenticity of inbound Stripe webhook requests.
 *
 * Wraps Stripe's signature verification scheme (the `Stripe-Signature`
 * header, HMAC-SHA256 over `{timestamp}.{payload}` using the webhook
 * signing secret). Contains ONLY signature verification — no event
 * parsing, no framework DTO/Response construction, and no lifecycle
 * orchestration (that is {@see StripeDriver}'s and the framework's
 * `WebhookVerifier` service's job).
 */
final class StripeWebhookVerifier
{
    /**
     * @param array<string, mixed> $config The driver's config block from payment.drivers.stripe
     *                                      (must contain 'webhook_secret').
     */
    public function __construct(
        private readonly array $config = [],
    ) {
    }

    /**
     * Verify a raw webhook payload against its Stripe-Signature header.
     *
     * TODO: Use \Stripe\Webhook::constructEvent($payload, $signatureHeader, $this->webhookSecret())
     *       (or a manual HMAC-SHA256 comparison) and return true/false based on
     *       whether verification succeeds, instead of letting the SDK throw.
     *
     * @param string $payload         The raw, unparsed webhook request body.
     * @param string $signatureHeader The value of the `Stripe-Signature` header.
     *
     * @return bool True when the signature is valid for the configured webhook secret.
     */
    public function verify(string $payload, string $signatureHeader): bool
    {
        throw new \LogicException('StripeWebhookVerifier::verify() not yet implemented.');
    }

    /**
     * Resolve the configured Stripe webhook signing secret.
     *
     * TODO: return (string) ($this->config['webhook_secret'] ?? '');
     *
     * @return string
     */
    private function webhookSecret(): string
    {
        throw new \LogicException('StripeWebhookVerifier::webhookSecret() not yet implemented.');
    }
}
