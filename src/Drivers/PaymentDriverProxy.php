<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Drivers;

use Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\PaymentDriverContract;
use Mifatoyeh\LaravelPaymentFramework\DTO\CancelSubscriptionRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\CaptureRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentLinkRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\RefundRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\SaveCardRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\SubscriptionRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\TokenChargeRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\TransactionLookupRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\VoidRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\WebhookRequest;
use Mifatoyeh\LaravelPaymentFramework\Factories\PaymentRequestFactory;
use Mifatoyeh\LaravelPaymentFramework\Responses\CaptureResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentLinkResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\RefundResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\StatusResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\SubscriptionResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\VerificationResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\VoidResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\WebhookResponse;

/**
 * Transparent decorator that lets package consumers call driver methods with
 * plain arrays instead of hand-built DTOs, without the wrapped driver ever
 * knowing the difference.
 *
 * `PaymentManager` resolves the real driver (e.g. `StripeDriver`) exactly as
 * before — config validation, container resolution, and exception handling
 * are all untouched — then hands it to this proxy before returning it to the
 * caller. Every method here accepts `DTO|array`, converts arrays via
 * {@see PaymentRequestFactory}, and immediately delegates to the wrapped
 * driver's identical method with a proper DTO. The wrapped driver's public
 * API (still strictly typed to DTOs only) and its internal behaviour are
 * completely unchanged.
 *
 * This is the ONLY layer aware that input might be an array; everything
 * downstream — this class's own `$driver`, `AbstractDriver`, and every
 * concrete driver implementation — only ever sees DTOs, exactly as today.
 */
final class PaymentDriverProxy implements PaymentDriverContract
{
    public function __construct(
        private readonly PaymentDriverContract $driver,
        private readonly PaymentRequestFactory $factory = new PaymentRequestFactory(),
    ) {
    }

    /** @param PaymentRequest|array<string, mixed> $request */
    public function authorize(PaymentRequest|array $request): PaymentResponse
    {
        return $this->driver->authorize($this->factory->toPaymentRequest($request));
    }

    /** @param CaptureRequest|array<string, mixed> $request */
    public function capture(CaptureRequest|array $request): CaptureResponse
    {
        return $this->driver->capture($this->factory->toCaptureRequest($request));
    }

    /** @param PaymentRequest|array<string, mixed> $request */
    public function charge(PaymentRequest|array $request): PaymentResponse
    {
        return $this->driver->charge($this->factory->toPaymentRequest($request));
    }

    /** @param VoidRequest|array<string, mixed> $request */
    public function void(VoidRequest|array $request): VoidResponse
    {
        return $this->driver->void($this->factory->toVoidRequest($request));
    }

    /** @param RefundRequest|array<string, mixed> $request */
    public function refund(RefundRequest|array $request): RefundResponse
    {
        return $this->driver->refund($this->factory->toRefundRequest($request));
    }

    /** @param RefundRequest|array<string, mixed> $request */
    public function partialRefund(RefundRequest|array $request): RefundResponse
    {
        return $this->driver->partialRefund($this->factory->toRefundRequest($request));
    }

    /** @param TransactionLookupRequest|array<string, mixed> $request */
    public function verify(TransactionLookupRequest|array $request): VerificationResponse
    {
        return $this->driver->verify($this->factory->toTransactionLookupRequest($request));
    }

    /** @param TransactionLookupRequest|array<string, mixed> $request */
    public function lookup(TransactionLookupRequest|array $request): StatusResponse
    {
        return $this->driver->lookup($this->factory->toTransactionLookupRequest($request));
    }

    /** @param PaymentLinkRequest|array<string, mixed> $request */
    public function createPaymentLink(PaymentLinkRequest|array $request): PaymentLinkResponse
    {
        return $this->driver->createPaymentLink($this->factory->toPaymentLinkRequest($request));
    }

    /** @param SaveCardRequest|array<string, mixed> $request */
    public function saveCard(SaveCardRequest|array $request): PaymentResponse
    {
        return $this->driver->saveCard($this->factory->toSaveCardRequest($request));
    }

    /** @param TokenChargeRequest|array<string, mixed> $request */
    public function chargeToken(TokenChargeRequest|array $request): PaymentResponse
    {
        return $this->driver->chargeToken($this->factory->toTokenChargeRequest($request));
    }

    /** @param SubscriptionRequest|array<string, mixed> $request */
    public function createSubscription(SubscriptionRequest|array $request): SubscriptionResponse
    {
        return $this->driver->createSubscription($this->factory->toSubscriptionRequest($request));
    }

    /** @param CancelSubscriptionRequest|array<string, mixed> $request */
    public function cancelSubscription(CancelSubscriptionRequest|array $request): SubscriptionResponse
    {
        return $this->driver->cancelSubscription($this->factory->toCancelSubscriptionRequest($request));
    }

    /**
     * Webhook payloads originate from raw HTTP requests, not developer-authored
     * arrays, so no array normalisation applies here — WebhookRequest only.
     */
    public function processWebhook(WebhookRequest $request): WebhookResponse
    {
        return $this->driver->processWebhook($request);
    }

    public function verifyWebhookSignature(WebhookRequest $request): bool
    {
        return $this->driver->verifyWebhookSignature($request);
    }

    /**
     * Return the real, wrapped driver instance.
     *
     * For code that needs to check an OPTIONAL capability interface not
     * part of {@see PaymentDriverContract} (e.g.
     * {@see \Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\SupportsSdkCheckout}) —
     * this proxy only implements the 15 contract methods, so `$proxy
     * instanceof SupportsSdkCheckout` is always false even when the
     * underlying driver does implement it. Unwrap first:
     * `$manager->driver('stripe')->getWrappedDriver() instanceof SupportsSdkCheckout`.
     */
    public function getWrappedDriver(): PaymentDriverContract
    {
        return $this->driver;
    }
}
