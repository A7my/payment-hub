<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe;

use Illuminate\Contracts\Events\Dispatcher;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\PaymentDriverContract;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Logging\PaymentLoggerContract;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Services\RetryServiceContract;
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
use Mifatoyeh\LaravelPaymentFramework\Drivers\AbstractDriver;
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentFailed;
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentInitiated;
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentSucceeded;
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
use Stripe\Exception\CardException;
use Throwable;

/**
 * Stripe implementation of {@see PaymentDriverContract}.
 *
 * Extends {@see AbstractDriver} for all shared infrastructure (config,
 * logging, event dispatch, retry, idempotency, exception wrapping) and
 * delegates every Stripe-specific concern to a dedicated collaborator:
 *
 *   - {@see StripeClient}           — raw SDK communication only.
 *   - {@see StripeMapper}           — raw Stripe payload → framework Response.
 *   - {@see StripeWebhookVerifier}  — Stripe-Signature verification.
 *   - {@see StripeExceptionMapper}  — Stripe SDK exception → framework exception.
 *
 * This class itself contains ONLY orchestration: validate idempotency, log,
 * dispatch lifecycle events, delegate to the collaborators above, and wrap
 * failures. It never talks to the Stripe SDK directly.
 *
 * Collaborators are PRIVATE implementation details of this driver, not
 * container-resolved services. The IoC container resolves ONLY this class
 * (via `PaymentManager`); `StripeClient`, `StripeMapper`,
 * `StripeWebhookVerifier`, and `StripeExceptionMapper` are constructed here,
 * directly, from the same `$config` array this driver itself receives. This
 * matters concretely: `StripeClient` and `StripeWebhookVerifier` both need
 * the resolved `payment.drivers.stripe` config (the secret key, the webhook
 * secret) to function — if the container resolved them independently, each
 * would receive its own default-constructed `[]` instead of that config,
 * and `StripeClient` would fail with "api_key cannot be the empty string"
 * even when `STRIPE_SECRET` is correctly set. Building them here guarantees
 * every collaborator shares the exact same config this driver was given.
 * Every other concrete driver (PayPal, Paymob, MyFatoorah, …) follows this
 * same pattern.
 *
 * `charge()` is fully implemented. Every other method body is intentionally
 * left as a `// TODO` stub, to be implemented in a later task.
 */
final class StripeDriver extends AbstractDriver implements PaymentDriverContract
{
    private readonly StripeClient $client;

    private readonly StripeMapper $mapper;

    private readonly StripeWebhookVerifier $webhookVerifier;

    private readonly StripeExceptionMapper $exceptionMapper;

    /**
     * @param PaymentLoggerContract $logger  The bound logger implementation.
     * @param Dispatcher            $events  Laravel's event dispatcher.
     * @param RetryServiceContract  $retry   The retry service for transient failure handling.
     * @param array<string, mixed>  $config  The driver's config block from payment.drivers.stripe
     *                                       (secret key, webhook secret, sandbox flag, timeout, etc.).
     */
    public function __construct(
        PaymentLoggerContract $logger,
        Dispatcher $events,
        RetryServiceContract $retry,
        array $config = [],
    ) {
        parent::__construct($logger, $events, $retry, $config);

        // Internal collaborators — constructed here, not container-resolved,
        // so every one of them shares this exact same $config array.
        $this->client          = new StripeClient($config);
        $this->mapper          = new StripeMapper();
        $this->webhookVerifier = new StripeWebhookVerifier($config);
        $this->exceptionMapper = new StripeExceptionMapper();
    }

