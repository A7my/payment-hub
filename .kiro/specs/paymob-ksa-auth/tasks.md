# Implementation Plan

- [x] 1. Write bug condition exploration test
  - **Property 1: Bug Condition** - KSA Config Fires `/auth/tokens` (Unfixed)
  - **CRITICAL**: This test MUST FAIL on unfixed code — failure confirms the bug exists
  - **DO NOT attempt to fix the test or the code when it fails**
  - **NOTE**: This test encodes the expected behavior — it will validate the fix when it passes after implementation
  - **GOAL**: Surface counterexamples that demonstrate that any config where `isBugCondition(config)` is true still fires `POST /auth/tokens` and embeds `auth_token` in request bodies
  - **Scoped PBT Approach**: Scope the property to the three concrete KSA config variants: `ksa.paymob.com` base_url, `sau_sk_test_` prefix, and `sau_sk_live_` prefix
  - For each KSA config variant: call `authenticate()` and assert no `POST /auth/tokens` request was made and the returned value equals `secret_key`
  - For each KSA config variant: call `createOrder(token, 1000, 'SAR', 'ord-1')` and assert `auth_token` is absent from the request body
  - Run test on UNFIXED code
  - **EXPECTED OUTCOME**: Test FAILS (this is correct — it proves the bug exists)
  - Document counterexamples found: e.g. `authenticate()` fires `POST /auth/tokens` even with `base_url = 'https://ksa.paymob.com/api'`; `createOrder()` sends `auth_token` in body for all KSA configs
  - Mark task complete when test is written, run, and failure is documented
  - _Requirements: 1.1, 1.2, 1.3_

- [x] 2. Write preservation property tests (BEFORE implementing fix)
  - **Property 2: Preservation** - Egypt/Accept Auth Flow Unchanged
  - **IMPORTANT**: Follow observation-first methodology
  - Observe behavior on UNFIXED code for non-buggy inputs (all configs where `isBugCondition` returns false)
  - Observe: `authenticate()` POSTs `{ api_key }` to `/auth/tokens` and returns the `token` field for Egypt configs
  - Observe: `createOrder()`, `requestPaymentKey()`, `voidTransaction()`, `captureTransaction()`, `refundTransaction()` all include `auth_token` in request body for Egypt configs
  - Observe: no `Authorization: Bearer` header is sent for Egypt configs
  - Observe: `retrieveTransaction()` sends the token as a `token` query param for Egypt configs
  - Write property-based test: for a representative set of Egypt/Accept configs (varying `api_key`, default `base_url`, no `secret_key`, `secret_key = ''`, `secret_key = 'some_non_ksa_value'`), assert all of the above observations hold
  - Run tests on UNFIXED code
  - **EXPECTED OUTCOME**: Tests PASS (this confirms baseline behavior to preserve)
  - Mark task complete when tests are written, run, and passing on unfixed code
  - _Requirements: 3.1, 3.2, 3.3, 3.4_

- [x] 3. Fix KSA authentication in PaymobClient

  - [x] 3.1 Add `isKsaMode()` private predicate to `PaymobClient`
    - Add private method that returns true when `base_url` contains `ksa.paymob.com` OR `secret_key` starts with `sau_sk_test_` OR `sau_sk_live_`
    - Single authoritative definition of the bug condition in production code
    - _Bug_Condition: isBugCondition(config) — str_contains(base_url, 'ksa.paymob.com') OR str_starts_with(secret_key, 'sau_sk_test_') OR str_starts_with(secret_key, 'sau_sk_live_')_
    - _Requirements: 2.1, 2.2_

  - [x] 3.2 Modify `authenticate()` to skip `/auth/tokens` in KSA mode
    - When `isKsaMode()` is true, return `secret_key` directly without making any HTTP call
    - When `isKsaMode()` is false, keep existing POST to `/auth/tokens` with `api_key` unchanged
    - _Expected_Behavior: authenticate() returns secret_key with zero HTTP calls when isBugCondition(config) is true_
    - _Preservation: Egypt/Accept configs still POST to /auth/tokens and return the token field_
    - _Requirements: 2.1, 2.2, 3.1, 3.2_

  - [x] 3.3 Modify `request()` builder to attach `Authorization: Bearer` header in KSA mode
    - When `isKsaMode()` is true, chain `->withToken((string) ($this->config['secret_key'] ?? ''))` on the `PendingRequest`
    - When `isKsaMode()` is false, leave the builder unchanged
    - _Expected_Behavior: every outgoing HTTP request carries Authorization: Bearer <secret_key> header in KSA mode_
    - _Preservation: no Authorization header added for Egypt/Accept configs_
    - _Requirements: 2.1, 2.2, 3.1_

  - [x] 3.4 Add `authBody()` helper and update all affected request methods
    - Add private `authBody(string $authToken): array` helper — returns `[]` in KSA mode, `['auth_token' => $authToken]` otherwise
    - Update `createOrder()` — spread `...$this->authBody($authToken)` instead of hardcoded `'auth_token' => $authToken`
    - Update `requestPaymentKey()` — same spread pattern
    - Update `voidTransaction()` — same spread pattern
    - Update `captureTransaction()` — same spread pattern
    - Update `refundTransaction()` — same spread pattern
    - Update `retrieveTransaction()` — in KSA mode omit the `token` query param entirely; in Egypt mode keep `['token' => $authToken]`
    - _Bug_Condition: isBugCondition(config) is true → auth_token absent from all request bodies_
    - _Preservation: auth_token still present in all request bodies for Egypt/Accept configs_
    - _Requirements: 2.4, 3.1, 3.2_

  - [x] 3.5 Add `secret_key` to `config/payment.php`
    - Add `'secret_key' => env('PAYMOB_SECRET_KEY')` to the `paymob` driver block
    - Include explanatory comment about KSA usage and the `sau_sk_test_`/`sau_sk_live_` prefix convention
    - _Requirements: 2.1, 2.2_

  - [x] 3.6 Verify bug condition exploration test now passes
    - **Property 1: Expected Behavior** - KSA Config Uses Bearer Auth Without `/auth/tokens`
    - **IMPORTANT**: Re-run the SAME test from task 1 — do NOT write a new test
    - The test from task 1 encodes the expected behavior
    - When this test passes, it confirms: `authenticate()` returns `secret_key` with zero HTTP calls; every request carries `Authorization: Bearer <secret_key>`; `auth_token` is absent from all request bodies for KSA configs
    - **EXPECTED OUTCOME**: Test PASSES (confirms bug is fixed)
    - _Requirements: 2.1, 2.2, 2.3, 2.4_

  - [x] 3.7 Verify preservation tests still pass
    - **Property 2: Preservation** - Egypt/Accept Auth Flow Unchanged
    - **IMPORTANT**: Re-run the SAME tests from task 2 — do NOT write new tests
    - Run preservation property tests from step 2
    - **EXPECTED OUTCOME**: Tests PASS (confirms no regressions in Egypt/Accept auth flow)
    - Confirm all Egypt/Accept behaviors still hold after the fix

- [x] 4. Checkpoint — Ensure all tests pass
  - Run the full test suite (`vendor/bin/phpunit`) and confirm all tests pass
  - Ensure all tests pass; ask the user if questions arise
