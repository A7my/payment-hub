# Paymob KSA Auth Bugfix Design

## Overview

`PaymobClient` hard-codes a single authentication strategy: `POST /auth/tokens` with `{api_key}`,
then inject the returned short-lived token into every subsequent request body. Paymob's KSA
(Saudi Arabia) platform does **not** expose `/auth/tokens` — it authenticates via a static Bearer
token in an `Authorization` header on every HTTP request. Because `PaymobDriver` always calls
`$this->client->authenticate()` as the first step of every public method, any use of KSA
credentials immediately produces an HTTP 403 before an order or payment key is ever attempted.

The fix introduces a KSA detection predicate (checking `base_url` for `ksa.paymob.com` or
`secret_key` for the `sau_sk_test_` / `sau_sk_live_` prefix) and a second code path inside
`PaymobClient` that:
1. Skips the `/auth/tokens` call entirely.
2. Injects `Authorization: Bearer <secret_key>` as an HTTP header on every outgoing request.
3. Omits `auth_token` fields from all request bodies (KSA authenticates via header only).

The existing Egypt/Accept path is left completely untouched.

---

## Glossary

- **Bug_Condition (C)**: The condition that identifies a KSA-mode configuration — either
  `base_url` contains `ksa.paymob.com` or `secret_key` starts with `sau_sk_test_` /
  `sau_sk_live_`.
- **Property (P)**: The desired correct behaviour when KSA credentials are used — requests carry
  an `Authorization: Bearer <secret_key>` header and no `auth_token` body field; no call to
  `/auth/tokens` is made.
- **Preservation**: The Egypt/Accept authentication flow (`POST /auth/tokens` → inject token into
  request bodies) must remain completely unchanged for all non-KSA configurations.
- **`PaymobClient`**: `src/Drivers/Paymob/PaymobClient.php` — the thin HTTP transport layer
  responsible for all Paymob API calls. All auth logic lives here.
- **`PaymobDriver`**: `src/Drivers/Paymob/PaymobDriver.php` — orchestrates multi-step Paymob
  sequences (`authenticate → createOrder → requestPaymentKey → pay`). Calls `authenticate()`
  first in every public method today.
- **`isKsaMode()`**: The new predicate to be added to `PaymobClient`, encapsulating the KSA
  detection logic so it is defined exactly once.
- **`secret_key`**: A new config key (alongside the existing `api_key`) holding the static KSA
  Bearer credential. Values begin with `sau_sk_test_` (sandbox) or `sau_sk_live_` (production).
- **`auth_token` body field**: The short-lived token injected into Egypt/Accept request bodies
  (`createOrder`, `requestPaymentKey`, `voidTransaction`, `captureTransaction`,
  `refundTransaction`, `retrieveTransaction`). Must be absent in KSA mode.

---

## Bug Details

### Bug Condition

The bug manifests whenever a caller uses KSA credentials (either `base_url` contains
`ksa.paymob.com`, or `secret_key` has a `sau_sk_test_`/`sau_sk_live_` prefix). In this
situation `PaymobClient::authenticate()` is called, which POSTs `{ api_key }` to
`/auth/tokens` — an endpoint that does not exist on the KSA platform — producing an HTTP 403.
The operation aborts before any order or payment is ever attempted.

**Formal Specification:**

```
FUNCTION isBugCondition(config)
  INPUT: config of type array<string, mixed>
  OUTPUT: boolean

  base_url   := config['base_url'] ?? 'https://accept.paymob.com/api'
  secret_key := config['secret_key'] ?? ''

  RETURN str_contains(base_url, 'ksa.paymob.com')
         OR str_starts_with(secret_key, 'sau_sk_test_')
         OR str_starts_with(secret_key, 'sau_sk_live_')
END FUNCTION
```

### Examples

- **KSA base_url**: `base_url = 'https://ksa.paymob.com/api'`, any `api_key` → `authenticate()`
  POSTs to `https://ksa.paymob.com/api/auth/tokens` → HTTP 403 → `PaymobApiException` thrown
  before `createOrder` is ever called. **Expected**: request carries `Authorization: Bearer <secret_key>` header, no call to `/auth/tokens`.
- **KSA secret_key prefix (test)**: `secret_key = 'sau_sk_test_abc123'`, `base_url` may still
  default to `accept.paymob.com` → same legacy auth flow fires → HTTP 403 or incorrect behaviour.
  **Expected**: KSA Bearer path used regardless of `base_url` value.
- **KSA secret_key prefix (live)**: `secret_key = 'sau_sk_live_xyz789'` → same as above.
  **Expected**: KSA Bearer path used.
- **`auth_token` in body (KSA)**: Even if auth somehow succeeded, `createOrder` sends
  `auth_token` in the request body — the KSA API ignores / rejects body-level tokens.
  **Expected**: `auth_token` fields absent from all KSA request bodies.

