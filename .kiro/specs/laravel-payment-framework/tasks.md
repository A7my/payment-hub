# Implementation Plan: Laravel Payment Framework

## Overview

Build the complete skeleton of the `mifatoyeh/laravel-payment-framework` Composer package for Laravel 12+ / PHP 8.4+. Every phase produces PHP files with `declare(strict_types=1)`, proper namespaces, full PHPDoc, and `// TODO` stubs — no real payment logic, no provider implementation. Each phase builds on the previous so the package compiles cleanly at every checkpoint.

**Language:** PHP 8.4+  
**Framework:** Laravel 12+  
**Testing library for property tests:** `innmind/black-box`

## Tasks

- [x] 1. Package scaffolding — composer.json and directory structure
  - Create `composer.json` with package name `mifatoyeh/laravel-payment-framework`, `"type": "library"`, PHP `^8.4` and `laravel/framework ^12.0` requirements, PSR-4 autoload for `Mifatoyeh\\LaravelPaymentFramework\\` → `src/` and `Mifatoyeh\\LaravelPaymentFramework\\Tests\\` → `tests/`, Laravel auto-discovery entries for `PaymentServiceProvider` and the `Payment` facade alias, and a `require-dev` block for `phpunit/phpunit`, `innmind/black-box`, and `orchestra/testbench`
  - Create all directories from the design's directory tree: `config/`, `database/migrations/`, `routes/`, `src/Contracts/Drivers/`, `src/Contracts/Responses/`, `src/Contracts/Logging/`, `src/Contracts/Repositories/`, `src/Contracts/Currency/`, `src/DTO/`, `src/Drivers/`, `src/Enums/`, `src/Events/`, `src/Exceptions/`, `src/Facades/`, `src/Logging/`, `src/Managers/`, `src/Providers/`, `src/Repositories/`, `src/Responses/`, `src/Services/`, `src/Testing/`, `src/ValueObjects/`, `src/Webhooks/`, `tests/Unit/ValueObjects/`, `tests/Unit/Enums/`, `tests/Unit/DTO/`, `tests/Unit/Managers/`, `tests/Unit/Drivers/`, `tests/Unit/Services/`, `tests/Unit/Testing/`, `tests/Unit/Exceptions/`, `tests/Feature/`
  - Create `phpunit.xml` with testsuites for Unit and Feature, bootstrap pointing to `vendor/autoload.php`
  - _Requirements: 16.1, 16.2_


- [x] 2. Enums
  - [x] 2.1 Create `src/Enums/PaymentStatus.php` — backed string enum with 10 cases: `Pending` (`"pending"`), `Authorized` (`"authorized"`), `Captured` (`"captured"`), `Failed` (`"failed"`), `Voided` (`"voided"`), `Refunded` (`"refunded"`), `PartiallyRefunded` (`"partially_refunded"`), `Cancelled` (`"cancelled"`), `Expired` (`"expired"`), `RequiresAction` (`"requires_action"`); include `declare(strict_types=1)`, namespace, and PHPDoc
    - _Requirements: 9.1_
  - [x] 2.2 Create `src/Enums/Currency.php` — backed string enum with ISO 4217 cases: `USD`, `EUR`, `GBP`, `SAR`, `AED`, `EGP`, `KWD`, `BHD`, `OMR`, `QAR`, `JOD`
    - _Requirements: 9.2_
  - [x] 2.3 Create `src/Enums/Environment.php` — backed string enum with cases `Sandbox` (`"sandbox"`) and `Production` (`"production"`)
    - _Requirements: 9.3_
  - [x] 2.4 Create `src/Enums/PaymentMethod.php` — backed string enum with cases: `Card` (`"card"`), `BankTransfer` (`"bank_transfer"`), `Wallet` (`"wallet"`), `PaymentLink` (`"payment_link"`), `Token` (`"token"`), `QrCode` (`"qr_code"`), `Installment` (`"installment"`), `BuyNowPayLater` (`"buy_now_pay_later"`)
    - _Requirements: 9.4, 18.1_
  - [x] 2.5 Create `src/Enums/TransactionType.php` — backed string enum with cases: `Charge` (`"charge"`), `Authorization` (`"authorization"`), `Capture` (`"capture"`), `Refund` (`"refund"`), `PartialRefund` (`"partial_refund"`), `Void` (`"void"`), `Subscription` (`"subscription"`), `TokenCharge` (`"token_charge"`)
    - _Requirements: 9.5_
  - [x] 2.6 Create `src/Enums/WebhookEventType.php` — backed string enum with cases: `PaymentSucceeded` (`"payment.succeeded"`), `PaymentFailed` (`"payment.failed"`), `RefundProcessed` (`"refund.processed"`), `DisputeOpened` (`"dispute.opened"`), `SubscriptionRenewed` (`"subscription.renewed"`), `SubscriptionCancelled` (`"subscription.cancelled"`), `CardSaved` (`"card.saved"`), `Unknown` (`"unknown"`)
    - _Requirements: 9.6_

- [x] 3. Value Objects
  - [x] 3.1 Create `src/ValueObjects/Money.php` — final class, readonly properties `int $amount` (non-negative) and `Currency $currency`; static named constructor `Money::of(int $amount, Currency $currency): Money`; methods `add(Money $other): Money`, `subtract(Money $other): Money`, `equals(Money $other): bool`, `format(string $locale): string`; all methods contain only `// TODO` bodies; constructor throws `\InvalidArgumentException` if `$amount < 0`; `add`/`subtract` throw `\InvalidArgumentException` if currencies differ
    - _Requirements: 5.5, 10.1, 14.1, 14.3_
  - [x] 3.2 Create `src/ValueObjects/TransactionId.php` — final class, readonly `string $value` (non-empty); static `fromString(string $id): TransactionId`; `toString(): string`; constructor throws `\InvalidArgumentException` on empty string
    - _Requirements: 10.2, 10.7_
  - [x] 3.3 Create `src/ValueObjects/CustomerId.php` — same pattern as `TransactionId`; readonly `string $value`; static `fromString`; `toString`; throws on empty
    - _Requirements: 10.3, 10.7_
  - [x] 3.4 Create `src/ValueObjects/OrderId.php` — same pattern; readonly `string $value`; static `fromString`; `toString`; throws on empty
    - _Requirements: 10.4, 10.7_
  - [x] 3.5 Create `src/ValueObjects/WebhookSignature.php` — final class, readonly `string $value` (may be empty — verified downstream); static `fromString(string $signature): WebhookSignature`; `toString(): string`
    - _Requirements: 10.5_
  - [x] 3.6 Create `src/ValueObjects/Token.php` — final class, readonly `string $value` (non-empty); static `fromString(string $token): Token`; `toString(): string`; throws on empty
    - _Requirements: 10.6, 10.7_


