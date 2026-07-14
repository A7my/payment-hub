# Requirements Document

## Introduction

The Laravel Payment Framework is an enterprise-grade, provider-agnostic payment package for Laravel 12+ and PHP 8.4+. It provides a unified, stable API that allows developers to switch between payment providers (Stripe, PayPal, MyFatoorah, Paymob, Fawry, HyperPay, Moyasar, Amazon Payment Services, Adyen, Square, Authorize.net, and others) by changing a single environment variable — without modifying application code. The framework is built on Clean Architecture, SOLID principles, and event-driven design, and it acts as the reusable core layer upon which any provider-specific driver package can be built.

---

## Glossary

- **Framework**: The Laravel Payment Framework package itself (`laravel-payment-framework`).
- **Driver**: A provider-specific adapter class that implements the `PaymentDriverContract` and translates the Framework's canonical DTOs into provider API calls and back.
- **Manager**: The `PaymentManager` class, responsible for resolving and caching Driver instances, modelled after Laravel's `CacheManager`.
- **Facade**: The `Payment` facade that proxies calls to the Manager.
- **DTO**: An immutable Data Transfer Object used to carry structured, validated data across layer boundaries.
- **Response**: A standardised, read-only value object returned by every Driver method, implementing a shared Response contract.
- **Event**: A Laravel event dispatched by the Framework at each meaningful payment lifecycle point.
- **Webhook**: An inbound HTTP callback from a payment provider carrying event notifications.
- **Money**: An immutable value object representing an amount in a specific currency, always stored in the smallest currency unit (e.g., cents).
- **Currency**: An ISO 4217 currency code backed by a PHP 8.4 `enum`.
- **PaymentStatus**: An enum representing the canonical lifecycle states of a payment transaction.
- **Environment**: An enum representing `sandbox` or `production` modes.
- **Idempotency Key**: A unique token supplied by the caller to allow safe retries without duplicate charges.
- **Token**: An opaque string representing a saved payment method or one-time payment token issued by a provider.
- **TransactionId**: A value object wrapping a provider-returned transaction identifier.
- **Webhook Signature**: A value object wrapping the raw signature header sent by a provider for webhook verification.

---

## Requirements

### Requirement 1: Driver System and Provider Abstraction

**User Story:** As a Laravel developer, I want every payment provider to implement the same interface, so that my application code never changes when I switch providers.

#### Acceptance Criteria

1. THE Framework SHALL define a `PaymentDriverContract` interface that every Driver MUST implement, declaring all payment operations as method signatures.
2. WHEN a Driver is registered, THE Manager SHALL only accept classes that implement `PaymentDriverContract`; IF a class does not implement the contract, THEN THE Manager SHALL throw a `DriverNotFoundException`.
3. THE Framework SHALL provide an abstract `AbstractDriver` base class that implements shared cross-cutting behaviour (logging, event dispatching, retry) so that concrete Drivers do not duplicate that logic.
4. WHEN a new Driver is added by a third-party package, THE Framework SHALL resolve it without any modification to Framework source files.
5. THE `PaymentDriverContract` SHALL declare methods for: `authorize`, `capture`, `charge`, `void`, `refund`, `partialRefund`, `verify`, `lookup`, `createPaymentLink`, `saveCard`, `chargeToken`, `createSubscription`, `cancelSubscription`, and `processWebhook`.

---

### Requirement 2: Configuration System

**User Story:** As a developer, I want a single, well-structured configuration file to control all Framework behaviour, so that I can change providers and settings without touching application code.

#### Acceptance Criteria

1. THE Framework SHALL publish a `config/payment.php` file containing: default driver key, per-driver credential blocks, sandbox/production toggle, supported currencies list, timeout values, logging channel, retry policy, and webhook secret keys.
2. WHEN the application sets `PAYMENT_DRIVER=stripe` in the environment, THE Manager SHALL resolve the `stripe` driver block from config without any code change.
3. WHEN a required driver configuration key is missing at resolve-time, THE Manager SHALL throw an `InvalidConfigurationException` with a descriptive message identifying the missing key.
4. THE Framework SHALL support multiple named driver instances (e.g., `stripe_usd`, `stripe_eur`) so that a single provider can be configured with different credentials simultaneously.
5. WHERE the `sandbox` flag is `true` for a driver, THE Driver SHALL target the provider's sandbox/test endpoint; WHILE `sandbox` is `false`, THE Driver SHALL target the provider's production endpoint.
6. THE Framework SHALL validate all configuration values against expected types and ranges at service-provider boot time; IF validation fails, THEN THE Framework SHALL throw `InvalidConfigurationException` before the application handles any request.