---

## Expected Behavior

### Preservation Requirements

**Unchanged Behaviors:**
- Egypt/Accept authentication (`POST /auth/tokens` → `{ api_key }` → return `token`) must
  continue to fire for all configurations where `isBugCondition(config)` is `false`.
- All `auth_token` body fields in `createOrder`, `requestPaymentKey`, `voidTransaction`,
  `captureTransaction`, `refundTransaction`, and `retrieveTransaction` must remain present and
  correct for Egypt/Accept mode.
- `PaymobDriver`'s `charge`, `authorize`, `void`, `capture`, `refund`, `partialRefund`,
  `verify`, `lookup`, `saveCard`, `chargeToken`, and `createPaymentLink` must continue to
  function identically with Egypt/Accept credentials.
- Any non-2xx response from the Paymob API (either mode) must continue to throw a
  `PaymobApiException` with the relevant error details from the response body.

**Scope:**
All configurations where `isBugCondition(config)` returns `false` (i.e. `base_url` does not
contain `ksa.paymob.com` and `secret_key` does not start with `sau_sk_test_` or
`sau_sk_live_`) must be completely unaffected. This includes:
- Any config with only `api_key` and no `secret_key`.
- Any config with `base_url` pointing to `accept.paymob.com` and no KSA `secret_key`.
- All existing test suites that exercise Egypt/Accept behaviour.

---

## Hypothesized Root Cause

Based on the bug description and code review, the root cause is:

1. **Single hardcoded auth strategy in `PaymobClient`**: `authenticate()` always POSTs to
   `/auth/tokens` with `api_key` — there is no branch for a different auth mechanism. The KSA
   API does not expose this endpoint, so the call always returns HTTP 403.

2. **`auth_token` embedded in every mutating request body**: `createOrder`, `requestPaymentKey`,
   `voidTransaction`, `captureTransaction`, `refundTransaction`, and `retrieveTransaction` all
   unconditionally include `auth_token` as a body field. The KSA API authenticates via
   `Authorization` header alone and does not consume a body-level token.

3. **No KSA-aware request builder**: `PaymobClient::request()` constructs a `PendingRequest`
   with `baseUrl`, `timeout`, and `acceptJson()` only — no `Authorization` header is ever added.
   A KSA code path requires injecting `withToken($secret_key)` (or equivalent
   `->withHeaders(['Authorization' => "Bearer $secret_key"])`) on the pending request.

4. **No config key for `secret_key`**: The current config shape (`config/payment.php`) only
   documents `api_key`. A new `secret_key` config key must be added (with env var
   `PAYMOB_SECRET_KEY`) to hold the KSA static credential.

---

## Correctness Properties

Property 1: Bug Condition — KSA Requests Use Bearer Auth Without `/auth/tokens`

_For any_ configuration where the bug condition holds (`isBugCondition(config)` returns `true`),
the fixed `PaymobClient` SHALL attach an `Authorization: Bearer <secret_key>` header to every
outgoing HTTP request, SHALL NOT call `POST /auth/tokens`, and SHALL NOT include `auth_token`
fields in any request body.

**Validates: Requirements 2.1, 2.2, 2.3, 2.4**

Property 2: Preservation — Egypt/Accept Auth Flow Unchanged

_For any_ configuration where the bug condition does NOT hold (`isBugCondition(config)` returns
`false`), the fixed `PaymobClient` SHALL produce exactly the same HTTP requests as the original
`PaymobClient`, preserving the `POST /auth/tokens` token-exchange and all `auth_token` body
fields in `createOrder`, `requestPaymentKey`, `voidTransaction`, `captureTransaction`,
`refundTransaction`, and `retrieveTransaction`.

**Validates: Requirements 3.1, 3.2, 3.3, 3.4**

---

## Fix Implementation

### Changes Required

Assuming our root cause analysis is correct:

**File 1**: `src/Drivers/Paymob/PaymobClient.php`

**Specific Changes:**

1. **Add `isKsaMode()` predicate**: A `private` method that reads `$this->config['base_url']`
   and `$this->config['secret_key']` and returns `true` when KSA detection conditions are met.
   This is the single definition of the bug condition in production code.

   ```php
   private function isKsaMode(): bool
   {
       $baseUrl   = (string) ($this->config['base_url'] ?? '');
       $secretKey = (string) ($this->config['secret_key'] ?? '');

       return str_contains($baseUrl, 'ksa.paymob.com')
           || str_starts_with($secretKey, 'sau_sk_test_')
           || str_starts_with($secretKey, 'sau_sk_live_');
   }
   ```