- [x] 4. Contracts / Interfaces
  - [x] 4.1 Create `src/Contracts/Drivers/PaymentDriverContract.php` — interface declaring all 15 method signatures: `authorize(PaymentRequest): PaymentResponse`, `capture(CaptureRequest): CaptureResponse`, `charge(PaymentRequest): PaymentResponse`, `void(VoidRequest): VoidResponse`, `refund(RefundRequest): RefundResponse`, `partialRefund(RefundRequest): RefundResponse`, `verify(TransactionLookupRequest): VerificationResponse`, `lookup(TransactionLookupRequest): StatusResponse`, `createPaymentLink(PaymentLinkRequest): PaymentLinkResponse`, `saveCard(SaveCardRequest): PaymentResponse`, `chargeToken(TokenChargeRequest): PaymentResponse`, `createSubscription(SubscriptionRequest): SubscriptionResponse`, `cancelSubscription(TransactionId): SubscriptionResponse`, `processWebhook(WebhookRequest): WebhookResponse`, `verifyWebhookSignature(WebhookRequest): bool`
    - _Requirements: 1.1, 1.5, 17.1, 20.1_
  - [x] 4.2 Create `src/Contracts/Drivers/SupportsCapabilities.php` — interface with single method `supports(string $capability): bool`
    - _Requirements: 18.3_
  - [x] 4.3 Create `src/Contracts/Responses/PaymentResponseContract.php` — interface declaring: `isSuccessful(): bool`, `getTransactionId(): TransactionId`, `getStatus(): PaymentStatus`, `getProviderReference(): string`, `getAmount(): Money`, `getRawResponse(): array`, `getMessage(): string`
    - _Requirements: 6.1, 6.3_
  - [x] 4.4 Create `src/Contracts/Responses/RefundResponseContract.php` — interface: `isSuccessful(): bool`, `getRefundId(): string`, `getAmount(): Money`, `getStatus(): PaymentStatus`, `getMessage(): string`
    - _Requirements: 6.1, 6.4_
  - [x] 4.5 Create `src/Contracts/Responses/CaptureResponseContract.php` — interface: `isSuccessful(): bool`, `getCaptureId(): string`, `getAmount(): Money`, `getStatus(): PaymentStatus`, `getMessage(): string`
    - _Requirements: 6.1_
  - [x] 4.6 Create `src/Contracts/Responses/WebhookResponseContract.php` — interface: `isSuccessful(): bool`, `getEventType(): WebhookEventType`, `getMessage(): string`, `getRawPayload(): array`
    - _Requirements: 6.1_
  - [x] 4.7 Create `src/Contracts/Responses/StatusResponseContract.php` — interface: `isSuccessful(): bool`, `getTransactionId(): TransactionId`, `getStatus(): PaymentStatus`, `getMessage(): string`
    - _Requirements: 6.1_
  - [x] 4.8 Create `src/Contracts/Responses/VerificationResponseContract.php` — interface: `isSuccessful(): bool`, `isVerified(): bool`, `getTransactionId(): TransactionId`, `getMessage(): string`
    - _Requirements: 6.1_
  - [x] 4.9 Create `src/Contracts/Responses/SubscriptionResponseContract.php` — interface: `isSuccessful(): bool`, `getSubscriptionId(): string`, `getStatus(): PaymentStatus`, `getNextBillingDate(): ?\DateTimeImmutable`, `getMessage(): string`
    - _Requirements: 6.1, 17.3_
  - [x] 4.10 Create `src/Contracts/Responses/PaymentLinkResponseContract.php` — interface: `isSuccessful(): bool`, `getPaymentUrl(): string`, `getLinkId(): string`, `getExpiresAt(): ?\DateTimeImmutable`, `getMessage(): string`
    - _Requirements: 6.1_
  - [x] 4.11 Create `src/Contracts/Logging/PaymentLoggerContract.php` — interface with methods: `info(string $message, array $context = []): void`, `error(string $message, array $context = []): void`, `debug(string $message, array $context = []): void`, `warning(string $message, array $context = []): void`
    - _Requirements: 12.1_
  - [x] 4.12 Create `src/Contracts/Repositories/PaymentTransactionRepositoryContract.php` — interface with methods: `store(PaymentResponse $response, PaymentRequest $request): void`, `findByTransactionId(TransactionId $id): ?array`, `findByOrderId(OrderId $id): array`, `findByCustomerId(CustomerId $id): array`
    - _Requirements: 19.1_
  - [x] 4.13 Create `src/Contracts/Currency/CurrencyConverterContract.php` — interface with method `convert(Money $from, Currency $to, float $rate): Money`
    - _Requirements: 14.2_


- [x] 5. DTOs
  - [x] 5.1 Create `src/DTO/CustomerData.php` — final readonly class: `string $name`, `string $email`, `?string $phone`, `?string $externalId`; constructor validates `$name` and `$email` are non-empty, throws `\InvalidArgumentException` on violation
    - _Requirements: 5.6_
  - [x] 5.2 Create `src/DTO/AddressData.php` — final readonly class: `string $line1`, `?string $line2`, `string $city`, `?string $state`, `string $country` (ISO 3166-1 alpha-2), `string $postalCode`; constructor validates required string fields are non-empty
    - _Requirements: 5.6_
  - [x] 5.3 Create `src/DTO/OrderData.php` — final readonly class: `OrderId $orderId`, `string $description`, `array $items`, `array $metadata`
    - _Requirements: 5.6_
  - [x] 5.4 Create `src/DTO/PaymentRequest.php` — final readonly class: `Money $amount`, `Currency $currency`, `string $idempotencyKey` (non-empty), `CustomerData $customer`, `?OrderData $order`, `?AddressData $billingAddress`, `?string $returnUrl`, `?string $cancelUrl`, `array $metadata`, `?Token $token`, `PaymentMethod $paymentMethod`; constructor throws `\InvalidArgumentException` on empty `$idempotencyKey`
    - _Requirements: 5.1, 5.3_
  - [x] 5.5 Create `src/DTO/RefundRequest.php` — final readonly class: `TransactionId $transactionId`, `Money $amount`, `string $reason`, `string $idempotencyKey` (non-empty), `array $metadata`; throws on empty idempotency key
    - _Requirements: 5.1, 5.4_
  - [x] 5.6 Create `src/DTO/CaptureRequest.php` — final readonly class: `TransactionId $transactionId`, `Money $amount`, `string $idempotencyKey`, `array $metadata`
    - _Requirements: 5.1_
  - [x] 5.7 Create `src/DTO/VoidRequest.php` — final readonly class: `TransactionId $transactionId`, `string $reason`, `string $idempotencyKey`, `array $metadata`
    - _Requirements: 5.1_
  - [x] 5.8 Create `src/DTO/WebhookRequest.php` — final readonly class: `string $driver`, `string $rawBody`, `array $headers`, `WebhookSignature $signature`, `array $metadata`
    - _Requirements: 5.1, 11.2_
  - [x] 5.9 Create `src/DTO/SubscriptionRequest.php` — final readonly class: `Money $amount`, `Currency $currency`, `string $interval` (daily/weekly/monthly/yearly), `int $intervalCount`, `?int $trialDays`, `CustomerData $customer`, `?string $planId`, `string $idempotencyKey`, `array $metadata`; constructor validates `$interval` is one of the allowed values
    - _Requirements: 5.1, 17.2_
  - [x] 5.10 Create `src/DTO/PaymentLinkRequest.php` — final readonly class: `Money $amount`, `Currency $currency`, `string $description`, `?CustomerData $customer`, `?string $returnUrl`, `?string $cancelUrl`, `?\DateTimeImmutable $expiresAt`, `string $idempotencyKey`, `array $metadata`
    - _Requirements: 5.1_
  - [x] 5.11 Create `src/DTO/TokenChargeRequest.php` — final readonly class: `Token $token`, `Money $amount`, `Currency $currency`, `string $idempotencyKey`, `CustomerData $customer`, `array $metadata`
    - _Requirements: 5.1_
  - [x] 5.12 Create `src/DTO/SaveCardRequest.php` — final readonly class: `Token $token`, `CustomerId $customerId`, `string $idempotencyKey`, `array $metadata`
    - _Requirements: 5.1_
  - [x] 5.13 Create `src/DTO/TransactionLookupRequest.php` — final readonly class: `TransactionId $transactionId`, `array $metadata`
    - _Requirements: 5.1_

