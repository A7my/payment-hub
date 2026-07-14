<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Exceptions;

use RuntimeException;

/**
 * Base exception for all Laravel Payment Framework errors.
 *
 * Every framework-thrown exception extends this class, allowing host
 * applications to catch all payment-related errors at a single granularity:
 *
 *   try {
 *       Payment::charge($request);
 *   } catch (PaymentException $e) {
 *       // Handle any payment framework error
 *   }
 *
 * Use more specific subclasses to handle errors at finer granularity.
 */
class PaymentException extends RuntimeException
{
}
