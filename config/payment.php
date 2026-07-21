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
    | Payable Models
    |--------------------------------------------------------------------------
    |
    | Maps the short `model_type` string a checkout request sends to the
    | actual Eloquent model class it refers to. This is a fixed, developer-
    | controlled allowlist — model_type is NEVER resolved directly to a class
    | string from request input; only keys registered here are reachable.
    | Every mapped class must implement
    | \Mifatoyeh\LaravelPaymentFramework\Contracts\Payable.
    |
    | Example:
    |   'order' => \App\Models\Order::class,
    |
    */
    'payables' => [

    ],

    /*
    |--------------------------------------------------------------------------
    | Generic Checkout Endpoint
    |--------------------------------------------------------------------------
    |
    | A single, package-owned endpoint that starts a payment for any
    | registered Payable model, without the host application needing its own
    | route/controller. Set enabled to false to disable auto-registration
    | (e.g. to register the route yourself with different middleware).
    |
    */
    'checkout' => [
        'enabled'    => env('PAYMENT_CHECKOUT_ENABLED', true),
        'route'      => env('PAYMENT_CHECKOUT_ROUTE', 'payment/checkout'),
        'middleware' => ['web', 'auth'],

        // Middleware for the auto-registered callback route
        // ({route}/callback/{driver} — see routes/callback.php). Deliberately
        // separate from `middleware` above and deliberately light by default:
        // the caller is a payment provider's redirect/server, never your
        // logged-in user, so 'auth' doesn't apply — and 'web'-group CSRF
        // would reject a provider's POST outright.
        'callback_middleware' => [],

        // When true, CheckoutService persists a checkout_transactions row for
        // every checkout attempt (pending at checkout() time) and every
        // authoritatively-verified confirmation. Requires the migration:
        //   php artisan vendor:publish --tag=payment-migrations
        //   php artisan migrate
        // Set to false if you haven't run that migration yet, or you handle
        // your own persistence via Payable::onPaymentCompleted()/CheckoutPaymentConfirmed.
        // NOTE: the callback/webhook confirmation mechanism and the
        // reconciliation sweep below both REQUIRE this to be true — there is
        // no way to correlate an inbound callback/webhook to a model without
        // a persisted row.
        'persist_transactions' => env('PAYMENT_CHECKOUT_PERSIST_TRANSACTIONS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Verification
    |--------------------------------------------------------------------------
    |
    | Backstops for confirming a checkout's outcome when nothing (a webview
    | redirect, a webhook) proactively resolves it:
    |
    | - `job`: for `driver_type: sdk` checkouts against a driver where
    |   supports('webhook') is false — see
    |   CheckoutService::driverSupportsWebhook(). A self-rescheduling
    |   VerifyPaymentJob actively re-checks status with the configured
    |   backoff, up to max_attempts or max_duration, whichever comes first.
    |   Requires a queue driver that supports delayed dispatch
    |   (redis/database/sqs) — QUEUE_CONNECTION=sync makes every attempt run
    |   immediately back-to-back, defeating the backoff entirely.
    |
    | - `sweep_interval_hours`: the universal backstop, regardless of driver
    |   or driver_type — a scheduled command re-verifies any
    |   checkout_transactions row still "pending" and older than this many
    |   hours. Catches a webhook that was supposed to arrive but didn't
    |   (provider-side outage, misconfigured dashboard URL, dropped
    |   delivery) AND a VerifyPaymentJob chain lost to a worker crash or
    |   queue flush — the sweep doesn't care which path was supposed to
    |   resolve a row, only that it didn't.
    |
    */
    'verification' => [
        'sweep_interval_hours' => (int) env('PAYMENT_SWEEP_INTERVAL_HOURS', 12),

        'job' => [
            // Seconds between VerifyPaymentJob attempts: 30s, 1m, 5m, 15m, 1h.
            // The last value repeats for any attempt beyond the list length.
            'backoff'      => [30, 60, 300, 900, 3600],
            'max_attempts' => (int) env('PAYMENT_VERIFY_JOB_MAX_ATTEMPTS', 8),
            // Seconds — 24h. Whichever of max_attempts/max_duration is hit
            // first stops the job chain; the sweep above remains as the
            // unconditional final backstop either way.
            'max_duration' => (int) env('PAYMENT_VERIFY_JOB_MAX_DURATION', 86400),
        ],
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