- [x] 6. Exceptions
  - [x] 6.1 Create `src/Exceptions/PaymentException.php` — base exception class extending `\RuntimeException`; include `declare(strict_types=1)`, namespace, PHPDoc; body is a `// TODO` stub
    - _Requirements: 8.1_
  - [x] 6.2 Create `src/Exceptions/DriverNotFoundException.php` — extends `PaymentException`; include a static named constructor `forDriver(string $driver): self` that returns a new instance with the driver name in the message
    - _Requirements: 8.2, 8.3_
  - [x] 6.3 Create `src/Exceptions/InvalidConfigurationException.php` — extends `PaymentException`; include static `forMissingKey(string $key): self`
    - _Requirements: 8.2, 2.3_
  - [x] 6.4 Create `src/Exceptions/WebhookVerificationException.php` — extends `PaymentException`; include static `forDriver(string $driver, string $signature): self` (truncates signature to 32 chars in message)
    - _Requirements: 8.2, 8.4, 20.5_
  - [x] 6.5 Create `src/Exceptions/RefundFailedException.php` — extends `PaymentException`
    - _Requirements: 8.2_
  - [x] 6.6 Create `src/Exceptions/CaptureFailedException.php` — extends `PaymentException`
    - _Requirements: 8.2_
  - [x] 6.7 Create `src/Exceptions/VoidFailedException.php` — extends `PaymentException`
    - _Requirements: 8.2_
  - [x] 6.8 Create `src/Exceptions/AuthorizationFailedException.php` — extends `PaymentException`
    - _Requirements: 8.2_
  - [x] 6.9 Create `src/Exceptions/SubscriptionException.php` — extends `PaymentException`
    - _Requirements: 8.2_
  - [x] 6.10 Create `src/Exceptions/IdempotencyException.php` — extends `PaymentException`; include static `forEmptyKey(): self`
    - _Requirements: 8.2, 13.6_
  - [x] 6.11 Create `src/Exceptions/UnsupportedOperationException.php` — extends `PaymentException`; include static `forOperation(string $operation, string $driver): self` whose message contains both `$operation` and `$driver`
    - _Requirements: 8.2, 8.5, 18.2_


- [x] 7. Events
  - [x] 7.1 Create `src/Events/PaymentInitiated.php` — final readonly class, constructor property `PaymentRequest $request`; `declare(strict_types=1)`, namespace, PHPDoc
    - _Requirements: 7.1_
  - [x] 7.2 Create `src/Events/PaymentSucceeded.php` — final readonly class: `PaymentRequest $request`, `PaymentResponse $response`
    - _Requirements: 7.1, 7.2, 7.4_
  - [x] 7.3 Create `src/Events/PaymentFailed.php` — final readonly class: `PaymentRequest $request`, `?PaymentResponse $response`, `?\Throwable $exception`
    - _Requirements: 7.1, 7.3_
  - [x] 7.4 Create `src/Events/PaymentCaptured.php` — final readonly class: `CaptureRequest $request`, `CaptureResponse $response`
    - _Requirements: 7.1_
  - [x] 7.5 Create `src/Events/PaymentRefunded.php` — final readonly class: `RefundRequest $request`, `RefundResponse $response`
    - _Requirements: 7.1_
  - [x] 7.6 Create `src/Events/PaymentVoided.php` — final readonly class: `VoidRequest $request`, `VoidResponse $response`
    - _Requirements: 7.1_
  - [x] 7.7 Create `src/Events/PaymentLinkCreated.php` — final readonly class: `PaymentLinkRequest $request`, `PaymentLinkResponse $response`
    - _Requirements: 7.1_
  - [x] 7.8 Create `src/Events/CardSaved.php` — final readonly class: `SaveCardRequest $request`, `PaymentResponse $response`
    - _Requirements: 7.1_
  - [x] 7.9 Create `src/Events/TokenCharged.php` — final readonly class: `TokenChargeRequest $request`, `PaymentResponse $response`
    - _Requirements: 7.1_
  - [x] 7.10 Create `src/Events/WebhookReceived.php` — final readonly class: `WebhookRequest $request`
    - _Requirements: 7.1, 11.5_
  - [x] 7.11 Create `src/Events/WebhookProcessed.php` — final readonly class: `WebhookRequest $request`, `WebhookResponse $response`
    - _Requirements: 7.1, 11.5_
  - [x] 7.12 Create `src/Events/SubscriptionCreated.php` — final readonly class: `SubscriptionRequest $request`, `SubscriptionResponse $response`
    - _Requirements: 7.1, 17.4_
  - [x] 7.13 Create `src/Events/SubscriptionCancelled.php` — final readonly class: `TransactionId $subscriptionId`, `SubscriptionResponse $response`
    - _Requirements: 7.1, 17.5_
  - [x] 7.14 Create `src/Events/TransactionLookuped.php` — final readonly class: `TransactionLookupRequest $request`, `StatusResponse $response`
    - _Requirements: 7.1_

