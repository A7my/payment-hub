<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Factories;

use DateTimeImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mifatoyeh\LaravelPaymentFramework\DTO\AddressData;
use Mifatoyeh\LaravelPaymentFramework\DTO\CaptureRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\CustomerData;
use Mifatoyeh\LaravelPaymentFramework\DTO\OrderData;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentLinkRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\RefundRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\SaveCardRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\SubscriptionRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\TokenChargeRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\TransactionLookupRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\VoidRequest;
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentMethod;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\CustomerId;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\OrderId;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Token;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;

/**
 * Converts plain PHP arrays into the framework's internal request DTOs.
 *
 * This is the ONLY place package consumers' array input is translated into
 * DTOs, Value Objects, and Enums. Everything downstream of this factory
 * (drivers, AbstractDriver, PaymentManager) continues to work exclusively
 * with DTOs exactly as before — this class adds a public-facing convenience
 * layer, it does not change what drivers receive.
 *
 * Every `toXxx()` method accepts either the array shape OR the already-built
 * DTO and returns the DTO unchanged in the latter case, so existing DTO-based
 * calling code keeps working without any change.
 *
 * All existing DTO-level validation (non-empty idempotency key, non-empty
 * customer name/email, valid subscription interval, currency consistency,
 * etc.) still applies — this factory only performs the array → object
 * translation and raises a clear {@see InvalidArgumentException} when a
 * required array key is missing, before that translation is attempted.
 *
 * Provider-specific options:
 * Array input may contain gateway-specific keys with no dedicated DTO
 * property (e.g. Stripe's `automatic_payment_methods`, `capture_method`,
 * `setup_future_usage`). Rather than silently discarding them or growing
 * the DTO with dozens of provider-specific properties, every top-level key
 * NOT recognised as a framework field is automatically collected into
 * `PaymentRequest::$options` and forwarded to the driver untouched — see
 * {@see self::extractOptions()}. This mechanism is generic and reusable by
 * every gateway (Stripe, PayPal, Paymob, MyFatoorah, …), not Stripe-specific.
 */
final class PaymentRequestFactory
{
    /**
     * Top-level array keys consumed by {@see self::toPaymentRequest()} that
     * populate a dedicated PaymentRequest property. Every other top-level
     * key is collected into `PaymentRequest::$options` instead of being lost.
     *
     * @var array<int, string>
     */
    private const PAYMENT_REQUEST_KNOWN_KEYS = [
        'amount',
        'currency',
        'idempotency_key',
        'customer',
        'order',
        'billing_address',
        'address',
        'return_url',
        'cancel_url',
        'metadata',
        'token',
        'payment_method',
    ];

    /**
     * Build (or pass through) a PaymentRequest for charge()/authorize().
     *
     * @param PaymentRequest|array<string, mixed> $data
     */
    public function toPaymentRequest(PaymentRequest|array $data): PaymentRequest
    {
        if ($data instanceof PaymentRequest) {
            return $data;
        }

        $this->requireKeys($data, ['amount', 'currency', 'customer'], 'charge');

        $currency = $this->currency($data['currency']);

        return new PaymentRequest(
            amount: Money::ofMinor((int) $data['amount'], $currency),
            currency: $currency,
            idempotencyKey: $this->idempotencyKey($data),
            customer: $this->customer($data['customer']),
            order: isset($data['order']) ? $this->order($data['order']) : null,
            billingAddress: isset($data['billing_address'])
                ? $this->address($data['billing_address'])
                : (isset($data['address']) ? $this->address($data['address']) : null),
            returnUrl: isset($data['return_url']) ? (string) $data['return_url'] : null,
            cancelUrl: isset($data['cancel_url']) ? (string) $data['cancel_url'] : null,
            metadata: (array) ($data['metadata'] ?? []),
            token: isset($data['token']) ? Token::fromString((string) $data['token']) : null,
            paymentMethod: $this->paymentMethod($data['payment_method'] ?? null),
            options: $this->extractOptions($data, self::PAYMENT_REQUEST_KNOWN_KEYS),
        );
    }

    /**
     * Build (or pass through) a RefundRequest for refund()/partialRefund().
     *
     * @param RefundRequest|array<string, mixed> $data
     */
    public function toRefundRequest(RefundRequest|array $data): RefundRequest
    {
        if ($data instanceof RefundRequest) {
            return $data;
        }

        $this->requireKeys($data, ['transaction_id', 'amount', 'currency'], 'refund');

        $currency = $this->currency($data['currency']);

        return new RefundRequest(
            transactionId: TransactionId::fromString((string) $data['transaction_id']),
            amount: Money::ofMinor((int) $data['amount'], $currency),
            reason: (string) ($data['reason'] ?? ''),
            idempotencyKey: $this->idempotencyKey($data),
            metadata: (array) ($data['metadata'] ?? []),
        );
    }

