<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Drivers\Paymob;

use Illuminate\Contracts\Events\Dispatcher;
use InvalidArgumentException;
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
use Mifatoyeh\LaravelPaymentFramework\Events\TokenCharged;
use Mifatoyeh\LaravelPaymentFramework\Events\TransactionLookuped;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\UnsupportedOperationException;
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
use Throwable;

/**
 * Paymob implementation of {@see PaymentDriverContract}.
 *
 * UNVERIFIED AGAINST LIVE PAYMOB DOCS — read this before trusting anything
 * below. Unlike {@see \Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeDriver},
 * which was built and continuously cross-checked against the real
 * `stripe/stripe-php` SDK source, Paymob has no official SDK. Every
 * endpoint, field name, and status flag this driver and its collaborators
 * ({@see PaymobClient}, {@see PaymobMapper}) rely on comes from general
 * knowledge of Paymob's Accept API, not a verified live source. Treat this
 * entire driver as a first-pass implementation to be checked against
 * Paymob's current documentation and real sandbox calls before production use.
 *
 * ## The one deliberate, load-bearing design decision in this driver
 *
 * Paymob's server-side "pay" endpoint requires either raw card data (PAN,
 * expiry, CVV — heavy PCI-DSS scope for the merchant) or a previously
 * Paymob-issued reusable token. This driver NEVER sends raw card data —
 * `charge()`, `authorize()`, `chargeToken()`, and `saveCard()` all assume
 * `$request->token` is a token Paymob already issued (via its own hosted
 * iframe, typically with "save card" enabled), matching the PCI-safe
 * pattern the rest of this framework already uses for Stripe (opaque
 * provider tokens in, never raw card data). Practically, this means: a
 * customer's very first payment for Paymob should normally go through
 * {@see self::createPaymentLink()} (Paymob's hosted iframe) — `charge()`
 * is for charging a card Paymob has already tokenised, not for a brand-new
 * card entered via a raw API call.
 *
 * ## Known gap: SaveCardRequest carries no email/name
 *
 * {@see SaveCardRequest} has no `CustomerData` (only an opaque
 * `CustomerId`) — but Paymob's `payment_keys` endpoint requires
 * `billing_data.email` to look like a real email. {@see self::saveCard()}
 * falls back to a placeholder email when none is available via
 * `$request->metadata['email']`, which real Paymob validation may reject —
 * flagged explicitly rather than silently working around it. This is the
 * same category of DTO-shape gap discussed for Stripe's `saveCard()`
 * (see that investigation), but unresolved here since Paymob's requirement
 * makes it a harder blocker (Stripe tolerated an empty Customer; Paymob's
 * endpoint may reject a placeholder email outright).
 *
 * ## Not implemented
 *
 * `createSubscription()`/`cancelSubscription()` throw
 * {@see UnsupportedOperationException} — Paymob has no subscription/billing-
 * cycle API resembling Stripe's Subscription object; this driver does not
 * fake one. `processWebhook()`/`verifyWebhookSignature()` are `// TODO`
 * stubs, deferred per explicit instruction (same status as Stripe's).
 */
final class PaymobDriver extends AbstractDriver implements PaymentDriverContract, SupportsCapabilities, SupportsSdkCheckout
{
    private readonly PaymobClient $client;

    private readonly PaymobMapper $mapper;

    private readonly PaymobExceptionMapper $exceptionMapper;

    public function __construct(
        PaymentLoggerContract $logger,
        Dispatcher $events,
        RetryServiceContract $retry,
        array $config = [],
    ) {
        parent::__construct($logger, $events, $retry, $config);

        $this->client          = new PaymobClient($config);
        $this->mapper          = new PaymobMapper();
        $this->exceptionMapper = new PaymobExceptionMapper();
    }

    /** {@inheritDoc} */
    public function supports(string $capability): bool
    {
        return $capability !== 'subscription';
    }

