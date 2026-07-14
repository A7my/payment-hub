# Design Document: Laravel Payment Framework

## Overview

The Laravel Payment Framework (`mifatoyeh/laravel-payment-framework`) is an enterprise-grade, provider-agnostic Composer package for Laravel 12+ and PHP 8.4+. It ships as a pure skeleton — every directory, class, interface, enum, DTO, response, event, exception, value object, service provider, facade, manager, and test structure is present with TODO-stubbed method bodies. No concrete payment provider is implemented inside the package; drivers live in separate packages.

The package follows Clean Architecture: the innermost layer (domain) contains value objects, enums, and DTOs that have zero external dependencies. The application layer contains contracts, events, and the service façade. The infrastructure layer contains the service provider, manager, repository implementations, loggers, and webhook plumbing. Concrete driver packages sit outside the framework and depend inward.

The design is intentionally build-once: a developer can switch their payment provider by changing `PAYMENT_DRIVER=stripe` to `PAYMENT_DRIVER=paymob` in their `.env` without touching any application code.

### Key Design Decisions

- **Laravel Manager pattern**: `PaymentManager` extends `Illuminate\Support\Manager` to get driver resolution, caching, and `extend()` for free.
- **Readonly DTOs**: All DTOs use PHP 8.4 `readonly` properties to enforce immutability at the language level.
- **Backed enums everywhere**: All categorical values (`PaymentStatus`, `Currency`, `Environment`, `PaymentMethod`, `TransactionType`, `WebhookEventType`) are PHP 8.4 backed string enums for type safety and IDE support.
- **Response objects never throw on provider failure**: Drivers return a response with `isSuccessful() === false` for recoverable provider errors; exceptions are reserved for configuration errors, security violations, and unrecoverable failures.
- **Event-first lifecycle**: Every meaningful payment lifecycle point dispatches a Laravel event, allowing zero-modification extensibility.
- **Optional repository**: Transaction persistence is opt-in via `payment.repository.enabled`; the `NullPaymentTransactionRepository` is bound by default so the framework never forces a schema on the host app.

---

## Architecture

### Layer Diagram

```
┌─────────────────────────────────────────────────────────────────────┐
│  HOST APPLICATION                                                   │
│  (Laravel 12+)                                                      │
│                                                                     │
│   $result = Payment::charge($dto);                                  │
│          │                                                          │
│          ▼                                                          │
│   ┌──────────────────────────────────────────────────────────────┐  │
│   │  FACADE LAYER  (src/Facades/Payment.php)                     │  │
│   └────────────────────────┬─────────────────────────────────────┘  │
│                            │ proxies                                 │
│   ┌────────────────────────▼─────────────────────────────────────┐  │
│   │  MANAGER LAYER  (src/Managers/PaymentManager.php)            │  │
│   │  extends Illuminate\Support\Manager                          │  │
│   │  - resolves & caches driver instances                        │  │
│   │  - validates config at resolution time                       │  │
│   └────────────────────────┬─────────────────────────────────────┘  │
│                            │ instantiates                            │
│   ┌────────────────────────▼─────────────────────────────────────┐  │
│   │  DRIVER LAYER  (src/Drivers/AbstractDriver.php)              │  │
│   │  + concrete driver packages (external)                       │  │
│   │  - cross-cutting: logging, events, retry                     │  │
│   │  - delegates to provider SDK / HTTP client                   │  │
│   └────────────────────────┬─────────────────────────────────────┘  │
│                            │                                         │
│   ┌────────────────────────▼─────────────────────────────────────┐  │
│   │  DOMAIN LAYER  (Value Objects, DTOs, Enums, Contracts)       │  │
│   │  - zero external dependencies                                │  │
│   │  - Money, TransactionId, PaymentRequest, PaymentStatus, …    │  │
│   └──────────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────────┘
```

### Package Flow: Charge

```
Payment::charge(PaymentRequest $dto)
  │
  ├─ Facade → PaymentManager::charge()
  │
  ├─ Manager resolves default driver (from config)
  │
  ├─ AbstractDriver::charge()
  │     ├─ Validates idempotency key (throws IdempotencyException if missing)
  │     ├─ Dispatches PaymentInitiated event
  │     ├─ Logs outbound call via PaymentLoggerContract
  │     ├─ withRetry() wraps the concrete driver HTTP call
  │     │     └─ ConcreteDriver::charge() [in external package]
  │     ├─ On success → Dispatches PaymentSucceeded
  │     │     └─ If repository enabled → stores via PaymentTransactionRepository
  │     ├─ On failure → Dispatches PaymentFailed
  │     └─ Returns PaymentResponse (isSuccessful true/false)
  │
  └─ Host application inspects PaymentResponse
```

### Webhook Flow

```
POST /payment/webhook/{driver}
  │
  ├─ WebhookController::handle()
  │     ├─ Builds WebhookRequest DTO from HTTP request
  │     ├─ Dispatches WebhookReceived event
  │     ├─ Calls WebhookVerifier::verify()
  │     │     └─ Driver::verifyWebhookSignature()
  │     │           └─ On failure → throws WebhookVerificationException → HTTP 400
  │     ├─ Driver::processWebhook(WebhookRequest) → WebhookResponse
  │     └─ Dispatches WebhookProcessed event
  │
  └─ Returns HTTP 200
```

---

## Components and Interfaces

### Composer Package Metadata

**File:** `composer.json`

```json
{
  "name": "mifatoyeh/laravel-payment-framework",
  "description": "Enterprise-grade provider-agnostic payment framework for Laravel 12+",
  "type": "library",
  "require": {
    "php": "^8.4",
    "laravel/framework": "^12.0"
  },
  "autoload": {
    "psr-4": {
      "Mifatoyeh\\LaravelPaymentFramework\\": "src/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "Mifatoyeh\\LaravelPaymentFramework\\Tests\\": "tests/"
    }
  },
  "extra": {
    "laravel": {
      "providers": [
        "Mifatoyeh\\LaravelPaymentFramework\\Providers\\PaymentServiceProvider"
      ],
      "aliases": {
        "Payment": "Mifatoyeh\\LaravelPaymentFramework\\Facades\\Payment"
      }
    }
  }
}
```

### Full Directory Tree