---

### Requirement 3: PaymentManager — Driver Resolution

**User Story:** As a developer, I want a Manager class similar to Laravel's `CacheManager`, so that I can fluently resolve and switch drivers at runtime.

#### Acceptance Criteria

1. THE Manager SHALL extend `Illuminate\Support\Manager` and implement driver resolution via a `createDriver(string $driver): PaymentDriverContract` method.
2. WHEN `Payment::driver('paypal')` is called, THE Manager SHALL return the configured `PayPalDriver` instance, implementing `PaymentDriverContract`.
3. THE Manager SHALL cache resolved driver instances per driver name so that the same instance is reused within a single request lifecycle.
4. WHEN `Payment::driver()` is called without arguments, THE Manager SHALL resolve the driver specified by the `default` key in `config/payment.php`.
5. THE Framework SHALL allow developers to extend the Manager with a custom driver via `Payment::extend('custom', fn($app) => new CustomDriver(...))` without modifying Framework source code.
6. THE Manager SHALL expose `getAvailableDrivers(): array` returning the list of driver keys defined in config.

---

### Requirement 4: Payment Facade

**User Story:** As a developer, I want a `Payment` facade, so that I can call payment operations with a clean, expressive API.

#### Acceptance Criteria

1. THE Framework SHALL register a `Payment` facade resolving to the `PaymentManager` from the service container.
2. WHEN `Payment::charge($request)` is called, THE Facade SHALL proxy the call to the default driver's `charge` method and return a `PaymentResponse`.
3. THE Facade SHALL expose: `charge`, `authorize`, `capture`, `void`, `refund`, `partialRefund`, `verify`, `lookup`, `createPaymentLink`, `saveCard`, `chargeToken`, `createSubscription`, `cancelSubscription`, `driver`, and `extend`.
4. THE Framework SHALL provide a `Payment::fake()` method that swaps the Manager for a `FakePaymentDriver` to facilitate testing without real provider calls.

---

### Requirement 5: Immutable DTOs

**User Story:** As a developer, I want strongly-typed, immutable DTOs to carry request data to drivers, so that I can be confident data is never mutated between layers.

#### Acceptance Criteria

1. THE Framework SHALL provide immutable DTO classes using PHP 8.4 readonly properties for: `PaymentRequest`, `RefundRequest`, `CaptureRequest`, `VoidRequest`, `WebhookRequest`, `SubscriptionRequest`, `PaymentLinkRequest`, `TokenChargeRequest`, `SaveCardRequest`, `TransactionLookupRequest`.
2. WHEN a DTO is instantiated with an invalid value (e.g., negative amount, null required field), THE DTO SHALL throw an `\InvalidArgumentException` with a descriptive message.
3. THE `PaymentRequest` DTO SHALL carry: `Money $amount`, `Currency $currency`, `string $idempotencyKey`, `CustomerData $customer`, `?OrderData $order`, `?AddressData $billingAddress`, `?string $returnUrl`, `?string $cancelUrl`, `array $metadata`, and `?Token $token`.
4. THE `RefundRequest` DTO SHALL carry: `TransactionId $transactionId`, `Money $amount`, `string $reason`, `string $idempotencyKey`, and `array $metadata`.
5. THE `Money` value object SHALL enforce that the `amount` is a non-negative integer (smallest currency unit) and expose `add(Money $other): Money`, `subtract(Money $other): Money`, and `equals(Money $other): bool` methods returning new immutable instances.
6. THE Framework SHALL provide `CustomerData`, `AddressData`, and `OrderData` immutable DTOs carrying structured, validated fields.
7. WHERE two `Money` instances have different `Currency` values, THE `Money` value object SHALL throw a `\InvalidArgumentException` on arithmetic operations.

---

### Requirement 6: Standardised Response Objects

**User Story:** As a developer, I want every driver method to return the same response types regardless of provider, so that my application code processes results identically for all providers.

#### Acceptance Criteria

