<?php

declare(strict_types=1);

namespace Mifatoyeh\LaravelPaymentFramework\Providers;

use Illuminate\Support\ServiceProvider;
use Mifatoyeh\LaravelPaymentFramework\Checkout\CheckoutService;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Currency\CurrencyConverterContract;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Logging\PaymentLoggerContract;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Repositories\PaymentTransactionRepositoryContract;
use Mifatoyeh\LaravelPaymentFramework\Contracts\Services\RetryServiceContract;
use Mifatoyeh\LaravelPaymentFramework\Logging\LaravelLogger;
use Mifatoyeh\LaravelPaymentFramework\Logging\NullLogger;
use Mifatoyeh\LaravelPaymentFramework\Managers\PaymentManager;
use Mifatoyeh\LaravelPaymentFramework\Repositories\EloquentPaymentTransactionRepository;
use Mifatoyeh\LaravelPaymentFramework\Repositories\NullPaymentTransactionRepository;
use Mifatoyeh\LaravelPaymentFramework\Services\PaymentService;
use Mifatoyeh\LaravelPaymentFramework\Services\RetryService;
use Mifatoyeh\LaravelPaymentFramework\Services\WebhookVerifier;
use Mifatoyeh\LaravelPaymentFramework\Webhooks\WebhookProcessor;

/**
 * Laravel service provider for the Payment Framework package.
 *
 * Registered automatically via Laravel's package auto-discovery using the
 * extra.laravel.providers entry in composer.json. No manual registration
 * is required in the host application.
 *
 * Responsibilities:
 *   register() — Bind all contracts and services into the IoC container.
 *   boot()     — Merge config, register routes, register listeners, publish assets.
 */
class PaymentServiceProvider extends ServiceProvider
{
    /**
     * Register all framework bindings into the service container.
     *
     * All bindings use the contract interface as the key, allowing the host
     * application to swap any implementation without touching framework code.
     */
    public function register(): void
    {
        // Merge package config with host application config
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/payment.php',
            'payment'
        );

        // Bind PaymentManager as a singleton (drivers are cached per-request)
        $this->app->singleton(PaymentManager::class, function ($app) {
            // TODO: return new PaymentManager($app);
            return new PaymentManager($app);
        });

        // Bind PaymentLoggerContract — use NullLogger if logging is disabled
        $this->app->bind(PaymentLoggerContract::class, function ($app) {
            // TODO: if (!config('payment.logging.enabled', true)) return new NullLogger();
            // TODO: $channel = config('payment.logging.channel', 'stack');
            // TODO: return new LaravelLogger($app->make(\Illuminate\Log\LogManager::class), $channel);
            return new NullLogger();
        });

        // Bind PaymentTransactionRepositoryContract — use Null repo by default
        $this->app->bind(PaymentTransactionRepositoryContract::class, function ($app) {
            // TODO: if (config('payment.repository.enabled', false)) {
            //           return new EloquentPaymentTransactionRepository(
            //               $app->make(\Mifatoyeh\LaravelPaymentFramework\Repositories\PaymentTransaction::class)
            //           );
            //       }
            return new NullPaymentTransactionRepository();
        });

        // Bind RetryServiceContract with config values
        $this->app->bind(RetryServiceContract::class, function ($app) {
            // TODO: return new RetryService(
            //     maxAttempts: (int) config('payment.retry.max_attempts', 3),
            //     delayMs:     (int) config('payment.retry.delay_ms', 500),
            //     enabled:     (bool) config('payment.retry.enabled', true),
            // );
            return new RetryService(3, 500, true);
        });

        // Bind WebhookVerifier
        $this->app->bind(WebhookVerifier::class, function ($app) {
            return new WebhookVerifier($app->make(PaymentLoggerContract::class));
        });

        // Bind WebhookProcessor
        $this->app->bind(WebhookProcessor::class, function ($app) {
            return new WebhookProcessor(
                $app->make(PaymentManager::class),
                $app->make(WebhookVerifier::class),
                $app->make(\Illuminate\Contracts\Events\Dispatcher::class),
            );
        });

        // Bind PaymentService
        $this->app->bind(PaymentService::class, function ($app) {
            return new PaymentService($app->make(PaymentManager::class));
        });

        // Bind CheckoutService — orchestrates the generic checkout endpoint
        $this->app->bind(CheckoutService::class, function ($app) {
            return new CheckoutService($app->make(PaymentManager::class));
        });
    }

    /**
     * Bootstrap the package after all service providers have been registered.
     *
     * - Registers the webhook route if payment.webhook.enabled is true.
     * - Registers the checkout route if payment.checkout.enabled is true.
     * - Registers a PaymentSucceeded listener for repository persistence.
     * - Publishes config and migration assets for vendor:publish.
     */
    public function boot(): void
    {
        // Validate configuration at boot time
        // TODO: $this->validateConfiguration();

        // Register the webhook route — POST {prefix}/{driver}. The route
        // file reads its own prefix/middleware from config; nothing beyond
        // the enabled flag is decided here, keeping this symmetric with the
        // checkout route below.
        if (config('payment.webhook.enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../../routes/webhooks.php');
        }

        // Register the generic checkout route — POST {route}. Same
        // loadRoutesFrom() pattern as webhooks above; the route file reads
        // its own path/middleware from config.
        if (config('payment.checkout.enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../../routes/checkout.php');
        }

        // Register PaymentSucceeded listener for optional repository persistence
        // TODO: if (config('payment.repository.enabled', false)) {
        //           \Illuminate\Support\Facades\Event::listen(
        //               \Mifatoyeh\LaravelPaymentFramework\Events\PaymentSucceeded::class,
        //               function ($event) use (&$app) {
        //                   app(PaymentTransactionRepositoryContract::class)
        //                       ->store($event->response, $event->request);
        //               }
        //           );
        //       }

        // Publish configuration
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/payment.php' => config_path('payment.php'),
            ], 'payment-config');

            $this->publishes([
                __DIR__ . '/../../database/migrations/' => database_path('migrations'),
            ], 'payment-migrations');
        }
    }
}
