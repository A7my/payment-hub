<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Drivers\MyFatoorah;

use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\Enums\PaymentStatus;
use Mifatoyeh\LaravelPaymentFramework\Enums\WebhookEventType;
use Mifatoyeh\LaravelPaymentFramework\Responses\CaptureResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentLinkResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\RefundResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\SdkCheckoutResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\StatusResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\VerificationResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\VoidResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\WebhookResponse;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;

/**
 * Converts raw MyFatoorah payloads into framework Response objects.
 */
final class MyFatoorahMapper
{
    /**
     * @param array<string, mixed> $raw SendPayment / ExecutePayment Data payload.
     */
    public function toPaymentLinkResponse(array $raw): PaymentLinkResponse
    {
        $url = (string) ($raw['InvoiceURL'] ?? $raw['PaymentURL'] ?? '');
        $id  = (string) ($raw['InvoiceId'] ?? '');

        return new PaymentLinkResponse(
            successful: $url !== '' && $id !== '',
            paymentUrl: $url,
            linkId: $id,
            expiresAt: null,
            message: $url !== '' ? 'Payment link created.' : 'MyFatoorah did not return a payment URL.',
            rawResponse: $raw,
        );
    }

    /**
     * @param array<string, mixed> $raw ExecutePayment Data payload.
     */
    public function toSdkCheckoutResponse(array $raw): SdkCheckoutResponse
    {
        $invoiceId  = (string) ($raw['InvoiceId'] ?? '');
        $paymentUrl = (string) ($raw['PaymentURL'] ?? '');

        return new SdkCheckoutResponse(
            successful: $invoiceId !== '' && $paymentUrl !== '',
            transactionReference: $invoiceId,
            // MyFatoorah has no Stripe-like client_secret — the PaymentURL is
            // what a native/webview client opens to complete payment.
            clientSecret: $paymentUrl,
            publishableKey: null,
            message: $paymentUrl !== '' ? 'SDK checkout intent created.' : 'MyFatoorah did not return a PaymentURL.',
            rawResponse: $raw,
        );
    }

    /**
     * Map ExecutePayment (charge/authorize) — typically returns a PaymentURL
     * before the customer pays → RequiresAction.
     *
     * @param array<string, mixed> $raw
     */
    public function toPaymentResponseFromExecute(array $raw, Money $requestedAmount): PaymentResponse
    {
        $invoiceId  = (string) ($raw['InvoiceId'] ?? '');
        $paymentUrl = (string) ($raw['PaymentURL'] ?? '');
        $status     = $paymentUrl !== '' ? PaymentStatus::RequiresAction : PaymentStatus::Pending;

        return new PaymentResponse(
            successful: $status->isSuccessful(),
            transactionId: TransactionId::fromString($invoiceId !== '' ? $invoiceId : 'unknown'),
            status: $status,
            providerReference: (string) ($raw['CustomerReference'] ?? ''),
            amount: $requestedAmount,
            rawResponse: $raw,
            message: $paymentUrl !== ''
                ? 'Additional customer action is required to complete this payment.'
                : 'Payment initiated.',
        );
    }

    /**
     * @param array<string, mixed> $raw GetPaymentStatus Data payload.
     */
    public function toPaymentResponseFromStatus(array $raw): PaymentResponse
    {
        $status = $this->mapInvoiceStatus($raw);
        $amount = $this->resolveAmount($raw);

        return new PaymentResponse(
            successful: $status->isSuccessful(),
            transactionId: TransactionId::fromString((string) ($raw['InvoiceId'] ?? '')),
            status: $status,
            providerReference: (string) ($raw['InvoiceReference'] ?? $raw['CustomerReference'] ?? ''),
            amount: $amount,
            rawResponse: $raw,
            message: $this->resolveStatusMessage($status, $raw),
        );
    }

    /**
     * @param array<string, mixed> $raw GetPaymentStatus Data payload.
     */
    public function toStatusResponse(array $raw): StatusResponse
    {
        $status = $this->mapInvoiceStatus($raw);

        return new StatusResponse(
            successful: true,
            transactionId: TransactionId::fromString((string) ($raw['InvoiceId'] ?? '')),
            status: $status,
            message: $this->resolveStatusMessage($status, $raw),
            rawResponse: $raw,
        );
    }

