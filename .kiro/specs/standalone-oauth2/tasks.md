# Implementation Plan: Standalone OAuth2

## Overview

Refactor the `OAuth2` class to eliminate the `league/oauth2-client` runtime
dependency by implementing token acquisition, caching, and refresh using the
library's own `HttpClient` infrastructure. Introduce a serializable `TokenData`
value object and update all related configuration and documentation.

## Tasks

- [x]
    1. Create the TokenData value object

    - [x] 1.1 Create `src/Clients/TokenData.php` with readonly properties:
      accessToken (string), expiresAt (int), refreshToken (?string), tokenType (
      ?string), scope (?string)
        - Implement `hasExpired(): bool` method comparing `time()` against
          `expiresAt`
        - Implement `toArray(): array` method for storage backends that prefer
          arrays
        - Implement `fromArray(array $data): static` factory method for
          reconstruction
        - Add full PHPDoc class and method documentation
        - _Requirements: 8.1, 8.2, 8.3_

  - [x]* 1.2 Write unit tests for TokenData
    - Create `tests/Clients/TokenDataTest.php`
    - Test construction with all parameters
    - Test `hasExpired()` returns true when expiresAt is in the past
    - Test `hasExpired()` returns false when expiresAt is in the future
    - Test `toArray()` produces expected key-value structure
    - Test `fromArray()` reconstructs identical object from array
    - Test `fromArray()` handles missing optional fields gracefully
    - _Requirements: 8.1, 8.2, 8.3_

  - [x]* 1.3 Write property tests for TokenData serialization round-trip
    - Create `tests/Clients/TokenDataPropertyTest.php`
    - **Property 1: TokenData serialization round-trip** — For any valid
      TokenData instance, `unserialize(serialize($token))` produces identical
      property values
    - **Validates: Requirements 8.2**

  - [x]* 1.4 Write property test for TokenData expiry detection
    - **Property 2: Token expiry detection correctness** — For any integer
      expiresAt, `hasExpired()` returns true iff `time() >= expiresAt`
    - **Validates: Requirements 8.3**

- [x]
    2. Create OAuth2TokenResponse and rewrite the OAuth2 class to be standalone

    - [x] 2.1 Create `src/Clients/Responses/OAuth2TokenResponse.php`
        - Extend `Simsoft\HttpClient\Response`
        - Implement `getToken(): ?string` — returns
          `$this->data('access_token')`
        - Implement `getTokenType(): ?string` — returns
          `$this->data('token_type')`
        - Implement `getExpiresIn(): ?int` — returns
          `(int) $this->data('expires_in')` or null
        - Implement `getExpiresAt(): ?int` — computes `time() + getExpiresIn()`
          or null
        - Implement `getRefreshToken(): ?string` — returns
          `$this->data('refresh_token')`
        - Implement `getScope(): ?string` — returns `$this->data('scope')`
        - Add full PHPDoc class and method documentation
        - _Requirements: 2.3, 8.4_

    - [x] 2.2 Rewrite `src/Clients/OAuth2.php` removing all League imports and
      GenericProvider usage
        - Remove all `League\OAuth2\Client` imports
        - Remove `$provider` property and `getProvider()` method
        - Remove `refreshToken(AccessTokenInterface $token)` public method
        - Keep constructor, `request()`, `sandbox()`, `getEndpoint()`,
          `getAccessToken()` with same signatures
        - _Requirements: 1.1, 1.2, 5.1, 5.2, 5.3, 5.4, 5.5, 10.1, 10.2, 10.3_

    - [x] 2.3 Implement `buildTokenRequest(array $params): OAuth2TokenResponse`
        - Create internal HttpClient instance per request
        - Use `withResponseClass(OAuth2TokenResponse::class)` and
          `withForm($params)`
        - POST to `$this->getEndpoint()`
        - _Requirements: 1.1, 2.1_

    - [x] 2.4 Implement `fetchNewToken(): TokenData`
        - Build form params: grant_type, client_id, client_secret, scope (if
          configured)
        - Call `buildTokenRequest()` and convert response to TokenData via
          `toTokenData()`
        - Throw exception if response is not successful
        - _Requirements: 2.1, 2.2, 2.3, 2.4_

    - [x] 2.5 Implement `refreshToken(TokenData $token): TokenData`
        - Build form params: grant_type=refresh_token, client_id, client_secret,
          refresh_token
        - Call `buildTokenRequest()` and convert response to TokenData
        - _Requirements: 4.1, 4.2_

    - [x] 2.6 Implement `toTokenData(OAuth2TokenResponse $response): TokenData`
        - Extract accessToken, expiresAt (with 30s buffer), refreshToken,
          tokenType, scope from response
        - Return new TokenData instance
        - _Requirements: 8.4, 3.5_

    - [x] 2.7 Implement `getAccessToken(): ?string` with full token lifecycle
        - Check storage for cached token; return if not expired
        - If expired with refresh token: attempt refresh, fallback to fresh on
          failure
        - If expired without refresh token: fetch new token
        - If no cached token: fetch new token
        - Store acquired TokenData in storage keyed by clientId
        - Wrap all in try/catch, log errors via `error_log()`, return null on
          failure
        - _Requirements: 3.1, 3.2, 3.3, 3.4, 4.3, 9.1, 9.2, 9.3_

