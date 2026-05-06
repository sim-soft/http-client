# Requirements Document

## Introduction

Refactor the `OAuth2` client class to be fully standalone — removing the runtime
dependency on `league/oauth2-client`. The refactored class will handle token
acquisition, caching, refresh, and expiry detection using only the library's own
`HttpClient` infrastructure (similar to how `SimpleOAuth2` works), while
preserving the existing public API surface (`request()`, `getAccessToken()`,
`sandbox()`, etc.) or providing a clear migration path.

This aligns with the library's zero-dependency philosophy (only `ext-curl`
required at runtime) and eliminates the need for `league/oauth2-client` in
`composer.json` `require`.

## Glossary

- **OAuth2_Client**: The refactored `Simsoft\HttpClient\Clients\OAuth2` class
  that handles OAuth2 token lifecycle without external dependencies.
- **HttpClient**: The library's core HTTP client class (
  `Simsoft\HttpClient\HttpClient`) used for making HTTP requests via cURL.
- **Token_Endpoint**: The OAuth2 authorization server URL that issues access
  tokens.
- **Access_Token**: A string credential used to authenticate API requests.
- **Refresh_Token**: A credential used to obtain a new access token without
  re-authenticating.
- **Token_Storage**: Any implementation of `StorageInterface` used to persist
  token data between requests.
- **Token_Data**: A serializable value object holding token fields (
  access_token, expires_at, refresh_token, token_type, scope).
- **Grant_Type**: The OAuth2 grant type used for token acquisition (e.g.,
  `client_credentials`, `refresh_token`).
- **OAuth2TokenResponse**: A new dedicated response class (
  `Simsoft\HttpClient\Clients\Responses\OAuth2TokenResponse`) that parses OAuth2
  token endpoint JSON responses, providing typed accessors for token fields.
- **StorageInterface**: The existing interface (
  `Simsoft\HttpClient\Interfaces\StorageInterface`) for token persistence.
- **SessionStorage**: The existing PHP session-backed `StorageInterface`
  implementation.

## Requirements

### Requirement 1: Remove league/oauth2-client Dependency

**User Story:** As a library maintainer, I want the OAuth2 class to have no
dependency on `league/oauth2-client`, so that the library maintains its
zero-dependency philosophy and users do not need to install external OAuth2
packages.

#### Acceptance Criteria

1. THE OAuth2_Client SHALL perform token acquisition using only the library's
   own HttpClient.
2. THE OAuth2_Client SHALL NOT import or reference any class from the
   `League\OAuth2\Client` namespace.
3. WHEN the refactoring is complete, THE library's `composer.json` SHALL list
   `league/oauth2-client` under `suggest` instead of `require`.

### Requirement 2: Token Acquisition via Client Credentials

**User Story:** As a developer, I want the OAuth2 class to acquire access tokens
using the client_credentials grant type by default, so that server-to-server
authentication works without external packages.

#### Acceptance Criteria

1. WHEN a token is not cached, THE OAuth2_Client SHALL send a POST request to
   the configured Token_Endpoint with `grant_type=client_credentials`, the
   client ID, and the client secret as form-encoded body parameters.
2. WHEN a scope is configured, THE OAuth2_Client SHALL include the `scope`
   parameter in the token request body.
3. WHEN the Token_Endpoint returns a successful response (HTTP 2xx), THE
   OAuth2_Client SHALL parse the response using OAuth2TokenResponse and extract
   the Access_Token.
4. IF the Token_Endpoint returns a non-2xx response, THEN THE OAuth2_Client
   SHALL log the error and return null from `getAccessToken()`.

### Requirement 3: Token Caching and Expiry Detection

**User Story:** As a developer, I want acquired tokens to be cached and
automatically refreshed when expired, so that my application avoids unnecessary
token requests.

#### Acceptance Criteria

1. WHEN a valid (non-expired) token exists in Token_Storage, THE OAuth2_Client
   SHALL return the cached Access_Token without making a network request.
2. WHEN a cached token has expired and no Refresh_Token is available, THE
   OAuth2_Client SHALL acquire a new token from the Token_Endpoint.
3. WHEN a cached token has expired and a Refresh_Token is available, THE
   OAuth2_Client SHALL attempt to refresh the token using the `refresh_token`
   grant type.
4. WHEN a new or refreshed token is acquired, THE OAuth2_Client SHALL store it
   in Token_Storage keyed by the client ID.
5. THE OAuth2_Client SHALL store token data as a serializable Token_Data value
   object rather than storing non-serializable response objects directly.

### Requirement 4: Token Refresh via Refresh Token Grant

**User Story:** As a developer, I want the OAuth2 class to support token refresh
using a refresh token, so that long-lived sessions do not require
re-authentication with client credentials.

#### Acceptance Criteria

