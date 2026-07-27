<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Drivers\MyFatoorah;

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
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentCaptured;
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentFailed;
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentInitiated;
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentLinkCreated;
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentRefunded;
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentSucceeded;
use Mifatoyeh\LaravelPaymentFramework\Events\PaymentVoided;
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
 * MyFatoorah implementation of {@see PaymentDriverContract}.
 *
 * Same collaborator pattern as Stripe/Paymob: Client (HTTP), Mapper (DTO),
 * WebhookVerifier (HMAC), ExceptionMapper — constructed here with the same
 * `$config` this driver receives from {@see \Mifatoyeh\LaravelPaymentFramework\Managers\PaymentManager}.
 *
 * Checkout confirmation matches Paymob's Stripe-aligned split:
 * - webview → package callback (`CallBackUrl` = `$request->returnUrl`)
 * - sdk → package webhook (`WebhookUrl` = notification URL)
 *
 * @see https://docs.myfatoorah.com/
 */
final class MyFatoorahDriver extends AbstractDriver implements PaymentDriverContract, SupportsSdkCheckout, SupportsCapabilities
{
    private readonly MyFatoorahClient $client;

    private readonly MyFatoorahMapper $mapper;

    private readonly MyFatoorahWebhookVerifier $webhookVerifier;

    private readonly MyFatoorahExceptionMapper $exceptionMapper;

    /**
     * @param array<string, mixed> $config payment.drivers.myfatoorah
     */
    public function __construct(
        PaymentLoggerContract $logger,
        Dispatcher $events,
        RetryServiceContract $retry,
        array $config = [],
    ) {
        parent::__construct($logger, $events, $retry, $config);

        $this->client          = new MyFatoorahClient($config);
        $this->mapper          = new MyFatoorahMapper();
        $this->webhookVerifier = new MyFatoorahWebhookVerifier($config);
        $this->exceptionMapper = new MyFatoorahExceptionMapper();
    }

    public function supports(string $capability): bool
    {
        return ! in_array($capability, ['subscription', 'save_card', 'charge_token'], true);
    }

    public function charge(PaymentRequest $request): PaymentResponse
    {
        $this->validateIdempotencyKey($request->idempotencyKey);
        $this->logInfo('Initiating charge', $this->buildLogContext('charge', [
            'amount'   => $request->amount->amount,
            'currency' => $request->currency->value,
        ]));
        $this->dispatchEvent(new PaymentInitiated($request));

        try {
            $raw      = $this->withRetry(fn () => $this->client->executePaymentFromRequest($request, autoCapture: true));
            $response = $this->mapper->toPaymentResponseFromExecute($raw, $request->amount);

            if ($response->isSuccessful()) {
                $this->dispatchEvent(new PaymentSucceeded($request, $response));
            } else {
                $this->dispatchEvent(new PaymentFailed($request, $response, null));
            }

            return $response;
        } catch (Throwable $e) {
            $this->dispatchEvent(new PaymentFailed($request, null, $e));
            $this->logError('Charge failed', $this->buildLogContext('charge', ['error' => $e->getMessage()]));

            throw $this->exceptionMapper->map($e, ['operation' => 'charge']);
        }
    }

    public function authorize(PaymentRequest $request): PaymentResponse
    {
        $this->validateIdempotencyKey($request->idempotencyKey);
        $this->logInfo('Initiating authorization', $this->buildLogContext('authorize', [
            'amount'   => $request->amount->amount,
            'currency' => $request->currency->value,
        ]));
        $this->dispatchEvent(new PaymentInitiated($request));

        try {
            $raw      = $this->withRetry(fn () => $this->client->executePaymentFromRequest($request, autoCapture: false));
            $response = $this->mapper->toPaymentResponseFromExecute($raw, $request->amount);

            if ($response->isSuccessful()) {
                $this->dispatchEvent(new PaymentSucceeded($request, $response));
            } else {
                $this->dispatchEvent(new PaymentFailed($request, $response, null));
            }

            return $response;
        } catch (Throwable $e) {
            $this->dispatchEvent(new PaymentFailed($request, null, $e));
            $this->logError('Authorization failed', $this->buildLogContext('authorize', ['error' => $e->getMessage()]));

            throw $this->exceptionMapper->map($e, ['operation' => 'authorize']);
        }
    }