    /**
     * {@inheritDoc}
     *
     * Workflow:
     *   1. Validate the idempotency key (throws before any Stripe call).
     *   2. Log payment initiation.
     *   3. Dispatch {@see PaymentInitiated}.
     *   4. Call {@see StripeClient::createPaymentIntent()} (wrapped in {@see AbstractDriver::withRetry()}).
     *   5. Convert the raw Stripe payload via {@see StripeMapper::toPaymentResponse()}.
     *   6. Dispatch {@see PaymentSucceeded} or {@see PaymentFailed}.
     *   7. Return the PaymentResponse.
     *
     * A declined card ({@see CardException}) is a soft failure: per
     * {@see PaymentResponse}'s own documented contract ("On soft failure (card
     * declined), isSuccessful() returns false but the response object is still
     * fully populated. Exceptions are reserved for unrecoverable errors."),
     * it is mapped to a PaymentResponse and returned, not thrown. Every other
     * Throwable is an unrecoverable failure and is passed through
     * {@see StripeExceptionMapper} and thrown.
     */
    public function charge(PaymentRequest $request): PaymentResponse
    {
        $this->validateIdempotencyKey($request->idempotencyKey);
        $this->logInfo('Initiating charge', $this->buildLogContext('charge', [
            'amount'   => $request->amount->amount,
            'currency' => $request->currency->value,
        ]));
        $this->dispatchEvent(new PaymentInitiated($request));

        try {
            $raw      = $this->withRetry(fn () => $this->client->createPaymentIntent($request));
            $response = $this->mapper->toPaymentResponse($raw);

            if ($response->isSuccessful()) {
                $this->dispatchEvent(new PaymentSucceeded($request, $response));
                $this->logInfo('Charge succeeded', $this->buildLogContext('charge', [
                    'transaction_id' => $response->getTransactionId()->toString(),
                    'status'         => $response->getStatus()->value,
                ]));
            } else {
                $this->dispatchEvent(new PaymentFailed($request, $response, null));
                $this->logWarning('Charge did not succeed', $this->buildLogContext('charge', [
                    'status'  => $response->getStatus()->value,
                    'message' => $response->getMessage(),
                ]));
            }

            return $response;
        } catch (CardException $e) {
            $response = $this->mapper->toPaymentResponse($this->declinedPaymentIntentFrom($e, $request));
            $this->dispatchEvent(new PaymentFailed($request, $response, null));
            $this->logWarning('Charge declined by Stripe', $this->buildLogContext('charge', [
                'decline_code' => $e->getDeclineCode(),
                'message'      => $e->getMessage(),
            ]));

            return $response;
        } catch (Throwable $e) {
            $this->dispatchEvent(new PaymentFailed($request, null, $e));
            $this->logError('Charge failed', $this->buildLogContext('charge', [
                'error' => $e->getMessage(),
            ]));

            throw $this->exceptionMapper->map($e, ['operation' => 'charge']);
        }
    }

    /**
     * Build a raw PaymentIntent-shaped array from a declined-card exception.
     *
     * Stripe includes the associated PaymentIntent under `error.payment_intent`
     * for confirmation-time card declines; when present, it already carries
     * the canonical id/status/amount/currency needed by {@see StripeMapper}.
     * When absent, a minimal synthetic payload is built from the exception
     * itself so the charge still resolves to a fully populated, non-thrown
     * PaymentResponse per the framework's soft-failure contract.
     *
     * @param CardException  $e       The card decline exception.
     * @param PaymentRequest $request The original request (used only for the fallback payload).
     *
     * @return array<string, mixed>
     */
    private function declinedPaymentIntentFrom(CardException $e, PaymentRequest $request): array
    {
        $intent = $e->getJsonBody()['error']['payment_intent'] ?? null;

        if (is_array($intent)) {
            return $intent;
        }

        return [
            'id'                 => 'declined_' . $request->idempotencyKey,
            'status'             => 'requires_payment_method',
            'amount'             => $request->amount->amount,
            'currency'           => strtolower($request->currency->value),
            'last_payment_error' => ['message' => $e->getMessage()],
        ];
    }

    /** {@inheritDoc} */
    public function authorize(PaymentRequest $request): PaymentResponse
    {
        // TODO: Same orchestration shape as charge(), but delegates to a
        //       Stripe PaymentIntent created with capture_method=manual, and
        //       dispatches PaymentInitiated / PaymentSucceeded / PaymentFailed.
        throw new \LogicException('StripeDriver::authorize() not yet implemented.');
    }

    /** {@inheritDoc} */
    public function capture(CaptureRequest $request): CaptureResponse
    {
        // TODO: $this->validateIdempotencyKey($request->idempotencyKey);
        // TODO: $this->logInfo('Capturing payment', $this->buildLogContext('capture'));
        // TODO: try {
        //           $raw      = $this->withRetry(fn () => /* $this->client capture call */ null);
        //           $response = $this->mapper->toCaptureResponse($raw);
        //           $this->dispatchEvent(new PaymentCaptured($request, $response));
        //           return $response;
        //       } catch (\Throwable $e) {
        //           $this->logError('Capture failed', $this->buildLogContext('capture'));
        //           throw $this->exceptionMapper->map($e, ['operation' => 'capture']);
        //       }
        throw new \LogicException('StripeDriver::capture() not yet implemented.');
    }