    /**
     * @param array<string, mixed> $raw GetPaymentStatus Data payload.
     */
    public function toVerificationResponse(array $raw): VerificationResponse
    {
        $status   = $this->mapInvoiceStatus($raw);
        $verified = $status === PaymentStatus::Captured;

        return new VerificationResponse(
            successful: true,
            verified: $verified,
            transactionId: TransactionId::fromString((string) ($raw['InvoiceId'] ?? '')),
            message: $verified ? 'Payment verified.' : 'Payment is not in a successful captured state.',
            rawResponse: $raw,
        );
    }

    /**
     * @param array<string, mixed> $raw MakeRefund Data payload.
     */
    public function toRefundResponse(array $raw, Money $requestedAmount): RefundResponse
    {
        $refundId = (string) ($raw['RefundId'] ?? $raw['RefundReference'] ?? '');

        return new RefundResponse(
            successful: $refundId !== '',
            refundId: $refundId,
            amount: isset($raw['Amount'])
                ? Money::ofMajor((string) $raw['Amount'], $requestedAmount->currency)
                : $requestedAmount,
            status: $refundId !== '' ? PaymentStatus::Refunded : PaymentStatus::Failed,
            message: $refundId !== '' ? 'Refund created.' : 'Refund failed.',
            rawResponse: $raw,
        );
    }

    /**
     * @param array<string, mixed> $raw UpdatePaymentStatus Data payload.
     */
    public function toCaptureResponse(array $raw, Money $requestedAmount): CaptureResponse
    {
        return new CaptureResponse(
            successful: true,
            captureId: (string) ($raw['InvoiceId'] ?? $raw['PaymentId'] ?? ''),
            amount: $requestedAmount,
            status: PaymentStatus::Captured,
            message: 'Capture processed.',
            rawResponse: $raw,
        );
    }

    /**
     * @param array<string, mixed> $raw UpdatePaymentStatus Data payload.
     */
    public function toVoidResponse(array $raw, string $transactionId): VoidResponse
    {
        return new VoidResponse(
            successful: true,
            transactionId: TransactionId::fromString($transactionId),
            status: PaymentStatus::Voided,
            message: 'Authorization released.',
            rawResponse: $raw,
        );
    }

    /**
     * Flatten a Webhook v2 payload for CheckoutService correlation + event type.
     *
     * @param array<string, mixed> $raw Full webhook JSON (Event + Data).
     */
    public function toWebhookResponse(array $raw): WebhookResponse
    {
        $eventName = (string) ($raw['Event']['Name'] ?? '');
        $data      = is_array($raw['Data'] ?? null) ? $raw['Data'] : [];
        $invoice   = is_array($data['Invoice'] ?? null) ? $data['Invoice'] : [];
        $txn       = is_array($data['Transaction'] ?? null) ? $data['Transaction'] : [];

        $eventType = match ($eventName) {
            'PAYMENT_STATUS_CHANGED' => match (strtoupper((string) ($txn['Status'] ?? $invoice['Status'] ?? ''))) {
                'SUCCESS', 'PAID', 'CAPTURED' => WebhookEventType::PaymentSucceeded,
                'FAILED', 'CANCELED', 'CANCELLED', 'EXPIRED' => WebhookEventType::PaymentFailed,
                'AUTHORIZE', 'AUTHORIZED' => WebhookEventType::PaymentAuthorized,
                default => WebhookEventType::Unknown,
            },
            'REFUND_STATUS_CHANGED' => WebhookEventType::RefundSucceeded,
            'DISPUTE_STATUS_CHANGED' => WebhookEventType::DisputeOpened,
            default => WebhookEventType::Unknown,
        };

        $rawPayload = $raw;

        // Flatten keys CheckoutService::resolveAndConfirm() understands.
        $merchantOrderId = (string) (
            $invoice['ExternalIdentifier']
            ?? $invoice['UserDefinedField']
            ?? $data['CustomerReference']
            ?? $invoice['CustomerReference']
            ?? ''
        );

        if ($merchantOrderId !== '') {
            $rawPayload['merchant_order_id'] = $merchantOrderId;
        }

        $invoiceId = (string) ($invoice['Id'] ?? '');
        $paymentId = (string) ($txn['PaymentId'] ?? '');

        if ($invoiceId !== '') {
            $rawPayload['id']         = $invoiceId;
            $rawPayload['session_id'] = $invoiceId;
            $rawPayload['InvoiceId']  = $invoiceId;
        }

        if ($paymentId !== '') {
            $rawPayload['paymentId'] = $paymentId;
            $rawPayload['PaymentId'] = $paymentId;
            // Prefer PaymentId for lookup when present — GetPaymentStatus accepts it.
            if ($invoiceId === '') {
                $rawPayload['id'] = $paymentId;
            }
        }

        return new WebhookResponse(
            successful: ! $eventType->isUnknown(),
            eventType: $eventType,
            message: $eventType->isUnknown()
                ? "Unrecognised MyFatoorah event: '{$eventName}'."
                : "MyFatoorah event '{$eventName}' processed.",
            rawPayload: $rawPayload,
        );
    }