- [x] 8. Response Objects
  - [x] 8.1 Create `src/Responses/PaymentResponse.php` — final class implementing `PaymentResponseContract`; readonly properties: `bool $successful`, `TransactionId $transactionId`, `PaymentStatus $status`, `string $providerReference`, `Money $amount`, `array $rawResponse`, `string $message`; implement all interface methods delegating to properties; `// TODO` bodies for any non-trivial logic
    - _Requirements: 6.3, 6.5_
  - [x] 8.2 Create `src/Responses/RefundResponse.php` — final class implementing `RefundResponseContract`; readonly: `bool $successful`, `string $refundId`, `Money $amount`, `PaymentStatus $status`, `string $message`, `array $rawResponse`
    - _Requirements: 6.4, 6.5_
  - [x] 8.3 Create `src/Responses/CaptureResponse.php` — final class implementing `CaptureResponseContract`; readonly: `bool $successful`, `string $captureId`, `Money $amount`, `PaymentStatus $status`, `string $message`, `array $rawResponse`
    - _Requirements: 6.5_
  - [x] 8.4 Create `src/Responses/VoidResponse.php` — final class; readonly: `bool $successful`, `TransactionId $transactionId`, `PaymentStatus $status`, `string $message`, `array $rawResponse`
    - _Requirements: 6.5_
  - [x] 8.5 Create `src/Responses/WebhookResponse.php` — final class implementing `WebhookResponseContract`; readonly: `bool $successful`, `WebhookEventType $eventType`, `string $message`, `array $rawPayload`
    - _Requirements: 6.5_
  - [x] 8.6 Create `src/Responses/StatusResponse.php` — final class implementing `StatusResponseContract`; readonly: `bool $successful`, `TransactionId $transactionId`, `PaymentStatus $status`, `string $message`, `array $rawResponse`
    - _Requirements: 6.5_
  - [x] 8.7 Create `src/Responses/VerificationResponse.php` — final class implementing `VerificationResponseContract`; readonly: `bool $successful`, `bool $verified`, `TransactionId $transactionId`, `string $message`, `array $rawResponse`
    - _Requirements: 6.5_
  - [x] 8.8 Create `src/Responses/SubscriptionResponse.php` — final class implementing `SubscriptionResponseContract`; readonly: `bool $successful`, `string $subscriptionId`, `PaymentStatus $status`, `?\DateTimeImmutable $nextBillingDate`, `string $message`, `array $rawResponse`
    - _Requirements: 6.5, 17.3_
  - [x] 8.9 Create `src/Responses/PaymentLinkResponse.php` — final class implementing `PaymentLinkResponseContract`; readonly: `bool $successful`, `string $paymentUrl`, `string $linkId`, `?\DateTimeImmutable $expiresAt`, `string $message`, `array $rawResponse`
    - _Requirements: 6.5_

- [x] 9. Checkpoint — Verify domain layer compiles
  - Run `composer dump-autoload` and `php -l` on every file created so far to confirm no syntax errors; ensure all contracts are satisfied by their response implementations
  - Ensure all tests pass, ask the user if questions arise.


- [x] 10. Abstract Driver
  - [x] 10.1 Create `src/Drivers/AbstractDriver.php` — abstract class implementing `PaymentDriverContract`; constructor accepts `PaymentLoggerContract $logger` and `\Illuminate\Contracts\Events\Dispatcher $events`; declare abstract stub for each of the 15 contract methods (concrete drivers must override); implement `withRetry(callable $operation, int $maxAttempts, int $delayMs): mixed` as a `// TODO` stub; implement protected helpers `dispatchEvent(object $event): void` (TODO) and `log(string $level, string $message, array $context = []): void` (TODO); enforce idempotency key check via `validateIdempotencyKey(string $key): void` (throws `IdempotencyException` on empty/whitespace); add `declare(strict_types=1)`, namespace, full PHPDoc
    - _Requirements: 1.3, 13.1, 13.2, 13.4_

- [x] 11. PaymentManager
  - [x] 11.1 Create `src/Managers/PaymentManager.php` — class extending `Illuminate\Support\Manager`; override `getDefaultDriver(): string` to read `config('payment.default')`; implement `createDriver(string $driver): PaymentDriverContract` with a `// TODO` body that: reads `payment.drivers.{driver}` config, throws `InvalidConfigurationException` if required keys are missing, instantiates the driver via the container, throws `DriverNotFoundException` if the result does not implement `PaymentDriverContract`; implement `getAvailableDrivers(): array` returning the keys of `payment.drivers` config; add `declare(strict_types=1)`, namespace, PHPDoc
    - _Requirements: 1.2, 2.3, 3.1, 3.2, 3.3, 3.4, 3.5, 3.6_

- [x] 12. Facade
  - [x] 12.1 Create `src/Facades/Payment.php` — class extending `Illuminate\Support\Facades\Facade`; implement `getFacadeAccessor(): string` returning `PaymentManager::class`; add static method `fake(): FakePaymentDriver` with `// TODO` body; declare PHPDoc `@method` stubs for all 15 driver methods plus `driver()` and `extend()` so IDEs resolve them correctly
    - _Requirements: 4.1, 4.2, 4.3, 4.4_

- [x] 13. Logging implementations
  - [x] 13.1 Create `src/Logging/NullLogger.php` — final class implementing `PaymentLoggerContract`; all four methods have empty bodies (discard all input)
    - _Requirements: 12.2, 12.3_
  - [x] 13.2 Create `src/Logging/LaravelLogger.php` — final class implementing `PaymentLoggerContract`; constructor accepts `\Illuminate\Log\LogManager $log` and `string $channel`; each method delegates to `$this->log->channel($this->channel)->{level}(...)` with `// TODO` body
    - _Requirements: 12.2, 12.4_
  - [x] 13.3 Create `src/Logging/DebugLogger.php` — final class implementing `PaymentLoggerContract`; constructor accepts `\Illuminate\Log\LogManager $log`; delegates to a dedicated debug channel; `// TODO` body
    - _Requirements: 12.2, 12.6_
  - [x] 13.4 Create `src/Logging/StackLogger.php` — final class implementing `PaymentLoggerContract`; constructor accepts `array $loggers` (array of `PaymentLoggerContract`); each method iterates `$this->loggers` and forwards the call; `// TODO` body
    - _Requirements: 12.2_


- [x] 14. Services
  - [x] 14.1 Create `src/Services/RetryService.php` — final class; constructor accepts `int $maxAttempts`, `int $delayMs`, `bool $enabled`; implement `execute(callable $operation): mixed` with `// TODO` body that retries on transient exceptions; implement `isTransient(\Throwable $e): bool` with `// TODO` body that classifies HTTP 429 and 5xx as transient; add `declare(strict_types=1)`, namespace, PHPDoc
    - _Requirements: 13.2, 13.3, 13.5_
  - [x] 14.2 Create `src/Services/WebhookVerifier.php` — final class; constructor accepts `PaymentLoggerContract $logger`; implement `verify(PaymentDriverContract $driver, WebhookRequest $request): void` with `// TODO` body that calls `$driver->verifyWebhookSignature($request)`, logs the result, and throws `WebhookVerificationException` with truncated signature on failure
    - _Requirements: 20.3, 20.5_
  - [x] 14.3 Create `src/Services/PaymentService.php` — final class; constructor accepts `PaymentManager $manager`; declare stub methods `charge`, `authorize`, `capture`, `refund`, `void`, `createPaymentLink`, `saveCard`, `chargeToken`, `createSubscription`, `cancelSubscription`, `verify`, `lookup` — each delegating to `$this->manager->driver()` with `// TODO` body
    - _Requirements: 3.1_

