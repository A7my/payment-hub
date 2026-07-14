<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers;

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
use Mifatoyeh\LaravelPaymentFramework\Responses\CaptureResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentLinkResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\RefundResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\StatusResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\SubscriptionResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\VerificationResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\VoidResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\WebhookResponse;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;

/**
 * The central contract that every payment driver must implement.
 *
 * Declares all 15 payment operations as method signatures. Because every
 * driver implements this interface, the host application can switch providers
 * by changing `PAYMENT_DRIVER` in the environment without modifying any
 * application code — the Strategy Pattern in action.
 *
 * Drivers that do not support a particular operation must throw
 * UnsupportedOperationException rather than returning null or empty responses.
 */
interface PaymentDriverContract
{
    /**
     * Authorise (reserve) a payment amount without capturing it immediately.
     *
     * @param PaymentRequest $request The payment authorisation request DTO.
     *
     * @return PaymentResponse A standardised response with Authorized status on success.
     */
    public function authorize(PaymentRequest $request): PaymentResponse;

    /**
     * Capture a previously authorised payment.
     *
     * @param CaptureRequest $request The capture request DTO.
     *
     * @return CaptureResponse A standardised capture response.
     */
    public function capture(CaptureRequest $request): CaptureResponse;

    /**
     * Perform a direct charge (authorise + capture in one step).
     *
     * @param PaymentRequest $request The payment charge request DTO.
     *
     * @return PaymentResponse A standardised response with Captured status on success.
     */
    public function charge(PaymentRequest $request): PaymentResponse;

    /**
     * Void an authorised payment that has not yet been captured.
     *
     * @param VoidRequest $request The void request DTO.
     *
     * @return VoidResponse A standardised void response.
     */
    public function void(VoidRequest $request): VoidResponse;

    /**
     * Refund the full amount of a previously captured payment.
     *
     * @param RefundRequest $request The refund request DTO.
     *
     * @return RefundResponse A standardised refund response.
     */
    public function refund(RefundRequest $request): RefundResponse;

    /**
     * Refund a partial amount of a previously captured payment.
     *
     * @param RefundRequest $request The partial refund request DTO.
     *
     * @return RefundResponse A standardised refund response.
     */
    public function partialRefund(RefundRequest $request): RefundResponse;

    /**
     * Verify the authenticity and integrity of a transaction.
     *
     * @param TransactionLookupRequest $request The lookup/verification request DTO.
     *
     * @return VerificationResponse A standardised verification response.
     */
    public function verify(TransactionLookupRequest $request): VerificationResponse;

    /**
     * Look up the current status of a transaction.
     *
     * @param TransactionLookupRequest $request The transaction lookup request DTO.
     *
     * @return StatusResponse A standardised status response.
     */
    public function lookup(TransactionLookupRequest $request): StatusResponse;

    /**
     * Generate a hosted payment link for the customer to complete payment.
     *
     * @param PaymentLinkRequest $request The payment link creation request DTO.
     *
     * @return PaymentLinkResponse A standardised payment link response containing the URL.
     */
    public function createPaymentLink(PaymentLinkRequest $request): PaymentLinkResponse;

    /**
     * Save a customer's payment method and return a reusable token.
     *
     * @param SaveCardRequest $request The save-card request DTO.
     *
     * @return PaymentResponse A standardised response containing the saved card token.
     */
    public function saveCard(SaveCardRequest $request): PaymentResponse;

    /**
     * Charge a payment using a previously saved or one-time provider token.
     *
     * @param TokenChargeRequest $request The token charge request DTO.
     *
     * @return PaymentResponse A standardised charge response.
     */
    public function chargeToken(TokenChargeRequest $request): PaymentResponse;

    /**
     * Create a recurring subscription with the specified billing interval.
     *
     * @param SubscriptionRequest $request The subscription creation request DTO.
     *
     * @return SubscriptionResponse A standardised subscription response.
     */
    public function createSubscription(SubscriptionRequest $request): SubscriptionResponse;

    /**
     * Cancel an active subscription.
     *
     * @param TransactionId $subscriptionId The provider's subscription identifier.
     *
     * @return SubscriptionResponse A standardised subscription response with Cancelled status.
     */
    public function cancelSubscription(TransactionId $subscriptionId): SubscriptionResponse;

    /**
     * Process an inbound webhook event from the provider.
     *
     * @param WebhookRequest $request The normalised webhook request DTO.
     *
     * @return WebhookResponse A standardised webhook processing response.
     */
    public function processWebhook(WebhookRequest $request): WebhookResponse;

    /**
     * Verify the cryptographic signature of an inbound webhook request.
     *
     * Must be called before processWebhook(). Returns false if the signature
     * is invalid or missing; the caller is responsible for throwing
     * WebhookVerificationException and returning HTTP 400.
     *
     * @param WebhookRequest $request The normalised webhook request DTO.
     *
     * @return bool True when the signature is valid, false otherwise.
     */
    public function verifyWebhookSignature(WebhookRequest $request): bool;
}