```
laravel-payment-framework/
├── composer.json
├── config/
│   └── payment.php
├── database/
│   └── migrations/
│       └── 2024_01_01_000000_create_payment_transactions_table.php
├── routes/
│   └── webhooks.php
├── src/
│   ├── Contracts/
│   │   ├── Drivers/
│   │   │   ├── PaymentDriverContract.php
│   │   │   └── SupportsCapabilities.php
│   │   ├── Responses/
│   │   │   ├── PaymentResponseContract.php
│   │   │   ├── RefundResponseContract.php
│   │   │   ├── CaptureResponseContract.php
│   │   │   ├── WebhookResponseContract.php
│   │   │   ├── StatusResponseContract.php
│   │   │   ├── VerificationResponseContract.php
│   │   │   ├── SubscriptionResponseContract.php
│   │   │   └── PaymentLinkResponseContract.php
│   │   ├── Logging/
│   │   │   └── PaymentLoggerContract.php
│   │   ├── Repositories/
│   │   │   └── PaymentTransactionRepositoryContract.php
│   │   └── Currency/
│   │       └── CurrencyConverterContract.php
│   ├── DTO/
│   │   ├── PaymentRequest.php
│   │   ├── RefundRequest.php
│   │   ├── CaptureRequest.php
│   │   ├── VoidRequest.php
│   │   ├── WebhookRequest.php
│   │   ├── SubscriptionRequest.php
│   │   ├── PaymentLinkRequest.php
│   │   ├── TokenChargeRequest.php
│   │   ├── SaveCardRequest.php
│   │   ├── TransactionLookupRequest.php
│   │   ├── CustomerData.php
│   │   ├── AddressData.php
│   │   └── OrderData.php
│   ├── Drivers/
│   │   └── AbstractDriver.php
│   ├── Enums/
│   │   ├── PaymentStatus.php
│   │   ├── Currency.php
│   │   ├── Environment.php
│   │   ├── PaymentMethod.php
│   │   ├── TransactionType.php
│   │   └── WebhookEventType.php
│   ├── Events/
│   │   ├── PaymentInitiated.php
│   │   ├── PaymentSucceeded.php
│   │   ├── PaymentFailed.php
│   │   ├── PaymentCaptured.php
│   │   ├── PaymentRefunded.php
│   │   ├── PaymentVoided.php
│   │   ├── PaymentLinkCreated.php
│   │   ├── CardSaved.php
│   │   ├── TokenCharged.php
│   │   ├── WebhookReceived.php
│   │   ├── WebhookProcessed.php
│   │   ├── SubscriptionCreated.php
│   │   ├── SubscriptionCancelled.php
│   │   └── TransactionLookuped.php
│   ├── Exceptions/
│   │   ├── PaymentException.php
│   │   ├── DriverNotFoundException.php
│   │   ├── InvalidConfigurationException.php
│   │   ├── WebhookVerificationException.php
│   │   ├── RefundFailedException.php
│   │   ├── CaptureFailedException.php
│   │   ├── VoidFailedException.php
│   │   ├── AuthorizationFailedException.php
│   │   ├── SubscriptionException.php
│   │   ├── IdempotencyException.php
│   │   └── UnsupportedOperationException.php
│   ├── Facades/
│   │   └── Payment.php
│   ├── Logging/
│   │   ├── LaravelLogger.php
│   │   ├── NullLogger.php
│   │   ├── DebugLogger.php
│   │   └── StackLogger.php
│   ├── Managers/
│   │   └── PaymentManager.php
│   ├── Providers/
│   │   └── PaymentServiceProvider.php
│   ├── Repositories/
│   │   ├── EloquentPaymentTransactionRepository.php
│   │   ├── NullPaymentTransactionRepository.php
│   │   └── PaymentTransaction.php
│   ├── Responses/
│   │   ├── PaymentResponse.php
│   │   ├── RefundResponse.php
│   │   ├── CaptureResponse.php
│   │   ├── VoidResponse.php
│   │   ├── WebhookResponse.php
│   │   ├── StatusResponse.php
│   │   ├── VerificationResponse.php
│   │   ├── SubscriptionResponse.php
│   │   └── PaymentLinkResponse.php
│   ├── Services/
│   │   ├── PaymentService.php
│   │   ├── WebhookVerifier.php
│   │   └── RetryService.php
│   ├── Testing/
│   │   ├── FakePaymentDriver.php
│   │   └── PaymentFactory.php
│   ├── ValueObjects/
│   │   ├── Money.php
│   │   ├── TransactionId.php
│   │   ├── CustomerId.php
│   │   ├── OrderId.php
│   │   ├── WebhookSignature.php
│   │   └── Token.php
│   └── Webhooks/
│       ├── WebhookController.php
│       └── WebhookProcessor.php
└── tests/
    ├── Unit/
    │   ├── ValueObjects/
    │   │   └── MoneyTest.php
    │   ├── Enums/
    │   │   └── PaymentStatusTest.php
    │   ├── DTO/
    │   │   └── PaymentRequestTest.php
    │   └── Managers/
    │       └── PaymentManagerTest.php
    └── Feature/
        ├── PaymentChargeTest.php
        ├── WebhookControllerTest.php
        └── FakePaymentDriverTest.php
```

### Contracts Layer (`src/Contracts/`)

#### `PaymentDriverContract`

The central interface all drivers must implement. Declares 15 method signatures:

| Method | Parameters | Returns |
|--------|-----------|---------|
| `authorize` | `PaymentRequest` | `PaymentResponse` |
| `capture` | `CaptureRequest` | `CaptureResponse` |
| `charge` | `PaymentRequest` | `PaymentResponse` |
| `void` | `VoidRequest` | `VoidResponse` |
| `refund` | `RefundRequest` | `RefundResponse` |
| `partialRefund` | `RefundRequest` | `RefundResponse` |
| `verify` | `TransactionLookupRequest` | `VerificationResponse` |
| `lookup` | `TransactionLookupRequest` | `StatusResponse` |
| `createPaymentLink` | `PaymentLinkRequest` | `PaymentLinkResponse` |
| `saveCard` | `SaveCardRequest` | `PaymentResponse` |
| `chargeToken` | `TokenChargeRequest` | `PaymentResponse` |
| `createSubscription` | `SubscriptionRequest` | `SubscriptionResponse` |
| `cancelSubscription` | `TransactionId` | `SubscriptionResponse` |
| `processWebhook` | `WebhookRequest` | `WebhookResponse` |
| `verifyWebhookSignature` | `WebhookRequest` | `bool` |

