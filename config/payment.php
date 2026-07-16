<?php

declare(strict_types=1);

use Mifatoyeh\LaravelPaymentFramework\Drivers\Paymob\PaymobDriver;
use Mifatoyeh\LaravelPaymentFramework\Drivers\Stripe\StripeDriver;

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
    | Each driver block configures a specific payment provider. The driver
    | `class` is a built-in implementation detail of this package — it is
    | NOT configurable via .env. Only credentials and behavioural settings
    | (sandbox, timeout) are.
    |
    | Required keys per driver: class, webhook_secret
    | Optional keys: sandbox, timeout
    |
    */
    'drivers' => [

        'stripe' => [
            // The built-in Stripe driver implementation. Not configurable —
            // driver classes are an internal detail of this package.
            'class'          => StripeDriver::class,

            // Provider API credentials — never hardcode these values.
            'key'            => env('STRIPE_KEY'),
            'secret'         => env('STRIPE_SECRET'),

            // Webhook secret for signature verification.
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

            // When true, the driver targets the provider's sandbox/test endpoint.
            'sandbox'        => env('PAYMENT_SANDBOX', true),

            // HTTP request timeout in seconds.
            'timeout'        => (int) env('PAYMENT_TIMEOUT', 30),
        ],

        'paymob' => [
            // The built-in Paymob driver implementation. Not configurable —
            // driver classes are an internal detail of this package.
            'class'          => PaymobDriver::class,

            // Paymob Egypt/Accept legacy API key — POSTed to /auth/tokens to
            // obtain a short-lived auth token for every request sequence.
            // Not used in KSA mode (see secret_key below).
            'api_key'        => env('PAYMOB_API_KEY'),

            // Paymob KSA static secret key (Developers > API Keys on the KSA
            // dashboard). Values begin with 'sau_sk_test_' (sandbox) or
            // 'sau_sk_live_' (production). When this key is present with either
            // prefix — OR when base_url contains 'ksa.paymob.com' — the driver
            // automatically activates KSA mode: it skips /auth/tokens entirely
            // and attaches Authorization: Bearer <secret_key> to every request.
            'secret_key'     => env('PAYMOB_SECRET_KEY'),

            // Paymob KSA public key — used to build the hosted checkout URL.
            'public_key'     => env('PAYMOB_PUBLIC_KEY'),

            // Paymob "Integration ID" for the online-card payment method
            // (dashboard: Developers > Payment Integrations). Required for
            // every payment-key request.
            'integration_id' => env('PAYMOB_INTEGRATION_ID'),

            // Paymob "Iframe ID" (dashboard: Developers > iFrames) — used to
            // build the hosted checkout URL for createPaymentLink().
            'iframe_id'      => env('PAYMOB_IFRAME_ID'),

            // HMAC secret (dashboard: Developers > Payment Integrations) for
            // webhook signature verification. Not yet used — webhooks are
            // not implemented for this driver.
            'hmac_secret'    => env('PAYMOB_HMAC_SECRET'),

            // Base API URL — override for a different Paymob region
            // (e.g. https://ksa.paymob.com/api for Saudi Arabia).
            'base_url'       => env('PAYMOB_BASE_URL', 'https://accept.paymob.com/api'),

            // When true, the driver still targets the same base_url — Paymob
            // has no separate sandbox host; test-mode behaviour is controlled
            // by which API keys/integration IDs you configure (test vs. live
            // credentials from the Paymob dashboard). Kept for interface
            // consistency with other drivers and for driver-side logging.
            'sandbox'        => env('PAYMENT_SANDBOX', true),

            // HTTP request timeout in seconds.
            'timeout'        => (int) env('PAYMENT_TIMEOUT', 30),
        ],

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
    ],

];
