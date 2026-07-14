<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Services;

use Mifatoyeh\LaravelPaymentFramework\DTO\CaptureRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentLinkRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\PaymentRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\RefundRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\SaveCardRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\SubscriptionRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\TokenChargeRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\TransactionLookupRequest;
use Mifatoyeh\LaravelPaymentFramework\DTO\VoidRequest;
use Mifatoyeh\LaravelPaymentFramework\Managers\PaymentManager;
use Mifatoyeh\LaravelPaymentFramework\Responses\CaptureResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentLinkResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\RefundResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\StatusResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\SubscriptionResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\VerificationResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\VoidResponse;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;

/**
 * High-level payment orchestration service.
 *
 * An alternative to using the Payment facade directly. Prefer this service
 * when constructor injection is more appropriate than static facade calls
 * (e.g., in controllers, command handlers, or any class resolved via the IoC).
 *
 * Each method resolves the default (or specified) driver via the manager
 * and delegates to it, keeping the service thin and testable.
 */
final class PaymentService
{
    /**
     * @param PaymentManager $manager The payment manager for driver resolution.
     */
    public function __construct(
        private readonly PaymentManager $manager,
    ) {
    }

    /**
     * Charge (authorise + capture) a payment amount.
     *
     * @param PaymentRequest $request The payment request DTO.
     *
     * @return PaymentResponse Standardised payment response.
     */
    public function charge(PaymentRequest $request): PaymentResponse
    {
        // TODO: return $this->manager->driver()->charge($request);
        throw new \LogicException('PaymentService::charge() not yet implemented.');
    }

    /**
     * Authorise (reserve) a payment amount without capturing.
     *
     * @param PaymentRequest $request The payment request DTO.
     *
     * @return PaymentResponse Standardised authorisation response.
     */
    public function authorize(PaymentRequest $request): PaymentResponse
    {
        // TODO: return $this->manager->driver()->authorize($request);
        throw new \LogicException('PaymentService::authorize() not yet implemented.');
    }

    /**
     * Capture a previously authorised payment.
     *
     * @param CaptureRequest $request The capture request DTO.
     *
     * @return CaptureResponse Standardised capture response.
     */
    public function capture(CaptureRequest $request): CaptureResponse
    {
        // TODO: return $this->manager->driver()->capture($request);
        throw new \LogicException('PaymentService::capture() not yet implemented.');
    }

    /**
     * Refund a previously captured payment (full refund).
     *
     * @param RefundRequest $request The refund request DTO.
     *
     * @return RefundResponse Standardised refund response.
     */
    public function refund(RefundRequest $request): RefundResponse
    {
        // TODO: return $this->manager->driver()->refund($request);
        throw new \LogicException('PaymentService::refund() not yet implemented.');
    }

    /**
     * Void an authorised but uncaptured payment.
     *
     * @param VoidRequest $request The void request DTO.
     *
     * @return VoidResponse Standardised void response.
     */
    public function void(VoidRequest $request): VoidResponse
    {
        // TODO: return $this->manager->driver()->void($request);
        throw new \LogicException('PaymentService::void() not yet implemented.');
    }

    /**
     * Create a hosted payment link.
     *
     * @param PaymentLinkRequest $request The payment link request DTO.
     *
     * @return PaymentLinkResponse Standardised payment link response.
     */
    public function createPaymentLink(PaymentLinkRequest $request): PaymentLinkResponse
    {
        // TODO: return $this->manager->driver()->createPaymentLink($request);
        throw new \LogicException('PaymentService::createPaymentLink() not yet implemented.');
    }

    /**
     * Save a customer's payment method as a reusable token.
     *
     * @param SaveCardRequest $request The save card request DTO.
     *
     * @return PaymentResponse Standardised response with saved token.
     */
    public function saveCard(SaveCardRequest $request): PaymentResponse
    {
        // TODO: return $this->manager->driver()->saveCard($request);
        throw new \LogicException('PaymentService::saveCard() not yet implemented.');
    }

    /**
     * Charge a payment using a provider-issued token.
     *
     * @param TokenChargeRequest $request The token charge request DTO.
     *
     * @return PaymentResponse Standardised charge response.
     */
    public function chargeToken(TokenChargeRequest $request): PaymentResponse
    {
        // TODO: return $this->manager->driver()->chargeToken($request);
        throw new \LogicException('PaymentService::chargeToken() not yet implemented.');
    }

    /**
     * Create a recurring subscription.
     *
     * @param SubscriptionRequest $request The subscription request DTO.
     *
     * @return SubscriptionResponse Standardised subscription response.
     */
    public function createSubscription(SubscriptionRequest $request): SubscriptionResponse
    {
        // TODO: return $this->manager->driver()->createSubscription($request);
        throw new \LogicException('PaymentService::createSubscription() not yet implemented.');
    }

    /**
     * Cancel an active subscription.
     *
     * @param TransactionId $subscriptionId The provider's subscription identifier.
     *
     * @return SubscriptionResponse Standardised subscription response.
     */
    public function cancelSubscription(TransactionId $subscriptionId): SubscriptionResponse
    {
        // TODO: return $this->manager->driver()->cancelSubscription($subscriptionId);
        throw new \LogicException('PaymentService::cancelSubscription() not yet implemented.');
    }

    /**
     * Verify the authenticity of a transaction.
     *
     * @param TransactionLookupRequest $request The verification request DTO.
     *
     * @return VerificationResponse Standardised verification response.
     */
    public function verify(TransactionLookupRequest $request): VerificationResponse
    {
        // TODO: return $this->manager->driver()->verify($request);
        throw new \LogicException('PaymentService::verify() not yet implemented.');
    }

    /**
     * Look up the current status of a transaction.
     *
     * @param TransactionLookupRequest $request The lookup request DTO.
     *
     * @return StatusResponse Standardised status response.
     */
    public function lookup(TransactionLookupRequest $request): StatusResponse
    {
        // TODO: return $this->manager->driver()->lookup($request);
        throw new \LogicException('PaymentService::lookup() not yet implemented.');
    }
}