    /**
     * {@inheritDoc}
     *
     * Requires `$request->token` — see this class's docblock for why (no
     * raw-card path exists in this driver). Orchestrates Paymob's
     * authenticate → create order → request payment key → pay sequence
     * (four Paymob calls, each individually wrapped in
     * {@see self::withRetry()}) — this sequencing is orchestration and
     * therefore lives here, not in {@see PaymobClient}, mirroring
     * {@see \Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeDriver::saveCard()}'s
     * multi-call precedent.
     *
     * No provider-exception soft-failure branch is needed the way Stripe's
     * `CardException` requires one: Paymob's pay endpoint returns HTTP 200
     * with `success: false` in the body for a declined card, not a thrown
     * error — that outcome is handled entirely inside
     * {@see PaymobMapper::toPaymentResponse()}, same as every other soft
     * decline in this framework.
     */
    public function charge(PaymentRequest $request): PaymentResponse
    {
        return $this->executePayment($request, $request->amount->amount, $request->currency->value, 'charge');
    }

    /**
     * {@inheritDoc}
     *
     * UNVERIFIED / LOW CONFIDENCE: Paymob's authorise-then-capture mechanism
     * is not confirmed against live docs — this driver currently has no
     * distinct request shape for "authorise only" vs. "charge and capture
     * immediately" (see {@see PaymobClient::captureTransaction()}'s
     * docblock for the same caveat on the capture side). This method
     * currently behaves identically to {@see self::charge()}. If Paymob's
     * real behaviour requires a distinct flag on the pay/payment_keys
     * request to produce an authorise-only hold, this needs revisiting
     * before `authorize()`/`capture()` can be trusted to actually reserve
     * funds without capturing them.
     */
    public function authorize(PaymentRequest $request): PaymentResponse
    {
        return $this->executePayment($request, $request->amount->amount, $request->currency->value, 'authorize');
    }

    /**
     * Shared orchestration for charge() and authorize() — both currently
     * identical (see {@see self::authorize()}'s docblock for the caveat).
     */
    private function executePayment(PaymentRequest $request, int $amountCents, string $currency, string $operation): PaymentResponse
    {
        $this->validateIdempotencyKey($request->idempotencyKey);

        if (! $request->hasToken()) {
            throw new InvalidArgumentException(
                "PaymentRequest::\$token is required for Paymob's {$operation}(): this driver never sends raw " .
                'card data (see PaymobDriver\'s class docblock). Pass a Paymob-issued reusable card token — ' .
                'obtain one via createPaymentLink() (Paymob\'s hosted iframe) or a prior saveCard() call.',
            );
        }

        $this->logInfo('Initiating ' . $operation, $this->buildLogContext($operation, [
            'amount'   => $amountCents,
            'currency' => $currency,
        ]));
        $this->dispatchEvent(new PaymentInitiated($request));

        $step = 'authenticate';

        try {
            $authToken = $this->withRetry(fn () => $this->client->authenticate());

            $step  = 'createOrder';
            $order = $this->withRetry(fn () => $this->client->createOrder($authToken, $amountCents, $currency, $request->idempotencyKey));
            $orderId = (int) ($order['id'] ?? 0);

            $step         = 'requestPaymentKey';
            $billingData  = $this->client->billingDataFrom($request->customer->name, $request->customer->email, $request->customer->phone);
            $paymentKeyResponse = $this->withRetry(fn () => $this->client->requestPaymentKey($authToken, $orderId, $amountCents, $currency, $billingData));
            $paymentKey   = (string) ($paymentKeyResponse['token'] ?? '');

            $step     = 'payWithToken';
            $raw      = $this->withRetry(fn () => $this->client->payWithToken($paymentKey, (string) $request->token?->toString()));
            $response = $this->mapper->toPaymentResponse($raw);

            if ($response->isSuccessful()) {
                $this->dispatchEvent(new PaymentSucceeded($request, $response));
                $this->logInfo(ucfirst($operation) . ' succeeded', $this->buildLogContext($operation, [
                    'transaction_id' => $response->getTransactionId()->toString(),
                    'status'         => $response->getStatus()->value,
                ]));
            } else {
                $this->dispatchEvent(new PaymentFailed($request, $response, null));
                $this->logWarning(ucfirst($operation) . ' did not succeed', $this->buildLogContext($operation, [
                    'status'  => $response->getStatus()->value,
                    'message' => $response->getMessage(),
                ]));
            }

            return $response;
        } catch (Throwable $e) {
            $this->dispatchEvent(new PaymentFailed($request, null, $e));
            $this->logError(ucfirst($operation) . " failed at step [{$step}]", $this->buildLogContext($operation, [
                'step'  => $step,
                'error' => $e->getMessage(),
            ]));

            throw $this->exceptionMapper->map($e, ['operation' => $operation, 'step' => $step]);
        }
    }