#### `SupportsCapabilities`

Optional interface drivers may implement for runtime capability detection:

```php
interface SupportsCapabilities {
    public function supports(string $capability): bool;
}
```

#### Response Contracts

Each response contract declares the minimal read-only surface area that the host application can rely on regardless of driver:

| Contract | Key Methods |
|----------|------------|
| `PaymentResponseContract` | `isSuccessful()`, `getTransactionId()`, `getStatus()`, `getProviderReference()`, `getAmount()`, `getRawResponse()`, `getMessage()` |
| `RefundResponseContract` | `isSuccessful()`, `getRefundId()`, `getAmount()`, `getStatus()`, `getMessage()` |
| `CaptureResponseContract` | `isSuccessful()`, `getCaptureId()`, `getAmount()`, `getStatus()`, `getMessage()` |
| `WebhookResponseContract` | `isSuccessful()`, `getEventType()`, `getMessage()`, `getRawPayload()` |
| `StatusResponseContract` | `isSuccessful()`, `getTransactionId()`, `getStatus()`, `getMessage()` |
| `VerificationResponseContract` | `isSuccessful()`, `isVerified()`, `getTransactionId()`, `getMessage()` |
| `SubscriptionResponseContract` | `isSuccessful()`, `getSubscriptionId()`, `getStatus()`, `getNextBillingDate()`, `getMessage()` |
| `PaymentLinkResponseContract` | `isSuccessful()`, `getPaymentUrl()`, `getLinkId()`, `getExpiresAt()`, `getMessage()` |

#### `PaymentLoggerContract`

```php
interface PaymentLoggerContract {
    public function info(string $message, array $context = []): void;
    public function error(string $message, array $context = []): void;
    public function debug(string $message, array $context = []): void;
    public function warning(string $message, array $context = []): void;
}
```

#### `PaymentTransactionRepositoryContract`

```php
interface PaymentTransactionRepositoryContract {
    public function store(PaymentResponse $response, PaymentRequest $request): void;
    public function findByTransactionId(TransactionId $id): ?array;
    public function findByOrderId(OrderId $id): array;
    public function findByCustomerId(CustomerId $id): array;
}
```

#### `CurrencyConverterContract`

```php
interface CurrencyConverterContract {
    public function convert(Money $from, Currency $to, float $rate): Money;
}
```

---

## Data Models

### Enums

#### `PaymentStatus` (backed string enum)

| Case | Value |
|------|-------|
| `Pending` | `"pending"` |
| `Authorized` | `"authorized"` |
| `Captured` | `"captured"` |
| `Failed` | `"failed"` |
| `Voided` | `"voided"` |
| `Refunded` | `"refunded"` |
| `PartiallyRefunded` | `"partially_refunded"` |
| `Cancelled` | `"cancelled"` |
| `Expired` | `"expired"` |
| `RequiresAction` | `"requires_action"` |

#### `Currency` (backed string enum, ISO 4217)

Minimum cases: `USD`, `EUR`, `GBP`, `SAR`, `AED`, `EGP`, `KWD`, `BHD`, `OMR`, `QAR`, `JOD`.

#### `Environment` (backed string enum)

| Case | Value |
|------|-------|
| `Sandbox` | `"sandbox"` |
| `Production` | `"production"` |

#### `PaymentMethod` (backed string enum)

| Case | Value |
|------|-------|
| `Card` | `"card"` |
| `BankTransfer` | `"bank_transfer"` |
| `Wallet` | `"wallet"` |
| `PaymentLink` | `"payment_link"` |
| `Token` | `"token"` |
| `QrCode` | `"qr_code"` |
| `Installment` | `"installment"` |
| `BuyNowPayLater` | `"buy_now_pay_later"` |

#### `TransactionType` (backed string enum)

| Case | Value |
|------|-------|
| `Charge` | `"charge"` |
| `Authorization` | `"authorization"` |
| `Capture` | `"capture"` |
| `Refund` | `"refund"` |
| `PartialRefund` | `"partial_refund"` |
| `Void` | `"void"` |
| `Subscription` | `"subscription"` |
| `TokenCharge` | `"token_charge"` |

#### `WebhookEventType` (backed string enum)

| Case | Value |
|------|-------|
| `PaymentSucceeded` | `"payment.succeeded"` |
| `PaymentFailed` | `"payment.failed"` |
| `RefundProcessed` | `"refund.processed"` |
| `DisputeOpened` | `"dispute.opened"` |
| `SubscriptionRenewed` | `"subscription.renewed"` |
| `SubscriptionCancelled` | `"subscription.cancelled"` |
| `CardSaved` | `"card.saved"` |
| `Unknown` | `"unknown"` |

---

### Value Objects (`src/ValueObjects/`)

All value objects are final classes with readonly properties. Construction with invalid input throws `\InvalidArgumentException`.

#### `Money`

```
Money {
    readonly int    $amount    // non-negative, smallest currency unit
    readonly Currency $currency

    static of(int $amount, Currency $currency): Money
    add(Money $other): Money           // throws if currencies differ
    subtract(Money $other): Money      // throws if currencies differ
    equals(Money $other): bool
    format(string $locale): string     // e.g. "USD 10.00"
}
```

#### `TransactionId`

```
TransactionId {
    readonly string $value    // non-empty

    static fromString(string $id): TransactionId
    toString(): string
}
```

#### `CustomerId`

```
CustomerId {
    readonly string $value    // non-empty

    static fromString(string $id): CustomerId
    toString(): string
}
```

#### `OrderId`

```
OrderId {
    readonly string $value    // non-empty

    static fromString(string $id): OrderId
    toString(): string
}
```

#### `WebhookSignature`

```
WebhookSignature {
    readonly string $value    // raw header value, may be empty (verified downstream)

    static fromString(string $signature): WebhookSignature
    toString(): string
}
```

#### `Token`

```
Token {
    readonly string $value    // non-empty provider token

    static fromString(string $token): Token
    toString(): string
}
```

---

### DTOs (`src/DTO/`)

All DTOs use PHP 8.4 `readonly` properties. Construction validates required fields and throws `\InvalidArgumentException` on violation.

#### `PaymentRequest`

