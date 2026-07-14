<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Tests\Unit\Managers;

use Mifatoyeh\LaravelPaymentFramework\Contracts\Drivers\PaymentDriverContract;
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
use Mifatoyeh\LaravelPaymentFramework\Enums\Currency;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\DriverNotFoundException;
use Mifatoyeh\LaravelPaymentFramework\Exceptions\InvalidConfigurationException;
use Mifatoyeh\LaravelPaymentFramework\Managers\PaymentManager;
use Mifatoyeh\LaravelPaymentFramework\Responses\CaptureResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentLinkResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\PaymentResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\RefundResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\StatusResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\SubscriptionResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\VerificationResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\VoidResponse;
use Mifatoyeh\LaravelPaymentFramework\Responses\WebhookResponse;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\Money;
use Mifatoyeh\LaravelPaymentFramework\ValueObjects\TransactionId;
use Orchestra\Testbench\TestCase;
use Mifatoyeh\LaravelPaymentFramework\Providers\PaymentServiceProvider;
use Mifatoyeh\LaravelPaymentFramework\Testing\FakePaymentDriver;

/**
 * Unit tests for PaymentManager.
 *
 * Covers: default driver resolution, named driver resolution, unknown driver
 * exception, driver caching, extend(), getAvailableDrivers(), and multiple
 * registered drivers.
 *
 * Uses Orchestra Testbench to provide the Laravel application container.
 */
class PaymentManagerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PaymentServiceProvider::class];
    }

    /**
     * Build a PaymentManager with a given config array.
     *
     * @param array<string, mixed> $config
     *
     * @return PaymentManager
     */
    private function makeManager(array $config = []): PaymentManager
    {
        $this->app['config']->set('payment', array_merge([
            'default' => 'fake',
            'drivers' => [],
        ], $config));

        return $this->app->make(PaymentManager::class);
    }

    // =========================================================================
    // Default driver resolution
    // =========================================================================

    /** @test */
    public function test_resolves_default_driver_from_config(): void
    {
        $manager = $this->makeManager(['default' => 'fake', 'drivers' => []]);
        $manager->extend('fake', FakePaymentDriver::class);

        $driver = $manager->driver();

        $this->assertInstanceOf(PaymentDriverContract::class, $driver);
    }

    /** @test */
    public function test_get_default_driver_reads_from_config(): void
    {
        $manager = $this->makeManager(['default' => 'my_driver', 'drivers' => []]);

        $this->assertSame('my_driver', $manager->getDefaultDriver());
    }

    /** @test */
    public function test_get_default_driver_falls_back_to_stripe(): void
    {
        $this->app['config']->set('payment.default', null);
        $manager = $this->app->make(PaymentManager::class);

        $this->assertSame('stripe', $manager->getDefaultDriver());
    }

    // =========================================================================
    // Named driver resolution
    // =========================================================================

    /** @test */
    public function test_resolves_named_driver_via_extend(): void
    {
        $manager = $this->makeManager();
        $manager->extend('fake', FakePaymentDriver::class);

        $driver = $manager->driver('fake');

        $this->assertInstanceOf(FakePaymentDriver::class, $driver);
    }

    /** @test */
    public function test_resolves_driver_via_closure_extend(): void
    {
        $manager = $this->makeManager();
        $manager->extend('fake', fn () => new FakePaymentDriver());

        $driver = $manager->driver('fake');

        $this->assertInstanceOf(FakePaymentDriver::class, $driver);
    }

    /** @test */
    public function test_extend_with_class_string_enables_container_resolution(): void
    {
        $manager = $this->makeManager();
        $manager->extend('fake', FakePaymentDriver::class);

        $driver = $manager->driver('fake');

        $this->assertInstanceOf(PaymentDriverContract::class, $driver);
    }

    // =========================================================================
    // Unknown driver exception
    // =========================================================================

    /** @test */
    public function test_unregistered_driver_throws_driver_not_found_exception(): void
    {
        $manager = $this->makeManager(['default' => 'x', 'drivers' => []]);

        $this->expectException(DriverNotFoundException::class);

        $manager->driver('nonexistent_driver_xyz');
    }

    /** @test */
    public function test_driver_not_found_exception_contains_driver_name(): void
    {
        $manager = $this->makeManager(['drivers' => []]);

        try {
            $manager->driver('missing_driver');
            $this->fail('Expected DriverNotFoundException');
        } catch (DriverNotFoundException $e) {
            $this->assertStringContainsString('missing_driver', $e->getMessage());
        }
    }

    /** @test */
    public function test_config_driver_missing_class_key_throws_invalid_configuration(): void
    {
        $manager = $this->makeManager([
            'drivers' => [
                'incomplete' => ['key' => 'value'], // no 'class' key
            ],
        ]);

        $this->expectException(InvalidConfigurationException::class);

        $manager->driver('incomplete');
    }

    /** @test */
    public function test_config_driver_not_implementing_contract_throws_driver_not_found(): void
    {
        // Register a class that does NOT implement PaymentDriverContract
        $manager = $this->makeManager([
            'drivers' => [
                'bad' => ['class' => \stdClass::class],
            ],
        ]);

        $this->expectException(DriverNotFoundException::class);

        $manager->driver('bad');
    }

    // =========================================================================
    // Driver caching
    // =========================================================================

    /** @test */
    public function test_same_instance_returned_on_second_call(): void
    {
        $manager = $this->makeManager();
        $manager->extend('fake', FakePaymentDriver::class);

        $first  = $manager->driver('fake');
        $second = $manager->driver('fake');

        $this->assertSame($first, $second);
    }

    /** @test */
    public function test_forget_drivers_clears_cache(): void
    {
        $manager = $this->makeManager();
        $manager->extend('fake', FakePaymentDriver::class);

        $first = $manager->driver('fake');
        $manager->forgetDrivers();
        $second = $manager->driver('fake');

        $this->assertNotSame($first, $second);
    }

    // =========================================================================
    // extend()
    // =========================================================================

    /** @test */
    public function test_extend_returns_manager_for_fluent_chaining(): void
    {
        $manager = $this->makeManager();
        $result  = $manager->extend('fake', FakePaymentDriver::class);

        $this->assertSame($manager, $result);
    }

    /** @test */
    public function test_extend_overrides_config_based_driver(): void
    {
        // Config has a driver named 'fake' pointing to stdClass (invalid)
        $manager = $this->makeManager([
            'drivers' => ['fake' => ['class' => \stdClass::class]],
        ]);

        // extend() should override the config entry
        $manager->extend('fake', FakePaymentDriver::class);

        $driver = $manager->driver('fake');

        $this->assertInstanceOf(FakePaymentDriver::class, $driver);
    }

    // =========================================================================
    // getAvailableDrivers()
    // =========================================================================

    /** @test */
    public function test_get_available_drivers_returns_config_keys(): void
    {
        $manager = $this->makeManager([
            'drivers' => [
                'stripe'     => ['class' => 'StripeDriver'],
                'paypal'     => ['class' => 'PayPalDriver'],
                'myfatoorah' => ['class' => 'MyfatoorahDriver'],
            ],
        ]);

        $available = $manager->getAvailableDrivers();

        $this->assertContains('stripe', $available);
        $this->assertContains('paypal', $available);
        $this->assertContains('myfatoorah', $available);
        $this->assertCount(3, $available);
    }

    /** @test */
    public function test_get_available_drivers_returns_empty_array_when_no_drivers(): void
    {
        $manager = $this->makeManager(['drivers' => []]);

        $this->assertSame([], $manager->getAvailableDrivers());
    }

    // =========================================================================
    // Multiple registered drivers
    // =========================================================================

    /** @test */
    public function test_multiple_drivers_can_be_registered_independently(): void
    {
        $driverA = new FakePaymentDriver();
        $driverB = new FakePaymentDriver();

        $manager = $this->makeManager();
        $manager->extend('driver_a', fn () => $driverA);
        $manager->extend('driver_b', fn () => $driverB);

        $this->assertSame($driverA, $manager->driver('driver_a'));
        $this->assertSame($driverB, $manager->driver('driver_b'));
        $this->assertNotSame($manager->driver('driver_a'), $manager->driver('driver_b'));
    }

    // =========================================================================
    // Property 1: Invalid Driver Resolution Throws
    // Feature: laravel-payment-framework, Property 1: Invalid driver resolution throws
    // =========================================================================
    /** @test */
    public function test_property_1_invalid_driver_resolution_throws(): void
    {
        // Feature: laravel-payment-framework, Property 1: Invalid driver resolution throws
        $manager   = $this->makeManager(['drivers' => []]);
        $testNames = ['unknown_xyz', 'not_registered', '__invalid__', ''];

        foreach ($testNames as $name) {
            if ($name === '') {
                continue; // empty string resolves to default driver, skip
            }

            try {
                $manager->driver($name);
                $this->fail("Expected DriverNotFoundException for driver [{$name}]");
            } catch (DriverNotFoundException $e) {
                $this->assertStringContainsString($name, $e->getMessage());
                $this->addToAssertionCount(1);
            }
        }
    }

    // =========================================================================
    // Property 2: Driver Resolution Caching
    // Feature: laravel-payment-framework, Property 2: Driver resolution caching
    // =========================================================================
    /** @test */
    public function test_property_2_driver_resolution_caching(): void
    {
        // Feature: laravel-payment-framework, Property 2: Driver resolution caching
        $manager = $this->makeManager();
        $manager->extend('cached_driver', FakePaymentDriver::class);

        $first  = $manager->driver('cached_driver');
        $second = $manager->driver('cached_driver');
        $third  = $manager->driver('cached_driver');

        $this->assertSame($first, $second);
        $this->assertSame($second, $third);
    }

    // =========================================================================
    // Property 3: Available Drivers Round-Trip
    // Feature: laravel-payment-framework, Property 3: Available drivers round-trip
    // =========================================================================
    /** @test */
    public function test_property_3_available_drivers_round_trip(): void
    {
        // Feature: laravel-payment-framework, Property 3: Available drivers round-trip
        $driverKeys = ['alpha', 'beta', 'gamma', 'delta'];

        $config = [];
        foreach ($driverKeys as $key) {
            $config[$key] = ['class' => FakePaymentDriver::class];
        }

        $manager   = $this->makeManager(['drivers' => $config]);
        $available = $manager->getAvailableDrivers();

        foreach ($driverKeys as $key) {
            $this->assertContains($key, $available, "Driver [{$key}] should be in available drivers.");
        }

        $this->assertCount(count($driverKeys), $available);
    }

    // =========================================================================
    // Property 4: Missing Config Key Throws at Resolution
    // Feature: laravel-payment-framework, Property 4: Missing config key throws at resolution
    // =========================================================================
    /** @test */
    public function test_property_4_missing_config_key_throws_at_resolution(): void
    {
        // Feature: laravel-payment-framework, Property 4: Missing config key throws at resolution
        // Driver config block exists but required 'class' key is absent
        $manager = $this->makeManager([
            'drivers' => [
                'no_class_driver' => ['secret' => 'value'], // missing class
            ],
        ]);

        try {
            $manager->driver('no_class_driver');
            $this->fail('Expected InvalidConfigurationException for missing class key.');
        } catch (InvalidConfigurationException $e) {
            $this->assertStringContainsString('class', $e->getMessage());
        }

        // Driver key entirely absent from config
        try {
            $manager->driver('completely_absent');
            $this->fail('Expected DriverNotFoundException for absent driver.');
        } catch (DriverNotFoundException | InvalidConfigurationException $e) {
            $this->addToAssertionCount(1);
        }
    }
}
