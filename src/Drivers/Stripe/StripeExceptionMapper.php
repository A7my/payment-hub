<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe;

use Mifatoyeh\LaravelPaymentFramework\Exceptions\PaymentException;
use Throwable;

/**
 * Converts Stripe SDK exceptions into framework {@see PaymentException} subclasses.
 *
 * Stripe-specific counterpart to {@see \Mifatoyeh\LaravelPaymentFramework\Drivers\AbstractDriver::wrapException()}.
 * Where `wrapException()` provides a generic fallback (wrap-and-preserve),
 * this mapper inspects the concrete Stripe SDK exception type (e.g.
 * `\Stripe\Exception\CardException`, `\Stripe\Exception\RateLimitException`,
 * `\Stripe\Exception\InvalidRequestException`) and maps it to the most
 * specific framework exception available (e.g. `RefundFailedException`,
 * `CaptureFailedException`, `AuthorizationFailedException`).
 *
 * Contains ONLY exception translation — no HTTP communication, no response
 * mapping, and no lifecycle orchestration.
 */
final class StripeExceptionMapper
{
    /**
     * Map a caught exception to the appropriate framework PaymentException subclass.
     *
     * TODO: match (true) {
     *           $e instanceof \Stripe\Exception\CardException           => new AuthorizationFailedException(...),
     *           $e instanceof \Stripe\Exception\RateLimitException      => new PaymentException(...), // transient, handled by RetryService upstream
     *           $e instanceof \Stripe\Exception\InvalidRequestException => new InvalidConfigurationException(...),
     *           $e instanceof \Stripe\Exception\ApiConnectionException  => new PaymentException(...),
     *           default                                                 => new PaymentException($e->getMessage(), (int) $e->getCode(), $e),
     *       };
     *
     * @param Throwable            $e       The original exception thrown by the Stripe SDK.
     * @param array<string, mixed> $context Additional context (e.g. ['operation' => 'refund']).
     *
     * @return PaymentException The mapped framework exception, wrapping $e as $previous.
     */
    public function map(Throwable $e, array $context = []): PaymentException
    {
        throw new \LogicException('StripeExceptionMapper::map() not yet implemented.');
    }
}