    /**
     * {@inheritDoc}
     *
     * No soft-failure branch — voiding does not touch a card network.
     */
    public function void(VoidRequest $request): VoidResponse
    {
        $this->validateIdempotencyKey($request->idempotencyKey);
        $this->logInfo('Voiding payment', $this->buildLogContext('void', [
            'transaction_id' => $request->transactionId->toString(),
            'reason'         => $request->reason,
        ]));

        $step = 'authenticate';

        try {
            $authToken = $this->withRetry(fn () => $this->client->authenticate());

            $step = 'voidTransaction';
            $raw  = $this->withRetry(fn () => $this->client->voidTransaction($authToken, $request->transactionId->toString()));
            $response = $this->mapper->toVoidResponse($raw);

            $this->dispatchEvent(new PaymentVoided($request, $response));
            $this->logInfo('Void succeeded', $this->buildLogContext('void', [
                'transaction_id' => $response->getTransactionId()->toString(),
                'status'         => $response->getStatus()->value,
            ]));

            return $response;
        } catch (Throwable $e) {
            $this->logError("Void failed at step [{$step}]", $this->buildLogContext('void', ['step' => $step, 'error' => $e->getMessage()]));

            throw $this->exceptionMapper->map($e, ['operation' => 'void', 'step' => $step]);
        }
    }

    /**
     * {@inheritDoc}
     *
     * See {@see self::authorize()}'s docblock — the authorise/capture
     * distinction is unverified for this driver.
     */
    public function capture(CaptureRequest $request): CaptureResponse
    {
        $this->validateIdempotencyKey($request->idempotencyKey);
        $this->logInfo('Capturing payment', $this->buildLogContext('capture', [
            'transaction_id' => $request->transactionId->toString(),
            'amount'         => $request->amount->amount,
        ]));

        $step = 'authenticate';

        try {
            $authToken = $this->withRetry(fn () => $this->client->authenticate());

            $step = 'captureTransaction';
            $raw  = $this->withRetry(fn () => $this->client->captureTransaction($authToken, $request->transactionId->toString(), $request->amount->amount));
            $response = $this->mapper->toCaptureResponse($raw);

            $this->dispatchEvent(new PaymentCaptured($request, $response));
            $this->logInfo('Capture succeeded', $this->buildLogContext('capture', [
                'capture_id' => $response->getCaptureId(),
                'status'     => $response->getStatus()->value,
            ]));

            return $response;
        } catch (Throwable $e) {
            $this->logError("Capture failed at step [{$step}]", $this->buildLogContext('capture', ['step' => $step, 'error' => $e->getMessage()]));

            throw $this->exceptionMapper->map($e, ['operation' => 'capture', 'step' => $step]);
        }
    }

    /** {@inheritDoc} */
    public function refund(RefundRequest $request): RefundResponse
    {
        return $this->executeRefund($request, 'refund');
    }

    /**
     * {@inheritDoc}
     *
     * Identical body to {@see self::refund()} — see
     * {@see PaymobClient::refundTransaction()}'s docblock: a single Paymob
     * endpoint handles both, distinguished only by the amount, same
     * precedent as Stripe's refund()/partialRefund() pair.
     */
    public function partialRefund(RefundRequest $request): RefundResponse
    {
        return $this->executeRefund($request, 'partialRefund');
    }