- [x]
    3. Checkpoint - Verify core implementation compiles

    - Ensure all tests pass, ask the user if questions arise.

- [x]
    4. Update composer.json and documentation

    - [x] 4.1 Move `league/oauth2-client` from `require` to `suggest` in
      `composer.json`
        - Add suggestion message:
          `"league/oauth2-client": "Required only for advanced provider-specific OAuth2 flows"`
        - Run `composer update` to regenerate lock file
        - _Requirements: 1.3, 10.4_

    - [x] 4.2 Update `docs/OAUTH2.md` documentation
        - Document the standalone OAuth2 class usage
        - Document TokenData value object
        - Document migration notes (removed `getProvider()`, changed token
          storage format)
        - Include example usage for subclassing with custom endpoints
        - _Requirements: 10.1, 10.2, 10.3, 10.4_

- [x]
    5. Write unit tests for the refactored OAuth2 class

    - [x] 5.1 Rewrite `tests/Clients/OAuth2Test.php` for the standalone
      implementation
        - Test `request()` factory returns correct instance
        - Test `sandbox()` switches endpoint
        - Test `getEndpoint()` returns production URL by default
        - Test `getEndpoint()` returns sandbox URL after `sandbox()` call
        - Test default grant type is `client_credentials`
        - Test scope is included in request when configured
        - Test cached non-expired token is returned without HTTP call
        - Test expired token without refresh token triggers `fetchNewToken()`
        - Test expired token with refresh token triggers `refreshToken()`
        - Test refresh failure falls back to fresh acquisition
        - Test non-2xx response causes `getAccessToken()` to return null
        - Test exception during acquisition returns null and logs error
        - Verify no League imports exist in the class
        - _Requirements: 1.2, 2.1, 2.2, 2.4, 3.1, 3.2, 3.3, 4.3, 5.1, 5.2, 5.3,
          5.4, 5.5, 6.1, 6.2, 7.1, 7.2, 7.3, 7.4, 9.1, 9.2, 9.3_

- [x]
    6. Write property-based tests for OAuth2
       - [x]* 6.1 Write property test: cached non-expired token returned without
       network call

    - Create `tests/Clients/OAuth2PropertyTest.php`
    - **Property 3: Cached non-expired token returned without network call**
    - Generate random TokenData with future expiresAt, verify getAccessToken()
      returns the token string without HTTP calls
    - **Validates: Requirements 3.1**

  - [x]* 6.2 Write property test: successful acquisition stores TokenData keyed
  by client ID
    - **Property 4: Successful acquisition stores TokenData keyed by client ID**
    - Generate random client IDs and mock successful responses, verify storage
      contains TokenData under the client ID key
    - **Validates: Requirements 3.4, 3.5**

  - [x]* 6.3 Write property test: custom grant type propagates to request body
    - **Property 5: Custom grant type propagates to request body**
    - Generate random non-empty strings as grant types, verify the POST body
      contains that exact grant_type value
    - **Validates: Requirements 6.3**

  - [x]* 6.4 Write property test: exception safety
    - **Property 6: Exception safety**
    - Generate random exception types and messages, verify getAccessToken()
      returns null without propagating
    - **Validates: Requirements 9.2**

- [x]
    7. Final checkpoint - Run full test suite and static analysis

    - Run `composer test` to ensure all tests pass
    - Run `composer qc` (PHPStan + PHPMD) to ensure code quality
    - Verify no League references remain in `src/Clients/OAuth2.php`
    - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation
- Property tests validate universal correctness properties from the design
  document
- Unit tests validate specific scenarios and edge cases
- The design uses PHP throughout, so all implementation tasks use PHP
- `buildTokenRequest()` is extracted as a protected method to enable test
  subclasses to override HTTP behavior without real network calls
