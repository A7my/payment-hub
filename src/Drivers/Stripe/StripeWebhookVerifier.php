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
     * Delegates to `\Stripe\WebhookSignature::verifyHeader()` (the same
     * routine `\Stripe\Webhook::constructEvent()` uses internally — verified
     * against the SDK, `vendor/stripe/stripe-php/lib/WebhookSignature.php`):
     * extracts the `t=`/`v1=` pairs from the header, recomputes
     * `hash_hmac('sha256', "{timestamp}.{payload}", $secret)`, and compares
     * it against every `v1` signature present via a constant-time comparison
     * — then checks the timestamp is within `Webhook::DEFAULT_TOLERANCE`
     * (300 seconds) of now, to reject a replayed old payload. Both an
     * invalid signature and a missing/unparsable header throw
     * `Exception\SignatureVerificationException` (and a malformed header
     * with no timestamp/signature pair also throws that same exception, not
     * `UnexpectedValueException` — verified against the SDK, that latter
     * exception is reserved for `constructEvent()`'s own JSON-decode step,
     * which this method never reaches). Every Throwable here means "not
     * valid" for this method's purposes, so it is caught broadly and turned
     * into `false` rather than propagating — signature verification is a
     * boolean gate, not a place that should ever surface an SDK exception to
     * the caller.
     *
     * An empty configured secret always fails closed (never verified as
     * valid) rather than skipping the check — see {@see self::webhookSecret()}.
     *
     * @param string $payload         The raw, unparsed webhook request body.
     * @param string $signatureHeader The value of the `Stripe-Signature` header.
     *
     * @return bool True when the signature is valid for the configured webhook secret.
     */
    public function verify(string $payload, string $signatureHeader): bool
    {
        $secret = $this->webhookSecret();

        if ($secret === '' || $signatureHeader === '') {
            return false;
        }

        try {
            // $tolerance is NOT defaulted by WebhookSignature::verifyHeader()
            // itself (its own default is `null`, which skips the timestamp
            // check entirely — verified against the SDK) the way
            // Webhook::constructEvent() defaults it for callers going
            // through that higher-level method. Calling verifyHeader()
            // directly, as this method does, must pass it explicitly or a
            // replayed old payload would verify successfully forever.
            return \Stripe\WebhookSignature::verifyHeader(
                $payload,
                $signatureHeader,
                $secret,
                \Stripe\Webhook::DEFAULT_TOLERANCE,
            );
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Resolve the configured Stripe webhook signing secret.
     *
     * @return string
     */
    private function webhookSecret(): string
    {
        return (string) ($this->config['webhook_secret'] ?? '');
    }
}