```
PaymentRequest {
    readonly Money          $amount
    readonly Currency       $currency
    readonly string         $idempotencyKey     // non-empty
    readonly CustomerData   $customer
    readonly ?OrderData     $order
    readonly ?AddressData   $billingAddress
    readonly ?string        $returnUrl
    readonly ?string        $cancelUrl
    readonly array          $metadata
    readonly ?Token         $token
    readonly PaymentMethod  $paymentMethod
}
```

#### `RefundRequest`

```
RefundRequest {
    readonly TransactionId  $transactionId
    readonly Money          $amount
    readonly string         $reason
    readonly string         $idempotencyKey
    readonly array          $metadata
}
```

#### `CaptureRequest`

```
CaptureRequest {
    readonly TransactionId  $transactionId
    readonly Money          $amount
    readonly string         $idempotencyKey
    readonly array          $metadata
}
```

#### `VoidRequest`

```
VoidRequest {
    readonly TransactionId  $transactionId
    readonly string         $reason
    readonly string         $idempotencyKey
    readonly array          $metadata
}
```

#### `WebhookRequest`

```
WebhookRequest {
    readonly string             $driver
    readonly string             $rawBody
    readonly array              $headers
    readonly WebhookSignature   $signature
    readonly array              $metadata
}
```

#### `SubscriptionRequest`

```
SubscriptionRequest {
    readonly Money          $amount
    readonly Currency       $currency
    readonly string         $interval           // daily|weekly|monthly|yearly
    readonly int            $intervalCount
    readonly ?int           $trialDays
    readonly CustomerData   $customer
    readonly ?string        $planId
    readonly string         $idempotencyKey
    readonly array          $metadata
}
```

#### `PaymentLinkRequest`

```
PaymentLinkRequest {
    readonly Money          $amount
    readonly Currency       $currency
    readonly string         $description
    readonly ?CustomerData  $customer
    readonly ?string        $returnUrl
    readonly ?string        $cancelUrl
    readonly ?\DateTimeImmutable $expiresAt
    readonly string         $idempotencyKey
    readonly array          $metadata
}
```

#### `TokenChargeRequest`

```
TokenChargeRequest {
    readonly Token          $token
    readonly Money          $amount
    readonly Currency       $currency
    readonly string         $idempotencyKey
    readonly CustomerData   $customer
    readonly array          $metadata
}
```

#### `SaveCardRequest`

```
SaveCardRequest {
    readonly Token          $token
    readonly CustomerId     $customerId
    readonly string         $idempotencyKey
    readonly array          $metadata
}
```

#### `TransactionLookupRequest`

```
TransactionLookupRequest {
    readonly TransactionId  $transactionId
    readonly array          $metadata
}
```

#### `CustomerData`

```
CustomerData {
    readonly string  $name
    readonly string  $email
    readonly ?string $phone
    readonly ?string $externalId
}
```

#### `AddressData`

```
AddressData {
    readonly string  $line1
    readonly ?string $line2
    readonly string  $city
    readonly ?string $state
    readonly string  $country    // ISO 3166-1 alpha-2
    readonly string  $postalCode
}
```

#### `OrderData`

```
OrderData {
    readonly OrderId $orderId
    readonly string  $description
    readonly array   $items       // array of line items
    readonly array   $metadata
}
```

---

### Response Objects (`src/Responses/`)

All response classes are final with readonly properties. They implement their corresponding response contract.

#### `PaymentResponse` implements `PaymentResponseContract`

```
PaymentResponse {
    readonly bool           $successful
    readonly TransactionId  $transactionId
    readonly PaymentStatus  $status
    readonly string         $providerReference
    readonly Money          $amount
    readonly array          $rawResponse
    readonly string         $message

    isSuccessful(): bool
    getTransactionId(): TransactionId
    getStatus(): PaymentStatus
    getProviderReference(): string
    getAmount(): Money
    getRawResponse(): array
    getMessage(): string
}
```

#### `RefundResponse` implements `RefundResponseContract`

```
RefundResponse {
    readonly bool           $successful
    readonly string         $refundId
    readonly Money          $amount
    readonly PaymentStatus  $status
    readonly string         $message
    readonly array          $rawResponse
}
```

#### `CaptureResponse` implements `CaptureResponseContract`

```
CaptureResponse {
    readonly bool           $successful
    readonly string         $captureId
    readonly Money          $amount
    readonly PaymentStatus  $status
    readonly string         $message
    readonly array          $rawResponse
}
```

#### `VoidResponse`

```
VoidResponse {
    readonly bool           $successful
    readonly TransactionId  $transactionId
    readonly PaymentStatus  $status
    readonly string         $message
    readonly array          $rawResponse
}
```

#### `WebhookResponse` implements `WebhookResponseContract`

```
WebhookResponse {
    readonly bool               $successful
    readonly WebhookEventType   $eventType
    readonly string             $message
    readonly array              $rawPayload
}
```

#### `StatusResponse` implements `StatusResponseContract`

```
StatusResponse {
    readonly bool           $successful
    readonly TransactionId  $transactionId
    readonly PaymentStatus  $status
    readonly string         $message
    readonly array          $rawResponse
}
```

#### `VerificationResponse` implements `VerificationResponseContract`

```
VerificationResponse {
    readonly bool           $successful
    readonly bool           $verified
    readonly TransactionId  $transactionId
    readonly string         $message
    readonly array          $rawResponse
}
```

#### `SubscriptionResponse` implements `SubscriptionResponseContract`

```
SubscriptionResponse {
    readonly bool                   $successful
    readonly string                 $subscriptionId
    readonly PaymentStatus          $status
    readonly ?\DateTimeImmutable    $nextBillingDate
    readonly string                 $message
    readonly array                  $rawResponse
}
```

#### `PaymentLinkResponse` implements `PaymentLinkResponseContract`

```
PaymentLinkResponse {
    readonly bool                   $successful
    readonly string                 $paymentUrl
    readonly string                 $linkId
    readonly ?\DateTimeImmutable    $expiresAt
    readonly string                 $message
    readonly array                  $rawResponse
}
```

---

### Events (`src/Events/`)

All events are plain PHP classes (no interface required) compatible with Laravel's event dispatcher. Each event carries the relevant DTO and/or Response as readonly constructor properties.