    /**
     * @param array<string, mixed> $raw GetPaymentStatus Data.
     */
    private function mapInvoiceStatus(array $raw): PaymentStatus
    {
        $invoiceStatus = strtoupper((string) ($raw['InvoiceStatus'] ?? ''));

        return match ($invoiceStatus) {
            'PAID'    => PaymentStatus::Captured,
            'PENDING' => $this->mapPendingFromTransactions($raw),
            'CANCELED', 'CANCELLED' => PaymentStatus::Cancelled,
            'EXPIRED' => PaymentStatus::Expired,
            default   => PaymentStatus::Failed,
        };
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function mapPendingFromTransactions(array $raw): PaymentStatus
    {
        $transactions = $raw['InvoiceTransactions'] ?? [];

        if (! is_array($transactions) || $transactions === []) {
            return PaymentStatus::Pending;
        }

        $last = $transactions[array_key_last($transactions)];
        $txnStatus = strtoupper((string) (is_array($last) ? ($last['TransactionStatus'] ?? '') : ''));

        // MyFatoorah documents the successful spelling as "Succss" (typo in their API).
        return match ($txnStatus) {
            'SUCCSS', 'SUCCESS' => PaymentStatus::Captured,
            'INPROGRESS', 'IN_PROGRESS' => PaymentStatus::Pending,
            'FAILED' => PaymentStatus::Failed,
            default => PaymentStatus::Pending,
        };
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function resolveAmount(array $raw): Money
    {
        $currencyCode = 'SAR';
        $transactions = $raw['InvoiceTransactions'] ?? [];

        if (is_array($transactions) && $transactions !== []) {
            $last = $transactions[array_key_last($transactions)];
            if (is_array($last) && ! empty($last['Currency'])) {
                $currencyCode = strtoupper((string) $last['Currency']);
            }
        }

        try {
            $currency = Currency::from($currencyCode);
        } catch (\ValueError) {
            $currency = Currency::SAR;
        }

        $value = $raw['InvoiceValue'] ?? 0;

        if (is_string($value) || is_int($value) || is_float($value)) {
            return Money::ofMajor((string) $value, $currency);
        }

        return Money::zero($currency);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function resolveStatusMessage(PaymentStatus $status, array $raw): string
    {
        $transactions = $raw['InvoiceTransactions'] ?? [];

        if (is_array($transactions) && $transactions !== []) {
            $last = $transactions[array_key_last($transactions)];
            $error = is_array($last) ? ($last['Error'] ?? null) : null;

            if (is_string($error) && $error !== '') {
                return $error;
            }
        }

        return match ($status) {
            PaymentStatus::Captured   => 'Payment succeeded.',
            PaymentStatus::Pending    => 'Payment is pending.',
            PaymentStatus::Cancelled  => 'Payment was cancelled.',
            PaymentStatus::Expired    => 'Payment expired.',
            default                   => 'Payment failed.',
        };
    }
}