- [x] 15. Webhooks
  - [x] 15.1 Create `src/Webhooks/WebhookController.php` — final class extending `\Illuminate\Routing\Controller`; constructor accepts `WebhookProcessor $processor`; implement `handle(\Illuminate\Http\Request $request, string $driver): \Illuminate\Http\JsonResponse` with `// TODO` body that builds `WebhookRequest` DTO from the raw request, calls `$this->processor->process(...)`, returns HTTP 200; catches `WebhookVerificationException` and returns HTTP 400; add `declare(strict_types=1)`, namespace, PHPDoc
    - _Requirements: 11.1, 11.2, 11.4, 20.2_
  - [x] 15.2 Create `src/Webhooks/WebhookProcessor.php` — final class; constructor accepts `PaymentManager $manager`, `WebhookVerifier $verifier`, `\Illuminate\Contracts\Events\Dispatcher $events`; implement `process(WebhookRequest $request): WebhookResponse` with `// TODO` body that: dispatches `WebhookReceived`, calls `WebhookVerifier::verify()`, resolves driver, calls `processWebhook()`, dispatches `WebhookProcessed`
    - _Requirements: 11.2, 11.5, 20.2_

- [x] 16. Repositories
  - [x] 16.1 Create `src/Repositories/PaymentTransaction.php` — Eloquent model extending `\Illuminate\Database\Eloquent\Model`; set `$table = 'payment_transactions'`; declare `$fillable` array with all 11 columns from the migration; declare `$casts` array casting `metadata` and `raw_response` to `array`; `// TODO` bodies for any scopes
    - _Requirements: 19.5_
  - [x] 16.2 Create `src/Repositories/NullPaymentTransactionRepository.php` — final class implementing `PaymentTransactionRepositoryContract`; all four methods have empty no-op bodies
    - _Requirements: 19.4_
  - [x] 16.3 Create `src/Repositories/EloquentPaymentTransactionRepository.php` — final class implementing `PaymentTransactionRepositoryContract`; constructor accepts `PaymentTransaction $model`; implement all four methods with `// TODO` bodies that delegate to the Eloquent model
    - _Requirements: 19.2, 19.3_


- [x] 17. Service Provider
  - [x] 17.1 Create `src/Providers/PaymentServiceProvider.php` — class extending `\Illuminate\Support\ServiceProvider`; implement `register(): void` with the full binding sequence (singleton `PaymentManager`, bind all contracts to their default implementations with `// TODO` for runtime logic); implement `boot(): void` that: merges config (`$this->mergeConfigFrom`), validates config (TODO), registers webhook route if `payment.webhook.enabled` (TODO), registers `PaymentSucceeded` listener to call repository if `payment.repository.enabled` (TODO), publishes config via `$this->publishes(...)` with tag `payment-config`, publishes migrations with tag `payment-migrations`; `declare(strict_types=1)`, namespace, PHPDoc
    - _Requirements: 16.1, 16.2, 16.3, 16.4, 2.6_

- [x] 18. Config, Routes, and Migration
  - [x] 18.1 Create `config/payment.php` — return array matching the design's structure exactly: `default`, `drivers` (with sample `stripe` block), `currencies`, `logging`, `retry`, `webhook`, `repository` keys; all values use `env()` helpers with documented defaults; every key commented with its purpose
    - _Requirements: 2.1, 2.2, 2.4, 2.5, 11.6, 11.7, 12.3, 12.4, 12.6, 13.3, 14.5_
  - [x] 18.2 Create `routes/webhooks.php` — define a single `Route::post(...)` for `{prefix}/{driver}` pointing to `WebhookController::handle`; read prefix from `config('payment.webhook.prefix', 'payment/webhook')`
    - _Requirements: 11.1, 11.6_
  - [x] 18.3 Create `database/migrations/2024_01_01_000000_create_payment_transactions_table.php` — standard Laravel migration; `up()` creates `payment_transactions` table with columns: `id` (bigIncrements), `transaction_id` (string, unique), `driver` (string), `order_id` (string, nullable), `customer_id` (string, nullable), `amount` (integer), `currency` (string, length 3), `status` (string), `payment_method` (string), `metadata` (json, nullable), `raw_response` (json, nullable), `idempotency_key` (string, nullable, index), `timestamps()`; add indexes on `order_id` and `customer_id`; `down()` drops the table
    - _Requirements: 19.2_

- [x] 19. Checkpoint — Verify infrastructure layer compiles
  - Run `composer dump-autoload` and `php -l` on all new files; ensure service provider can be instantiated without errors in a testbench environment
  - Ensure all tests pass, ask the user if questions arise.

- [x] 20. Testing support
  - [x] 20.1 Create `src/Testing/FakePaymentDriver.php` — final class implementing `PaymentDriverContract`; maintain internal arrays `$charges = []`, `$refunds = []` etc. to record calls; each of the 15 contract methods stores the call and returns a pre-built successful response (use static factory methods on response classes with `// TODO` defaults); implement assertion methods: `assertCharged(Money $amount): void`, `assertRefunded(TransactionId $id): void`, `assertNotCharged(): void`, `assertEventDispatched(string $eventClass): void` — each method has a `// TODO` body with PHPDoc; `declare(strict_types=1)`, namespace, PHPDoc
    - _Requirements: 15.1, 15.3_
  - [x] 20.2 Create `src/Testing/PaymentFactory.php` — final class; implement static factory methods `paymentRequest(): self` and `refundRequest(): self` returning a new fluent builder instance; implement fluent methods `withAmount(int $amount, Currency $currency): self`, `withCustomer(string $name, string $email): self`, `withIdempotencyKey(string $key): self`, `withTransactionId(string $id): self`; implement `make(): PaymentRequest|RefundRequest` with `// TODO` body that constructs the DTO using sensible defaults for omitted fields; `declare(strict_types=1)`, namespace, PHPDoc
    - _Requirements: 15.5_