    private function executeRefund(RefundRequest $request, string $operation): RefundResponse
    {
        $this->validateIdempotencyKey($request->idempotencyKey);
        $this->logInfo('Refunding payment', $this->buildLogContext($operation, [
            'transaction_id' => $request->transactionId->toString(),
            'amount'         => $request->amount->amount,
            'reason'         => $request->reason,
        ]));

        $step = 'authenticate';

        try {
            $authToken = $this->withRetry(fn () => $this->client->authenticate());

            $step = 'refundTransaction';
            $raw  = $this->withRetry(fn () => $this->client->refundTransaction($authToken, $request->transactionId->toString(), $request->amount->amount));
            $response = $this->mapper->toRefundResponse($raw);

            $this->dispatchEvent(new PaymentRefunded($request, $response));
            $this->logInfo('Refund processed', $this->buildLogContext($operation, [
                'refund_id' => $response->getRefundId(),
                'status'    => $response->getStatus()->value,
            ]));

            return $response;
        } catch (Throwable $e) {
            $this->logError("Refund failed at step [{$step}]", $this->buildLogContext($operation, ['step' => $step, 'error' => $e->getMessage()]));

            throw $this->exceptionMapper->map($e, ['operation' => $operation, 'step' => $step]);
        }
    }

    /**
     * {@inheritDoc}
     *
     * Read-only — no idempotency check (same reasoning as
     * {@see \Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeDriver::verify()}:
     * `TransactionLookupRequest` carries no idempotency key). No event —
     * none exists for verify() in this framework.
     */
    public function verify(TransactionLookupRequest $request): VerificationResponse
    {
        $this->logInfo('Verifying transaction', $this->buildLogContext('verify', [
            'transaction_id' => $request->transactionId->toString(),
        ]));

        $step = 'authenticate';

        try {
            $authToken = $this->withRetry(fn () => $this->client->authenticate());

            $step = 'retrieveTransaction';
            $raw  = $this->withRetry(fn () => $this->client->retrieveTransaction($authToken, $request->transactionId->toString()));
            $response = $this->mapper->toVerificationResponse($raw);

            $this->logInfo('Verification completed', $this->buildLogContext('verify', [
                'verified' => $response->isVerified(),
            ]));

            return $response;
        } catch (Throwable $e) {
            $this->logError("Verification failed at step [{$step}]", $this->buildLogContext('verify', ['step' => $step, 'error' => $e->getMessage()]));

            throw $this->exceptionMapper->map($e, ['operation' => 'verify', 'step' => $step]);
        }
    }

    /**
     * {@inheritDoc}
     *
     * Same read-only shape as {@see self::verify()}, but dispatches
     * {@see TransactionLookuped} on success.
     */
    public function lookup(TransactionLookupRequest $request): StatusResponse
    {
        $this->logInfo('Looking up transaction', $this->buildLogContext('lookup', [
            'transaction_id' => $request->transactionId->toString(),
        ]));

        $step = 'authenticate';

        try {
            $authToken = $this->withRetry(fn () => $this->client->authenticate());

            $step = 'retrieveTransaction';
            $raw  = $this->withRetry(fn () => $this->client->retrieveTransaction($authToken, $request->transactionId->toString()));
            $response = $this->mapper->toStatusResponse($raw);

            $this->dispatchEvent(new TransactionLookuped($request, $response));
            $this->logInfo('Lookup completed', $this->buildLogContext('lookup', [
                'status' => $response->getStatus()->value,
            ]));

            return $response;
        } catch (Throwable $e) {
            $this->logError("Lookup failed at step [{$step}]", $this->buildLogContext('lookup', ['step' => $step, 'error' => $e->getMessage()]));

            throw $this->exceptionMapper->map($e, ['operation' => 'lookup', 'step' => $step]);
        }
    }

