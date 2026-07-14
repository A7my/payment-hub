<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Enums;

/**
 * Execution environment for a payment driver instance.
 *
 * Design decisions:
 * - Only two cases are needed: Sandbox (safe test mode) and Production (live).
 *   Additional granularity (e.g., "staging", "UAT") is a provider concern
 *   and should be expressed through driver configuration, not this enum.
 * - The `sandbox` boolean in `config/payment.php` is mapped to this enum
 *   at service-provider boot time, giving a type-safe representation
 *   throughout the framework instead of raw booleans.
 * - `isSandbox()` / `isProduction()` guards prevent accidental live charges
 *   in test code and vice versa.
 */
enum Environment: string
{
    /**
     * Test / staging environment.
     * No real money is moved. Provider test credentials must be used.
     */
    case Sandbox = 'sandbox';

    /**
     * Live production environment.
     * Real transactions are processed. Use only with production credentials.
     */
    case Production = 'production';

    // =========================================================================
    // Helper methods
    // =========================================================================

    /**
     * Whether this is the sandbox (test) environment.
     *
     * @return bool
     */
    public function isSandbox(): bool
    {
        return $this === self::Sandbox;
    }

    /**
     * Whether this is the live production environment.
     *
     * @return bool
     */
    public function isProduction(): bool
    {
        return $this === self::Production;
    }

    /**
     * Human-readable label.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::Sandbox    => 'Sandbox (Test)',
            self::Production => 'Production (Live)',
        };
    }

    /**
     * Create an Environment from a boolean sandbox flag.
     *
     * Convenience factory that mirrors the `sandbox` key in config/payment.php,
     * allowing drivers to resolve their environment with a single call:
     *
     *   $env = Environment::fromSandboxFlag((bool) $config['sandbox']);
     *
     * @param bool $sandbox True → Sandbox, false → Production.
     *
     * @return self
     */
    public static function fromSandboxFlag(bool $sandbox): self
    {
        return $sandbox ? self::Sandbox : self::Production;
    }
}