1. THE Framework SHALL define response contracts: `PaymentResponseContract`, `RefundResponseContract`, `CaptureResponseContract`, `WebhookResponseContract`, `StatusResponseContract`, `VerificationResponseContract`, `SubscriptionResponseContract`, `PaymentLinkResponseContract`.
2. EVERY Driver method SHALL return an object implementing the corresponding response contract; IF a provider API call fails, THEN THE Driver SHALL return a response with `isSuccessful(): bool` returning `false` rather than throwing by default, unless the operation is unrecoverable.
3. THE `PaymentResponse` SHALL expose: `isSuccessful(): bool`, `getTransactionId(): TransactionId`, `getStatus(): PaymentStatus`, `getProviderReference(): string`, `getAmount(): Money`, `getRawResponse(): array`, and `getMessage(): string`.
4. THE `RefundResponse` SHALL expose: `isSuccessful(): bool`, `getRefundId(): string`, `getAmount(): Money`, `getStatus(): PaymentStatus`, and `getMessage(): string`.
5. THE Framework SHALL ensure all response objects are immutable (readonly properties) and carry the raw provider response for debugging.

---

### Requirement 7: Payment Lifecycle Events

**User Story:** As a developer, I want the Framework to dispatch Laravel events at every meaningful payment lifecycle step, so that I can hook into payments without modifying Framework or Driver code.

#### Acceptance Criteria

1. THE Framework SHALL dispatch the following events at the corresponding lifecycle points: `PaymentInitiated` (before driver call), `PaymentSucceeded`, `PaymentFailed`, `PaymentCaptured`, `PaymentRefunded`, `PaymentVoided`, `PaymentLinkCreated`, `CardSaved`, `TokenCharged`, `WebhookReceived` (on inbound webhook), `WebhookProcessed` (after processing), `SubscriptionCreated`, `SubscriptionCancelled`, `TransactionLookuped`.
2. WHEN an event is dispatched, THE Framework SHALL include the relevant DTO and Response (where available) as event properties.
3. THE Framework SHALL dispatch `PaymentFailed` regardless of whether the failure originated from a provider API error or a local validation error.
4. WHERE a developer registers a listener for `PaymentSucceeded`, THE listener SHALL receive the `PaymentResponse` and the original `PaymentRequest` without any additional Framework configuration.
5. THE Framework SHALL use Laravel's standard event dispatcher so that developers can use `Event::fake()` in tests.

---

### Requirement 8: Exception Hierarchy

**User Story:** As a developer, I want a well-structured exception hierarchy, so that I can catch payment-related errors at the appropriate granularity.

#### Acceptance Criteria

1. THE Framework SHALL define a base `PaymentException` extending `\RuntimeException` that all Framework-thrown exceptions extend.
2. THE Framework SHALL provide the following exception classes, each extending `PaymentException`: `DriverNotFoundException`, `InvalidConfigurationException`, `WebhookVerificationException`, `RefundFailedException`, `CaptureFailedException`, `VoidFailedException`, `AuthorizationFailedException`, `SubscriptionException`, `IdempotencyException`, `UnsupportedOperationException`.
3. WHEN a driver is requested by name and no matching driver key exists in config, THE Manager SHALL throw `DriverNotFoundException` with the requested driver name in the message.
4. WHEN a webhook signature fails verification, THE Webhook processor SHALL throw `WebhookVerificationException`.
5. WHEN `UnsupportedOperationException` is thrown, THE exception message SHALL state the operation name and the driver name so the developer knows which capability is missing.

---

### Requirement 9: Enums

**User Story:** As a developer, I want PHP 8.4 backed enums for all categorical values, so that I get type safety and IDE autocompletion throughout the payment workflow.

#### Acceptance Criteria

1. THE Framework SHALL provide a `PaymentStatus` backed string enum with cases: `Pending`, `Authorized`, `Captured`, `Failed`, `Voided`, `Refunded`, `PartiallyRefunded`, `Cancelled`, `Expired`, `RequiresAction`.
2. THE Framework SHALL provide a `Currency` backed string enum covering at minimum the ISO 4217 codes: `USD`, `EUR`, `GBP`, `SAR`, `AED`, `EGP`, `KWD`, `BHD`, `OMR`, `QAR`, `JOD`.
3. THE Framework SHALL provide an `Environment` backed string enum with cases: `Sandbox`, `Production`.
4. THE Framework SHALL provide a `PaymentMethod` backed string enum with cases: `Card`, `BankTransfer`, `Wallet`, `PaymentLink`, `Token`, `QrCode`, `Installment`, `BuyNowPayLater`.
5. THE Framework SHALL provide a `TransactionType` backed string enum with cases: `Charge`, `Authorization`, `Capture`, `Refund`, `PartialRefund`, `Void`, `Subscription`, `TokenCharge`.
6. THE Framework SHALL provide a `WebhookEventType` backed string enum with cases: `PaymentSucceeded`, `PaymentFailed`, `RefundProcessed`, `DisputeOpened`, `SubscriptionRenewed`, `SubscriptionCancelled`, `CardSaved`, `Unknown`.

