<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Managers;

use Closure;
use Illuminate\Support\Manager;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\PaymentDriverContract;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\DriverNotFoundException;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\InvalidConfigurationException;

/**
 * Resolves and caches payment driver instances.
 *
 * Extends {@see \Illuminate\Support\Manager} to inherit:
 *   - driver(?string $name): resolves and caches a named driver instance
 *   - forgetDrivers():       clears all cached driver instances
 *   - __call():              proxies method calls to the default driver
 *
 * Resolution flow for config-based drivers:
 *   1. Read `payment.drivers.{name}` config block.
 *   2. Validate required key `class` is present and non-empty.
 *   3. Resolve the class via the service container (supports DI).
 *   4. Verify it implements PaymentDriverContract; throw DriverNotFoundException if not.
 *   5. Cache the instance under the driver name for subsequent calls.
 *
 * Resolution flow for runtime-registered drivers (via extend()):
 *   1. Check $customCreators for the driver name.
 *   2. Invoke the registered Closure or instantiate the registered class string.
 *   3. Verify and cache as above.
 *
 * Usage:
 *   $manager->driver();            // Default driver from config('payment.default')
 *   $manager->driver('stripe');    // Named driver
 *   $manager->driver('paypal');    // Switch providers — zero application code change
 *   $manager->extend('mock', MockDriver::class);
 */
class PaymentManager extends Manager
{
    /**
     * Get the default payment driver name from config.
     *
     * Falls back to 'stripe' if `payment.default` is not set.
     *
     * @return string The default driver key (e.g. "stripe").
     */
    public function getDefaultDriver(): string
    {
        return (string) ($this->config->get('payment.default') ?? 'stripe');
    }

    /**
     * Register a custom driver creator.
     *
     * Accepts either:
     *   - A class string that implements PaymentDriverContract. It will be
     *     resolved via the IoC container at driver() call time.
     *   - A Closure(Container): PaymentDriverContract — the standard Laravel
     *     Manager pattern, kept for backward compatibility.
     *
     * Example:
     *   $manager->extend('mock', MockDriver::class);
     *   $manager->extend('fake', fn($app) => new FakeDriver($app['config']));
     *
     * @param string         $driver        The driver name to register.
     * @param string|Closure $driverOrClosure Either a FQCN string or a Closure factory.
     *
     * @return $this
     */
    public function extend($driver, $driverOrClosure): static
    {
        if (is_string($driverOrClosure)) {
            // Wrap the class name in a closure so the parent's callCustomCreator()
            // resolves it via the IoC container, enabling proper dependency injection.
            $className = $driverOrClosure;
            $this->customCreators[$driver] = function ($container) use ($className) {
                return $container->make($className);
            };
        } else {
            // Standard Laravel Manager: store the Closure directly.
            $this->customCreators[$driver] = $driverOrClosure;
        }

        return $this;
    }

    /**
     * Get the list of all configured driver keys.
     *
     * Reads the top-level keys from `payment.drivers` config. Useful for
     * admin health-check endpoints or capability-discovery features.
     *
     * @return array<int, string> Array of driver name strings from config/payment.php.
     */
    public function getAvailableDrivers(): array
    {
        return array_keys((array) $this->config->get('payment.drivers', []));
    }

    /**
     * Create (resolve) a payment driver instance for the given name.
     *
     * Called automatically by {@see Manager::driver()} when the instance is
     * not yet cached in $this->drivers[]. Checks custom creators first (registered
     * via extend()), then falls back to config-based resolution.
     *
     * @param string $driver The driver name key from config/payment.php.
     *
     * @throws InvalidConfigurationException When the driver config block is missing or has no 'class'.
     * @throws DriverNotFoundException       When the resolved class does not implement PaymentDriverContract.
     *
     * @return PaymentDriverContract The resolved and validated driver instance.
     */
    protected function createDriver($driver): PaymentDriverContract
    {
        // 1. Honour custom creators registered via extend() — they take priority
        //    over config-based drivers, enabling runtime overrides in tests.
        if (isset($this->customCreators[$driver])) {
            return $this->resolveCustomCreator($driver);
        }

        // 2. Config-based resolution.
        return $this->resolveFromConfig($driver);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Resolve a driver via the custom creator callback and validate the result.
     *
     * @param string $driver Driver name.
     *
     * @throws DriverNotFoundException When the returned object is not a PaymentDriverContract.
     *
     * @return PaymentDriverContract
     */
    private function resolveCustomCreator(string $driver): PaymentDriverContract
    {
        $instance = $this->callCustomCreator($driver);

        return $this->assertDriverContract($instance, $driver);
    }

    /**
     * Resolve a driver from the payment config and validate the result.
     *
     * @param string $driver Driver name key.
     *
     * @throws DriverNotFoundException       When no config block is registered for the driver name.
     * @throws InvalidConfigurationException When the config block exists but the 'class' key is absent.
     *
     * @return PaymentDriverContract
     */
    private function resolveFromConfig(string $driver): PaymentDriverContract
    {
        $drivers = (array) $this->config->get('payment.drivers', []);

        // Validate the driver is registered at all — an unknown driver name
        // is a DriverNotFoundException, not a configuration problem.
        if (! array_key_exists($driver, $drivers) || empty($drivers[$driver]) || ! is_array($drivers[$driver])) {
            throw DriverNotFoundException::forDriver($driver);
        }

        $config = $drivers[$driver];

        // Validate required 'class' key.
        if (empty($config['class']) || ! is_string($config['class'])) {
            throw InvalidConfigurationException::forMissingKey("payment.drivers.{$driver}.class");
        }

        // Resolve via container — allows constructor DI in driver implementations.
        $instance = $this->container->make($config['class'], ['config' => $config]);

        return $this->assertDriverContract($instance, $driver);
    }

    /**
     * Assert the resolved instance implements PaymentDriverContract.
     *
     * @param mixed  $instance The resolved driver object.
     * @param string $driver   Driver name, used in the exception message.
     *
     * @throws DriverNotFoundException When $instance does not implement the contract.
     *
     * @return PaymentDriverContract
     */
    private function assertDriverContract(mixed $instance, string $driver): PaymentDriverContract
    {
        if (! $instance instanceof PaymentDriverContract) {
            throw DriverNotFoundException::forDriver($driver);
        }

        return $instance;
    }
}