    /**
     * Build (or pass through) a CaptureRequest for capture().
     *
     * @param CaptureRequest|array<string, mixed> $data
     */
    public function toCaptureRequest(CaptureRequest|array $data): CaptureRequest
    {
        if ($data instanceof CaptureRequest) {
            return $data;
        }

        $this->requireKeys($data, ['transaction_id', 'amount', 'currency'], 'capture');

        $currency = $this->currency($data['currency']);

        return new CaptureRequest(
            transactionId: TransactionId::fromString((string) $data['transaction_id']),
            amount: Money::ofMinor((int) $data['amount'], $currency),
            idempotencyKey: $this->idempotencyKey($data),
            metadata: (array) ($data['metadata'] ?? []),
        );
    }

    /**
     * Build (or pass through) a VoidRequest for void().
     *
     * @param VoidRequest|array<string, mixed> $data
     */
    public function toVoidRequest(VoidRequest|array $data): VoidRequest
    {
        if ($data instanceof VoidRequest) {
            return $data;
        }

        $this->requireKeys($data, ['transaction_id'], 'void');

        return new VoidRequest(
            transactionId: TransactionId::fromString((string) $data['transaction_id']),
            reason: (string) ($data['reason'] ?? ''),
            idempotencyKey: $this->idempotencyKey($data),
            metadata: (array) ($data['metadata'] ?? []),
        );
    }

    /**
     * Build (or pass through) a TransactionLookupRequest for verify()/lookup().
     *
     * @param TransactionLookupRequest|array<string, mixed> $data
     */
    public function toTransactionLookupRequest(TransactionLookupRequest|array $data): TransactionLookupRequest
    {
        if ($data instanceof TransactionLookupRequest) {
            return $data;
        }

        $this->requireKeys($data, ['transaction_id'], 'lookup');

        return new TransactionLookupRequest(
            transactionId: TransactionId::fromString((string) $data['transaction_id']),
            metadata: (array) ($data['metadata'] ?? []),
        );
    }

    /**
     * Build (or pass through) a PaymentLinkRequest for createPaymentLink().
     *
     * @param PaymentLinkRequest|array<string, mixed> $data
     */
    public function toPaymentLinkRequest(PaymentLinkRequest|array $data): PaymentLinkRequest
    {
        if ($data instanceof PaymentLinkRequest) {
            return $data;
        }

        $this->requireKeys($data, ['amount', 'currency', 'description'], 'payment link');

        $currency = $this->currency($data['currency']);

        return new PaymentLinkRequest(
            amount: Money::ofMinor((int) $data['amount'], $currency),
            currency: $currency,
            description: (string) $data['description'],
            customer: isset($data['customer']) ? $this->customer($data['customer']) : null,
            returnUrl: isset($data['return_url']) ? (string) $data['return_url'] : null,
            cancelUrl: isset($data['cancel_url']) ? (string) $data['cancel_url'] : null,
            expiresAt: $this->dateTime($data['expires_at'] ?? null),
            idempotencyKey: $this->idempotencyKey($data),
            metadata: (array) ($data['metadata'] ?? []),
        );
    }

    /**
     * Build (or pass through) a SaveCardRequest for saveCard().
     *
     * @param SaveCardRequest|array<string, mixed> $data
     */
    public function toSaveCardRequest(SaveCardRequest|array $data): SaveCardRequest
    {
        if ($data instanceof SaveCardRequest) {
            return $data;
        }

        $this->requireKeys($data, ['token', 'customer_id'], 'save card');

        return new SaveCardRequest(
            token: Token::fromString((string) $data['token']),
            customerId: CustomerId::fromString((string) $data['customer_id']),
            idempotencyKey: $this->idempotencyKey($data),
            metadata: (array) ($data['metadata'] ?? []),
        );
    }

    /**
     * Build (or pass through) a TokenChargeRequest for chargeToken().
     *
     * @param TokenChargeRequest|array<string, mixed> $data
     */
    public function toTokenChargeRequest(TokenChargeRequest|array $data): TokenChargeRequest
    {
        if ($data instanceof TokenChargeRequest) {
            return $data;
        }

        $this->requireKeys($data, ['token', 'amount', 'currency', 'customer'], 'token charge');

        $currency = $this->currency($data['currency']);

        return new TokenChargeRequest(
            token: Token::fromString((string) $data['token']),
            amount: Money::ofMinor((int) $data['amount'], $currency),
            currency: $currency,
            idempotencyKey: $this->idempotencyKey($data),
            customer: $this->customer($data['customer']),
            metadata: (array) ($data['metadata'] ?? []),
        );
    }