    /**
     * {@inheritDoc}
     *
     * See this class's docblock, "Known gap: SaveCardRequest carries no
     * email/name" — `billing_data.email` falls back to
     * `$request->metadata['email']` or a placeholder when absent, which
     * real Paymob validation may reject.
     */
    public function saveCard(SaveCardRequest $request): PaymentResponse
    {
        $this->validateIdempotencyKey($request->idempotencyKey);
        $this->logInfo('Saving card', $this->buildLogContext('saveCard', [
            'customer_id' => $request->customerId->toString(),
        ]));

        $name  = (string) ($request->metadata['name'] ?? 'NA Customer');
        $email = (string) ($request->metadata['email'] ?? ($request->customerId->toString() . '@example.invalid'));
        $phone = isset($request->metadata['phone']) ? (string) $request->metadata['phone'] : null;

        // No amount is intrinsic to "saving a card" — Paymob's payment_keys
        // endpoint nonetheless requires one; a minimal, non-zero placeholder
        // amount is used purely to complete the tokenisation sequence.
        // UNVERIFIED: whether Paymob supports a genuine $0 verification-only
        // charge here, the way Stripe's SetupIntent does, is not confirmed.
        $amountCents = 100;
        $currency    = 'EGP';

        $step = 'authenticate';

        try {
            $authToken = $this->withRetry(fn () => $this->client->authenticate());

            $step  = 'createOrder';
            $order = $this->withRetry(fn () => $this->client->createOrder($authToken, $amountCents, $currency, $request->idempotencyKey));
            $orderId = (int) ($order['id'] ?? 0);

            $step               = 'requestPaymentKey';
            $billingData        = $this->client->billingDataFrom($name, $email, $phone);
            $paymentKeyResponse = $this->withRetry(fn () => $this->client->requestPaymentKey($authToken, $orderId, $amountCents, $currency, $billingData));
            $paymentKey         = (string) ($paymentKeyResponse['token'] ?? '');

            $step     = 'payWithToken';
            $raw      = $this->withRetry(fn () => $this->client->payWithToken($paymentKey, $request->token->toString()));
            $response = $this->mapper->toPaymentResponse($raw);

            if ($response->isSuccessful()) {
                $this->dispatchEvent(new CardSaved($request, $response));
                $this->logInfo('Card saved', $this->buildLogContext('saveCard', [
                    'transaction_id' => $response->getTransactionId()->toString(),
                ]));
            } else {
                $this->logWarning('Card save did not succeed', $this->buildLogContext('saveCard', [
                    'status'  => $response->getStatus()->value,
                    'message' => $response->getMessage(),
                ]));
            }

            return $response;
        } catch (Throwable $e) {
            $this->logError("Save card failed at step [{$step}]", $this->buildLogContext('saveCard', ['step' => $step, 'error' => $e->getMessage()]));

            throw $this->exceptionMapper->map($e, ['operation' => 'saveCard', 'step' => $step]);
        }
    }

    /**
     * {@inheritDoc}
     *
     * Unlike Stripe's `chargeToken()`, no `providerCustomerReference` guard
     * is needed — Paymob has no separate "Customer" object scoping a saved
     * card the way Stripe does; the card token itself is the only reference
     * required. `$request->customer` (real `CustomerData`, unlike
     * `saveCard()`'s DTO) supplies genuine billing_data — no placeholder
     * fallback needed here.
     */
    public function chargeToken(TokenChargeRequest $request): PaymentResponse
    {
        $this->validateIdempotencyKey($request->idempotencyKey);
        $this->logInfo('Charging saved token', $this->buildLogContext('chargeToken', [
            'amount'   => $request->amount->amount,
            'currency' => $request->currency->value,
        ]));

        $step = 'authenticate';

        try {
            $authToken = $this->withRetry(fn () => $this->client->authenticate());

            $step  = 'createOrder';
            $order = $this->withRetry(fn () => $this->client->createOrder($authToken, $request->amount->amount, $request->currency->value, $request->idempotencyKey));
            $orderId = (int) ($order['id'] ?? 0);

            $step               = 'requestPaymentKey';
            $billingData        = $this->client->billingDataFrom($request->customer->name, $request->customer->email, $request->customer->phone);
            $paymentKeyResponse = $this->withRetry(fn () => $this->client->requestPaymentKey($authToken, $orderId, $request->amount->amount, $request->currency->value, $billingData));
            $paymentKey         = (string) ($paymentKeyResponse['token'] ?? '');

            $step     = 'payWithToken';
            $raw      = $this->withRetry(fn () => $this->client->payWithToken($paymentKey, $request->token->toString()));
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
        } catch (Throwable $e) {
            $this->logError("Token charge failed at step [{$step}]", $this->buildLogContext('chargeToken', ['step' => $step, 'error' => $e->getMessage()]));

            throw $this->exceptionMapper->map($e, ['operation' => 'chargeToken', 'step' => $step]);
        }
    }

