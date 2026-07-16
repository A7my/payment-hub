<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\DTO;

use InvalidArgumentException;
use JsonSerializable;
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Token;

/**
 * Immutable DTO representing a subscription creation request.
 *
 * Passed to PaymentDriverContract::createSubscription().
 * Defines the recurring billing amount, interval, and customer context.
 *
 * Interval values:
 * - 'daily'   — billed every $intervalCount days
 * - 'weekly'  — billed every $intervalCount weeks
 * - 'monthly' — billed every $intervalCount months
 * - 'yearly'  — billed every $intervalCount years
 *
 * Currency consistency:
 * $amount->currency and $currency must match, validated at construction.
 *
 * $token and $providerCustomerReference mirror {@see TokenChargeRequest}'s
 * fields exactly — this DTO is provider-agnostic and does NOT enforce their
 * presence itself; a specific driver may require either (e.g. Stripe's
 * driver requires $providerCustomerReference and rejects a missing value
 * with an InvalidArgumentException, since Stripe Subscriptions are
 * unconditionally customer-scoped — see StripeDriver::createSubscription()).
 * $token is genuinely optional even for Stripe: if omitted, Stripe falls
 * back to the customer's own stored default payment method (verified
 * against the SDK — SubscriptionService::create()'s own docblock on
 * `default_payment_method`), which only works if that customer already has
 * one (e.g. from a prior saveCard() call).
 */
final readonly class SubscriptionRequest implements JsonSerializable
{
    /** @var string[] Allowed billing interval values. */
    private const VALID_INTERVALS = ['daily', 'weekly', 'monthly', 'yearly'];

    /**
     * @param Money                $amount                    The recurring billing amount in the smallest currency unit.
     * @param Currency             $currency                  ISO 4217 billing currency — must match $amount->currency.
     * @param string               $interval                  Billing frequency: daily | weekly | monthly | yearly.
     * @param int                  $intervalCount             Number of intervals between billings (e.g. 2 = every 2 months).
     * @param int|null             $trialDays                 Optional free trial period in days (null or 0 = no trial).
     * @param CustomerData         $customer                  Customer identity information.
     * @param string|null          $planId                    Provider-specific PRE-EXISTING price/plan identifier —
     *                                                         NOT inline/ad-hoc pricing data, and NOT a product
     *                                                         identifier. For Stripe specifically: this must be an
     *                                                         existing Stripe Price ID (e.g. `price_1N...`), created
     *                                                         beforehand via the Stripe dashboard or API. Stripe's
     *                                                         driver rejects a missing value — it does not create a
     *                                                         Product/Price on the caller's behalf. Whether a value
     *                                                         is required at all, and what it must reference, is
     *                                                         driver-specific; consult the specific driver's
     *                                                         createSubscription() documentation.
     * @param string               $idempotencyKey            Unique key for safe retries (non-empty).
     * @param Token|null           $token                      Optional provider-issued payment method token to use
     *                                                         for this subscription's automatic renewals. See the
     *                                                         class docblock for driver-specific requirements.
     * @param string|null          $providerCustomerReference  The provider-side customer identifier this
     *                                                         subscription is scoped to, when the provider requires
     *                                                         customer-scoped subscriptions. Round-tripped verbatim
     *                                                         from a prior
     *                                                         {@see \Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\PaymentDriverContract::saveCard()}
     *                                                         response's `PaymentResponse::getProviderReference()` —
     *                                                         same field, same meaning as
     *                                                         {@see TokenChargeRequest::$providerCustomerReference}.
     *                                                         See the class docblock for driver-specific requirements.
     * @param array<string, mixed> $metadata                  Arbitrary key-value metadata forwarded to the provider.
     *
     * @throws InvalidArgumentException When $interval is not one of the allowed values.
     * @throws InvalidArgumentException When $intervalCount is less than 1.
     * @throws InvalidArgumentException When $idempotencyKey is empty.
     * @throws InvalidArgumentException When $amount->currency !== $currency.
     */
    public function __construct(
        public Money $amount,
        public Currency $currency,
        public string $interval,
        public int $intervalCount,
        public ?int $trialDays,
        public CustomerData $customer,
        public ?string $planId,
        public string $idempotencyKey,
        public ?Token $token = null,
        public ?string $providerCustomerReference = null,
        public array $metadata = [],
    ) {
        if (! in_array($this->interval, self::VALID_INTERVALS, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'SubscriptionRequest $interval must be one of [%s]; [%s] given.',
                    implode(', ', self::VALID_INTERVALS),
                    $this->interval,
                ),
            );
        }

        if ($this->intervalCount < 1) {
            throw new InvalidArgumentException(
                "SubscriptionRequest \$intervalCount must be >= 1; [{$this->intervalCount}] given.",
            );
        }

        if ($this->idempotencyKey === '') {
            throw new InvalidArgumentException(
                'SubscriptionRequest $idempotencyKey must not be empty.',
            );
        }

        if ($this->amount->currency !== $this->currency) {
            throw new InvalidArgumentException(
                sprintf(
                    'SubscriptionRequest currency mismatch: $amount is [%s] but $currency is [%s].',
                    $this->amount->currency->value,
                    $this->currency->value,
                ),
            );
        }
    }

    /**
     * Whether a free trial period is configured.
     *
     * @return bool
     */
    public function hasTrial(): bool
    {
        return $this->trialDays !== null && $this->trialDays > 0;
    }

    /**
     * Whether a provider plan identifier is specified.
     *
     * @return bool
     */
    public function hasPlanId(): bool
    {
        return $this->planId !== null && $this->planId !== '';
    }

    /**
     * Serialise to a JSON-compatible array.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'amount'                      => $this->amount->jsonSerialize(),
            'currency'                    => $this->currency->value,
            'interval'                    => $this->interval,
            'interval_count'              => $this->intervalCount,
            'trial_days'                  => $this->trialDays,
            'customer'                    => $this->customer->jsonSerialize(),
            'plan_id'                     => $this->planId,
            'idempotency_key'             => $this->idempotencyKey,
            'token_masked'                => $this->token?->masked(),
            'provider_customer_reference' => $this->providerCustomerReference,
            'metadata'                    => $this->metadata,
        ];
    }
}