- [x] 21. Unit test skeletons — Value Objects
  - [x] 21.1 Create `tests/Unit/ValueObjects/MoneyTest.php` — PHPUnit test class with example-based test methods: `test_money_of_constructs_correctly()`, `test_money_add_returns_correct_sum()`, `test_money_subtract_returns_correct_difference()`, `test_money_equals_returns_true_for_equal_instances()`, `test_negative_amount_throws()`, `test_cross_currency_add_throws()`, `test_format_returns_string()`; each method body is `// TODO`
    - _Requirements: 5.5, 14.1_
  - [x] 21.2 Create `tests/Unit/ValueObjects/ValueObjectTest.php` — PHPUnit test class covering all five string value objects (`TransactionId`, `CustomerId`, `OrderId`, `WebhookSignature`, `Token`); methods: `test_transaction_id_round_trip()`, `test_customer_id_round_trip()`, `test_order_id_round_trip()`, `test_token_round_trip()`, `test_empty_transaction_id_throws()`, `test_empty_customer_id_throws()`, `test_empty_order_id_throws()`, `test_empty_token_throws()`; `// TODO` bodies
    - _Requirements: 10.2, 10.3, 10.4, 10.6, 10.7_

- [x] 22. Unit test skeletons — Enums, DTOs, Manager, Drivers, Services, Exceptions
  - [x] 22.1 Create `tests/Unit/Enums/PaymentStatusTest.php` — test class with methods: `test_all_cases_have_correct_backing_values()`, `test_from_valid_value_returns_case()`, `test_try_from_invalid_value_returns_null()`; `// TODO` bodies
    - _Requirements: 9.1_
  - [x] 22.2 Create `tests/Unit/DTO/PaymentRequestTest.php` — test class with methods: `test_valid_construction()`, `test_empty_idempotency_key_throws()`, `test_properties_are_readonly()`; `// TODO` bodies
    - _Requirements: 5.1, 5.2, 5.3_
  - [x] 22.3 Create `tests/Unit/Managers/PaymentManagerTest.php` — test class with methods: `test_resolves_registered_driver()`, `test_unregistered_driver_throws_driver_not_found()`, `test_same_instance_returned_on_second_call()`, `test_get_available_drivers_returns_config_keys()`, `test_missing_config_key_throws_invalid_configuration()`; `// TODO` bodies
    - _Requirements: 1.2, 2.3, 3.2, 3.3, 3.6_
  - [x] 22.4 Create `tests/Unit/Drivers/AbstractDriverTest.php` — test class verifying the abstract driver contracts; methods: `test_each_method_returns_correct_response_contract()`, `test_empty_idempotency_key_throws_before_driver_call()`; `// TODO` bodies
    - _Requirements: 1.3, 13.6_
  - [x] 22.5 Create `tests/Unit/Services/RetryServiceTest.php` — test class with methods: `test_succeeds_on_nth_attempt()`, `test_exhausted_retries_rethrow()`, `test_http_5xx_classified_as_transient()`, `test_http_429_classified_as_transient()`, `test_http_4xx_not_transient()`; `// TODO` bodies
    - _Requirements: 13.3, 13.5_
  - [x] 22.6 Create `tests/Unit/Exceptions/ExceptionTest.php` — test class with method `test_unsupported_operation_message_contains_driver_and_operation()`; `// TODO` body
    - _Requirements: 8.5_
  - [x] 22.7 Create `tests/Unit/Testing/PaymentFactoryTest.php` — test class with methods: `test_payment_request_factory_produces_valid_dto()`, `test_refund_request_factory_produces_valid_dto()`, `test_fluent_builder_overrides_defaults()`; `// TODO` bodies
    - _Requirements: 15.5_

- [x] 23. Feature test skeletons
  - [x] 23.1 Create `tests/Feature/PaymentChargeTest.php` — Testbench feature test class; methods: `test_charge_returns_payment_response()`, `test_payment_initiated_event_dispatched()`, `test_payment_succeeded_event_dispatched_on_success()`, `test_payment_failed_event_dispatched_on_failure()`, `test_logger_receives_info_call_for_charge()`; uses `Payment::fake()` and `Event::fake()`; `// TODO` bodies
    - _Requirements: 4.2, 7.1, 7.2, 7.3, 12.5_
  - [x] 23.2 Create `tests/Feature/WebhookControllerTest.php` — Testbench feature test; methods: `test_valid_webhook_returns_200()`, `test_invalid_signature_returns_400()`, `test_webhook_received_dispatched_before_processed()`, `test_verification_failure_logged_at_error()`; `// TODO` bodies
    - _Requirements: 11.1, 11.4, 11.5, 20.2, 20.5_
  - [x] 23.3 Create `tests/Feature/FakePaymentDriverTest.php` — feature test class; methods: `test_assert_charged_passes_after_charge()`, `test_assert_not_charged_passes_before_any_charge()`, `test_assert_not_charged_fails_after_charge()`, `test_assert_refunded_passes_after_refund()`; uses `Payment::fake()`; `// TODO` bodies
    - _Requirements: 15.1, 15.2, 15.3_


- [x] 24. Property-based tests — Value Objects (P5–P9)
  - [x] 24.1 Write property test P5 in `tests/Unit/ValueObjects/MoneyTest.php`
    - **Property 5: Money Constructor Round-Trip** — for any non-negative integer and any `Currency` value, `Money::of(n, c)` produces a `Money` where `amount === n` and `currency === c`
    - Use `innmind/black-box` generators: `Set::integers()->between(0, PHP_INT_MAX)` for amount, `Set::elements(...Currency::cases())` for currency; minimum 100 iterations
    - Tag comment: `// Feature: laravel-payment-framework, Property 5: Money constructor round-trip`
    - **Validates: Requirements 10.1, 14.1**
  - [x] 24.2 Write property test P6 in `tests/Unit/ValueObjects/MoneyTest.php`
    - **Property 6: Money Arithmetic Preserves Non-Negative Invariant** — for any two non-negative integers `a`, `b` and same currency, `Money::of(a, c)->add(Money::of(b, c))->amount === a + b` and `add->subtract` round-trip equals original
    - Tag comment: `// Feature: laravel-payment-framework, Property 6: Money arithmetic invariant`
    - **Validates: Requirements 5.5**
  - [x] 24.3 Write property test P7 in `tests/Unit/ValueObjects/MoneyTest.php`
    - **Property 7: Cross-Currency Arithmetic Throws** — for any two distinct `Currency` values, `add()` or `subtract()` throws `\InvalidArgumentException`
    - Generate pairs of distinct currencies using `Set::elements(...Currency::cases())` and filter `$a !== $b`
    - Tag comment: `// Feature: laravel-payment-framework, Property 7: Cross-currency arithmetic throws`
    - **Validates: Requirements 5.7**
  - [x] 24.4 Write property test P8 in `tests/Unit/ValueObjects/ValueObjectTest.php`
    - **Property 8: String Value Object Round-Trip** — for any non-empty string `s`, `fromString(s)->toString() === s` for all five value object types
    - Use `Set::strings()->atLeast(1)` generator for non-empty strings
    - Tag comment: `// Feature: laravel-payment-framework, Property 8: String value object round-trip`
    - **Validates: Requirements 10.2, 10.3, 10.4, 10.5, 10.6**
  - [x] 24.5 Write property test P9 in `tests/Unit/ValueObjects/ValueObjectTest.php`
    - **Property 9: Empty String Throws on Value Object Construction** — passing empty string to `TransactionId`, `CustomerId`, `OrderId`, `Token` constructors always throws `\InvalidArgumentException`
    - Tag comment: `// Feature: laravel-payment-framework, Property 9: Empty string throws`
    - **Validates: Requirements 10.7**