---

### Requirement 10: Value Objects

**User Story:** As a developer, I want typed value objects for domain primitives, so that raw strings and integers cannot be passed where structured values are expected.

#### Acceptance Criteria

1. THE Framework SHALL provide a `Money` value object with readonly `int $amount` (smallest unit) and `Currency $currency`, and named constructor `Money::of(int $amount, Currency $currency): Money`.
2. THE Framework SHALL provide a `TransactionId` value object wrapping a non-empty string, with `TransactionId::fromString(string $id): TransactionId` and `toString(): string` methods.
3. THE Framework SHALL provide a `CustomerId` value object wrapping a non-empty string, following the same pattern as `TransactionId`.
4. THE Framework SHALL provide an `OrderId` value object wrapping a non-empty string, following the same pattern as `TransactionId`.
5. THE Framework SHALL provide a `WebhookSignature` value object wrapping the raw signature string received in the webhook request header.
6. THE Framework SHALL provide a `Token` value object wrapping a non-empty provider-issued token string.
7. WHEN any value object is constructed with an empty or null value where a non-empty value is required, THE value object constructor SHALL throw `\InvalidArgumentException`.

---

### Requirement 11: Webhook Processing

**User Story:** As a developer, I want a provider-agnostic webhook routing and processing system, so that I can receive and handle webhook events from any provider through a single endpoint.

#### Acceptance Criteria

1. THE Framework SHALL register a route `POST /payment/webhook/{driver}` that routes inbound webhooks to the correct Driver's `processWebhook` method based on the `{driver}` path segment.
2. WHEN a webhook request arrives, THE WebhookController SHALL construct a `WebhookRequest` DTO from the raw HTTP request body, headers, and driver name, then dispatch it to the Driver.
3. THE `processWebhook` method on `PaymentDriverContract` SHALL accept a `WebhookRequest` DTO and return a `WebhookResponse`.
4. WHEN webhook signature verification fails for any driver, THE Framework SHALL throw `WebhookVerificationException` and return an HTTP 400 response.
5. THE Framework SHALL dispatch `WebhookReceived` before processing and `WebhookProcessed` after a successful `processWebhook` call.
6. THE Framework SHALL allow the webhook route prefix to be customised via the config `payment.webhook.prefix` key.
7. WHERE a developer needs to disable the Framework's auto-registered webhook route, THE Framework config SHALL provide a `payment.webhook.enabled` boolean flag.

---

### Requirement 12: Logging Abstraction

**User Story:** As a developer, I want a pluggable logging system, so that I can route payment logs to any destination without coupling the Framework to a specific logger.

#### Acceptance Criteria

1. THE Framework SHALL define a `PaymentLoggerContract` interface with methods: `info(string $message, array $context): void`, `error(string $message, array $context): void`, `debug(string $message, array $context): void`, `warning(string $message, array $context): void`.
2. THE Framework SHALL provide four Logger implementations: `LaravelLogger` (wraps `Illuminate\Log\LogManager`), `NullLogger` (discards all messages), `DebugLogger` (writes to a dedicated debug log channel), `StackLogger` (forwards to multiple loggers).
3. WHEN `payment.logging.enabled` is `false` in config, THE Framework SHALL bind `NullLogger` as the `PaymentLoggerContract` implementation.
4. WHEN `payment.logging.channel` is set to a valid Laravel log channel name, THE `LaravelLogger` SHALL write to that channel.
5. THE Framework SHALL log every outbound driver method call and its result at `info` level, and every exception at `error` level, using the bound `PaymentLoggerContract`.
6. WHERE `payment.logging.debug` is `true`, THE Framework SHALL additionally log raw request and response payloads at `debug` level.

---

### Requirement 13: Retry and Idempotency

**User Story:** As a developer, I want built-in retry logic and idempotency key support, so that transient failures are handled gracefully and duplicate charges are prevented.

#### Acceptance Criteria