    /**
     * Build (or pass through) a SubscriptionRequest for createSubscription().
     *
     * @param SubscriptionRequest|array<string, mixed> $data
     */
    public function toSubscriptionRequest(SubscriptionRequest|array $data): SubscriptionRequest
    {
        if ($data instanceof SubscriptionRequest) {
            return $data;
        }

        $this->requireKeys($data, ['amount', 'currency', 'interval', 'customer'], 'subscription');

        $currency = $this->currency($data['currency']);

        return new SubscriptionRequest(
            amount: Money::ofMinor((int) $data['amount'], $currency),
            currency: $currency,
            interval: (string) $data['interval'],
            intervalCount: (int) ($data['interval_count'] ?? 1),
            trialDays: isset($data['trial_days']) ? (int) $data['trial_days'] : null,
            customer: $this->customer($data['customer']),
            planId: isset($data['plan_id']) ? (string) $data['plan_id'] : null,
            idempotencyKey: $this->idempotencyKey($data),
            metadata: (array) ($data['metadata'] ?? []),
        );
    }

    /**
     * Resolve a subscription id argument that may be a TransactionId or a plain string.
     *
     * @param TransactionId|string $subscriptionId
     */
    public function toTransactionId(TransactionId|string $subscriptionId): TransactionId
    {
        return $subscriptionId instanceof TransactionId
            ? $subscriptionId
            : TransactionId::fromString($subscriptionId);
    }

    // =========================================================================
    // Shared field builders
    // =========================================================================

    /**
     * @param array<string, mixed> $data
     */
    private function customer(array $data): CustomerData
    {
        $this->requireKeys($data, ['name', 'email'], 'customer');

        return new CustomerData(
            name: (string) $data['name'],
            email: (string) $data['email'],
            phone: isset($data['phone']) ? (string) $data['phone'] : null,
            externalId: isset($data['external_id']) ? (string) $data['external_id'] : null,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function order(array $data): OrderData
    {
        $this->requireKeys($data, ['order_id', 'description'], 'order');

        return new OrderData(
            orderId: OrderId::fromString((string) $data['order_id']),
            description: (string) $data['description'],
            items: (array) ($data['items'] ?? []),
            metadata: (array) ($data['metadata'] ?? []),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function address(array $data): AddressData
    {
        $this->requireKeys($data, ['line1', 'city', 'country', 'postal_code'], 'address');

        return new AddressData(
            line1: (string) $data['line1'],
            line2: isset($data['line2']) ? (string) $data['line2'] : null,
            city: (string) $data['city'],
            state: isset($data['state']) ? (string) $data['state'] : null,
            country: (string) $data['country'],
            postalCode: (string) $data['postal_code'],
        );
    }

    /**
     * Collect every top-level array key NOT in $knownKeys into a plain array.
     *
     * Generic, gateway-agnostic mechanism: any driver's factory method can
     * call this with its own known-keys list to give callers a provider
     * options bag without adding provider-specific properties to a DTO.
     *
     * @param array<string, mixed> $data
     * @param array<int, string>   $knownKeys
     *
     * @return array<string, mixed>
     */
    private function extractOptions(array $data, array $knownKeys): array
    {
        return array_diff_key($data, array_flip($knownKeys));
    }

    private function currency(string|Currency $value): Currency
    {
        return $value instanceof Currency ? $value : Currency::from(strtoupper($value));
    }

    private function paymentMethod(mixed $value): PaymentMethod
    {
        if ($value instanceof PaymentMethod) {
            return $value;
        }

        return $value !== null ? PaymentMethod::from((string) $value) : PaymentMethod::Card;
    }

    private function dateTime(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value instanceof DateTimeImmutable) {
            return $value;
        }

        return new DateTimeImmutable((string) $value);
    }

    /**
     * Resolve the idempotency key, generating a UUID when the caller omits one.
     *
     * @param array<string, mixed> $data
     */
    private function idempotencyKey(array $data): string
    {
        $key = $data['idempotency_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : (string) Str::uuid();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string>   $keys
     */
    private function requireKeys(array $data, array $keys, string $context): void
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
                throw new InvalidArgumentException(
                    "Missing required key [{$key}] for {$context} request.",
                );
            }
        }
    }
}