- [x] 25. Property-based tests — Manager (P1–P4)
  - [x] 25.1 Write property test P1 in `tests/Unit/Managers/PaymentManagerTest.php`
    - **Property 1: Invalid Driver Resolution Throws** — for any string not registered as a driver key in config, `PaymentManager::driver()` throws `DriverNotFoundException` containing the name in its message
    - Use `Set::strings()` and exclude registered driver names; verify exception message contains the input string
    - Tag comment: `// Feature: laravel-payment-framework, Property 1: Invalid driver resolution throws`
    - **Validates: Requirements 1.2, 8.3**
  - [x] 25.2 Write property test P2 in `tests/Unit/Managers/PaymentManagerTest.php`
    - **Property 2: Driver Resolution Caching** — resolving any registered driver name twice returns the identical (===) instance
    - Tag comment: `// Feature: laravel-payment-framework, Property 2: Driver resolution caching`
    - **Validates: Requirements 3.3**
  - [x] 25.3 Write property test P3 in `tests/Unit/Managers/PaymentManagerTest.php`
    - **Property 3: Available Drivers Round-Trip** — for any set of driver keys defined in config, `getAvailableDrivers()` returns exactly those keys
    - Generate random sets of distinct driver name strings; build config array; assert returned keys match
    - Tag comment: `// Feature: laravel-payment-framework, Property 3: Available drivers round-trip`
    - **Validates: Requirements 3.6**
  - [x] 25.4 Write property test P4 in `tests/Unit/Managers/PaymentManagerTest.php`
    - **Property 4: Missing Config Key Throws at Resolution** — for any driver config block with one required key randomly removed, resolution throws `InvalidConfigurationException` identifying the missing key
    - Tag comment: `// Feature: laravel-payment-framework, Property 4: Missing config key throws at resolution`
    - **Validates: Requirements 2.3**


- [x] 26. Property-based tests — DTOs (P10, P18)
  - [x] 26.1 Write property test P10 in `tests/Unit/DTO/PaymentRequestTest.php`
    - **Property 10: DTO Invalid Field Throws** — constructing any DTO with a null or empty value for a required field throws `\InvalidArgumentException`
    - Generate DTO construction calls with each required field individually nulled; assert exception thrown every time
    - Tag comment: `// Feature: laravel-payment-framework, Property 10: DTO invalid field throws`
    - **Validates: Requirements 5.2**
  - [x] 26.2 Write property test P18 in `tests/Unit/DTO/PaymentRequestTest.php`
    - **Property 18: Idempotency Key Enforcement** — for any `PaymentRequest` or `RefundRequest` with an empty or whitespace-only `idempotencyKey`, the framework throws `IdempotencyException` before invoking the driver
    - Use `Set::strings()` filtered to whitespace-only strings; construct DTOs with those keys; assert `IdempotencyException`
    - Tag comment: `// Feature: laravel-payment-framework, Property 18: Idempotency key enforcement`
    - **Validates: Requirements 13.6**

- [x] 27. Property-based tests — Driver contracts (P11)
  - [x] 27.1 Write property test P11 in `tests/Unit/Drivers/AbstractDriverTest.php`
    - **Property 11: Driver Methods Return Correct Response Contract** — for any of the 15 methods on `PaymentDriverContract`, the return value from `FakePaymentDriver` implements the corresponding response contract interface
    - Use `Set::elements()` over the 15 method names; call each on a `FakePaymentDriver` instance; assert `instanceof` the expected contract
    - Tag comment: `// Feature: laravel-payment-framework, Property 11: Driver methods return correct response contract`
    - **Validates: Requirements 6.2**

- [x] 28. Property-based tests — RetryService (P16, P17)
  - [x] 28.1 Write property test P16 in `tests/Unit/Services/RetryServiceTest.php`
    - **Property 16: Retry on Transient Errors** — for any configured `max_attempts` N ≥ 1, a callable that throws a transient exception exactly N−1 times and succeeds on attempt N returns the successful result without exception
    - Use `Set::integers()->between(1, 10)` for N; build a counter-based callable; assert no exception thrown and correct result returned
    - Tag comment: `// Feature: laravel-payment-framework, Property 16: Retry on transient errors`
    - **Validates: Requirements 13.3**
  - [x] 28.2 Write property test P17 in `tests/Unit/Services/RetryServiceTest.php`
    - **Property 17: HTTP Status Code Transience Classification** — for any HTTP code 500–599 or 429, `RetryService::isTransient()` returns `true`; for any code 400–428 or 430–499, returns `false`
    - Use `Set::integers()->between(500, 599)` and `Set::integers()->between(400, 499)` with explicit 429 case
    - Tag comment: `// Feature: laravel-payment-framework, Property 17: HTTP status code transience classification`
    - **Validates: Requirements 13.5**