1. THE Framework SHALL accept an `idempotencyKey` string on all `PaymentRequest` and `RefundRequest` DTOs and forward it to the Driver.
2. THE `AbstractDriver` SHALL expose a `withRetry(callable $operation, int $maxAttempts, int $delayMs): mixed` helper that retries the callable on transient exceptions up to `maxAttempts` times.
3. WHEN `payment.retry.enabled` is `true` and a transient exception occurs, THE `AbstractDriver` SHALL retry the operation the number of times specified by `payment.retry.max_attempts` with the delay specified by `payment.retry.delay_ms`.
4. WHEN all retry attempts are exhausted without success, THE `AbstractDriver` SHALL dispatch `PaymentFailed` and rethrow the last exception wrapped in the appropriate `PaymentException` subclass.
5. THE Framework SHALL treat HTTP 429 (rate limit) and HTTP 5xx responses from providers as transient and eligible for retry; all other HTTP errors SHALL be treated as non-retryable.
6. IF an `idempotencyKey` is empty or missing on a `PaymentRequest`, THEN THE Framework SHALL throw `IdempotencyException` before invoking the Driver.

---

### Requirement 14: Multi-Currency Support

**User Story:** As a developer, I want built-in currency handling, so that amounts are always correctly represented and never suffer from floating-point errors.

#### Acceptance Criteria

1. THE `Money` value object SHALL store amounts exclusively as non-negative integers in the smallest currency unit (e.g., cents for USD, fils for KWD).
2. THE Framework SHALL provide a `CurrencyConverter` contract (`CurrencyConverterContract`) with method `convert(Money $from, Currency $to, float $rate): Money`.
3. THE `Money::format(string $locale): string` method SHALL return a locale-formatted string representation of the amount (e.g., "USD 10.00").
4. WHEN a Driver receives a `Money` amount in a currency not supported by that provider, THE Driver SHALL throw `UnsupportedOperationException` with the unsupported currency identified in the message.
5. THE Framework config `payment.currencies` SHALL list the currencies enabled for the application; IF a `Money` value object is constructed with a `Currency` not in that list, THEN THE Framework SHALL throw `\InvalidArgumentException`.

---

### Requirement 15: Testing Support — Fake Driver

**User Story:** As a developer, I want a fake/mock driver and testing helpers, so that I can write fast, isolated unit and feature tests without hitting real provider APIs.

#### Acceptance Criteria

1. THE Framework SHALL provide a `FakePaymentDriver` implementing `PaymentDriverContract` that returns configurable successful or failed responses.
2. WHEN `Payment::fake()` is called in a test, THE Framework SHALL replace the Manager's default driver with `FakePaymentDriver` for the duration of the test.
3. THE `FakePaymentDriver` SHALL expose `assertCharged(Money $amount): void`, `assertRefunded(TransactionId $id): void`, `assertNotCharged(): void`, `assertEventDispatched(string $eventClass): void` assertion helpers.
4. THE Framework SHALL be compatible with Laravel's `Event::fake()` so that all dispatched payment events can be asserted without real side effects.
5. THE Framework SHALL provide a `PaymentFactory` (in the test support namespace) for generating valid `PaymentRequest` and `RefundRequest` DTOs with sensible defaults using a fluent builder pattern.

---

### Requirement 16: Service Provider and Package Discovery

**User Story:** As a developer, I want the Framework to integrate seamlessly with Laravel's package auto-discovery, so that no manual registration is needed.

#### Acceptance Criteria

1. THE Framework SHALL define a `PaymentServiceProvider` that: binds `PaymentManager` into the container, registers the `Payment` facade alias, merges the default config, registers the webhook route (if enabled), and binds all contracts to their default implementations.
2. THE `PaymentServiceProvider` SHALL be declared in `composer.json` under `extra.laravel.providers` so that Laravel auto-discovers it.
3. THE Framework SHALL publish assets via `php artisan vendor:publish --tag=payment-config` for the config file and `--tag=payment-migrations` for any required database migrations.
4. WHEN `php artisan vendor:publish --tag=payment-config` is executed, THE Framework SHALL copy `config/payment.php` to the host application's `config/` directory without overwriting an existing file unless `--force` is used.

---

### Requirement 17: Subscription and Recurring Payment Support

**User Story:** As a developer, I want the Framework to model recurring payments and subscriptions as first-class concepts, so that subscription lifecycle can be handled uniformly across providers.

#### Acceptance Criteria