| Event Class | Properties |
|-------------|-----------|
| `PaymentInitiated` | `PaymentRequest $request` |
| `PaymentSucceeded` | `PaymentRequest $request`, `PaymentResponse $response` |
| `PaymentFailed` | `PaymentRequest $request`, `?PaymentResponse $response`, `?\Throwable $exception` |
| `PaymentCaptured` | `CaptureRequest $request`, `CaptureResponse $response` |
| `PaymentRefunded` | `RefundRequest $request`, `RefundResponse $response` |
| `PaymentVoided` | `VoidRequest $request`, `VoidResponse $response` |
| `PaymentLinkCreated` | `PaymentLinkRequest $request`, `PaymentLinkResponse $response` |
| `CardSaved` | `SaveCardRequest $request`, `PaymentResponse $response` |
| `TokenCharged` | `TokenChargeRequest $request`, `PaymentResponse $response` |
| `WebhookReceived` | `WebhookRequest $request` |
| `WebhookProcessed` | `WebhookRequest $request`, `WebhookResponse $response` |
| `SubscriptionCreated` | `SubscriptionRequest $request`, `SubscriptionResponse $response` |
| `SubscriptionCancelled` | `TransactionId $subscriptionId`, `SubscriptionResponse $response` |
| `TransactionLookuped` | `TransactionLookupRequest $request`, `StatusResponse $response` |

---

### Exception Hierarchy

```
\RuntimeException
└── PaymentException                    (base for all framework exceptions)
    ├── DriverNotFoundException          (driver key not found in config)
    ├── InvalidConfigurationException   (missing/invalid config value)
    ├── WebhookVerificationException    (signature mismatch)
    ├── RefundFailedException           (unrecoverable refund failure)
    ├── CaptureFailedException          (unrecoverable capture failure)
    ├── VoidFailedException             (unrecoverable void failure)
    ├── AuthorizationFailedException    (auth/authorize failure)
    ├── SubscriptionException           (subscription lifecycle errors)
    ├── IdempotencyException            (missing/invalid idempotency key)
    └── UnsupportedOperationException   (driver does not support operation)
```

---

### Manager (`src/Managers/PaymentManager.php`)

Extends `Illuminate\Support\Manager`. Key methods:

```
PaymentManager {
    createDriver(string $driver): PaymentDriverContract
    getDefaultDriver(): string
    getAvailableDrivers(): array
    // Inherits: driver(), extend(), forgetDrivers()
}
```

Driver resolution flow:
1. Read `payment.drivers.{name}` from config.
2. Validate required keys exist; throw `InvalidConfigurationException` if not.
3. Instantiate the driver class (registered via `extend()` or resolved from container).
4. Verify it implements `PaymentDriverContract`; throw `DriverNotFoundException` if not.
5. Cache instance in `$drivers[name]`.

---

### Facade (`src/Facades/Payment.php`)

```php
class Payment extends Facade {
    protected static function getFacadeAccessor(): string {
        return PaymentManager::class;
    }

    public static function fake(): FakePaymentDriver { /* TODO */ }
}
```

---

### Services (`src/Services/`)

#### `PaymentService`

High-level orchestration service that wraps the Manager and provides convenience methods. Injected into controllers that prefer constructor injection over facade usage.

#### `WebhookVerifier`

Wraps driver's `verifyWebhookSignature()`. Logs result via `PaymentLoggerContract`. Throws `WebhookVerificationException` on failure, logging the driver name and truncated signature.

#### `RetryService`

Encapsulates the retry-with-backoff logic used by `AbstractDriver::withRetry()`. Reads `payment.retry.*` config values. Identifies transient (HTTP 429, 5xx) vs non-retryable errors.

---

### Logging (`src/Logging/`)

| Class | Behaviour |
|-------|-----------|
| `LaravelLogger` | Wraps `Illuminate\Log\LogManager`; writes to `payment.logging.channel` |
| `NullLogger` | Discards all messages; bound when `payment.logging.enabled = false` |
| `DebugLogger` | Writes to a dedicated debug channel; active when `payment.logging.debug = true` |
| `StackLogger` | Accepts an array of `PaymentLoggerContract` instances; fans out all messages |

All four implement `PaymentLoggerContract`.

---

### Webhooks (`src/Webhooks/`)

#### `WebhookController`

Laravel controller registered on `POST /payment/webhook/{driver}`. Responsibilities:
- Extract raw body, headers, and driver name from `Request`.
- Build `WebhookRequest` DTO.
- Dispatch `WebhookReceived`.
- Delegate to `WebhookProcessor`.
- Return HTTP 200 on success, HTTP 400 on `WebhookVerificationException`.

#### `WebhookProcessor`

Orchestrates the verification + processing sequence:
1. Call `WebhookVerifier::verify($request)`.
2. Resolve the driver via `PaymentManager`.
3. Call `driver->processWebhook($request)`.
4. Dispatch `WebhookProcessed`.

---

### Repositories (`src/Repositories/`)

#### `PaymentTransaction` (Eloquent model)

Mapped to `payment_transactions` table. Fillable: `transaction_id`, `driver`, `order_id`, `customer_id`, `amount`, `currency`, `status`, `payment_method`, `metadata`, `raw_response`, `idempotency_key`. Casts `metadata` and `raw_response` to `array`.

#### `EloquentPaymentTransactionRepository`

Implements `PaymentTransactionRepositoryContract` using the `PaymentTransaction` model. Bound when `payment.repository.enabled = true`.

#### `NullPaymentTransactionRepository`

Implements `PaymentTransactionRepositoryContract` with no-op methods. Bound by default.

---

### Service Provider (`src/Providers/PaymentServiceProvider.php`)

Boot sequence:
1. Merge default `config/payment.php`.
2. Validate config (throw `InvalidConfigurationException` if invalid).
3. Bind `PaymentManager` as singleton.
4. Register `Payment` facade alias.
5. Bind `PaymentLoggerContract` → `LaravelLogger` (or `NullLogger`).
6. Bind `PaymentTransactionRepositoryContract` → `EloquentPaymentTransactionRepository` or `NullPaymentTransactionRepository`.
7. Register `WebhookSucceeded` listener to call repository `store()` if enabled.
8. Register webhook route if `payment.webhook.enabled = true`.
9. Register publishable config (`--tag=payment-config`) and migrations (`--tag=payment-migrations`).

