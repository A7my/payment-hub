<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Drivers\Paymob;

/**
 * Verifies the authenticity of inbound Paymob "Transaction Processed
 * Callback" requests via HMAC-SHA512.
 *
 * UNVERIFIED AGAINST A LIVE SIGNED PAYLOAD — same caveat as every other
 * Paymob touchpoint in this package (see {@see PaymobClient}'s own
 * docblock): the field NAMES below are confirmed against a real production
 * Paymob callback URL (every key here — `order`, `source_data.pan`,
 * `source_data.sub_type`, `source_data.type`, etc. — appears literally,
 * flat, in that URL's query string), but the CONCATENATION ORDER is taken
 * from Paymob's publicly documented HMAC calculation for this callback, not
 * re-derived from a signature this package computed and matched against a
 * real one. Verify against a real webhook from your own Paymob dashboard
 * (Developers > Webhooks — there is usually a "test" trigger) before
 * relying on this to reject/accept traffic in production.
 *
 * Deliberately a separate class from {@see PaymobDriver}, mirroring
 * {@see \Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeWebhookVerifier}'s
 * own split — contains ONLY signature verification, no event parsing, no
 * framework Response construction.
 */
final class PaymobWebhookVerifier
{
    /**
     * Fixed field order Paymob concatenates before HMAC-SHA512 signing.
     *
     * Paymob's classic "Transaction Processed Callback" flattens nested
     * objects into dotted query-string keys (`source_data.pan`, not
     * `source_data: {pan: ...}`) — the keys below are exactly those flat
     * names, read directly off the inbound request's query/body params, not
     * off any nested/normalised payload shape.
     *
     * @var list<string>
     */
    private const HMAC_FIELDS = [
        'amount_cents',
        'created_at',
        'currency',
        'error_occured',
        'has_parent_transaction',
        'id',
        'integration_id',
        'is_3d_secure',
        'is_auth',
        'is_capture',
        'is_refunded',
        'is_standalone_payment',
        'is_voided',
        'order',
        'owner',
        'pending',
        'source_data.pan',
        'source_data.sub_type',
        'source_data.type',
        'success',
    ];

    /**
     * @param array<string, mixed> $config The driver's config block from payment.drivers.paymob
     *                                      (must contain 'hmac_secret').
     */
    public function __construct(
        private readonly array $config = [],
    ) {
    }

    /**
     * Verify a flat webhook payload against its `hmac` value.
     *
     * @param array<string, mixed> $payload      The flat request params (query string and/or body,
     *                                            NOT the unflattened/nested form {@see PaymobDriver}
     *                                            builds for status mapping — this needs the literal
     *                                            dotted keys as Paymob sent them).
     * @param string                $providedHmac The `hmac` value from the request.
     *
     * @return bool True when both the HMAC secret is configured and the computed digest matches.
     */
    public function verify(array $payload, string $providedHmac): bool
    {
        $hmacSecret = $this->hmacSecret();

        if ($hmacSecret === '' || trim($providedHmac) === '') {
            return false;
        }

        return hash_equals($this->compute($payload, $hmacSecret), strtolower(trim($providedHmac)));
    }

    /**
     * Compute the expected HMAC-SHA512 hex digest for a flat webhook payload.
     *
     * Exposed publicly (not just via verify()) so tests — and callers
     * building a test/sandbox payload — can compute the same digest Paymob
     * itself would send, without duplicating {@see self::HMAC_FIELDS}'s order.
     *
     * @param array<string, mixed> $payload
     */
    public function compute(array $payload, string $hmacSecret): string
    {
        $concatenated = '';

        foreach (self::HMAC_FIELDS as $field) {
            $concatenated .= $this->fieldValue($payload, $field);
        }

        return hash_hmac('sha512', $concatenated, $hmacSecret);
    }

    /**
     * Read one HMAC field, tolerating PHP's own query-string parsing.
     *
     * `parse_str()` — which Laravel's `Request::all()`/`$_GET` ultimately go
     * through for a GET request — silently rewrites dots to underscores in
     * top-level parameter names. A real Paymob callback's `source_data.pan`
     * therefore arrives at this package as `source_data_pan`, NOT literally
     * `source_data.pan`, even though that's the exact field name in Paymob's
     * own URL and documented HMAC field list. The literal dotted key is
     * checked first (a JSON body, or a payload built programmatically —
     * e.g. in tests — never goes through `parse_str()` and keeps its dot),
     * falling back to the underscored form real GET traffic actually uses.
     */
    private function fieldValue(array $payload, string $field): string
    {
        if (array_key_exists($field, $payload)) {
            return (string) $payload[$field];
        }

        $underscored = str_replace('.', '_', $field);

        return (string) ($payload[$underscored] ?? '');
    }

    private function hmacSecret(): string
    {
        return (string) ($this->config['hmac_secret'] ?? '');
    }
}