2. **Modify `authenticate()`**: If `isKsaMode()` is true, skip the HTTP call and return the
   `secret_key` directly (it is used as the "auth token" passed into subsequent driver calls,
   but will be ignored when building request bodies in KSA mode — see change 3).

   ```php
   public function authenticate(): string
   {
       if ($this->isKsaMode()) {
           return (string) ($this->config['secret_key'] ?? '');
       }

       $response = $this->request()->post('/auth/tokens', [
           'api_key' => (string) ($this->config['api_key'] ?? ''),
       ]);

       $body = $this->decode($response, 'authenticate');
       return (string) ($body['token'] ?? '');
   }
   ```

3. **Modify `request()` builder**: When `isKsaMode()` is true, add an `Authorization: Bearer`
   header to every outgoing `PendingRequest`.

   ```php
   private function request(): PendingRequest
   {
       $pending = $this->http
           ->baseUrl(rtrim((string) ($this->config['base_url'] ?? 'https://accept.paymob.com/api'), '/'))
           ->timeout((int) ($this->config['timeout'] ?? 30))
           ->acceptJson();

       if ($this->isKsaMode()) {
           $pending = $pending->withToken((string) ($this->config['secret_key'] ?? ''));
       }

       return $pending;
   }
   ```

4. **Strip `auth_token` from KSA request bodies**: Each of `createOrder`, `requestPaymentKey`,
   `voidTransaction`, `captureTransaction`, `refundTransaction`, and `retrieveTransaction` must
   conditionally omit the `auth_token` field (or use a different query-parameter approach for
   `retrieveTransaction`) when `isKsaMode()` is true. The cleanest approach is a small private
   helper:

   ```php
   /**
    * Build the auth_token field for request bodies — empty array in KSA mode
    * (header-based auth), or ['auth_token' => $token] for Egypt/Accept mode.
    *
    * @return array<string, string>
    */
   private function authBody(string $authToken): array
   {
       return $this->isKsaMode() ? [] : ['auth_token' => $authToken];
   }
   ```

   Each affected method spreads `...$this->authBody($authToken)` into its request body array,
   and `retrieveTransaction` omits the `token` query parameter in KSA mode.

5. **Add `secret_key` to `config/payment.php`**: Document the new config key with an env-var
   fallback and an explanatory comment.

   ```php
   // Paymob KSA static secret key (Developers > API Keys on the KSA dashboard).
   // Used as a static Bearer token for Saudi Arabia requests.
   // When present with a 'sau_sk_test_' or 'sau_sk_live_' prefix, KSA mode
   // is automatically activated regardless of base_url.
   'secret_key' => env('PAYMOB_SECRET_KEY'),
   ```

**File 2**: `config/payment.php`

Add the `secret_key` key to the `paymob` driver block (see change 5 above).

---

## Testing Strategy

### Validation Approach

The testing strategy follows a two-phase approach: first, surface counterexamples that demonstrate
the bug on unfixed code, then verify the fix works correctly and preserves existing Egypt/Accept
behaviour.

### Exploratory Bug Condition Checking

**Goal**: Surface counterexamples that demonstrate the bug BEFORE implementing the fix. Confirm
or refute the root cause analysis. If we refute, we will need to re-hypothesize.

**Test Plan**: Use `PaymobClient::setTestHttpFactory()` to install a fake HTTP factory, configure
KSA credentials, call `authenticate()`, and assert that no `POST /auth/tokens` request was made.
Run these tests on the UNFIXED code to observe failures and understand the root cause.

**Test Cases:**

1. **KSA base_url — no `/auth/tokens` call**: Configure `base_url = 'https://ksa.paymob.com/api'`,
   call `authenticate()`, assert no HTTP request to `/auth/tokens` was made.
   (Will fail on unfixed code — the request IS made and returns 403.)

2. **KSA secret_key test prefix — no `/auth/tokens` call**: Configure
   `secret_key = 'sau_sk_test_abc'`, call `authenticate()`, assert no HTTP request to
   `/auth/tokens` was made. (Will fail on unfixed code.)

3. **KSA secret_key live prefix — no `/auth/tokens` call**: Configure
   `secret_key = 'sau_sk_live_xyz'`, call `authenticate()`, assert no `/auth/tokens` request.
   (Will fail on unfixed code.)

4. **KSA `createOrder` — no `auth_token` in body**: Configure KSA credentials, call
   `createOrder('any_token', 1000, 'SAR', 'order-1')`, inspect the captured request body and
   assert `auth_token` is absent. (Will fail on unfixed code — body always includes it.)

**Expected Counterexamples:**
- `authenticate()` fires a POST to `/auth/tokens` even with KSA config — the HTTP 403 that
  results in production is replaced in tests by a fake 403 response to make the failure visible.
- `auth_token` appears in `createOrder` / `requestPaymentKey` / `voidTransaction` etc. bodies
  regardless of `base_url`.

### Fix Checking

**Goal**: Verify that for all inputs where the bug condition holds, the fixed `PaymobClient`
produces the expected behaviour.

**Pseudocode:**

