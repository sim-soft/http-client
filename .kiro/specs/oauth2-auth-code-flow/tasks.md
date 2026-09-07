# Implementation Plan: OAuth2 Authorization Code Flow with PKCE

## Overview

This plan implements the Authorization Code Flow with PKCE support by extending
the existing abstract `OAuth2` class. Tasks are ordered to build incrementally:
new properties first, then PKCE/state helpers, then the two public methods (
`getAuthorizationUrl()`, `exchangeCode()`), then extension points, and finally
tests and documentation. Each task builds on the previous ones, ensuring no
orphaned code.

## Tasks

- [x]
    1. Add protected properties and authorize endpoint resolution

    - [x] 1.1 Add protected properties to the OAuth2 class
        - Add `protected string $authorizeEndpoint = ''` property
        - Add `protected string $sandboxAuthorizeEndpoint = ''` property
        - Add `protected string $redirectUri = ''` property
        - Add PHPDoc blocks for each property
        - _Requirements: 8.1, 8.2, 8.4_

    - [x] 1.2 Implement the `getAuthorizeEndpoint()` private method
        - Returns `$sandboxAuthorizeEndpoint` when sandbox mode is enabled,
          otherwise `$authorizeEndpoint`
        - Throws `RuntimeException` with descriptive message when the resolved
          endpoint is empty
        - _Requirements: 8.3, 8.4_

- [x]
    2. Implement PKCE generation methods

    - [x] 2.1 Implement `generateCodeVerifier()` private method
        - Generate 128-character random string using `random_bytes()`
        - Use only unreserved characters: A-Z, a-z, 0-9, `-`, `.`, `_`, `~`
        - _Requirements: 2.1, 9.1_

    - [x] 2.2 Implement `generateCodeChallenge(string $verifier)` private method
        - Apply SHA-256 hash to the verifier
        - Base64url-encode the hash (replace `+/` with `-_`, strip `=` padding)
        - _Requirements: 2.2, 2.6_

    - [x] 2.3 Implement `generateState()` private method
        - Generate 32 random bytes via `random_bytes()`
        - Convert to 64-character hex string via `bin2hex()`
        - _Requirements: 3.1, 9.1_

- [x]
    3. Implement `getAuthorizationUrl()` public method

    - [x] 3.1 Implement the `buildAuthorizationParams()` protected method
        - Accept `$state` and `$codeChallenge` parameters
        - Return array with `client_id`, `redirect_uri`, `response_type=code`,
          `state`, `code_challenge`, `code_challenge_method=S256`
        - Include `scope` only when `$this->scope` is non-null
        - _Requirements: 1.1, 1.2, 1.3, 1.4, 6.1_

    - [x] 3.2 Implement the `getAuthorizationUrl()` public method
        - Call `getAuthorizeEndpoint()` to resolve the endpoint URL
        - Generate code verifier and store via
          `$storage->set("{$clientId}_pkce_verifier", $verifier)`
        - Generate state and store via
          `$storage->set("{$clientId}_oauth_state", $state)`
        - Derive code challenge from verifier
        - Call `buildAuthorizationParams($state, $codeChallenge)` to get query
          params
        - Filter null values, build query string with `http_build_query()`,
          append to endpoint
        - Return the complete authorization URL string
        - _Requirements: 1.1, 1.5, 2.3, 2.5, 3.2_

- [x]
    4. Implement `exchangeCode()` public method

    - [x] 4.1 Implement the `buildCodeExchangeParams()` protected method
        - Accept `$code` and `$verifier` parameters
        - Return array with `grant_type=authorization_code`, `code`,
          `redirect_uri`, `client_id`, `client_secret`, `code_verifier`
        - _Requirements: 4.2, 6.2_

    - [x] 4.2 Implement the `parseTokenResponse()` protected method
        - Accept `OAuth2TokenResponse` parameter
        - Delegate to existing `toTokenData()` method
        - _Requirements: 6.3_

    - [x] 4.3 Implement the `exchangeCode(string $code, string $state)` public
      method
        - Retrieve stored state from `$storage->get("{$clientId}_oauth_state")`
        - Throw `RuntimeException` if no stored state found
        - Compare provided `$state` with stored state; throw `RuntimeException`
          on mismatch
        - Remove stored state from storage (prevents replay)
        - Retrieve stored PKCE verifier from
          `$storage->get("{$clientId}_pkce_verifier")`
        - Throw `RuntimeException` if no stored verifier found
        - Remove stored verifier from storage
        - Call `buildCodeExchangeParams($code, $verifier)` to assemble POST body
        - Call `buildTokenRequest($params)` to make the HTTP call
        - Throw `RuntimeException` with HTTP status code on non-successful
          response
        - Call `parseTokenResponse($response)` to build `TokenData`
        - Store `TokenData` via `$storage->set($this->clientId, $tokenData)`
        - Return `TokenData`
        - _Requirements: 3.3, 3.4, 3.5, 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 5.1_

- [x]
    5. Checkpoint - Verify core implementation

    - Ensure all code compiles without errors (run `composer qc`)
    - Ensure existing tests still pass (run `composer test`)
    - Ensure no regressions in the existing `client_credentials` flow
    - Ask the user if questions arise.