    /** {@inheritDoc} */
    public function void(VoidRequest $request): VoidResponse
    {
        // TODO: $this->validateIdempotencyKey($request->idempotencyKey);
        // TODO: $this->logInfo('Voiding payment', $this->buildLogContext('void'));
        // TODO: try {
        //           $raw      = $this->withRetry(fn () => /* $this->client cancel call */ null);
        //           $response = $this->mapper->toVoidResponse($raw);
        //           $this->dispatchEvent(new PaymentVoided($request, $response));
        //           return $response;
        //       } catch (\Throwable $e) {
        //           $this->logError('Void failed', $this->buildLogContext('void'));
        //           throw $this->exceptionMapper->map($e, ['operation' => 'void']);
        //       }
        throw new \LogicException('StripeDriver::void() not yet implemented.');
    }

    /** {@inheritDoc} */
    public function refund(RefundRequest $request): RefundResponse
    {
        // TODO: $this->validateIdempotencyKey($request->idempotencyKey);
        // TODO: $this->logInfo('Refunding payment', $this->buildLogContext('refund'));
        // TODO: try {
        //           $raw      = $this->withRetry(fn () => /* $this->client refund call */ null);
        //           $response = $this->mapper->toRefundResponse($raw);
        //           $this->dispatchEvent(new PaymentRefunded($request, $response));
        //           return $response;
        //       } catch (\Throwable $e) {
        //           $this->logError('Refund failed', $this->buildLogContext('refund'));
        //           throw $this->exceptionMapper->map($e, ['operation' => 'refund']);
        //       }
        throw new \LogicException('StripeDriver::refund() not yet implemented.');
    }

    /** {@inheritDoc} */
    public function partialRefund(RefundRequest $request): RefundResponse
    {
        // TODO: Same orchestration shape as refund(), passing $request->amount
        //       (less than the original charge) through to the Stripe refund call.
        throw new \LogicException('StripeDriver::partialRefund() not yet implemented.');
    }

    /** {@inheritDoc} */
    public function verify(TransactionLookupRequest $request): VerificationResponse
    {
        // TODO: $this->logInfo('Verifying transaction', $this->buildLogContext('verify'));
        // TODO: try {
        //           $raw = $this->withRetry(fn () => /* $this->client retrieve call */ null);
        //           return $this->mapper->toVerificationResponse($raw);
        //       } catch (\Throwable $e) {
        //           $this->logError('Verification failed', $this->buildLogContext('verify'));
        //           throw $this->exceptionMapper->map($e, ['operation' => 'verify']);
        //       }
        throw new \LogicException('StripeDriver::verify() not yet implemented.');
    }

    /** {@inheritDoc} */
    public function lookup(TransactionLookupRequest $request): StatusResponse
    {
        // TODO: $this->logInfo('Looking up transaction', $this->buildLogContext('lookup'));
        // TODO: try {
        //           $raw      = $this->withRetry(fn () => /* $this->client retrieve call */ null);
        //           $response = $this->mapper->toStatusResponse($raw);
        //           $this->dispatchEvent(new TransactionLookuped($request, $response));
        //           return $response;
        //       } catch (\Throwable $e) {
        //           $this->logError('Lookup failed', $this->buildLogContext('lookup'));
        //           throw $this->exceptionMapper->map($e, ['operation' => 'lookup']);
        //       }
        throw new \LogicException('StripeDriver::lookup() not yet implemented.');
    }

    /** {@inheritDoc} */
    public function createPaymentLink(PaymentLinkRequest $request): PaymentLinkResponse
    {
        // TODO: $this->validateIdempotencyKey($request->idempotencyKey);
        // TODO: $this->logInfo('Creating payment link', $this->buildLogContext('createPaymentLink'));
        // TODO: try {
        //           $raw      = $this->withRetry(fn () => /* $this->client checkout session call */ null);
        //           $response = $this->mapper->toPaymentLinkResponse($raw);
        //           $this->dispatchEvent(new PaymentLinkCreated($request, $response));
        //           return $response;
        //       } catch (\Throwable $e) {
        //           $this->logError('Payment link creation failed', $this->buildLogContext('createPaymentLink'));
        //           throw $this->exceptionMapper->map($e, ['operation' => 'createPaymentLink']);
        //       }
        throw new \LogicException('StripeDriver::createPaymentLink() not yet implemented.');
    }

