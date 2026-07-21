<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe;

use Illuminate\Contracts\Events\Dispatcher;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\PaymentDriverContract;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\SupportsCapabilities;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\SupportsSdkCheckout;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Logging\PaymentLoggerContract;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Services\RetryServiceContract;
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
use Mifatoyeh\LaravelPaymentFramework\Drivers\AbstractDriver;
use Mifatoyeh\LaravelPaymentFramework\Events\CardSaved;
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentCaptured;
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentFailed;
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentInitiated;
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentLinkCreated;
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentRefunded;
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentSucceeded;
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentVoided;
use Mifatoyeh\LaravelPaymentFramework\Events\SubscriptionCancelled;
use Mifatoyeh\LaravelPaymentFramework\Events\SubscriptionCreated;
use Mifatoyeh\LaravelPaymentFramework\Events\TokenCharged;
use Mifatoyeh\LaravelPaymentFramework\Events\TransactionLookuped;
use Mifatoyeh\LaravelPaymentFramework\Responses\CaptureResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentLinkResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\RefundResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\SdkCheckoutResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\StatusResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\SubscriptionResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\VerificationResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\VoidResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\WebhookResponse;
use InvalidArgumentException;
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
 * `charge()`, `authorize()`, `void()`, `capture()`, `refund()`,
 * `partialRefund()`, `verify()`, `lookup()`, `saveCard()`, `chargeToken()`,
 * `createSubscription()`, `cancelSubscription()`, and `createPaymentLink()`
 * are fully implemented. Every other method body is intentionally left as a
 * `// TODO` stub, to be implemented in a later task.
 */
