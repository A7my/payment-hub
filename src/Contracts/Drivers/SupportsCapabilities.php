<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers;

/**
 * Optional interface for drivers that support runtime capability detection.
 *
 * Implementing this interface allows the host application to query a driver
 * for its supported operations before calling them, enabling graceful
 * degradation or UI feature-toggling without try/catch on
 * UnsupportedOperationException.
 *
 * Drivers that do not implement this interface are assumed to support all
 * operations declared in PaymentDriverContract.
 *
 * Example capability strings: 'authorize', 'subscription', 'refund', 'qr_code'
 */
interface SupportsCapabilities
{
    /**
     * Determine whether this driver supports the given capability.
     *
     * @param string $capability A capability identifier string (e.g., 'authorize', 'subscription').
     *
     * @return bool True if the driver supports the capability, false otherwise.
     */
    public function supports(string $capability): bool;
}