    /** {@inheritDoc} */
    public function saveCard(SaveCardRequest $request): PaymentResponse
    {
        // TODO: $this->validateIdempotencyKey($request->idempotencyKey);
        // TODO: $this->logInfo('Saving card', $this->buildLogContext('saveCard'));
        // TODO: try {
        //           $raw      = $this->withRetry(fn () => /* $this->client setup intent / payment method attach call */ null);
        //           $response = $this->mapper->toPaymentResponse($raw);
        //           $this->dispatchEvent(new CardSaved($request, $response));
        //           return $response;
        //       } catch (\Throwable $e) {
        //           $this->logError('Save card failed', $this->buildLogContext('saveCard'));
        //           throw $this->exceptionMapper->map($e, ['operation' => 'saveCard']);
        //       }
        throw new \LogicException('StripeDriver::saveCard() not yet implemented.');
    }

    /** {@inheritDoc} */
    public function chargeToken(TokenChargeRequest $request): PaymentResponse
    {
        // TODO: $this->validateIdempotencyKey($request->idempotencyKey);
        // TODO: $this->logInfo('Charging saved token', $this->buildLogContext('chargeToken'));
        // TODO: try {
        //           $raw      = $this->withRetry(fn () => /* $this->client off-session PaymentIntent call */ null);
        //           $response = $this->mapper->toPaymentResponse($raw);
        //           $this->dispatchEvent(new TokenCharged($request, $response));
        //           return $response;
        //       } catch (\Throwable $e) {
        //           $this->logError('Token charge failed', $this->buildLogContext('chargeToken'));
        //           throw $this->exceptionMapper->map($e, ['operation' => 'chargeToken']);
        //       }
        throw new \LogicException('StripeDriver::chargeToken() not yet implemented.');
    }

    /** {@inheritDoc} */
    public function createSubscription(SubscriptionRequest $request): SubscriptionResponse
    {
        // TODO: $this->validateIdempotencyKey($request->idempotencyKey);
        // TODO: $this->logInfo('Creating subscription', $this->buildLogContext('createSubscription'));
        // TODO: try {
        //           $raw      = $this->withRetry(fn () => /* $this->client subscription create call */ null);
        //           $response = $this->mapper->toSubscriptionResponse($raw);
        //           $this->dispatchEvent(new SubscriptionCreated($request, $response));
        //           return $response;
        //       } catch (\Throwable $e) {
        //           $this->logError('Subscription creation failed', $this->buildLogContext('createSubscription'));
        //           throw $this->exceptionMapper->map($e, ['operation' => 'createSubscription']);
        //       }
        throw new \LogicException('StripeDriver::createSubscription() not yet implemented.');
    }

    /** {@inheritDoc} */
    public function cancelSubscription(TransactionId $subscriptionId): SubscriptionResponse
    {
        // TODO: $this->logInfo('Cancelling subscription', $this->buildLogContext('cancelSubscription'));
        // TODO: try {
        //           $raw      = $this->withRetry(fn () => /* $this->client subscription cancel call */ null);
        //           $response = $this->mapper->toSubscriptionResponse($raw);
        //           $this->dispatchEvent(new SubscriptionCancelled($subscriptionId, $response));
        //           return $response;
        //       } catch (\Throwable $e) {
        //           $this->logError('Subscription cancellation failed', $this->buildLogContext('cancelSubscription'));
        //           throw $this->exceptionMapper->map($e, ['operation' => 'cancelSubscription']);
        //       }
        throw new \LogicException('StripeDriver::cancelSubscription() not yet implemented.');
    }

    /** {@inheritDoc} */
    public function processWebhook(WebhookRequest $request): WebhookResponse
    {
        // TODO: $this->logInfo('Processing webhook', $this->buildLogContext('processWebhook'));
        // TODO: try {
        //           $raw = /* json_decode($request->rawBody, true) */ [];
        //           return $this->mapper->toWebhookResponse($raw);
        //       } catch (\Throwable $e) {
        //           $this->logError('Webhook processing failed', $this->buildLogContext('processWebhook'));
        //           throw $this->exceptionMapper->map($e, ['operation' => 'processWebhook']);
        //       }
        // NOTE: WebhookReceived / WebhookProcessed are dispatched by
        //       WebhookProcessor at the orchestration layer, not here.
        throw new \LogicException('StripeDriver::processWebhook() not yet implemented.');
    }

    /** {@inheritDoc} */
    public function verifyWebhookSignature(WebhookRequest $request): bool
    {
        // TODO: return $this->webhookVerifier->verify($request->rawBody, $request->signature->toString());
        throw new \LogicException('StripeDriver::verifyWebhookSignature() not yet implemented.');
    }
}