---

### Testing Support (`src/Testing/`)

#### `FakePaymentDriver`

Implements `PaymentDriverContract`. Stores all calls in internal arrays. Returns pre-configured `PaymentResponse` objects. Assertion helpers:

```
assertCharged(Money $amount): void
assertRefunded(TransactionId $id): void
assertNotCharged(): void
assertEventDispatched(string $eventClass): void
```

#### `PaymentFactory`

Fluent builder for generating valid test DTOs:

```
PaymentFactory::paymentRequest()
    ->withAmount(int $amount, Currency $currency)
    ->withCustomer(string $name, string $email)
    ->withIdempotencyKey(string $key)
    ->make(): PaymentRequest

PaymentFactory::refundRequest()
    ->withTransactionId(string $id)
    ->withAmount(int $amount, Currency $currency)
    ->make(): RefundRequest
```

---

### Config (`config/payment.php`)

Structure:

```php
return [
    'default' => env('PAYMENT_DRIVER', 'stripe'),

    'drivers' => [
        'stripe' => [
            'class'          => \YourVendor\StripeDriver\StripeDriver::class,
            'key'            => env('STRIPE_KEY'),
            'secret'         => env('STRIPE_SECRET'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            'sandbox'        => env('PAYMENT_SANDBOX', true),
            'environment'    => Environment::Sandbox,
            'timeout'        => 30,
            'currencies'     => ['USD', 'EUR', 'GBP'],
        ],
        // Additional driver blocks follow the same shape
    ],

    'currencies' => ['USD', 'EUR', 'GBP', 'SAR', 'AED', 'EGP'],

    'logging' => [
        'enabled' => env('PAYMENT_LOGGING_ENABLED', true),
        'channel' => env('PAYMENT_LOG_CHANNEL', 'stack'),
        'debug'   => env('PAYMENT_LOG_DEBUG', false),
    ],

    'retry' => [
        'enabled'      => env('PAYMENT_RETRY_ENABLED', true),
        'max_attempts' => env('PAYMENT_RETRY_MAX_ATTEMPTS', 3),
        'delay_ms'     => env('PAYMENT_RETRY_DELAY_MS', 500),
    ],

    'webhook' => [
        'enabled' => env('PAYMENT_WEBHOOK_ENABLED', true),
        'prefix'  => env('PAYMENT_WEBHOOK_PREFIX', 'payment/webhook'),
        'middleware' => ['api'],
    ],

    'repository' => [
        'enabled' => env('PAYMENT_REPOSITORY_ENABLED', false),
        'model'   => \Mifatoyeh\LaravelPaymentFramework\Repositories\PaymentTransaction::class,
    ],
];
```

---

### Routes (`routes/webhooks.php`)

```php
Route::post(
    config('payment.webhook.prefix', 'payment/webhook') . '/{driver}',
    [\Mifatoyeh\LaravelPaymentFramework\Webhooks\WebhookController::class, 'handle']
)->name('payment.webhook');
```

---

### Database Migration

**Table:** `payment_transactions`

| Column | Type | Notes |
|--------|------|-------|
| `id` | `bigIncrements` | Primary key |
| `transaction_id` | `string` | Unique provider transaction ID |
| `driver` | `string` | Driver name (e.g. `stripe`) |
| `order_id` | `string`, nullable | Host application order reference |
| `customer_id` | `string`, nullable | Host application customer reference |
| `amount` | `integer` | Smallest currency unit |
| `currency` | `string(3)` | ISO 4217 code |
| `status` | `string` | `PaymentStatus` value |
| `payment_method` | `string` | `PaymentMethod` value |
| `metadata` | `json`, nullable | Arbitrary application metadata |
| `raw_response` | `json`, nullable | Full provider response for debugging |
| `idempotency_key` | `string`, nullable | Idempotency key from request |
| `created_at` | `timestamp` | |
| `updated_at` | `timestamp` | |

Index: `transaction_id` (unique), `order_id`, `customer_id`, `idempotency_key`.

---

### Test Skeletons

#### Unit Tests (`tests/Unit/`)

- `ValueObjects/MoneyTest.php` — tests `Money` arithmetic, equality, format, and invalid construction
- `Enums/PaymentStatusTest.php` — tests enum case values and `from()`/`tryFrom()` behaviour
- `DTO/PaymentRequestTest.php` — tests DTO construction, validation, and readonly enforcement
- `Managers/PaymentManagerTest.php` — tests driver resolution, caching, unknown driver exception

#### Feature Tests (`tests/Feature/`)

- `PaymentChargeTest.php` — end-to-end charge flow using `FakePaymentDriver`
- `WebhookControllerTest.php` — webhook routing, signature verification, HTTP 400 on failure
- `FakePaymentDriverTest.php` — assertion helpers on `FakePaymentDriver`

---

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system — essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

---

### Property 1: Invalid Driver Resolution Throws

*For any* string value that is not registered as a driver key in the payment config, calling `PaymentManager::driver(string)` SHALL throw `DriverNotFoundException` containing the requested name in its message.

**Validates: Requirements 1.2, 8.3**

---

### Property 2: Driver Resolution Caching

*For any* registered driver name, resolving it via `PaymentManager::driver(name)` twice in the same request lifecycle SHALL return the identical (same reference) instance both times.

**Validates: Requirements 3.3**

---

### Property 3: Available Drivers Round-Trip

*For any* set of driver keys defined in `payment.drivers` config, `PaymentManager::getAvailableDrivers()` SHALL return exactly those keys (regardless of order), with no additions or omissions.

**Validates: Requirements 3.6**

---

### Property 4: Missing Config Key Throws at Resolution

*For any* driver config block where any one required key is absent, `PaymentManager::driver()` resolution SHALL throw `InvalidConfigurationException` identifying the missing key.

**Validates: Requirements 2.3**

---

### Property 5: Money Constructor Round-Trip

*For any* non-negative integer `n` and any `Currency` value `c`, `Money::of(n, c)` SHALL produce a `Money` instance where `->amount === n` and `->currency === c`.

**Validates: Requirements 10.1, 14.1**

---

### Property 6: Money Arithmetic Preserves Non-Negative Invariant