    public function capture(CaptureRequest $request): CaptureResponse
    {
        $this->validateIdempotencyKey($request->idempotencyKey);
        $this->logInfo('Capturing payment', $this->buildLogContext('capture', [
            'transaction_id' => $request->transactionId->toString(),
        ]));

        try {
            $raw      = $this->withRetry(fn () => $this->client->capturePayment($request));
            $response = $this->mapper->toCaptureResponse($raw, $request->amount);
            $this->dispatchEvent(new PaymentCaptured($request, $response));

            return $response;
        } catch (Throwable $e) {
            $this->logError('Capture failed', $this->buildLogContext('capture', ['error' => $e->getMessage()]));

            throw $this->exceptionMapper->map($e, ['operation' => 'capture']);
        }
    }

    public function void(VoidRequest $request): VoidResponse
    {
        $this->validateIdempotencyKey($request->idempotencyKey);
        $this->logInfo('Voiding payment', $this->buildLogContext('void', [
            'transaction_id' => $request->transactionId->toString(),
        ]));

        try {
            $raw      = $this->withRetry(fn () => $this->client->releasePayment($request));
            $response = $this->mapper->toVoidResponse($raw, $request->transactionId->toString());
            $this->dispatchEvent(new PaymentVoided($request, $response));

            return $response;
        } catch (Throwable $e) {
            $this->logError('Void failed', $this->buildLogContext('void', ['error' => $e->getMessage()]));

            throw $this->exceptionMapper->map($e, ['operation' => 'void']);
        }
    }

    public function refund(RefundRequest $request): RefundResponse
    {
        return $this->doRefund($request, 'refund');
    }

    public function partialRefund(RefundRequest $request): RefundResponse
    {
        return $this->doRefund($request, 'partialRefund');
    }

    public function verify(TransactionLookupRequest $request): VerificationResponse
    {
        $this->logInfo('Verifying transaction', $this->buildLogContext('verify', [
            'transaction_id' => $request->transactionId->toString(),
        ]));

        try {
            $raw = $this->withRetry(fn () => $this->client->getPaymentStatus($request));

            return $this->mapper->toVerificationResponse($raw);
        } catch (Throwable $e) {
            $this->logError('Verification failed', $this->buildLogContext('verify', ['error' => $e->getMessage()]));

            throw $this->exceptionMapper->map($e, ['operation' => 'verify']);
        }
    }

    public function lookup(TransactionLookupRequest $request): StatusResponse
    {
        $this->logInfo('Looking up transaction', $this->buildLogContext('lookup', [
            'transaction_id' => $request->transactionId->toString(),
        ]));

        try {
            $raw      = $this->withRetry(fn () => $this->client->getPaymentStatus($request));
            $response = $this->mapper->toStatusResponse($raw);
            $this->dispatchEvent(new TransactionLookuped($request, $response));

            return $response;
        } catch (Throwable $e) {
            $this->logError('Lookup failed', $this->buildLogContext('lookup', ['error' => $e->getMessage()]));

            throw $this->exceptionMapper->map($e, ['operation' => 'lookup']);
        }
    }

    public function createPaymentLink(PaymentLinkRequest $request): PaymentLinkResponse
    {
        $this->validateIdempotencyKey($request->idempotencyKey);

        if ($request->returnUrl === null || trim($request->returnUrl) === '') {
            throw new InvalidArgumentException(
                'PaymentLinkRequest::$returnUrl is required for MyFatoorah SendPayment: ' .
                'CallBackUrl must point at the package checkout callback (or your success URL).',
            );
        }

        $this->logInfo('Creating payment link', $this->buildLogContext('createPaymentLink', [
            'amount'   => $request->amount->amount,
            'currency' => $request->currency->value,
        ]));

        try {
            // Webview: browser callback only — no WebhookUrl (sdk owns webhook).
            $raw      = $this->withRetry(fn () => $this->client->sendPayment(
                $request,
                callBackUrl: $request->returnUrl,
                errorUrl: $request->cancelUrl ?? $request->returnUrl,
                webhookUrl: null,
            ));
            $response = $this->mapper->toPaymentLinkResponse($raw);
            $this->dispatchEvent(new PaymentLinkCreated($request, $response));

            return $response;
        } catch (Throwable $e) {
            $this->logError('Payment link creation failed', $this->buildLogContext('createPaymentLink', [
                'error' => $e->getMessage(),
            ]));

            throw $this->exceptionMapper->map($e, ['operation' => 'createPaymentLink']);
        }
    }