1. WHEN refreshing a token, THE OAuth2_Client SHALL send a POST request to the
   Token_Endpoint with `grant_type=refresh_token` and the stored Refresh_Token
   value.
2. WHEN the refresh request succeeds, THE OAuth2_Client SHALL update
   Token_Storage with the new token data.
3. IF the refresh request fails, THEN THE OAuth2_Client SHALL fall back to
   acquiring a new token using the configured Grant_Type.

### Requirement 5: Preserve Public API Surface

**User Story:** As a developer using the existing OAuth2 class, I want the
refactored class to maintain the same public API, so that my existing code
continues to work with minimal changes.

#### Acceptance Criteria

1. THE OAuth2_Client SHALL provide a static
   `request(string $clientId, string $clientSecret, ?StorageInterface $storage)`
   factory method returning an instance.
2. THE OAuth2_Client SHALL provide a `getAccessToken(): ?string` method that
   returns a valid token or null on failure.
3. THE OAuth2_Client SHALL provide a `sandbox(): self` method that switches to
   the sandbox Token_Endpoint.
4. THE OAuth2_Client SHALL provide a `getEndpoint(): string` method that returns
   the active Token_Endpoint URL.
5. THE OAuth2_Client SHALL accept a custom `StorageInterface` implementation as
   the third constructor argument, defaulting to SessionStorage.

### Requirement 6: Configurable Grant Type and Scope

**User Story:** As a developer, I want to configure the grant type and scope in
subclasses, so that the OAuth2 class supports different OAuth2 flows beyond
client_credentials.

#### Acceptance Criteria

1. THE OAuth2_Client SHALL expose a protected `$grantType` property defaulting
   to `'client_credentials'`.
2. THE OAuth2_Client SHALL expose a protected `$scope` property defaulting to
   null (omitting scope from the request).
3. WHEN a subclass sets `$grantType` to a custom value, THE OAuth2_Client SHALL
   use that value in the `grant_type` parameter of token requests.

### Requirement 7: Sandbox Mode Support

**User Story:** As a developer, I want to switch between production and sandbox
token endpoints at runtime, so that I can test integrations without affecting
production.

#### Acceptance Criteria

1. THE OAuth2_Client SHALL expose a protected `$accessTokenEndpoint` property
   for the production URL.
2. THE OAuth2_Client SHALL expose a protected `$sandboxEndpoint` property for
   the sandbox URL.
3. WHEN `sandbox()` is called, THE OAuth2_Client SHALL use `$sandboxEndpoint`
   for all subsequent token requests.
4. WHEN `sandbox()` is not called, THE OAuth2_Client SHALL use
   `$accessTokenEndpoint` for token requests.

### Requirement 8: Serializable Token Data Object

**User Story:** As a developer, I want token data stored in a serializable
format, so that tokens can be safely persisted in PHP sessions, Redis,
databases, or any cache backend.

#### Acceptance Criteria

1. THE OAuth2_Client SHALL define a Token_Data value object containing:
   accessToken (string), expiresAt (int), refreshToken (?string), tokenType (
   ?string), and scope (?string).
2. THE Token_Data object SHALL contain only scalar and nullable-scalar
   properties to ensure serializability.
3. THE Token_Data object SHALL provide a `hasExpired(): bool` method that
   returns true when the current time exceeds `expiresAt`.
4. WHEN storing a token, THE OAuth2_Client SHALL convert the OAuth2TokenResponse
   into a Token_Data object before persisting.

### Requirement 9: Error Handling and Logging

**User Story:** As a developer, I want clear error logging when token operations
fail, so that I can diagnose authentication issues in production.

#### Acceptance Criteria

1. IF a token acquisition request throws an exception, THEN THE OAuth2_Client
   SHALL log the error via `error_log()` with the client ID and exception
   message.
2. IF a token acquisition request throws an exception, THEN THE OAuth2_Client
   SHALL return null from `getAccessToken()` rather than propagating the
   exception.
3. IF a refresh attempt fails, THEN THE OAuth2_Client SHALL log the refresh
   failure and attempt a fresh token acquisition before returning null.

### Requirement 10: Backward Compatibility and Migration

**User Story:** As a library maintainer, I want a clear migration path from the
league-dependent OAuth2 to the standalone version, so that existing users can
upgrade without confusion.

#### Acceptance Criteria

1. THE OAuth2_Client SHALL remain in the same namespace (
   `Simsoft\HttpClient\Clients\OAuth2`).
2. THE OAuth2_Client SHALL remove the `getProvider(): GenericProvider` method
   since it exposes league internals.
3. THE OAuth2_Client SHALL remove the
   `refreshToken(AccessTokenInterface $token)` public method signature that
   depends on league types.
4. WHEN `league/oauth2-client` is listed under `suggest` in composer.json, THE
   suggestion message SHALL explain that it is only needed for advanced
   provider-specific flows.