    /**
     * {@inheritDoc}
     *
     * Backed by Paymob's hosted iframe (order → payment key → iframe URL),
     * the closest Paymob equivalent to Stripe's Checkout Session. Unlike
     * Stripe's driver, `$request->returnUrl`/`$request->cancelUrl` are
     * accepted by the DTO but NOT forwarded to Paymob — UNVERIFIED, but per
     * general knowledge, Paymob's hosted iframe redirects to a URL
     * configured per-integration in the Paymob dashboard, not supplied
     * per-request the way Stripe's `success_url`/`cancel_url` are. No
     * `returnUrl` guard is applied here for that reason (it would be
     * enforcing a requirement Paymob doesn't actually have).
     */
    public function createPaymentLink(PaymentLinkRequest $request): PaymentLinkResponse
    {
        $this->validateIdempotencyKey($request->idempotencyKey);
        $this->logInfo('Creating payment link', $this->buildLogContext('createPaymentLink', [
            'amount'   => $request->amount->amount,
            'currency' => $request->currency->value,
        ]));

        $step = 'init';

        try {
            $ref = $this->createCheckoutReference($request, $step);

            $url = $this->client->isKsaMode()
                ? $this->client->buildKsaCheckoutUrl($ref['secret'])
                : $this->client->buildIframeUrl($ref['secret']);

            $response = $this->mapper->toPaymentLinkResponse($url, $ref['raw']);

            $this->dispatchEvent(new PaymentLinkCreated($request, $response));
            $this->logInfo('Payment link created', $this->buildLogContext('createPaymentLink', [
                'link_id' => $response->getLinkId(),
            ]));

            return $response;
        } catch (Throwable $e) {
            $this->logError("Payment link creation failed at step [{$step}]", $this->buildLogContext('createPaymentLink', ['step' => $step, 'error' => $e->getMessage()]));

            throw $this->exceptionMapper->map($e, ['operation' => 'createPaymentLink', 'step' => $step]);
        }
    }

    /**
     * {@inheritDoc}
     *
     * Native-SDK counterpart to {@see self::createPaymentLink()} — both need
     * the exact same Paymob order/intention created (see
     * {@see self::createCheckoutReference()}), and differ only in what they
     * do with the resulting secret: `createPaymentLink()` turns it into a
     * hosted redirect URL; this method hands it back raw so a client-side
     * SDK (Paymob's unified checkout JS/mobile SDK) can confirm the charge
     * itself. No raw card data passes through this method or this package's
     * server at any point — matching this class's own docblock's "one
     * deliberate, load-bearing design decision".
     *
     * KSA mode returns the Intention API's `client_secret` (`sau_csk_...`)
     * directly — this is the exact value Paymob's unified-checkout SDK
     * expects. Egypt/Accept mode returns the `payment_key` token from
     * {@see PaymobClient::requestPaymentKey()} — the same token
     * {@see self::createPaymentLink()} already turns into an iframe URL,
     * usable by Paymob's iframe/mobile SDKs to confirm client-side.
     *
     * No event is dispatched here — same reasoning as
     * {@see \Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeDriver::createSdkIntent()}:
     * creating an intent never itself charges anything; the actual outcome
     * is confirmed later via `CheckoutService::confirm()`'s authoritative
     * `lookup()` call.
     */
    public function createSdkIntent(PaymentLinkRequest $request): SdkCheckoutResponse
    {
        $this->validateIdempotencyKey($request->idempotencyKey);
        $this->logInfo('Creating SDK checkout intent', $this->buildLogContext('createSdkIntent', [
            'amount'   => $request->amount->amount,
            'currency' => $request->currency->value,
        ]));

        $step = 'init';

        try {
            $ref = $this->createCheckoutReference($request, $step);

            $response = new SdkCheckoutResponse(
                successful: $ref['secret'] !== '',
                transactionReference: $ref['reference'],
                clientSecret: $ref['secret'],
                publishableKey: $this->client->publicKey(),
                message: $ref['secret'] !== '' ? 'SDK checkout intent created.' : 'Paymob did not return a client-usable secret.',
                rawResponse: $ref['raw'],
            );

            $this->logInfo('SDK checkout intent created', $this->buildLogContext('createSdkIntent', [
                'transaction_reference' => $response->getTransactionReference(),
            ]));

            return $response;
        } catch (Throwable $e) {
            $this->logError("SDK checkout intent creation failed at step [{$step}]", $this->buildLogContext('createSdkIntent', ['step' => $step, 'error' => $e->getMessage()]));

            throw $this->exceptionMapper->map($e, ['operation' => 'createSdkIntent', 'step' => $step]);
        }
    }

