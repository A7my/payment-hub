<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Drivers\MyFatoorah;

/**
 * Verifies MyFatoorah Webhook v2 signatures (`MyFatoorah-Signature` header).
 *
 * MyFatoorah does NOT HMAC the raw JSON body. It builds a canonical
 * `Key=Value,Key2=Value2` string from specific `Data` fields in a fixed
 * per-event order, then HMAC-SHA256 + base64-encodes that string with the
 * portal webhook secret.
 *
 * @see https://docs.myfatoorah.com/docs/webhook-signature
 * @see https://docs.myfatoorah.com/docs/webhook-v2-payment-status-data-model
 */
final class MyFatoorahWebhookVerifier
{
    /**
     * Field order for PAYMENT_STATUS_CHANGED (Webhook v2).
     *
     * @var list<string>
     */
    private const PAYMENT_STATUS_FIELDS = [
        'Invoice.Id',
        'Invoice.Status',
        'Transaction.Status',
        'Transaction.PaymentId',
        'Invoice.ExternalIdentifier',
    ];

    /**
     * Field order for REFUND_STATUS_CHANGED (Webhook v2) — best-effort from docs.
     *
     * @var list<string>
     */
    private const REFUND_STATUS_FIELDS = [
        'Refund.Id',
        'Refund.Status',
        'Refund.Reference',
        'Refund.InvoiceId',
        'Amount',
    ];

    /**
     * @param array<string, mixed> $config payment.drivers.myfatoorah block.
     */
    public function __construct(
        private readonly array $config = [],
    ) {
    }

    /**
     * @param array<string, mixed> $payload Decoded webhook JSON (Event + Data).
     * @param string               $signatureHeader Value of MyFatoorah-Signature.
     */
    public function verify(array $payload, string $signatureHeader): bool
    {
        $secret = $this->webhookSecret();

        if ($secret === '' || trim($signatureHeader) === '') {
            return false;
        }

        $eventName = (string) ($payload['Event']['Name'] ?? $payload['EventName'] ?? '');
        $data      = $payload['Data'] ?? [];
        $data      = is_array($data) ? $data : [];

        $canonical = $this->canonicalString($eventName, $data);
        $expected  = base64_encode(hash_hmac('sha256', $canonical, $secret, true));

        return hash_equals($expected, trim($signatureHeader));
    }

    /**
     * Build the signed canonical string for tests / debugging.
     *
     * @param array<string, mixed> $data The webhook `Data` object.
     */
    public function canonicalString(string $eventName, array $data): string
    {
        $fields = match ($eventName) {
            'REFUND_STATUS_CHANGED' => self::REFUND_STATUS_FIELDS,
            default                 => self::PAYMENT_STATUS_FIELDS,
        };

        $parts = [];

        foreach ($fields as $path) {
            $parts[] = $path . '=' . $this->valueAtPath($data, $path);
        }

        return implode(',', $parts);
    }

    private function webhookSecret(): string
    {
        return (string) ($this->config['webhook_secret'] ?? '');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function valueAtPath(array $data, string $path): string
    {
        $cursor = $data;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return '';
            }

            $cursor = $cursor[$segment];
        }

        if ($cursor === null) {
            return '';
        }

        if (is_bool($cursor)) {
            return $cursor ? 'true' : 'false';
        }

        if (is_array($cursor)) {
            return '';
        }

        return (string) $cursor;
    }
}