```
FOR ALL config WHERE isBugCondition(config) DO
  client := new PaymobClient(config, fakeHttpFactory)

  -- authenticate() must return secret_key without making HTTP calls
  token := client.authenticate()
  ASSERT token == config['secret_key']
  ASSERT fakeHttpFactory.recordedRequests IS EMPTY

  -- Every request must carry Authorization: Bearer header
  client.createOrder(token, 1000, 'SAR', 'ord-1')
  ASSERT lastRequest.headers['Authorization'] == 'Bearer ' + config['secret_key']

  -- auth_token must be absent from all request bodies
  ASSERT 'auth_token' NOT IN lastRequest.body
END FOR
```

### Preservation Checking

**Goal**: Verify that for all inputs where the bug condition does NOT hold, the fixed
`PaymobClient` produces exactly the same HTTP requests as the original.

**Pseudocode:**

```
FOR ALL config WHERE NOT isBugCondition(config) DO
  client_original := original PaymobClient(config, fakeFactory)
  client_fixed    := fixed    PaymobClient(config, fakeFactory)

  ASSERT client_original.authenticate() requests == client_fixed.authenticate() requests
  ASSERT client_original.createOrder(...) body == client_fixed.createOrder(...) body
  ASSERT client_original.voidTransaction(...) body == client_fixed.voidTransaction(...) body
  -- etc. for every mutating method
END FOR
```

**Testing Approach**: Property-based testing is recommended for preservation checking because:
- It generates many Egypt/Accept config variations automatically (different `api_key` values,
  timeouts, missing optional keys) to verify the Egypt path is unaffected.
- It catches edge cases (empty string `secret_key`, `base_url` without `ksa.paymob.com`) that
  manual tests might miss.
- It provides strong guarantees that no incidental config value accidentally enables KSA mode.

**Test Plan**: Observe the exact HTTP requests the original `PaymobClient` makes for Egypt/Accept
configs, then write property-based tests asserting the fixed client produces identical requests.

**Test Cases:**

1. **Egypt/Accept `authenticate()` preservation**: Verify the fixed client still POSTs to
   `/auth/tokens` with `{ api_key }` for any config without KSA indicators, and that the
   returned token is used correctly.

2. **`auth_token` body preservation (Egypt/Accept)**: For every mutating method
   (`createOrder`, `requestPaymentKey`, `voidTransaction`, `captureTransaction`,
   `refundTransaction`), verify `auth_token` is still present in the request body.

3. **No `Authorization` header for Egypt/Accept**: Verify the fixed client does NOT add an
   `Authorization: Bearer` header to any request when using Egypt/Accept config.

4. **Error propagation preservation**: Verify that non-2xx responses still throw
   `PaymobApiException` with the correct message and status code in both modes.

### Unit Tests

- Test `isKsaMode()` (via behaviour, not direct access) for all detection variants:
  `ksa.paymob.com` in `base_url`; `sau_sk_test_` prefix; `sau_sk_live_` prefix; negative cases
  (Egypt `base_url`, no `secret_key`, unrecognised prefix).
- Test that KSA `authenticate()` returns `secret_key` and makes zero HTTP calls.
- Test that Egypt/Accept `authenticate()` POSTs to `/auth/tokens` and returns the `token` field.
- Test each request method for presence/absence of `auth_token` in both modes.
- Test `Authorization` header presence (KSA) and absence (Egypt/Accept) on every request method.
- Test `retrieveTransaction` omits the `token` query parameter in KSA mode.
- Test `decode()` error path: non-2xx response → `PaymobApiException` with correct status and
  body, in both auth modes.

### Property-Based Tests

- Generate random Egypt/Accept configs (varying `api_key`, `base_url` without `ksa.paymob.com`,
  no `secret_key`) and assert fixed client behaviour is identical to original for all
  `PaymobClient` methods.
- Generate random KSA configs (either `ksa.paymob.com` in `base_url` or `sau_sk_*` prefix) and
  assert: no `/auth/tokens` call, `Authorization` header present on every request, no
  `auth_token` in any body.
- Generate boundary `secret_key` values (empty string, non-KSA-prefixed string, `sau_sk_test_`
  with no suffix) to verify the predicate boundary exactly.

### Integration Tests

- Full `PaymobDriver::charge()` flow with KSA config using `setTestHttpFactory` — fake
  `createOrder`, `requestPaymentKey`, and `payWithToken` responses, assert no call to
  `/auth/tokens` and all three fake endpoints receive `Authorization: Bearer` header.
- Full `PaymobDriver::void()` / `capture()` / `refund()` flows with KSA config — verify the
  same header pattern and absent `auth_token` body fields.
- Full Egypt/Accept `PaymobDriver::charge()` flow with fixed code — assert behaviour is
  indistinguishable from the pre-fix behaviour (same HTTP calls, same body shapes).