- [x]
    6. Write unit tests for the authorization code flow

    - [x] 6.1 Create test subclass `AuthCodeTestOAuth2` in
      `tests/Clients/OAuth2AuthCodeTest.php`
        - Extend `OAuth2` with configured `$authorizeEndpoint`,
          `$sandboxAuthorizeEndpoint`, and `$redirectUri`
        - Override `buildTokenRequest()` to capture params and return mock
          responses
        - Expose `generateCodeVerifier()` and `generateState()` via public
          wrapper methods
        - Track request count for no-call verification
        - _Requirements: 7.1, 7.2, 7.3_

    - [x] 6.2 Write unit tests in `tests/Clients/OAuth2AuthCodeTest.php`
        - Test: `getAuthorizationUrl()` returns URL with all required parameters
        - Test: `getAuthorizationUrl()` omits scope when not configured
        - Test: `getAuthorizationUrl()` includes scope when configured
        - Test: `getAuthorizationUrl()` throws when no authorize endpoint
          configured
        - Test: Sandbox mode uses `$sandboxAuthorizeEndpoint`
        - Test: `exchangeCode()` succeeds with valid state and stores TokenData
        - Test: `exchangeCode()` throws on state mismatch
        - Test: `exchangeCode()` throws when no stored state exists
        - Test: `exchangeCode()` throws when no stored verifier exists
        - Test: `exchangeCode()` throws on HTTP error response
        - Test: State is removed from storage after successful exchange
        - Test: PKCE verifier is removed from storage after exchange
        - Test: Existing `client_credentials` flow still works (regression)
        - Test: `getAccessToken()` returns cached auth-code token without HTTP
          call
        - Test: Expired auth-code token with refresh token triggers refresh
        - _Requirements: 1.1–1.5, 2.1–2.6, 3.1–3.5, 4.1–4.6, 5.1–5.3, 7.1–7.5,
          8.1–8.4_

- [x]* 7. Write property-based tests for the authorization code flow
- [x]* 7.1 Write property test: Authorization URL contains all required
parameters
- **Property 1: Authorization URL contains all required parameters**
- **Validates: Requirements 1.1, 1.2, 1.3, 1.4, 2.3**

- [x]* 7.2 Write property test: URL parameter encoding round-trip
- **Property 2: URL parameter encoding round-trip**
- **Validates: Requirements 1.5**

- [x]* 7.3 Write property test: Code verifier format invariant
- **Property 3: Code verifier format invariant**
- **Validates: Requirements 2.1**

- [x]* 7.4 Write property test: PKCE challenge derivation round-trip
- **Property 4: PKCE challenge derivation round-trip**
- **Validates: Requirements 2.2, 2.6**

- [x]* 7.5 Write property test: URL generation stores verifier and state
- **Property 5: URL generation stores verifier and state**
- **Validates: Requirements 2.5, 3.1, 3.2**

- [x]* 7.6 Write property test: State mismatch prevents exchange
- **Property 6: State mismatch prevents exchange**
- **Validates: Requirements 3.3, 3.4, 4.1**

- [x]* 7.7 Write property test: Code exchange request contains all required
parameters
- **Property 7: Code exchange request contains all required parameters**
- **Validates: Requirements 2.4, 4.2**

- [x]* 7.8 Write property test: Successful exchange stores TokenData
- **Property 8: Successful exchange stores TokenData**
- **Validates: Requirements 4.3, 4.6**

- [x]* 7.9 Write property test: Failed exchange throws RuntimeException
- **Property 9: Failed exchange throws RuntimeException**
- **Validates: Requirements 4.4**

- [x]* 7.10 Write property test: State is removed after successful exchange
- **Property 10: State is removed after successful exchange**
- **Validates: Requirements 3.5**

- [x]* 7.11 Write property test: Subclass authorization params appear in URL
- **Property 11: Subclass authorization params appear in URL**
- **Validates: Requirements 6.1, 6.4**

- [x]* 7.12 Write property test: Subclass exchange params appear in request
- **Property 12: Subclass exchange params appear in request**
- **Validates: Requirements 6.2**

- [x]* 7.13 Write property test: Subclass parseTokenResponse override is used
- **Property 13: Subclass parseTokenResponse override is used**
- **Validates: Requirements 6.3, 6.5**

- [x]
    8. Checkpoint - Run full test suite

    - Ensure all tests pass (run `composer test`)
    - Ensure static analysis passes (run `composer qc`)
    - Ask the user if questions arise.

- [x]
    9. Update documentation

    - [x] 9.1 Update `docs/OAUTH2.md` with Authorization Code Flow usage guide
        - Add section explaining the authorization code flow with PKCE
        - Include example subclass showing `$authorizeEndpoint`,
          `$sandboxAuthorizeEndpoint`, `$redirectUri` configuration
        - Show example usage of `getAuthorizationUrl()` and `exchangeCode()`
        - Document provider extensibility via `buildAuthorizationParams()`,
          `buildCodeExchangeParams()`, `parseTokenResponse()`
        - Include example of a Google-specific subclass overriding
          `buildAuthorizationParams()`
        - _Requirements: 1.1, 2.1, 3.1, 4.1, 6.1, 8.1_

- [x]
    10. Final checkpoint - Ensure all tests pass

    - Run `composer test` and `composer qc`
    - Verify no regressions in existing functionality
    - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation
- Property tests validate universal correctness properties from the design
  document
- Unit tests validate specific examples and edge cases
- The implementation uses only PHP built-in functions (`random_bytes`, `hash`,
  `base64_encode`) — no new dependencies
- All new methods follow existing code style: camelCase, PHPDoc blocks, early
  returns, no `else` expressions
