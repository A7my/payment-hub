# Bugfix Requirements Document

## Introduction

`PaymobClient` hard-codes a single authentication strategy: posting `{ api_key }` to `/auth/tokens`
and injecting the returned short-lived token into every subsequent request. This strategy is specific
to Paymob's Egypt/Accept API. Paymob's Saudi Arabia (KSA) platform is a separate, newer API that
does **not** expose `/auth/tokens` at all — it authenticates via a static Bearer token passed as an
`Authorization: Bearer <secret_key>` header on every HTTP request.

Because `PaymobDriver` always calls `$this->client->authenticate()` as the very first step of every
public method (`charge`, `authorize`, `void`, `capture`, `refund`, `partialRefund`, `verify`,
`lookup`, `saveCard`, `chargeToken`, `createPaymentLink`), any attempt to use KSA credentials
immediately produces an HTTP 403 before an order or payment key is ever attempted. The fix must
introduce a KSA-aware auth path while leaving the existing Egypt/Accept path completely unchanged.

---

## Bug Analysis

### Current Behavior (Defect)

1.1 WHEN the configured `base_url` targets `ksa.paymob.com` (i.e. the KSA API) THEN the system
    POSTs `{ api_key }` to `/auth/tokens` and receives an HTTP 403 error, aborting the operation
    before any payment request is made.

1.2 WHEN a `secret_key` with the `sau_sk_test_` or `sau_sk_live_` prefix is present in config and
    no KSA-compatible auth path exists THEN the system falls through to the legacy token-exchange
    flow and receives an HTTP 403 error.

1.3 WHEN any `PaymobDriver` method (`charge`, `authorize`, `void`, `capture`, `refund`,
    `partialRefund`, `verify`, `lookup`, `saveCard`, `chargeToken`, `createPaymentLink`) is called
    with KSA credentials THEN the system throws an exception immediately at the `authenticate()`
    call, never reaching the actual operation.

### Expected Behavior (Correct)

2.1 WHEN the configured `base_url` contains `ksa.paymob.com` THEN the system SHALL skip the
    `/auth/tokens` token-exchange step and instead attach an `Authorization: Bearer <secret_key>`
    header to every outgoing HTTP request.

2.2 WHEN a `secret_key` with the `sau_sk_test_` or `sau_sk_live_` prefix is present in config
    THEN the system SHALL use it as a static Bearer token, attaching it as an `Authorization:
    Bearer <secret_key>` header on every request, with no call to `/auth/tokens`.

2.3 WHEN any `PaymobDriver` method is called with KSA credentials THEN the system SHALL proceed
    past authentication without error and attempt the actual payment operation (create order,
    request payment key, etc.) against the KSA API endpoint.

2.4 WHEN operating in KSA mode THEN the system SHALL NOT include `auth_token` fields in request
    bodies (e.g. `createOrder`, `requestPaymentKey`, `voidTransaction`, `captureTransaction`,
    `refundTransaction`), since the KSA API authenticates via header only.

### Unchanged Behavior (Regression Prevention)

3.1 WHEN the configured `base_url` targets `accept.paymob.com` (Egypt/Accept API) THEN the system
    SHALL CONTINUE TO POST `{ api_key }` to `/auth/tokens` and use the returned token in all
    subsequent request bodies, exactly as before.

3.2 WHEN no `secret_key` is present and an `api_key` is configured THEN the system SHALL CONTINUE
    TO use the legacy token-exchange authentication flow without any change in behavior.

3.3 WHEN using Egypt/Accept credentials, `charge()`, `authorize()`, `void()`, `capture()`,
    `refund()`, `partialRefund()`, `verify()`, `lookup()`, `saveCard()`, `chargeToken()`, and
    `createPaymentLink()` SHALL CONTINUE TO function identically to their current implementations.

3.4 WHEN a Paymob API request fails with a non-2xx response in either auth mode THEN the system
    SHALL CONTINUE TO throw a `PaymobApiException` with the error details from the response body.