- [x] 29. Property-based tests — FakeDriver, Factory, Logger, Webhooks, Exceptions (P19–P23)
  - [x] 29.1 Write property test P19 in `tests/Feature/FakePaymentDriverTest.php`
    - **Property 19: FakePaymentDriver Assertion Round-Trip** — for any `Money` amount charged via `FakePaymentDriver`, `assertCharged(amount)` does not throw; `assertNotCharged()` throws after that charge; before any charge `assertNotCharged()` does not throw
    - Use `Set::integers()->between(1, 1_000_000)` for amounts; `Set::elements(...Currency::cases())` for currency
    - Tag comment: `// Feature: laravel-payment-framework, Property 19: FakePaymentDriver assertion round-trip`
    - **Validates: Requirements 15.3**
  - [x] 29.2 Write property test P20 in `tests/Unit/Testing/PaymentFactoryTest.php`
    - **Property 20: PaymentFactory Produces Valid DTOs** — for any combination of valid parameter values passed to `PaymentFactory`'s fluent builder, the produced DTO is non-null, passes internal validation, and is an instance of the expected class
    - Generate random valid amounts, customer names/emails, idempotency keys
    - Tag comment: `// Feature: laravel-payment-framework, Property 20: PaymentFactory produces valid DTOs`
    - **Validates: Requirements 15.5**
  - [x] 29.3 Write property test P21 in `tests/Feature/PaymentChargeTest.php`
    - **Property 21: Logger Receives Call for Every Driver Operation** — for any of the 15 driver method invocations via `FakePaymentDriver`, the bound `PaymentLoggerContract` receives at least one `info()` call with contextual information about that operation
    - Use a `MockPaymentLogger` (implements `PaymentLoggerContract`, captures calls); assert non-empty `info` calls after each operation
    - Tag comment: `// Feature: laravel-payment-framework, Property 21: Logger receives call for every driver operation`
    - **Validates: Requirements 12.5**
  - [x] 29.4 Write property test P22 in `tests/Feature/WebhookControllerTest.php`
    - **Property 22: Webhook Verification Failure Logged at Error Level** — for any `WebhookRequest` causing `WebhookVerificationException`, the logger's `error()` method is called with context including the driver name and a truncated (≤ 32 chars) signature value
    - Use `Set::strings()` for signature values; assert `error()` called and context fields present and signature ≤ 32 chars
    - Tag comment: `// Feature: laravel-payment-framework, Property 22: Webhook verification failure logged at error level`
    - **Validates: Requirements 20.5**
  - [x] 29.5 Write property test P23 in `tests/Unit/Exceptions/ExceptionTest.php`
    - **Property 23: UnsupportedOperationException Message Content** — for any driver name `d` and operation name `op`, `UnsupportedOperationException::forOperation(d, op)->getMessage()` contains both `d` and `op`
    - Use `Set::strings()->atLeast(1)` for both `d` and `op`
    - Tag comment: `// Feature: laravel-payment-framework, Property 23: UnsupportedOperationException message content`
    - **Validates: Requirements 8.5, 18.2**

- [x] 30. Property-based tests — Events and Webhooks (P12–P15)
  - [x] 30.1 Write property test P12 in `tests/Feature/PaymentChargeTest.php`
    - **Property 12: Event Payload Completeness** — for any `PaymentRequest` used to call `charge()`, the `PaymentSucceeded` event carries that exact `PaymentRequest` instance and a `PaymentResponse`
    - Use `PaymentFactory` to generate random `PaymentRequest` instances; use `Event::fake()`; assert event properties after charge
    - Tag comment: `// Feature: laravel-payment-framework, Property 12: Event payload completeness`
    - **Validates: Requirements 7.2, 7.4**
  - [x] 30.2 Write property test P13 in `tests/Feature/PaymentChargeTest.php`
    - **Property 13: PaymentFailed Always Dispatched on Failure** — for any operation resulting in failure (driver returns unsuccessful response or throws), `PaymentFailed` is dispatched exactly once
    - Configure `FakePaymentDriver` to return failure responses; verify `PaymentFailed` dispatched once; use `Event::fake()`
    - Tag comment: `// Feature: laravel-payment-framework, Property 13: PaymentFailed always dispatched on failure`
    - **Validates: Requirements 7.3**
  - [x] 30.3 Write property test P14 in `tests/Feature/WebhookControllerTest.php`
    - **Property 14: Webhook Verification Guards Processing** — for any `WebhookRequest` where `verifyWebhookSignature()` returns `false`, `processWebhook()` is never called and the controller returns HTTP 400
    - Configure driver to fail verification; assert `processWebhook()` call count is zero and response status is 400
    - Tag comment: `// Feature: laravel-payment-framework, Property 14: Webhook verification guards processing`
    - **Validates: Requirements 20.2, 8.4, 11.4**
  - [x] 30.4 Write property test P15 in `tests/Feature/WebhookControllerTest.php`
    - **Property 15: Webhook Event Ordering** — for any valid webhook request, `WebhookReceived` is dispatched before `WebhookProcessed`, and `WebhookProcessed` is dispatched only after `processWebhook()` completes
    - Use `Event::fake()`; capture dispatched events in order; assert ordering invariant
    - Tag comment: `// Feature: laravel-payment-framework, Property 15: Webhook event ordering`
    - **Validates: Requirements 11.5**

- [x] 31. Final wiring and validation
  - [x] 31.1 Wire `Payment::fake()` in `src/Facades/Payment.php` — implement the `fake()` method body so it instantiates a `FakePaymentDriver`, swaps it as the default driver in the resolved `PaymentManager`, and returns the instance; ensure `Payment::fake()` called in a test environment replaces the real manager binding
    - _Requirements: 4.4, 15.2_
  - [x] 31.2 Wire idempotency enforcement in `src/Drivers/AbstractDriver.php` — in the `charge()`, `authorize()`, `refund()`, and other request-bearing method stubs, call `$this->validateIdempotencyKey(...)` before any other logic
    - _Requirements: 13.6_
  - [x] 31.3 Wire event dispatching in `src/Drivers/AbstractDriver.php` — for each of the 15 methods, the stub must dispatch the corresponding lifecycle event (`PaymentInitiated`, `PaymentSucceeded`/`PaymentFailed`, etc.) using `$this->dispatchEvent(...)`
    - _Requirements: 7.1, 7.2, 7.3_
  - [x] 31.4 Wire logging calls in `src/Drivers/AbstractDriver.php` — each method stub must call `$this->log('info', ...)` before delegating to the concrete implementation and after receiving a result, and call `$this->log('error', ...)` on any caught exception
    - _Requirements: 12.5_
  - [x] 31.5 Wire retry logic in `src/Drivers/AbstractDriver.php` — wrap driver calls in `$this->withRetry(...)` stub referencing `RetryService`; ensure the stub structure makes it clear that retry wraps the inner HTTP call
    - _Requirements: 13.2, 13.3_
  - [x] 31.6 Verify all 23 correctness property tests are present — run `vendor/bin/phpunit --list-tests` and confirm 23 property-tagged test methods exist across the test files
  - [x] 31.7 Verify the full directory tree matches the design — confirm every file from the design's directory tree exists with `declare(strict_types=1)`, a correct namespace, and a class/interface/enum declaration

- [x] 32. Final checkpoint — all tests pass
  - Run `vendor/bin/phpunit --testdox` and confirm zero failures; fix any missing imports, wrong return types, or interface mismatches revealed by PHP lint
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP delivery
- Every PHP file MUST have `declare(strict_types=1)` as the second line, a correct PSR-4 namespace, full PHPDoc block on the class/interface/enum, and strict return types on all methods
- Method bodies contain only `// TODO` comments — no real payment logic, no provider SDKs
- The `innmind/black-box` property test library is used for all 23 correctness properties; minimum 100 iterations per property
- All property tests MUST include the tag comment `// Feature: laravel-payment-framework, Property N: <text>`
- No test should make outbound HTTP calls; all tests use `FakePaymentDriver` or mocks
- PHP 8.4 readonly properties are used on all DTOs, value objects, events, and response classes
- PHP 8.4 backed enums are used for all categorical values
