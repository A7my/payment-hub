<?php

declare(strict_types=1);

    use Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeDriver;
    use Mifatoyeh\LaravelPaymentFramework\Enums\Environment;

/**
 * Laravel Payment Framework — Configuration File
 *
 * Publish this file to your application:
 *   php artisan vendor:publish --tag=payment-config
 *
 * Switch payment providers by changing PAYMENT_DRIVER in your .env file.
 * No application code changes required.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Default Payment Driver
    |--------------------------------------------------------------------------
    |
    | The default driver to use when Payment::charge() is called without an
    | explicit driver() selection. Must match a key in the 'drivers' array.
    |
    */
    'default' => env('PAYMENT_DRIVER', 'stripe'),

    /*
    |--------------------------------------------------------------------------
    | Payment Drivers
    |--------------------------------------------------------------------------
    |
    | Each driver block configures a specific payment provider. You may define
    | multiple instances of the same provider (e.g. stripe_usd, stripe_eur) to
    | use different credentials simultaneously.
    |
    | Required keys per driver: class, webhook_secret
    | Optional keys: sandbox, environment, timeout, currencies
    |
    */
    'drivers' => [

        'stripe' => [
            // The fully-qualified class name of the driver implementation.
            // Ships built-in with this package — override only if you need
            // to substitute a custom Stripe driver implementation.
            'class'          => env('STRIPE_DRIVER_CLASS', StripeDriver::class),

            // Provider API credentials — never hardcode these values.
            'key'            => env('STRIPE_KEY'),
            'secret'         => env('STRIPE_SECRET'),

            // Webhook secret for signature verification.
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

            // When true, the driver targets the provider's sandbox/test endpoint.
            'sandbox'        => env('PAYMENT_SANDBOX', true),

            // Canonical environment enum value (derived from sandbox flag above).
            'environment'    => env('PAYMENT_SANDBOX', true)
                ? Environment::Sandbox
                : Environment::Production,

            // HTTP request timeout in seconds.
            'timeout'        => (int) env('PAYMENT_TIMEOUT', 30),

            // Currencies accepted by this driver instance.
            'currencies'     => ['USD', 'EUR', 'GBP'],
        ],

        // Add additional driver blocks here following the same structure.
        // Example: 'paypal' => [ 'class' => ..., 'client_id' => ..., ... ]

    ],

    /*
    |--------------------------------------------------------------------------
    | Application-Level Supported Currencies
    |--------------------------------------------------------------------------
    |
    | The ISO 4217 currency codes your application accepts across all drivers.
    | Money value objects are validated against this list at construction time.
    |
    */
    'currencies' => explode(',', env('PAYMENT_CURRENCIES', 'USD,EUR,GBP,SAR,AED,EGP')),

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Controls how the framework logs payment operations.
    | Set enabled to false to bind NullLogger and suppress all payment logs.
    | Set debug to true to additionally log raw request/response payloads.
    | WARNING: debug mode may log sensitive card data — never enable in production.
    |
    */
    'logging' => [
        'enabled' => env('PAYMENT_LOGGING_ENABLED', true),
        'channel' => env('PAYMENT_LOG_CHANNEL', 'stack'),
        'debug'   => env('PAYMENT_LOG_DEBUG', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Policy
    |--------------------------------------------------------------------------
    |
    | Configure automatic retry behaviour for transient failures.
    | Transient failures: HTTP 429 (rate limit) and HTTP 5xx (server errors).
    | max_attempts: total number of attempts (1 = no retry).
    | delay_ms: milliseconds to wait between attempts.
    |
    */
    'retry' => [
        'enabled'      => env('PAYMENT_RETRY_ENABLED', true),
        'max_attempts' => (int) env('PAYMENT_RETRY_MAX_ATTEMPTS', 3),
        'delay_ms'     => (int) env('PAYMENT_RETRY_DELAY_MS', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    |
    | Set enabled to false to disable the framework's auto-registered webhook
    | route (useful if you want to register it manually with custom middleware).
    | The prefix controls the URL path: /payment/webhook/{driver}
    |
    */
    'webhook' => [
        'enabled'    => env('PAYMENT_WEBHOOK_ENABLED', true),
        'prefix'     => env('PAYMENT_WEBHOOK_PREFIX', 'payment/webhook'),
        'middleware' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Transaction Repository
    |--------------------------------------------------------------------------
    |
    | When enabled, successful payments are automatically persisted to the
    | payment_transactions database table. Run the migration first:
    |   php artisan vendor:publish --tag=payment-migrations
    |   php artisan migrate
    |
    */
    'repository' => [
        'enabled' => env('PAYMENT_REPOSITORY_ENABLED', false),
        'model'   => \Mifatoyeh\LaravelPaymentFramework\Repositories\PaymentTransaction::class,
    ],

];