    public function createSdkIntent(PaymentLinkRequest $request): SdkCheckoutResponse
    {
        $this->validateIdempotencyKey($request->idempotencyKey);
        $this->logInfo('Creating SDK checkout intent', $this->buildLogContext('createSdkIntent', [
            'amount'   => $request->amount->amount,
            'currency' => $request->currency->value,
        ]));

        try {
            $raw = $this->withRetry(fn () => $this->client->executePayment(
                amount: $request->amount,
                currencyIso: $request->currency->value,
                customerReference: $request->idempotencyKey,
                paymentMethodId: $this->client->paymentMethodId(),
                customerName: $request->customer?->name,
                customerEmail: $request->customer?->email,
                customerMobile: $request->customer?->phone,
                callBackUrl: null,
                errorUrl: null,
                webhookUrl: $this->webhookNotificationUrl(),
                extra: [
                    'ProcessingDetails' => ['AutoCapture' => true],
                ],
            ));

            return $this->mapper->toSdkCheckoutResponse($raw);
        } catch (Throwable $e) {
            $this->logError('SDK checkout intent creation failed', $this->buildLogContext('createSdkIntent', [
                'error' => $e->getMessage(),
            ]));

            throw $this->exceptionMapper->map($e, ['operation' => 'createSdkIntent']);
        }
    }

    public function saveCard(SaveCardRequest $request): PaymentResponse
    {
        throw UnsupportedOperationException::forOperation('saveCard', 'myfatoorah');
    }

    public function chargeToken(TokenChargeRequest $request): PaymentResponse
    {
        throw UnsupportedOperationException::forOperation('chargeToken', 'myfatoorah');
    }

    public function createSubscription(SubscriptionRequest $request): SubscriptionResponse
    {
        throw UnsupportedOperationException::forOperation('createSubscription', 'myfatoorah');
    }

    public function cancelSubscription(CancelSubscriptionRequest $request): SubscriptionResponse
    {
        throw UnsupportedOperationException::forOperation('cancelSubscription', 'myfatoorah');
    }

    public function processWebhook(WebhookRequest $request): WebhookResponse
    {
        $this->logInfo('Processing webhook', $this->buildLogContext('processWebhook'));

        try {
            $raw = json_decode($request->rawBody, true) ?? [];
            $raw = is_array($raw) ? $raw : [];

            $response = $this->mapper->toWebhookResponse($raw);
            $this->logInfo('Webhook processed', $this->buildLogContext('processWebhook', [
                'event_type' => $response->getEventType()->value,
            ]));

            return $response;
        } catch (Throwable $e) {
            $this->logError('Webhook processing failed', $this->buildLogContext('processWebhook', [
                'error' => $e->getMessage(),
            ]));

            throw $this->exceptionMapper->map($e, ['operation' => 'processWebhook']);
        }
    }

    public function verifyWebhookSignature(WebhookRequest $request): bool
    {
        $signature = $request->header('myfatoorah-signature');

        if ($signature === '') {
            $signature = $request->signature->toString();
        }

        $raw = json_decode($request->rawBody, true) ?? [];
        $raw = is_array($raw) ? $raw : [];

        return $this->webhookVerifier->verify($raw, $signature);
    }

    private function doRefund(RefundRequest $request, string $operation): RefundResponse
    {
        $this->validateIdempotencyKey($request->idempotencyKey);
        $this->logInfo('Refunding payment', $this->buildLogContext($operation, [
            'transaction_id' => $request->transactionId->toString(),
            'amount'         => $request->amount->amount,
        ]));

        try {
            $raw      = $this->withRetry(fn () => $this->client->makeRefund($request));
            $response = $this->mapper->toRefundResponse($raw, $request->amount);
            $this->dispatchEvent(new PaymentRefunded($request, $response));

            return $response;
        } catch (Throwable $e) {
            $this->logError('Refund failed', $this->buildLogContext($operation, ['error' => $e->getMessage()]));

            throw $this->exceptionMapper->map($e, ['operation' => $operation]);
        }
    }

    private function webhookNotificationUrl(): ?string
    {
        if (! function_exists('route')) {
            return null;
        }

        try {
            return route('payment.webhook', ['driver' => 'myfatoorah'], absolute: true);
        } catch (Throwable) {
            return null;
        }
    }
}