    /**
     * Shared order/intention creation for {@see self::createPaymentLink()}
     * and {@see self::createSdkIntent()} — both need the identical Paymob
     * order (Egypt) or Intention (KSA) created; only the caller decides what
     * to do with the resulting secret. Extracted specifically to avoid the
     * two methods drifting apart on the underlying Paymob call sequence.
     *
     * `$step` is passed by reference so the caller's own try/catch block
     * (which logs/maps exceptions using `$step`) reflects exactly which
     * Paymob call failed, same as every other multi-call method in this
     * driver.
     *
     * @param string $step Caller's step-tracking variable, updated in place.
     *
     * @return array{secret: string, reference: string, raw: array<string, mixed>}
     */
    private function createCheckoutReference(PaymentLinkRequest $request, string &$step): array
    {
        $name  = $request->customer?->name ?? 'NA Customer';
        $email = $request->customer?->email ?? 'guest@example.invalid';
        $phone = $request->customer?->phone;

        $billingData = $this->client->billingDataFrom($name, $email, $phone);

        if ($this->client->isKsaMode()) {
            // KSA: single Intention API call → client_secret
            $step      = 'createIntention';
            $intention = $this->withRetry(fn () => $this->client->createIntention(
                $request->amount->amount,
                $request->currency->value,
                $billingData,
                $request->idempotencyKey,
            ));

            return [
                'secret'    => (string) ($intention['client_secret'] ?? ''),
                'reference' => (string) ($intention['intention_order_id'] ?? ($intention['id'] ?? '')),
                'raw'       => $intention,
            ];
        }

        // Egypt: authenticate → createOrder → requestPaymentKey
        $step      = 'authenticate';
        $authToken = $this->withRetry(fn () => $this->client->authenticate());

        $step    = 'createOrder';
        $order   = $this->withRetry(fn () => $this->client->createOrder($authToken, $request->amount->amount, $request->currency->value, $request->idempotencyKey));
        $orderId = (int) ($order['id'] ?? 0);

        $step               = 'requestPaymentKey';
        $paymentKeyResponse = $this->withRetry(fn () => $this->client->requestPaymentKey($authToken, $orderId, $request->amount->amount, $request->currency->value, $billingData));
        $paymentKey         = (string) ($paymentKeyResponse['token'] ?? '');

        return [
            'secret'    => $paymentKey,
            'reference' => (string) $orderId,
            'raw'       => $order,
        ];
    }

    /**
     * {@inheritDoc}
     *
     * Paymob has no subscription/recurring-billing API resembling Stripe's
     * Subscription object (see this class's docblock) — this driver does
     * not fake one. `supports('subscription')` correctly reports `false`
     * for external capability checks, but the throw here is explicit
     * rather than routed through `assertSupports()`: that helper builds its
     * message from `AbstractDriver::$driverName`, which defaults to the
     * generic string `'unknown'` unless a `driver_name` config key happens
     * to be set — throwing directly guarantees a correctly-named,
     * correctly-labelled exception regardless of config, per
     * {@see UnsupportedOperationException}'s own documented contract
     * ("drivers that do not implement a particular operation MUST throw
     * this exception rather than returning null or empty responses").
     */
    public function createSubscription(SubscriptionRequest $request): SubscriptionResponse
    {
        throw UnsupportedOperationException::forOperation('createSubscription', 'paymob');
    }

    /** {@inheritDoc} */
    public function cancelSubscription(CancelSubscriptionRequest $request): SubscriptionResponse
    {
        throw UnsupportedOperationException::forOperation('cancelSubscription', 'paymob');
    }

    /** {@inheritDoc} */
    public function processWebhook(WebhookRequest $request): WebhookResponse
    {
        throw new \LogicException('PaymobDriver::processWebhook() not yet implemented.');
    }

    /** {@inheritDoc} */
    public function verifyWebhookSignature(WebhookRequest $request): bool
    {
        throw new \LogicException('PaymobDriver::verifyWebhookSignature() not yet implemented.');
    }
}