*For any* two `Money` instances `a` and `b` sharing the same `Currency`, `a->add(b)` SHALL return a new `Money` with `amount === a->amount + b->amount`. The result amount SHALL always be non-negative. Additionally, `a->add(b)->subtract(b)->equals(a)` SHALL be `true` (add/subtract round-trip).

**Validates: Requirements 5.5**

---

### Property 7: Cross-Currency Arithmetic Throws

*For any* two `Money` instances whose `Currency` values differ, calling `add()` or `subtract()` on one with the other as argument SHALL throw `\InvalidArgumentException`.

**Validates: Requirements 5.7**

---

### Property 8: String Value Object Round-Trip

*For any* non-empty string `s`, constructing any string-wrapping value object (`TransactionId`, `CustomerId`, `OrderId`, `WebhookSignature`, `Token`) via its `fromString(s)` named constructor and immediately calling `toString()` SHALL return a string equal to `s`.

**Validates: Requirements 10.2, 10.3, 10.4, 10.5, 10.6**

---

### Property 9: Empty String Throws on Value Object Construction

*For any* string-wrapping value object type (`TransactionId`, `CustomerId`, `OrderId`, `Token`) that requires a non-empty value, passing an empty string to its constructor or named constructor SHALL throw `\InvalidArgumentException`.

**Validates: Requirements 10.7**

---

### Property 10: DTO Invalid Field Throws

*For any* DTO class, constructing an instance with a null or empty value for a required field SHALL throw `\InvalidArgumentException`.

**Validates: Requirements 5.2**

---

### Property 11: Driver Methods Return Correct Response Contract

*For any* call to any of the 15 methods declared on `PaymentDriverContract`, the return value SHALL be an instance that implements the corresponding response contract interface (`PaymentResponseContract`, `RefundResponseContract`, etc.) as specified.

**Validates: Requirements 6.2**

---

### Property 12: Event Payload Completeness

*For any* `PaymentRequest` used to call `charge()` on the framework, the `PaymentSucceeded` event dispatched upon success SHALL carry that exact `PaymentRequest` instance and a `PaymentResponse` instance as its properties.

**Validates: Requirements 7.2, 7.4**

---

### Property 13: Payment Failed Always Dispatched on Failure

*For any* operation that results in failure — whether the driver returns an unsuccessful response or throws an exception — the `PaymentFailed` event SHALL be dispatched exactly once before the failure propagates to the caller.

**Validates: Requirements 7.3**

---

### Property 14: Webhook Verification Guards Processing

*For any* `WebhookRequest` for which `verifyWebhookSignature()` returns `false`, `processWebhook()` SHALL NOT be called on the driver. The controller SHALL throw `WebhookVerificationException` and return an HTTP 400 response.

**Validates: Requirements 20.2, 8.4, 11.4**

---

### Property 15: Webhook Event Ordering

*For any* valid (successfully verified) webhook request, `WebhookReceived` SHALL be dispatched before `WebhookProcessed`. `WebhookProcessed` SHALL only be dispatched after `processWebhook()` completes successfully.

**Validates: Requirements 11.5**

---

### Property 16: Retry on Transient Errors

*For any* configured `max_attempts` value N ≥ 1, a callable wrapped in `withRetry()` that throws a transient exception exactly N−1 times and succeeds on the Nth attempt SHALL return the successful result without propagating any exception.

**Validates: Requirements 13.3**

---

### Property 17: HTTP Status Code Transience Classification

*For any* HTTP status code in the range 500–599, or equal to 429, `RetryService` SHALL classify it as transient (retryable). For any HTTP status code in the range 400–428 or 430–499, it SHALL be classified as non-retryable.

**Validates: Requirements 13.5**

---

### Property 18: Idempotency Key Enforcement

*For any* `PaymentRequest` or `RefundRequest` whose `idempotencyKey` is an empty string or composed entirely of whitespace, the framework SHALL throw `IdempotencyException` before invoking the driver.

**Validates: Requirements 13.6**

---

### Property 19: FakePaymentDriver Assertion Round-Trip

*For any* `Money` amount charged via `FakePaymentDriver`, `assertCharged(amount)` SHALL not throw, and `assertNotCharged()` SHALL throw after that charge has been recorded. Conversely, before any charge is made, `assertNotCharged()` SHALL not throw.

**Validates: Requirements 15.3**

---

### Property 20: PaymentFactory Produces Valid DTOs

*For any* combination of valid parameter values passed to `PaymentFactory`'s fluent builder, the produced DTO instance SHALL be non-null, pass all of its own internal validation, and be an instance of the expected DTO class.

**Validates: Requirements 15.5**

---

### Property 21: Logger Receives Call for Every Driver Operation

*For any* driver method invocation (using `FakePaymentDriver`), the bound `PaymentLoggerContract` implementation SHALL receive at least one `info()` call containing contextual information about that operation.

**Validates: Requirements 12.5**

---

### Property 22: Webhook Verification Failure Logged at Error Level

*For any* `WebhookRequest` that causes `WebhookVerificationException` to be thrown, the bound `PaymentLoggerContract` SHALL receive an `error()` call whose context includes the driver name and a truncated (≤ 32 characters) form of the signature header value.

**Validates: Requirements 20.5**

---

### Property 23: UnsupportedOperationException Message Content

*For any* driver name `d` and operation name `op`, when `UnsupportedOperationException` is constructed with those values, `getMessage()` SHALL return a string containing both `d` and `op`.

**Validates: Requirements 8.5, 18.2**

---

## Error Handling

### Exception Throwing Rules

| Situation | Exception | HTTP Status (if applicable) |
|-----------|-----------|----------------------------|
| Driver name not found in config | `DriverNotFoundException` | — |
| Required config key missing | `InvalidConfigurationException` | — |
| Webhook signature invalid | `WebhookVerificationException` | 400 |
| Unrecoverable refund failure | `RefundFailedException` | — |
| Unrecoverable capture failure | `CaptureFailedException` | — |
| Unrecoverable void failure | `VoidFailedException` | — |
| Authorization failure | `AuthorizationFailedException` | — |
| Subscription error | `SubscriptionException` | — |
| Missing/empty idempotency key | `IdempotencyException` | — |
| Operation not supported by driver | `UnsupportedOperationException` | — |
| Invalid value in DTO/Value Object | `\InvalidArgumentException` | — |

### Provider Failure Handling

