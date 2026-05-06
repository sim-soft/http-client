# Requirements Document

## Introduction

This feature adds Authorization Code Flow with PKCE (Proof Key for Code
Exchange) support to the existing standalone `OAuth2` class. The authorization
code flow enables user-interactive authentication via browser redirect, while
PKCE provides protection against authorization code interception attacks. The
implementation must be extensible for provider-specific quirks (e.g., Google's
`access_type=offline`, Microsoft's non-standard response fields) via protected
override methods, and must not break the existing `client_credentials` flow.

## Glossary

- **OAuth2_Client**: The existing abstract `OAuth2` class in
  `src/Clients/OAuth2.php` that handles token lifecycle management.
- **Authorization_URL_Builder**: The component responsible for constructing the
  authorization endpoint URL with all required parameters (client_id,
  redirect_uri, response_type, scope, state, code_challenge).
- **Code_Exchanger**: The component responsible for exchanging an authorization
  code for an access token via the token endpoint.
- **PKCE_Generator**: The component responsible for generating cryptographically
  random code verifiers and deriving S256 code challenges.
- **State_Manager**: The component responsible for generating, storing, and
  validating the `state` parameter for CSRF protection.
- **Storage_Interface**: The existing `StorageInterface` used for persisting
  tokens and PKCE/state data between the redirect and callback phases.
- **Token_Data**: The existing `TokenData` value object used for serializable
  token storage.
- **Provider_Subclass**: A concrete subclass of the OAuth2 client that overrides
  protected methods to accommodate provider-specific behavior.

## Requirements

### Requirement 1: Authorization URL Generation

**User Story:** As a developer, I want to generate a complete authorization URL
with all required OAuth2 parameters, so that I can redirect users to the
provider's authorization page.

#### Acceptance Criteria

1. WHEN the developer calls the authorization URL method, THE
   Authorization_URL_Builder SHALL return a fully-formed URL containing
   `client_id`, `redirect_uri`, `response_type=code`, `scope`, `state`,
   `code_challenge`, and `code_challenge_method` parameters.
2. THE Authorization_URL_Builder SHALL include only non-null parameters in the
   generated URL.
3. WHEN a scope is configured on the OAuth2_Client, THE
   Authorization_URL_Builder SHALL include the scope value in the authorization
   URL.
4. WHEN no scope is configured, THE Authorization_URL_Builder SHALL omit the
   `scope` parameter from the authorization URL.
5. THE Authorization_URL_Builder SHALL URL-encode all parameter values according
   to RFC 3986.

### Requirement 2: PKCE Support

**User Story:** As a developer, I want PKCE (S256) to be automatically applied
to the authorization code flow, so that my application is protected against
authorization code interception attacks.

#### Acceptance Criteria

1. THE PKCE_Generator SHALL generate a cryptographically random code verifier of
   128 characters using characters from the unreserved set (A-Z, a-z, 0-9, `-`,
   `.`, `_`, `~`).
2. THE PKCE_Generator SHALL derive the code challenge by applying SHA-256 to the
   code verifier and encoding the result with base64url (no padding).
3. THE Authorization_URL_Builder SHALL include `code_challenge` and
   `code_challenge_method=S256` parameters in the authorization URL.
4. WHEN exchanging the authorization code for a token, THE Code_Exchanger SHALL
   include the original `code_verifier` in the token request body.
5. THE PKCE_Generator SHALL store the code verifier via the Storage_Interface so
   it persists between the authorization redirect and the callback.
6. FOR ALL generated code verifiers, encoding then decoding the challenge SHALL
   produce a value that matches the SHA-256 hash of the original verifier (
   round-trip property).

### Requirement 3: State Parameter for CSRF Protection

**User Story:** As a developer, I want the library to automatically generate and
validate a `state` parameter, so that my application is protected against CSRF
attacks during the OAuth2 callback.

#### Acceptance Criteria

1. THE State_Manager SHALL generate a cryptographically random state value of at
   least 32 characters.
2. THE State_Manager SHALL store the generated state value via the
   Storage_Interface before returning the authorization URL.
3. WHEN the callback is received, THE State_Manager SHALL compare the returned
   state value against the stored value.
4. IF the returned state value does not match the stored value, THEN THE
   State_Manager SHALL throw an exception indicating a CSRF validation failure.
5. WHEN state validation succeeds, THE State_Manager SHALL remove the stored
   state value from the Storage_Interface to prevent replay.

### Requirement 4: Authorization Code Exchange

**User Story:** As a developer, I want to exchange an authorization code for an
access token, so that I can make authenticated API calls on behalf of the user.

#### Acceptance Criteria

1. WHEN the developer provides an authorization code and the callback state, THE
   Code_Exchanger SHALL validate the state parameter before proceeding with the
   token exchange.
2. THE Code_Exchanger SHALL send a POST request to the token endpoint with
   `grant_type=authorization_code`, `code`, `redirect_uri`, `client_id`,
   `client_secret`, and `code_verifier` parameters.
3. WHEN the token endpoint returns a successful response, THE Code_Exchanger
   SHALL parse the response into a Token_Data object and store it via the
   Storage_Interface.
4. WHEN the token endpoint returns an error response, THE Code_Exchanger SHALL
   throw a RuntimeException containing the HTTP status code and error message.
5. THE Code_Exchanger SHALL reuse the existing `buildTokenRequest()` method for
   making the HTTP call to the token endpoint.
6. WHEN the token response includes a refresh token, THE Token_Data object SHALL
   store the refresh token for subsequent automatic refresh.

### Requirement 5: Token Refresh for Authorization Code Tokens

**User Story:** As a developer, I want tokens obtained via the authorization
code flow to be automatically refreshed when they expire, so that users do not
need to re-authenticate frequently.

#### Acceptance Criteria

1. WHEN a cached authorization code token has expired and a refresh token is
   available, THE OAuth2_Client SHALL attempt to refresh the token using the
   existing `refreshToken()` method.
2. IF the refresh attempt fails, THEN THE OAuth2_Client SHALL return null from
   `getAccessToken()` and log the failure, rather than silently re-initiating
   the authorization code flow.
3. THE OAuth2_Client SHALL store the refreshed token via the Storage_Interface,
   replacing the expired token.

### Requirement 6: Provider Extensibility via Protected Methods

**User Story:** As a developer building a provider-specific subclass, I want to
override specific steps of the authorization code flow, so that I can
accommodate provider quirks without modifying the base class.

#### Acceptance Criteria

1. THE OAuth2_Client SHALL expose a protected method for building authorization
   URL parameters that Provider_Subclass implementations can override to add
   provider-specific parameters (e.g., `access_type=offline` for Google).
2. THE OAuth2_Client SHALL expose a protected method for building token exchange
   parameters that Provider_Subclass implementations can override to add or
   modify parameters.
3. THE OAuth2_Client SHALL expose a protected method for parsing the token
   response that Provider_Subclass implementations can override to handle
   non-standard response fields.
4. WHEN a Provider_Subclass overrides the authorization parameters method, THE
   Authorization_URL_Builder SHALL merge the custom parameters with the standard
   parameters.
5. WHEN a Provider_Subclass overrides the token response parsing method, THE
   Code_Exchanger SHALL use the overridden method to construct the Token_Data
   object.

### Requirement 7: Backward Compatibility

**User Story:** As a developer using the existing client_credentials flow, I
want the new authorization code flow to be additive, so that my existing code
continues to work without modification.

#### Acceptance Criteria

1. THE OAuth2_Client SHALL continue to support the `client_credentials` grant
   type via the existing `getAccessToken()` method without any API changes.
2. THE OAuth2_Client SHALL preserve the existing constructor signature (
   `clientId`, `clientSecret`, `storage`).
3. THE OAuth2_Client SHALL preserve the existing `request()` factory method
   signature.
4. WHEN the grant type is set to `client_credentials`, THE OAuth2_Client SHALL
   not require an authorization endpoint or redirect URI to be configured.
5. THE Token_Data class SHALL remain unchanged in its public interface and
   serialization format.

### Requirement 8: Authorization Endpoint Configuration

**User Story:** As a developer, I want to configure the authorization endpoint
URL in my subclass, so that the library knows where to redirect users for
authentication.

#### Acceptance Criteria

1. THE OAuth2_Client SHALL provide a protected property for the authorization
   endpoint URL.
2. THE OAuth2_Client SHALL provide a protected property for the redirect URI (
   callback URL).
3. WHEN the authorization URL method is called and no authorization endpoint is
   configured, THE OAuth2_Client SHALL throw a RuntimeException indicating the
   missing configuration.
4. WHEN sandbox mode is enabled, THE OAuth2_Client SHALL support a separate
   sandbox authorization endpoint.

### Requirement 9: No External Dependencies

**User Story:** As a library maintainer, I want the authorization code flow
implementation to use only the existing library infrastructure, so that the
library remains standalone.

#### Acceptance Criteria

1. THE PKCE_Generator SHALL use PHP's built-in `random_bytes()` function for
   cryptographic randomness.
2. THE Code_Exchanger SHALL use the existing `HttpClient` class (via
   `buildTokenRequest()`) for all HTTP communication.
3. THE State_Manager SHALL use the existing Storage_Interface for state
   persistence.
4. THE OAuth2_Client SHALL not introduce any new Composer dependencies for the
   authorization code flow.
