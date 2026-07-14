<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe;

use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentRequest;
use Stripe\StripeClient as StripeSdkClient;

/**
 * Thin transport wrapper around the Stripe SDK.
 *
 * Responsible ONLY for communication with Stripe's API. Contains no business
 * logic (no status interpretation, no event dispatch, no exception mapping —
 * those belong to {@see StripeDriver}, {@see StripeMapper}, and
 * {@see StripeExceptionMapper} respectively) and no framework Response
 * construction. Every method returns the raw, JSON-decoded Stripe payload
 * as an array so {@see StripeMapper} can translate it.
 *
 * Credentials are read exclusively from the injected driver config (never
 * from globals or the environment directly) and are never exposed via any
 * public accessor — only the private, lazily-built SDK client instance ever
 * sees the secret key.
 */
final class StripeClient
{
    /**
     * The lazily-instantiated Stripe SDK client. Null until first use.
     */
    private ?StripeSdkClient $sdk = null;

    /**
     * @param array<string, mixed> $config The driver's config block from payment.drivers.stripe
     *                                      (secret key, sandbox flag, timeout, etc.).
     */
    public function __construct(
        private readonly array $config = [],
    ) {
    }

    /**
     * Create a Stripe PaymentIntent for the given payment request.
     *
     * Confirms immediately (`confirm: true`) since `charge()` represents an
     * intent to capture funds now, not merely to reserve them. The request's
     * idempotency key is forwarded as the Stripe idempotency key so retried
     * calls (via {@see \Mifatoyeh\LaravelPaymentFramework\Drivers\AbstractDriver::withRetry()})
     * are safe against duplicate charges on Stripe's side too.
     *
     * `$request->options` — arbitrary Stripe parameters with no dedicated
     * framework DTO property (`automatic_payment_methods`, `capture_method`,
     * `setup_future_usage`, `receipt_email`, `shipping`, or any future Stripe
     * parameter) — are merged in verbatim, forwarding them to Stripe
     * untouched. This class never hardcodes or special-cases any of them.
     * Framework-derived values (`amount`, `currency`, `confirm`,
     * `payment_method`, `metadata`) always win on key collision: `$params`
     * is merged SECOND, since PHP's `array_merge()` lets the later array's
     * values overwrite the earlier one's for matching string keys. A caller
     * cannot use `options` to override the amount actually charged.
     *
     * Performs no interpretation of the result or of any exception raised —
     * both are simply propagated to the caller.
     *
     * @param PaymentRequest $request The payment request to charge.
     *
     * @return array<string, mixed> The raw, decoded Stripe PaymentIntent payload.
     *
     * @throws \Stripe\Exception\ApiErrorException On any Stripe API error (including card declines).
     */
    public function createPaymentIntent(PaymentRequest $request): array
    {
        $params = array_filter(
            [
                'amount'          => $request->amount->amount,
                'currency'        => strtolower($request->currency->value),
                'confirm'         => true,
                'payment_method'  => $request->token?->toString(),
                'metadata'        => $request->metadata,
            ],
            static fn (mixed $value): bool => $value !== null && $value !== [],
        );

        // Provider-specific options first, framework-derived $params second —
        // framework values must always win on key collision.
        $params = array_merge($request->options, $params);

        $intent = $this->sdk()->paymentIntents->create(
            $params,
            ['idempotency_key' => $request->idempotencyKey],
        );

        return $intent->toArray();
    }

    /**
     * Lazily build (or return the already-built) underlying Stripe SDK client.
     *
     * The secret key is read only from the driver configuration and is held
     * exclusively by the SDK client instance — this method has no public
     * counterpart, so no caller outside this class can ever retrieve it.
     *
     * @return StripeSdkClient The underlying Stripe SDK client instance.
     */
    private function sdk(): StripeSdkClient
    {
        if ($this->sdk === null) {
            $this->sdk = new StripeSdkClient([
                'api_key' => (string) ($this->config['secret'] ?? ''),
            ]);
        }

        return $this->sdk;
    }
}