For recoverable provider failures (e.g., card declined, insufficient funds):
- The driver returns a `PaymentResponse` with `isSuccessful() === false`.
- The driver does NOT throw an exception.
- The framework dispatches `PaymentFailed`.
- The host application checks `$response->isSuccessful()`.

For unrecoverable failures (e.g., network timeout after all retries, auth error):
- The driver wraps the underlying exception in the appropriate `PaymentException` subclass.
- The framework dispatches `PaymentFailed` before rethrowing.
- The host application catches `PaymentException` (or a subclass).

### Webhook Error Handling

- Invalid signature → `WebhookVerificationException` → HTTP 400 (logged at error level).
- Valid signature but processing failure → exception propagates → host application's exception handler returns appropriate HTTP response.
- The framework never swallows exceptions silently; all failures are logged.

### Config Validation

At `PaymentServiceProvider::boot()`, the framework validates:
- `payment.default` is a non-empty string matching a key in `payment.drivers`.
- Each driver block has a `class` key whose value is a string.
- `payment.retry.max_attempts` is a positive integer.
- `payment.retry.delay_ms` is a non-negative integer.

Validation failure throws `InvalidConfigurationException` with the specific invalid key identified.

---

## Testing Strategy

### Overview

The framework uses a dual testing approach: property-based tests for universal correctness properties and example-based unit/feature tests for specific scenarios, integration points, and configuration behavior.

### Property-Based Testing

**Library:** [`jqno/equalsverifier`](https://jqno.nl/equalsverifier/) for Java-style equality contracts is not applicable here; for PHP the recommended library is **[`antecedent/patchwork`](https://github.com/antecedent/patchwork)** combined with **[`eris`](https://github.com/giorgiosironi/eris)** (the PHP property-based testing library). Alternatively, **[`innmind/black-box`](https://github.com/Innmind/BlackBox)** is a PHP-native property testing framework compatible with PHPUnit that supports generators and shrinking.

**Recommended library:** `innmind/black-box` — PHPUnit-compatible, supports composable generators, and provides shrinking.

**Configuration:** Minimum 100 iterations per property test.

**Tag format:** Each property test MUST include a comment in this format:
```
// Feature: laravel-payment-framework, Property N: <property_text>
```

**Properties to implement as property-based tests:**

| Property | Test Location | Generator Strategy |
|----------|--------------|-------------------|
| P1: Invalid driver resolution | `tests/Unit/Managers/PaymentManagerTest.php` | Generate arbitrary non-registered strings |
| P2: Driver caching | `tests/Unit/Managers/PaymentManagerTest.php` | Registered driver names |
| P3: Available drivers round-trip | `tests/Unit/Managers/PaymentManagerTest.php` | Generate sets of driver key names |
| P4: Missing config key throws | `tests/Unit/Managers/PaymentManagerTest.php` | Generate driver configs with random key removed |
| P5: Money constructor round-trip | `tests/Unit/ValueObjects/MoneyTest.php` | Non-negative integers × Currency enum values |
| P6: Money arithmetic invariant | `tests/Unit/ValueObjects/MoneyTest.php` | Pairs of non-negative integers × single Currency |
| P7: Cross-currency arithmetic throws | `tests/Unit/ValueObjects/MoneyTest.php` | Pairs of distinct Currency values |
| P8: String value object round-trip | `tests/Unit/ValueObjects/ValueObjectTest.php` | Non-empty strings |
| P9: Empty string throws | `tests/Unit/ValueObjects/ValueObjectTest.php` | Empty string |
| P10: DTO invalid field throws | `tests/Unit/DTO/PaymentRequestTest.php` | DTOs with nulled required fields |
| P11: Driver methods return correct contract | `tests/Unit/Drivers/AbstractDriverTest.php` | All 15 method names |
| P12: Event payload completeness | `tests/Feature/PaymentChargeTest.php` | Random PaymentRequest instances |
| P13: PaymentFailed always dispatched | `tests/Feature/PaymentChargeTest.php` | Various failure scenarios |
| P14: Webhook verification guards processing | `tests/Feature/WebhookControllerTest.php` | Invalid signature inputs |
| P15: Webhook event ordering | `tests/Feature/WebhookControllerTest.php` | Valid webhook requests |
| P16: Retry on transient errors | `tests/Unit/Services/RetryServiceTest.php` | N in 1..10, callables failing N-1 times |
| P17: HTTP status code classification | `tests/Unit/Services/RetryServiceTest.php` | HTTP codes 400..599 |
| P18: Idempotency key enforcement | `tests/Unit/DTO/PaymentRequestTest.php` | Empty/whitespace strings |
| P19: FakePaymentDriver assertion round-trip | `tests/Feature/FakePaymentDriverTest.php` | Random Money amounts |
| P20: PaymentFactory produces valid DTOs | `tests/Unit/Testing/PaymentFactoryTest.php` | Random valid parameter combinations |
| P21: Logger receives call for every operation | `tests/Feature/PaymentChargeTest.php` | All 15 driver method names |
| P22: Verification failure logged | `tests/Feature/WebhookControllerTest.php` | Invalid signatures |
| P23: UnsupportedOperationException message | `tests/Unit/Exceptions/ExceptionTest.php` | Driver × operation name pairs |

### Unit Tests

Unit tests cover:
- Each enum's cases and backing values (smoke).
- Each DTO's structure via reflection (readonly, correct types).
- Service provider bindings (correct contract → implementation binding).
- Logger implementations: `NullLogger` discards, `StackLogger` fans out, `LaravelLogger` writes to correct channel.
- `FakePaymentDriver` stores calls correctly.

### Feature Tests

Feature tests cover:
- Full charge flow via facade using `FakePaymentDriver`.
- Webhook HTTP routing (POST to `/payment/webhook/{driver}`).
- Event dispatching verified with `Event::fake()`.
- Config-driven behavior (repository enabled/disabled, logging enabled/disabled, webhook prefix).
- `Payment::fake()` swaps driver correctly.

### Testing Best Practices

- All tests use `FakePaymentDriver` — no real provider credentials needed.
- `Event::fake()` is used in all feature tests that assert event dispatching.
- The `PaymentFactory` is used in tests to create valid DTOs without manual construction boilerplate.
- Tests that verify logging use a `MockPaymentLogger` (implements `PaymentLoggerContract`) to capture calls.
- No test should make outbound HTTP calls.