1. THE Framework SHALL define `createSubscription(SubscriptionRequest $request): SubscriptionResponse` and `cancelSubscription(TransactionId $subscriptionId): SubscriptionResponse` on `PaymentDriverContract`.
2. THE `SubscriptionRequest` DTO SHALL carry: `Money $amount`, `Currency $currency`, `string $interval` (daily/weekly/monthly/yearly), `int $intervalCount`, `?int $trialDays`, `CustomerData $customer`, `?string $planId`, `string $idempotencyKey`, and `array $metadata`.
3. THE `SubscriptionResponse` SHALL expose: `isSuccessful(): bool`, `getSubscriptionId(): string`, `getStatus(): PaymentStatus`, `getNextBillingDate(): ?\DateTimeImmutable`, and `getMessage(): string`.
4. WHEN `createSubscription` is called, THE Framework SHALL dispatch `SubscriptionCreated` on success and `PaymentFailed` on failure.
5. WHEN `cancelSubscription` is called, THE Framework SHALL dispatch `SubscriptionCancelled` on success.

---

### Requirement 18: Extensibility for Future Payment Methods

**User Story:** As an architect, I want the Framework to be designed so that adding Apple Pay, Google Pay, BNPL, Crypto, Bank Transfer, Wallets, QR Payments, or Installments requires zero changes to Framework core, so that the Open/Closed Principle is strictly upheld.

#### Acceptance Criteria

1. THE `PaymentMethod` enum SHALL include future-oriented cases (`BuyNowPayLater`, `QrCode`, `Installment`, `Wallet`) so that Drivers can declare support without enum modification being required for common future methods.
2. WHEN a Driver does not support an operation (e.g., `createSubscription` for a QR-only provider), THE Driver SHALL throw `UnsupportedOperationException` rather than return an empty or null response.
3. THE Framework SHALL define a `SupportsCapabilities` interface with method `supports(string $capability): bool` that any Driver MAY optionally implement to allow runtime capability detection.
4. WHERE a developer needs to add a payment method not represented in the `PaymentMethod` enum, THE Framework documentation SHALL define the process for proposing an enum extension via a minor version release.

---

### Requirement 19: Database Support — Transaction Repository

**User Story:** As a developer, I want an optional transaction persistence layer, so that I can store and query payment transaction records without building my own schema.

#### Acceptance Criteria

1. THE Framework SHALL provide a `PaymentTransactionRepository` contract with methods: `store(PaymentResponse $response, PaymentRequest $request): void`, `findByTransactionId(TransactionId $id): ?array`, `findByOrderId(OrderId $id): array`, `findByCustomerId(CustomerId $id): array`.
2. THE Framework SHALL publish a migration for a `payment_transactions` table with columns: `id`, `transaction_id`, `driver`, `order_id`, `customer_id`, `amount`, `currency`, `status`, `payment_method`, `metadata` (JSON), `raw_response` (JSON), `idempotency_key`, `created_at`, `updated_at`.
3. WHEN `payment.repository.enabled` is `true`, THE `PaymentServiceProvider` SHALL bind `EloquentPaymentTransactionRepository` as the `PaymentTransactionRepository` implementation and register a listener that calls `store()` on `PaymentSucceeded` events.
4. WHERE `payment.repository.enabled` is `false`, THE Framework SHALL bind a `NullPaymentTransactionRepository` that silently discards all calls.
5. THE Framework SHALL provide a `Payment` Eloquent model in the `Repositories` namespace mapped to the `payment_transactions` table.

---

### Requirement 20: Security — Webhook Signature Verification

**User Story:** As a developer, I want the Framework to enforce webhook signature verification, so that only authentic provider callbacks are processed.

#### Acceptance Criteria

1. THE `PaymentDriverContract` SHALL declare a `verifyWebhookSignature(WebhookRequest $request): bool` method that every Driver MUST implement.
2. WHEN a webhook request arrives at `POST /payment/webhook/{driver}`, THE WebhookController SHALL call `verifyWebhookSignature` before calling `processWebhook`; IF verification returns `false`, THEN THE controller SHALL throw `WebhookVerificationException` and return HTTP 400.
3. THE Framework SHALL provide a `WebhookVerifier` service that wraps the Driver's `verifyWebhookSignature` call and logs the result via `PaymentLoggerContract`.
4. THE Framework SHALL store webhook secrets per-driver in config under `payment.drivers.{driver}.webhook_secret`, never hardcoding secrets in source files.
5. WHEN `WebhookVerificationException` is thrown, THE Framework SHALL log the event at `error` level including the driver name and the raw signature header value (truncated to 32 characters for security).
