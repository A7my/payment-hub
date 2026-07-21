<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Exceptions;

use Mifatoyeh\LaravelPaymentFramework\Contracts\Payable;

/**
 * Thrown by `src/Checkout/CheckoutService.php` for every rejectable state of
 * a generic checkout request — unknown `model_type`, missing record, a
 * driver not supported by that specific model, a failed
 * {@see \Mifatoyeh\LaravelPaymentFramework\Contracts\Payable::authorizePayment()}
 * check, or an unsupported `driver_type`.
 *
 * Carries its own HTTP status code (mirroring
 * {@see \Mifatoyeh\LaravelPaymentFramework\Drivers\Paymob\PaymobApiException}'s
 * design) so `CheckoutController` can map every failure mode to the correct
 * response with one `catch` block, instead of the service layer reaching
 * into HTTP concerns directly.
 */
final class CheckoutException extends PaymentException
{
    public function __construct(
        string $message,
        private readonly int $statusCode,
    ) {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public static function unknownPayableType(string $modelType): self
    {
        return new self(
            "No payable model is registered for model_type [{$modelType}]. " .
            'Add it to the payment.payables config map.',
            422,
        );
    }

    public static function payableNotFound(string $modelType, string $modelId): self
    {
        return new self("No [{$modelType}] record found with id [{$modelId}].", 404);
    }

    public static function modelNotPayable(string $modelType, string $class): self
    {
        return new self(
            "config('payment.payables.{$modelType}') resolves to [{$class}], which does not implement " .
            Payable::class . '. Fix the payables config map.',
            500,
        );
    }

    public static function unsupportedDriverForPayable(string $driver, string $modelType): self
    {
        return new self("The [{$driver}] driver is not enabled for [{$modelType}] payments.", 422);
    }

    public static function unauthorized(): self
    {
        return new self('You are not authorised to pay for this record.', 403);
    }

    public static function unsupportedDriverType(string $driverType): self
    {
        return new self(
            "driver_type [{$driverType}] must be one of: sdk, webview.",
            422,
        );
    }

    public static function sdkModeNotSupportedByDriver(string $driver): self
    {
        return new self(
            "The [{$driver}] driver does not support driver_type \"sdk\" (it does not implement " .
            'SupportsSdkCheckout). Use driver_type "webview" instead, or choose a driver that supports SDK checkout.',
            422,
        );
    }

    public static function unsupportedOs(string $os): self
    {
        return new self("os [{$os}] must be one of: web, mobile.", 422);
    }

    public static function returnUrlRequiredForWebviewWeb(): self
    {
        return new self(
            'return_url is required when driver_type is "webview" and os is "web" — the package redirects ' .
            'the customer\'s browser back to it once the payment callback has been verified.',
            422,
        );
    }
}