final class StripeDriver extends AbstractDriver implements PaymentDriverContract, SupportsSdkCheckout, SupportsCapabilities
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
     * Explicit about `'webhook'` specifically — {@see SupportsCapabilities}'s
     * own docblock says a driver that doesn't implement this interface is
     * ASSUMED to support everything, which would be actively wrong here:
     * {@see self::processWebhook()}/{@see self::verifyWebhookSignature()}
     * are still `// TODO` stubs. Rather than rely on that default (silently
     * telling `CheckoutService`'s webhook-vs-job dispatch decision that
     * Stripe webhooks work when they don't), Stripe now implements this
     * interface explicitly, same as {@see \Mifatoyeh\LaravelPaymentFramework\Drivers\Paymob\PaymobDriver}
     * already does — everything except `'webhook'` is supported.
     */
    public function supports(string $capability): bool
    {
        return $capability !== 'webhook';
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

    /**
     * {@inheritDoc}
     *
     * Identical orchestration to {@see self::charge()} — same idempotency
     * check, logging, event dispatch, retry wrapping, decline handling, and
     * exception mapping — except the underlying PaymentIntent is created via
     * {@see StripeClient::createAuthorization()} (`capture_method: manual`)
     * so funds are reserved but not captured. Reuses
     * {@see StripeMapper::toPaymentResponse()} unchanged: it already maps
     * Stripe's `requires_capture` status to `PaymentStatus::Authorized`.
     */
    public function authorize(PaymentRequest $request): PaymentResponse
    {
        $this->validateIdempotencyKey($request->idempotencyKey);
        $this->logInfo('Initiating authorization', $this->buildLogContext('authorize', [
            'amount'   => $request->amount->amount,
            'currency' => $request->currency->value,
        ]));
        $this->dispatchEvent(new PaymentInitiated($request));

        try {
            $raw      = $this->withRetry(fn () => $this->client->createAuthorization($request));
            $response = $this->mapper->toPaymentResponse($raw);

            if ($response->isSuccessful()) {
                $this->dispatchEvent(new PaymentSucceeded($request, $response));
                $this->logInfo('Authorization succeeded', $this->buildLogContext('authorize', [
                    'transaction_id' => $response->getTransactionId()->toString(),
                    'status'         => $response->getStatus()->value,
                ]));
            } else {
                $this->dispatchEvent(new PaymentFailed($request, $response, null));
                $this->logWarning('Authorization did not succeed', $this->buildLogContext('authorize', [
                    'status'  => $response->getStatus()->value,
                    'message' => $response->getMessage(),
                ]));
            }

            return $response;
        } catch (CardException $e) {
            $response = $this->mapper->toPaymentResponse($this->declinedPaymentIntentFrom($e, $request));
            $this->dispatchEvent(new PaymentFailed($request, $response, null));
            $this->logWarning('Authorization declined by Stripe', $this->buildLogContext('authorize', [
                'decline_code' => $e->getDeclineCode(),
                'message'      => $e->getMessage(),
            ]));

            return $response;
        } catch (Throwable $e) {
            $this->dispatchEvent(new PaymentFailed($request, null, $e));
            $this->logError('Authorization failed', $this->buildLogContext('authorize', [
                'error' => $e->getMessage(),
            ]));

            throw $this->exceptionMapper->map($e, ['operation' => 'authorize']);
        }
    }

    /**
     * {@inheritDoc}
     *
     * Same narrower shape as {@see self::void()}: capturing an already
     * authorised PaymentIntent does not touch a card network, so there is no
     * {@see CardException} / soft-failure branch. Every Throwable is mapped
     * via {@see StripeExceptionMapper} and thrown; {@see PaymentCaptured} is
     * dispatched only after a successful capture.
     */
    public function capture(CaptureRequest $request): CaptureResponse
    {
        $this->validateIdempotencyKey($request->idempotencyKey);
        $this->logInfo('Capturing payment', $this->buildLogContext('capture', [
            'transaction_id' => $request->transactionId->toString(),
            'amount'         => $request->amount->amount,
        ]));

        try {
            $raw      = $this->withRetry(fn () => $this->client->capturePaymentIntent($request));
            $response = $this->mapper->toCaptureResponse($raw);

            $this->dispatchEvent(new PaymentCaptured($request, $response));
            $this->logInfo('Capture succeeded', $this->buildLogContext('capture', [
                'capture_id' => $response->getCaptureId(),
                'status'     => $response->getStatus()->value,
            ]));

            return $response;
        } catch (Throwable $e) {
            $this->logError('Capture failed', $this->buildLogContext('capture', [
                'error' => $e->getMessage(),
            ]));

            throw $this->exceptionMapper->map($e, ['operation' => 'capture']);
        }
    }

    /**
     * {@inheritDoc}
     *
     * Simpler orchestration than {@see self::charge()}/{@see self::authorize()}:
     * Stripe's PaymentIntent cancel endpoint never touches a card network, so
     * {@see CardException} cannot occur here — there is no soft-failure
     * branch. Every Throwable is mapped via {@see StripeExceptionMapper} and
     * thrown; {@see PaymentVoided} is dispatched only after a successful
     * cancel (there is no `PaymentVoidFailed`-style event to dispatch on the
     * exception path).
     */
    public function void(VoidRequest $request): VoidResponse
    {
        $this->validateIdempotencyKey($request->idempotencyKey);
        $this->logInfo('Voiding payment', $this->buildLogContext('void', [
            'transaction_id' => $request->transactionId->toString(),
            'reason'         => $request->reason,
        ]));

        try {
            $raw      = $this->withRetry(fn () => $this->client->cancelPaymentIntent($request));
            $response = $this->mapper->toVoidResponse($raw);

            $this->dispatchEvent(new PaymentVoided($request, $response));
            $this->logInfo('Void succeeded', $this->buildLogContext('void', [
                'transaction_id' => $response->getTransactionId()->toString(),
                'status'         => $response->getStatus()->value,
            ]));

            return $response;
        } catch (Throwable $e) {
            $this->logError('Void failed', $this->buildLogContext('void', [
                'error' => $e->getMessage(),
            ]));

            throw $this->exceptionMapper->map($e, ['operation' => 'void']);
        }
    }

    /**
     * {@inheritDoc}
     *
     * Same narrower shape as {@see self::void()}/{@see self::capture()}: no
     * {@see CardException} / soft-failure branch — a refund is a money-
     * movement operation, not a new card-network authorisation. Every
     * Throwable is mapped via {@see StripeExceptionMapper} and thrown;
     * {@see PaymentRefunded} is dispatched after any non-throwing result,
     * including a `pending` or `requires_action` refund status (not just a
     * fully `succeeded` one) — those are still legitimate, non-exceptional
     * outcomes {@see StripeMapper::toRefundResponse()} reports accurately,
     * and the caller inspects `$response->getStatus()`/`isSuccessful()` to
     * distinguish them.
     */
    public function refund(RefundRequest $request): RefundResponse
    {
        $this->validateIdempotencyKey($request->idempotencyKey);
        $this->logInfo('Refunding payment', $this->buildLogContext('refund', [
            'transaction_id' => $request->transactionId->toString(),
            'amount'         => $request->amount->amount,
            'reason'         => $request->reason,
        ]));

        try {
            $raw      = $this->withRetry(fn () => $this->client->createRefund($request));
            $response = $this->mapper->toRefundResponse($raw);

            $this->dispatchEvent(new PaymentRefunded($request, $response));
            $this->logInfo('Refund processed', $this->buildLogContext('refund', [
                'refund_id' => $response->getRefundId(),
                'status'    => $response->getStatus()->value,
            ]));

            return $response;
        } catch (Throwable $e) {
            $this->logError('Refund failed', $this->buildLogContext('refund', [
                'error' => $e->getMessage(),
            ]));

            throw $this->exceptionMapper->map($e, ['operation' => 'refund']);
        }
    }

    /** {@inheritDoc} */
    /**
     * {@inheritDoc}
     *
     * Same shape as {@see self::refund()}, deliberately — Stripe has no
     * separate API operation for a partial refund; {@see StripeClient::createRefund()}
     * (called by both methods, unchanged) forwards `$request->amount`
     * identically either way. What DOES distinguish a full refund from a
     * partial one is resolved entirely inside
     * {@see StripeMapper::toRefundResponse()} from the response payload
     * itself (via the expanded Charge's cumulative `amount_refunded`), not
     * by which of these two methods was called — so calling `refund()` with
     * less than the full amount, or `partialRefund()` with the exact full
     * remaining amount, both still resolve to the objectively correct
     * `PaymentStatus`. This method exists as its own driver method only to
     * satisfy {@see PaymentDriverContract}'s distinct `refund()`/
     * `partialRefund()` shape and to carry its own log/operation-context
     * label. Same exception surface as refund() — verified against the
     * SDK: Stripe rejects an amount exceeding the remaining refundable
     * balance the same way for a full or partial request, via
     * {@see \Stripe\Exception\InvalidRequestException}, already covered by
     * the existing {@see StripeExceptionMapper} rule.
     */
    public function partialRefund(RefundRequest $request): RefundResponse
    {
        $this->validateIdempotencyKey($request->idempotencyKey);
        $this->logInfo('Partially refunding payment', $this->buildLogContext('partialRefund', [
            'transaction_id' => $request->transactionId->toString(),
            'amount'         => $request->amount->amount,
            'reason'         => $request->reason,
        ]));

        try {
            $raw      = $this->withRetry(fn () => $this->client->createRefund($request));
            $response = $this->mapper->toRefundResponse($raw);

            $this->dispatchEvent(new PaymentRefunded($request, $response));
            $this->logInfo('Partial refund processed', $this->buildLogContext('partialRefund', [
                'refund_id' => $response->getRefundId(),
                'status'    => $response->getStatus()->value,
            ]));

            return $response;
        } catch (Throwable $e) {
            $this->logError('Partial refund failed', $this->buildLogContext('partialRefund', [
                'error' => $e->getMessage(),
            ]));

            throw $this->exceptionMapper->map($e, ['operation' => 'partialRefund']);
        }
    }

    /** {@inheritDoc} */
    /**
     * {@inheritDoc}
     *
     * Read-only — no idempotency key exists on `TransactionLookupRequest`
     * at all (it carries only `transactionId` and `metadata`), and
     * {@see AbstractDriver::validateIdempotencyKey()}'s own docblock
     * enumerates only the mutating methods it must guard; verify() is not
     * among them. So there is no idempotency check here — not an omission,
     * there is nothing to validate. No event is dispatched either: the
     * Events directory has {@see \Mifatoyeh\LaravelPaymentFramework\Events\TransactionLookuped}
     * for {@see self::lookup()}, but no corresponding event exists for
     * verify() — inventing one wasn't asked for and nothing consumes it.
     * The retrieve call is still wrapped in {@see self::withRetry()}
     * (transient network/5xx errors apply to reads too), and every
     * Throwable still goes through {@see StripeExceptionMapper} (e.g. an
     * unknown/invalid transaction id surfaces as
     * {@see \Stripe\Exception\InvalidRequestException}, HTTP 404).
     */
    public function verify(TransactionLookupRequest $request): VerificationResponse
    {
        $this->logInfo('Verifying transaction', $this->buildLogContext('verify', [
            'transaction_id' => $request->transactionId->toString(),
        ]));

        try {
            $raw      = $this->withRetry(fn () => $this->client->retrievePaymentIntent($request));
            $response = $this->mapper->toVerificationResponse($raw);

            $this->logInfo('Verification completed', $this->buildLogContext('verify', [
                'verified' => $response->isVerified(),
            ]));

            return $response;
        } catch (Throwable $e) {
            $this->logError('Verification failed', $this->buildLogContext('verify', [
                'error' => $e->getMessage(),
            ]));

            throw $this->exceptionMapper->map($e, ['operation' => 'verify']);
        }
    }

    /** {@inheritDoc} */
    /**
     * {@inheritDoc}
     *
     * Same read-only shape as {@see self::verify()} — no idempotency check,
     * same reasoning (`TransactionLookupRequest` has no idempotency key).
     * Unlike verify(), this DOES dispatch
     * {@see \Mifatoyeh\LaravelPaymentFramework\Events\TransactionLookuped}
     * on success — that event exists specifically for this operation.
     */
    public function lookup(TransactionLookupRequest $request): StatusResponse
    {
        $this->logInfo('Looking up transaction', $this->buildLogContext('lookup', [
            'transaction_id' => $request->transactionId->toString(),
        ]));

        try {
            $raw      = $this->withRetry(fn () => $this->client->retrievePaymentIntent($request));
            $response = $this->mapper->toStatusResponse($raw);

            $this->dispatchEvent(new TransactionLookuped($request, $response));
            $this->logInfo('Lookup completed', $this->buildLogContext('lookup', [
                'status' => $response->getStatus()->value,
            ]));

            return $response;
        } catch (Throwable $e) {
            $this->logError('Lookup failed', $this->buildLogContext('lookup', [
                'error' => $e->getMessage(),
            ]));

            throw $this->exceptionMapper->map($e, ['operation' => 'lookup']);
        }
    }

    /**
     * {@inheritDoc}
     *
     * Backed by a Stripe Checkout Session, not Stripe's separate
     * `PaymentLink` API resource — see
     * {@see StripeMapper::toPaymentLinkResponse()}'s docblock for why.
     *
     * One framework-level guard runs before any Stripe call:
     * `$request->returnUrl` is required — verified against the SDK that
     * Stripe's hosted Checkout flow (the only flow this driver uses; no
     * `ui_mode`/`redirect_on_completion` override is set) requires a
     * `success_url`. `$request->cancelUrl` is NOT guarded — verified
     * genuinely optional (Stripe just omits the "back" button on the hosted
     * page when absent).
     *
     * No {@see CardException} soft-failure branch — verified against the
     * SDK that creating a Checkout Session never itself charges a card; the
     * actual charge happens later, asynchronously, when the customer
     * completes checkout on Stripe's hosted page (observable via a
     * `checkout.session.completed` webhook — out of scope for this method,
     * see {@see self::processWebhook()}). `PaymentLinkCreated` is
     * dispatched unconditionally after any non-throwing result, matching
     * {@see StripeMapper::toPaymentLinkResponse()}'s `successful:
     * true`-unconditionally contract.
     */
    public function createPaymentLink(PaymentLinkRequest $request): PaymentLinkResponse
    {
        $this->validateIdempotencyKey($request->idempotencyKey);

        if ($request->returnUrl === null || trim($request->returnUrl) === '') {
            throw new InvalidArgumentException(
                'PaymentLinkRequest::$returnUrl is required to create a Stripe Checkout Session: ' .
                'Stripe requires a success_url for the hosted checkout redirect flow this driver uses. ' .
                'Pass the URL customers should land on after completing payment.',
            );
        }

        $this->logInfo('Creating payment link', $this->buildLogContext('createPaymentLink', [
            'amount'   => $request->amount->amount,
            'currency' => $request->currency->value,
        ]));

        try {
            $raw      = $this->withRetry(fn () => $this->client->createCheckoutSession($request));
            $response = $this->mapper->toPaymentLinkResponse($raw);

            $this->dispatchEvent(new PaymentLinkCreated($request, $response));
            $this->logInfo('Payment link created', $this->buildLogContext('createPaymentLink', [
                'link_id' => $response->getLinkId(),
            ]));

            return $response;
        } catch (Throwable $e) {
            $this->logError('Payment link creation failed', $this->buildLogContext('createPaymentLink', [
                'error' => $e->getMessage(),
            ]));

            throw $this->exceptionMapper->map($e, ['operation' => 'createPaymentLink']);
        }
    }

    /**
     * {@inheritDoc}
     *
     * Creates an UNCONFIRMED Stripe PaymentIntent (see
     * {@see StripeClient::createUnconfirmedPaymentIntent()}'s own docblock
     * for how this differs from {@see self::createPaymentLink()}/
     * {@see self::charge()}) and returns its `client_secret` for a native
     * Stripe SDK (mobile or web) to confirm directly, client-side. No
     * `returnUrl` guard here — unlike the hosted Checkout Session flow,
     * confirming a PaymentIntent client-side has no redirect concept to
     * require a `success_url` for.
     *
     * No {@see CardException} branch — verified against the SDK that
     * creating an unconfirmed PaymentIntent never touches a card network at
     * all (nothing is confirmed yet). No event is dispatched — the
     * meaningful confirmation moment is `CheckoutService::confirm()`, which
     * dispatches {@see \Mifatoyeh\LaravelPaymentFramework\Events\CheckoutPaymentConfirmed}
     * once the client-side confirmation has actually happened; dispatching
     * something here, before any card was even entered, would be premature.
     */
    public function createSdkIntent(PaymentLinkRequest $request): SdkCheckoutResponse
    {
        $this->validateIdempotencyKey($request->idempotencyKey);
        $this->logInfo('Creating SDK checkout intent', $this->buildLogContext('createSdkIntent', [
            'amount'   => $request->amount->amount,
            'currency' => $request->currency->value,
        ]));

        try {
            $raw      = $this->withRetry(fn () => $this->client->createUnconfirmedPaymentIntent($request));
            $response = $this->mapper->toSdkCheckoutResponse($raw, $this->getCredential('key'));

            $this->logInfo('SDK checkout intent created', $this->buildLogContext('createSdkIntent', [
                'transaction_reference' => $response->getTransactionReference(),
            ]));

            return $response;
        } catch (Throwable $e) {
            $this->logError('SDK checkout intent creation failed', $this->buildLogContext('createSdkIntent', [
                'error' => $e->getMessage(),
            ]));

            throw $this->exceptionMapper->map($e, ['operation' => 'createSdkIntent']);
        }
    }

    /**
     * {@inheritDoc}
     *
     * Two sequential Stripe API calls, both wrapped individually in
     * {@see self::withRetry()} — this two-step sequence (create a Customer,
     * then a SetupIntent scoped to it) is orchestration, so it lives here,
     * not inside a single {@see StripeClient} method (see that class's own
     * docblock: "no business logic"):
     *   1. {@see StripeClient::createCustomer()} — `SaveCardRequest` carries
     *      no provider-side customer identity at all (design gap resolved:
     *      see {@see SaveCardRequest}'s docblock and
     *      {@see \Mifatoyeh\LaravelPaymentFramework\DTO\TokenChargeRequest::$providerCustomerReference}),
     *      so one is created here, every call.
     *   2. {@see StripeClient::createSetupIntent()} — attaches
     *      `$request->token` to that new customer and confirms immediately.
     *
     * A declined card ({@see CardException}) IS a soft failure here, same
     * as {@see self::charge()}/{@see self::authorize()} — verified against
     * the SDK that SetupIntent confirmation genuinely touches a card
     * network — per {@see PaymentResponse}'s own documented contract, which
     * is not scoped to any one operation. The already-created Stripe
     * Customer id is preserved in the returned response's
     * `providerReference` even on a decline, via {@see self::declinedSetupIntentFrom()},
     * so a caller can retry saveCard() for the same customer with a
     * different card without losing that reference. No event is dispatched
     * on a soft failure: {@see \Mifatoyeh\LaravelPaymentFramework\Events\PaymentFailed}
     * is hard-typed to `PaymentRequest` (verified — it does not accept a
     * `SaveCardRequest`) and no `CardSaveFailed`-style event exists to
     * dispatch instead; inventing one wasn't asked for and nothing consumes
     * it, matching the same reasoning used for verify()/void() not having a
     * failure-event counterpart.
     *
     * No `CardSaved`-equivalent "started" event is dispatched before the
     * call either — the Events directory has no such event, and this
     * mirrors {@see self::capture()}/{@see self::void()}/{@see self::refund()},
     * none of which dispatch a "started" event; only {@see self::charge()}/
     * {@see self::authorize()} do, via {@see PaymentInitiated}, which is
     * itself hard-typed to `PaymentRequest`.
     */
    public function saveCard(SaveCardRequest $request): PaymentResponse
    {
        $this->validateIdempotencyKey($request->idempotencyKey);
        $this->logInfo('Saving card', $this->buildLogContext('saveCard', [
            'customer_id' => $request->customerId->toString(),
        ]));

        $stripeCustomerId = '';

        try {
            $customer         = $this->withRetry(fn () => $this->client->createCustomer($request));
            $stripeCustomerId = (string) ($customer['id'] ?? '');

            $raw      = $this->withRetry(fn () => $this->client->createSetupIntent($stripeCustomerId, $request));
            $response = $this->mapper->toSaveCardResponse($raw);

            if ($response->isSuccessful()) {
                $this->dispatchEvent(new CardSaved($request, $response));
                $this->logInfo('Card saved', $this->buildLogContext('saveCard', [
                    'transaction_id'     => $response->getTransactionId()->toString(),
                    'provider_reference' => $response->getProviderReference(),
                ]));
            } else {
                $this->logWarning('Card save did not succeed', $this->buildLogContext('saveCard', [
                    'status'  => $response->getStatus()->value,
                    'message' => $response->getMessage(),
                ]));
            }

            return $response;
        } catch (CardException $e) {
            $response = $this->mapper->toSaveCardResponse(
                $this->declinedSetupIntentFrom($e, $request, $stripeCustomerId),
            );
            $this->logWarning('Card save declined by Stripe', $this->buildLogContext('saveCard', [
                'decline_code' => $e->getDeclineCode(),
                'message'      => $e->getMessage(),
            ]));

            return $response;
        } catch (Throwable $e) {
            $this->logError('Save card failed', $this->buildLogContext('saveCard', [
                'error' => $e->getMessage(),
            ]));

            throw $this->exceptionMapper->map($e, ['operation' => 'saveCard']);
        }
    }

    /**
     * Build a raw SetupIntent-shaped array from a declined-card exception.
     *
     * Counterpart to {@see self::declinedPaymentIntentFrom()} for the
     * SetupIntent shape {@see StripeMapper::toSaveCardResponse()} expects
     * (`last_setup_error`, not `last_payment_error`; `customer`, not
     * `amount`/`currency`). Stripe embeds the associated SetupIntent under
     * `error.setup_intent` for confirmation-time card declines, mirroring
     * `error.payment_intent` on a PaymentIntent decline; when absent, a
     * minimal synthetic payload is built instead — crucially still carrying
     * `$stripeCustomerId`, so the caller does not lose the already-created
     * Customer reference just because the card itself was declined.
     *
     * @param CardException    $e                 The card decline exception.
     * @param SaveCardRequest  $request           The original request (used only for the fallback payload's id).
     * @param string           $stripeCustomerId  The Stripe Customer id already created before the decline.
     *
     * @return array<string, mixed>
     */
    private function declinedSetupIntentFrom(CardException $e, SaveCardRequest $request, string $stripeCustomerId): array
    {
        $setupIntent = $e->getJsonBody()['error']['setup_intent'] ?? null;

        if (is_array($setupIntent)) {
            return $setupIntent;
        }

        return [
            'id'               => 'declined_' . $request->idempotencyKey,
            'status'           => 'requires_payment_method',
            'customer'         => $stripeCustomerId,
            'last_setup_error' => ['message' => $e->getMessage()],
        ];
    }

    /**
     * {@inheritDoc}
     *
     * Requires `$request->providerCustomerReference` — enforced here with an
     * explicit, framework-level {@see InvalidArgumentException} BEFORE any
     * Stripe call is attempted, rather than letting a bare/omitted value
     * surface as a cryptic Stripe API rejection. Same philosophy as the
     * `payment_method`/`token` collision guard in
     * {@see \Mifatoyeh\LaravelPaymentFramework\Factories\PaymentRequestFactory::paymentMethod()}:
     * a caller mistake should fail fast with a message that names the exact
     * fix, not a generic provider error several layers removed from the
     * actual cause. This is a genuinely hard Stripe requirement, not a
     * framework-imposed one — verified against the SDK
     * (`PaymentIntent::$customer`'s own docblock).
     *
     * Otherwise identical orchestration to {@see self::charge()}: same
     * {@see CardException} soft-failure handling (per
     * {@see PaymentResponse}'s own documented contract), reusing
     * {@see StripeMapper::toPaymentResponse()} unchanged — a token charge
     * creates a real PaymentIntent, the same shape charge()/authorize()
     * already produce. No `PaymentInitiated`-equivalent "started" event is
     * dispatched (same reasoning as {@see self::saveCard()}); no event is
     * dispatched on a soft failure either, for the same reason
     * ({@see \Mifatoyeh\LaravelPaymentFramework\Events\PaymentFailed} is
     * hard-typed to `PaymentRequest`, verified, and no
     * `TokenChargeFailed`-style event exists).
     */
    public function chargeToken(TokenChargeRequest $request): PaymentResponse
    {
        $this->validateIdempotencyKey($request->idempotencyKey);

        if ($request->providerCustomerReference === null || trim($request->providerCustomerReference) === '') {
            throw new InvalidArgumentException(
                'TokenChargeRequest::$providerCustomerReference is required to charge a saved Stripe ' .
                'payment method off-session: Stripe scopes a saved payment method to the Customer it was ' .
                "attached to during saveCard() (verified against the SDK — PaymentIntent::\$customer: " .
                '"Payment methods attached to other Customers cannot be used with this PaymentIntent"). ' .
                "Pass the value returned via a prior saveCard() call's PaymentResponse::getProviderReference().",
            );
        }

        $this->logInfo('Charging saved token', $this->buildLogContext('chargeToken', [
            'amount'   => $request->amount->amount,
            'currency' => $request->currency->value,
        ]));

        try {
            $raw      = $this->withRetry(fn () => $this->client->createTokenCharge($request));
            $response = $this->mapper->toPaymentResponse($raw);

            if ($response->isSuccessful()) {
                $this->dispatchEvent(new TokenCharged($request, $response));
                $this->logInfo('Token charge succeeded', $this->buildLogContext('chargeToken', [
                    'transaction_id' => $response->getTransactionId()->toString(),
                    'status'         => $response->getStatus()->value,
                ]));
            } else {
                $this->logWarning('Token charge did not succeed', $this->buildLogContext('chargeToken', [
                    'status'  => $response->getStatus()->value,
                    'message' => $response->getMessage(),
                ]));
            }

            return $response;
        } catch (CardException $e) {
            $response = $this->mapper->toPaymentResponse($this->declinedTokenChargeFrom($e, $request));
            $this->logWarning('Token charge declined by Stripe', $this->buildLogContext('chargeToken', [
                'decline_code' => $e->getDeclineCode(),
                'message'      => $e->getMessage(),
            ]));

            return $response;
        } catch (Throwable $e) {
            $this->logError('Token charge failed', $this->buildLogContext('chargeToken', [
                'error' => $e->getMessage(),
            ]));

            throw $this->exceptionMapper->map($e, ['operation' => 'chargeToken']);
        }
    }

    /**
     * Build a raw PaymentIntent-shaped array from a declined-card exception.
     *
     * Counterpart to {@see self::declinedPaymentIntentFrom()} typed for
     * {@see TokenChargeRequest} instead of {@see PaymentRequest} — kept as
     * its own separate method rather than a shared/generic helper, matching
     * this package's established convention of not sharing driver-method
     * bodies across different DTO types (e.g. refund()/partialRefund()).
     *
     * @param CardException      $e       The card decline exception.
     * @param TokenChargeRequest $request The original request (used only for the fallback payload).
     *
     * @return array<string, mixed>
     */
    private function declinedTokenChargeFrom(CardException $e, TokenChargeRequest $request): array
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

    /**
     * {@inheritDoc}
     *
     * Two framework-level guards run BEFORE any Stripe call, both throwing
     * {@see InvalidArgumentException} with a message naming the requirement
     * and how to satisfy it — same philosophy as
     * {@see self::chargeToken()}'s `providerCustomerReference` guard:
     *   1. `$request->providerCustomerReference` is required — verified
     *      against the SDK that Stripe Subscriptions are unconditionally
     *      customer-scoped (`Subscription::$customer`).
     *   2. `$request->planId` is required — this driver does not support
     *      ad-hoc/inline pricing (verified against the SDK: even Stripe's
     *      inline `price_data` path still requires an existing Product id,
     *      so there is no genuinely ad-hoc path to fall back to; see
     *      {@see StripeClient::createSubscription()}'s docblock).
     *
     * `$request->token` is NOT guarded — verified against the SDK that it
     * is genuinely optional (Stripe falls back to the customer's own stored
     * default payment method when omitted).
     *
     * No {@see CardException} soft-failure branch, unlike
     * {@see self::charge()}/{@see self::chargeToken()} — verified against
     * the SDK that creating a subscription does not throw synchronously on
     * a first-charge decline; that outcome surfaces as
     * `Subscription::$status === 'incomplete'` in the response payload
     * instead, handled entirely inside
     * {@see StripeMapper::toSubscriptionResponse()}. `SubscriptionCreated`
     * is dispatched only when the mapped response is successful — mirroring
     * {@see self::saveCard()}'s pattern, since an unsuccessful-but-non-
     * throwing outcome (e.g. `incomplete`) is not a "created" event in any
     * meaningful sense.
     */
    public function createSubscription(SubscriptionRequest $request): SubscriptionResponse
    {
        $this->validateIdempotencyKey($request->idempotencyKey);

        if ($request->providerCustomerReference === null || trim($request->providerCustomerReference) === '') {
            throw new InvalidArgumentException(
                'SubscriptionRequest::$providerCustomerReference is required to create a Stripe subscription: ' .
                "Stripe Subscriptions are unconditionally customer-scoped (verified against the SDK — " .
                'Subscription::$customer: "ID of the customer who owns the subscription"). ' .
                "Pass the value returned via a prior saveCard() call's PaymentResponse::getProviderReference().",
            );
        }

        if (! $request->hasPlanId()) {
            throw new InvalidArgumentException(
                'SubscriptionRequest::$planId is required: this driver does not support ad-hoc/inline pricing. ' .
                'Verified against the SDK: even Stripe\'s inline price_data path still requires an existing ' .
                'Stripe Product id, so there is no genuinely ad-hoc path available either way. Pass an ' .
                "existing Stripe Price ID (e.g. 'price_1N...'), created beforehand via the Stripe dashboard or API.",
            );
        }

        $this->logInfo('Creating subscription', $this->buildLogContext('createSubscription', [
            'interval' => $request->interval,
            'plan_id'  => $request->planId,
        ]));

        try {
            $raw      = $this->withRetry(fn () => $this->client->createSubscription($request));
            $response = $this->mapper->toSubscriptionResponse($raw);

            if ($response->isSuccessful()) {
                $this->dispatchEvent(new SubscriptionCreated($request, $response));
                $this->logInfo('Subscription created', $this->buildLogContext('createSubscription', [
                    'subscription_id' => $response->getSubscriptionId(),
                    'status'          => $response->getStatus()->value,
                ]));
            } else {
                $this->logWarning('Subscription creation did not succeed', $this->buildLogContext('createSubscription', [
                    'status'  => $response->getStatus()->value,
                    'message' => $response->getMessage(),
                ]));
            }

            return $response;
        } catch (Throwable $e) {
            $this->logError('Subscription creation failed', $this->buildLogContext('createSubscription', [
                'error' => $e->getMessage(),
            ]));

            throw $this->exceptionMapper->map($e, ['operation' => 'createSubscription']);
        }
    }

    /**
     * {@inheritDoc}
     *
     * Dispatches to one of two genuinely different Stripe API calls
     * depending on `$request->cancelAtPeriodEnd` (verified against the SDK
     * — see {@see StripeClient::cancelSubscriptionImmediately()} and
     * {@see StripeClient::scheduleSubscriptionCancellation()}'s own
     * docblocks for why these are not variants of the same call). Same
     * narrower shape as {@see self::void()}/{@see self::capture()}: no
     * {@see CardException} branch (cancelling never touches a card
     * network), and {@see SubscriptionCancelled} is dispatched
     * unconditionally after any non-throwing result — this operation has no
     * soft-failure concept.
     */
    public function cancelSubscription(CancelSubscriptionRequest $request): SubscriptionResponse
    {
        $this->validateIdempotencyKey($request->idempotencyKey);
        $this->logInfo('Cancelling subscription', $this->buildLogContext('cancelSubscription', [
            'subscription_id'      => $request->subscriptionId->toString(),
            'cancel_at_period_end' => $request->cancelAtPeriodEnd,
        ]));

        try {
            $raw = $request->cancelAtPeriodEnd
                ? $this->withRetry(fn () => $this->client->scheduleSubscriptionCancellation($request))
                : $this->withRetry(fn () => $this->client->cancelSubscriptionImmediately($request));

            $response = $this->mapper->toSubscriptionResponse($raw);

            $this->dispatchEvent(new SubscriptionCancelled($request, $response));
            $this->logInfo('Subscription cancellation processed', $this->buildLogContext('cancelSubscription', [
                'subscription_id' => $response->getSubscriptionId(),
                'status'          => $response->getStatus()->value,
            ]));

            return $response;
        } catch (Throwable $e) {
            $this->logError('Subscription cancellation failed', $this->buildLogContext('cancelSubscription', [
                'error' => $e->getMessage(),
            ]));

            throw $this->exceptionMapper->map($e, ['operation' => 'cancelSubscription']);
        }
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
