# Requirements Document

## Introduction

This document defines the requirements for comprehensive unit testing and code
quality enforcement for the Simsoft HttpClient PHP library. The goal is to
achieve full test coverage of all library components (HttpClient, Response,
Streams, Traits, Exceptions, OAuth2 clients) using PHPUnit 11, with test
fixtures (JSON files, database schemas where needed), and ensure all source code
complies with PHPMD and PHPStan (level 8) rules.

## Glossary

- **Test_Suite**: The complete collection of PHPUnit test classes covering all
  library components.
- **HttpClient**: The main HTTP client class implementing PSR-18
  ClientInterface (`src/HttpClient.php`).
- **Response**: The PSR-7 ResponseInterface implementation (`src/Response.php`).
- **StringStream**: The in-memory string-based PSR-7 StreamInterface
  implementation (`src/Streams/StringStream.php`).
- **FileStream**: The file-based read-only PSR-7 StreamInterface
  implementation (`src/Streams/FileStream.php`).
- **Fixture**: A static JSON or data file used as test input.
- **PHPMD**: PHP Mess Detector static analysis tool configured via `phpmd.xml`.
- **PHPStan**: PHP Static Analysis Tool configured at level 8 via
  `phpstan.neon`.
- **OAuth2_Client**: The OAuth2 token management class using
  league/oauth2-client (`src/Clients/OAuth2.php`).
- **SimpleOAuth2_Client**: The abstract OAuth2 client without league
  dependency (`src/Clients/SimpleOAuth2.php`).
- **SimpleOAuth2Response**: The OAuth2 token response wrapper (
  `src/Clients/Responses/SimpleOAuth2Response.php`).
- **SessionStorage**: The PHP session-backed StorageInterface implementation (
  `src/Clients/Helpers/SessionStorage.php`).
- **Macroable_Trait**: The dynamic method registration trait (
  `src/Traits/Macroable.php`).
- **CurlOptions_Trait**: The cURL handle lifecycle and options trait (
  `src/Traits/CurlOptionsTrait.php`).
- **Retry_Trait**: The retry logic trait (`src/Traits/RetryTrait.php`).
- **Sandbox_Trait**: The production/sandbox endpoint switching trait (
  `src/Traits/Sandbox.php`).
- **Debug_Trait**: The dd() and dump() debugging helpers trait (
  `src/Traits/DebugTrait.php`).
- **Deprecated_Trait**: The deprecated method aliases trait (
  `src/Traits/DeprecatedTrait.php`).

## Requirements

### Requirement 1: StringStream Unit Tests

**User Story:** As a developer, I want comprehensive unit tests for
StringStream, so that I can verify all PSR-7 StreamInterface operations on
in-memory strings work correctly.

#### Acceptance Criteria

1. THE Test_Suite SHALL test StringStream construction with empty and non-empty
   content strings
2. WHEN StringStream read() is called with a positive length, THE Test_Suite
   SHALL verify the correct substring is returned and position advances
3. WHEN StringStream write() is called, THE Test_Suite SHALL verify content is
   inserted at the current position and content length updates
4. WHEN StringStream seek() is called with SEEK_SET, SEEK_CUR, and SEEK_END, THE
   Test_Suite SHALL verify the position is set correctly
5. WHEN StringStream close() is called, THE Test_Suite SHALL verify the stream
   becomes detached and subsequent operations throw RuntimeException
6. THE Test_Suite SHALL verify StringStream __toString() returns full content
   when attached and empty string when detached
7. WHEN StringStream seek() is called with a negative resulting position, THE
   Test_Suite SHALL verify a RuntimeException is thrown
8. THE Test_Suite SHALL verify StringStream getSize() returns content length
   when attached and null when detached
9. THE Test_Suite SHALL verify StringStream eof() returns true when position
   reaches or exceeds content length
10. FOR ALL valid string content, writing then reading from position zero SHALL
    produce the original content (round-trip property)

### Requirement 2: FileStream Unit Tests

**User Story:** As a developer, I want comprehensive unit tests for FileStream,
so that I can verify file-based stream operations work correctly with real
files.

#### Acceptance Criteria

1. WHEN FileStream is constructed with a valid file path, THE Test_Suite SHALL
   verify read operations return file contents
2. WHEN FileStream read() is called, THE Test_Suite SHALL verify the correct
   number of bytes is returned
3. WHEN FileStream seek() is called, THE Test_Suite SHALL verify the position
   changes correctly
4. THE Test_Suite SHALL verify FileStream isWritable() returns false and write()
   throws RuntimeException
5. WHEN FileStream is constructed with a non-existent file path, THE Test_Suite
   SHALL verify a RuntimeException is thrown on first access
6. THE Test_Suite SHALL verify FileStream getSize() returns the actual file size
   in bytes
7. THE Test_Suite SHALL verify FileStream getContents() returns remaining
   content from current position
8. THE Test_Suite SHALL verify FileStream __toString() returns full file content
   for files under 5MB

### Requirement 3: Response Unit Tests

**User Story:** As a developer, I want comprehensive unit tests for the Response
class, so that I can verify HTTP response parsing, status helpers, JSON
decoding, and dot-notation data access work correctly.

#### Acceptance Criteria

1. THE Test_Suite SHALL test Response construction with various HTTP status
   codes and verify status helper methods (ok(), created(), notFound(),
   internalServerError(), etc.) return correct boolean values
2. WHEN Response body contains valid JSON, THE Test_Suite SHALL verify json()
   returns an associative array and object() returns a stdClass
3. WHEN Response body contains invalid JSON, THE Test_Suite SHALL verify a
   RuntimeException is thrown
4. WHEN Response data() is called with dot-notation keys, THE Test_Suite SHALL
   verify nested values are resolved correctly
5. WHEN Response data() is called with wildcard (*) segments, THE Test_Suite
   SHALL verify values are collected from all array items
6. THE Test_Suite SHALL verify Response header methods (getHeader(),
   getHeaderLine(), hasHeader(), withHeader(), withoutHeader()) comply with
   PSR-7 immutability
7. THE Test_Suite SHALL verify Response successful(), failed(), isClientError(),
   isServerError(), isRedirect() return correct values for their respective
   status code ranges
8. WHEN Response is constructed with a non-zero errno, THE Test_Suite SHALL
   verify isNetworkError() returns true and failed() returns true
9. THE Test_Suite SHALL verify Response withStatus() returns a new instance with
   the updated status code
10. THE Test_Suite SHALL verify Response withProtocolVersion() returns a new
    instance with the updated protocol version
11. THE Test_Suite SHALL verify Response getBody() returns a StringStream for
    in-memory bodies
12. WHEN raw headers contain multiple response blocks (redirects), THE
    Test_Suite SHALL verify only the final block headers are parsed

### Requirement 4: HttpClient Unit Tests

**User Story:** As a developer, I want comprehensive unit tests for the
HttpClient class, so that I can verify request building, content type handling,
middleware pipeline, and fluent API work correctly without making real HTTP
calls.

#### Acceptance Criteria

1. THE Test_Suite SHALL verify HttpClient::make() returns a new HttpClient
   instance
2. THE Test_Suite SHALL verify withBaseUrl() and resource() correctly compose
   the endpoint URL via getEndpoint()
3. WHEN withMethod() is called with GET, POST, PUT, PATCH, DELETE, THE
   Test_Suite SHALL verify the method is stored correctly
4. THE Test_Suite SHALL verify withHeaders() and withHeader() accumulate headers
   correctly without duplicates
5. THE Test_Suite SHALL verify withBearerToken() sets the authorization header
   with Bearer prefix
6. THE Test_Suite SHALL verify withQuery() merges query parameters
7. THE Test_Suite SHALL verify withJson() encodes data and sets application/json
   content type
8. WHEN withJson() is called with non-encodable data, THE Test_Suite SHALL
   verify an InvalidArgumentException is thrown
9. THE Test_Suite SHALL verify withForm() sets application/x-www-form-urlencoded
   content type and URL-encodes data
10. THE Test_Suite SHALL verify withGraphQL() encodes query and variables as
    JSON
11. THE Test_Suite SHALL verify asJson(), asForm(), asMultipart(), asRaw() set
    the correct content type constants
12. THE Test_Suite SHALL verify withMiddleware() registers middleware closures
    and named middleware prevents duplicates
13. THE Test_Suite SHALL verify withResponseClass() accepts valid Response
    subclasses and rejects invalid classes
14. WHEN withResponseClass() is called with a non-existent class, THE Test_Suite
    SHALL verify an InvalidArgumentException is thrown
15. THE Test_Suite SHALL verify retry() stores retry count and delay, and throws
    InvalidArgumentException for values less than 1
16. THE Test_Suite SHALL verify shouldRetry() returns false for non-seekable
    stream bodies

### Requirement 5: Macroable Trait Unit Tests

**User Story:** As a developer, I want comprehensive unit tests for the
Macroable trait, so that I can verify dynamic method registration and mixin
functionality work correctly.

#### Acceptance Criteria

1. WHEN macro() registers a closure, THE Test_Suite SHALL verify calling the
   registered method name executes the closure
2. WHEN a non-existent macro is called, THE Test_Suite SHALL verify a
   BadMethodCallException is thrown
3. WHEN mixin() is called with an object, THE Test_Suite SHALL verify all public
   and protected methods are registered as macros
4. WHEN mixin() is called with replace=false, THE Test_Suite SHALL verify
   existing macros are not overwritten
5. THE Test_Suite SHALL verify macros have access to the host object via $this
   binding

### Requirement 6: CurlOptions Trait Unit Tests

**User Story:** As a developer, I want comprehensive unit tests for the
CurlOptionsTrait, so that I can verify timeout, buffer, SSL, and option
management work correctly.

#### Acceptance Criteria

1. THE Test_Suite SHALL verify timeout() and connectionTimeout() store correct
   values and throw InvalidArgumentException for negative values
2. THE Test_Suite SHALL verify withBufferSize() stores the buffer size value
3. THE Test_Suite SHALL verify withoutVerifying() disables SSL peer and host
   verification
4. THE Test_Suite SHALL verify withOptions() merges arbitrary cURL options
5. THE Test_Suite SHALL verify verbose() enables CURLOPT_VERBOSE

### Requirement 7: Exception Classes Unit Tests

**User Story:** As a developer, I want unit tests for all exception classes, so
that I can verify they implement the correct PSR-18 interfaces and carry request
context.

#### Acceptance Criteria

1. THE Test_Suite SHALL verify ClientException implements
   Psr\Http\Client\ClientExceptionInterface
2. THE Test_Suite SHALL verify NetworkException implements
   Psr\Http\Client\NetworkExceptionInterface and getRequest() returns the
   provided RequestInterface
3. THE Test_Suite SHALL verify RequestException implements
   Psr\Http\Client\RequestExceptionInterface and getRequest() returns the
   provided RequestInterface
4. THE Test_Suite SHALL verify exception message, code, and previous exception
   are preserved through construction

### Requirement 8: OAuth2 Client Unit Tests

**User Story:** As a developer, I want unit tests for the OAuth2 class, so that
I can verify token acquisition, refresh, storage, and sandbox mode work
correctly.

#### Acceptance Criteria

1. THE Test_Suite SHALL verify OAuth2::request() factory method returns a new
   instance with correct client credentials
2. THE Test_Suite SHALL verify sandbox() switches the endpoint to the sandbox
   URL
3. THE Test_Suite SHALL verify getEndpoint() returns the production endpoint by
   default and sandbox endpoint after sandbox() is called
4. WHEN a valid token exists in storage and has not expired, THE Test_Suite
   SHALL verify getAccessToken() returns the cached token without fetching a new
   one
5. WHEN a stored token has expired and has a refresh token, THE Test_Suite SHALL
   verify refreshToken() is called
6. WHEN refreshToken() is called with a token that has no refresh token, THE
   Test_Suite SHALL verify an InvalidArgumentException is thrown
7. WHEN token acquisition fails, THE Test_Suite SHALL verify getAccessToken()
   returns null

### Requirement 9: SimpleOAuth2 Client Unit Tests

**User Story:** As a developer, I want unit tests for the SimpleOAuth2 abstract
class, so that I can verify token lifecycle management works correctly.

#### Acceptance Criteria

1. THE Test_Suite SHALL verify makeWith() factory method creates an instance
   with correct credentials
2. WHEN a valid non-expired token exists in storage, THE Test_Suite SHALL verify
   getAccessToken() returns the cached token
3. WHEN a stored token has expired, THE Test_Suite SHALL verify a new token is
   fetched via postRequest()
4. WHEN postRequest() returns a non-200 response, THE Test_Suite SHALL verify
   getAccessToken() returns null
5. THE Test_Suite SHALL verify SimpleOAuth2Response accessor methods (
   getToken(), getExpiresIn(), getExpiresAt(), hasExpired(), getRefreshToken(),
   getTokenType(), getScope()) return correct values from JSON body

### Requirement 10: SessionStorage Unit Tests

**User Story:** As a developer, I want unit tests for SessionStorage, so that I
can verify session-backed key-value storage operations work correctly.

#### Acceptance Criteria

1. THE Test_Suite SHALL verify set() stores a value retrievable by get() with
   the same key
2. THE Test_Suite SHALL verify has() returns true for existing keys and false
   for non-existing keys
3. THE Test_Suite SHALL verify remove() deletes the key so has() returns false
   afterward
4. THE Test_Suite SHALL verify get() returns null for non-existing keys

### Requirement 11: Deprecated Trait Unit Tests

**User Story:** As a developer, I want unit tests for the DeprecatedTrait, so
that I can verify deprecated method aliases trigger deprecation notices and
delegate correctly.

#### Acceptance Criteria

1. WHEN query() is called, THE Test_Suite SHALL verify an E_USER_DEPRECATED
   notice is triggered
2. WHEN formData() is called, THE Test_Suite SHALL verify an E_USER_DEPRECATED
   notice is triggered
3. WHEN raw() is called, THE Test_Suite SHALL verify an E_USER_DEPRECATED notice
   is triggered
4. WHEN json() is called, THE Test_Suite SHALL verify an E_USER_DEPRECATED
   notice is triggered
5. WHEN graphQL() is called, THE Test_Suite SHALL verify an E_USER_DEPRECATED
   notice is triggered

### Requirement 12: Sandbox Trait Unit Tests

**User Story:** As a developer, I want unit tests for the Sandbox trait, so that
I can verify endpoint switching between production and sandbox modes.

#### Acceptance Criteria

1. THE Test_Suite SHALL verify getEndpoint() returns the production endpoint by
   default
2. WHEN sandbox() is called, THE Test_Suite SHALL verify getEndpoint() returns
   the sandbox endpoint
3. THE Test_Suite SHALL verify getEndpoint() appends the URI parameter to the
   active endpoint

### Requirement 13: JSON Fixture Files

**User Story:** As a developer, I want JSON fixture files for test data, so that
tests use deterministic data without external dependencies.

#### Acceptance Criteria

1. THE Test_Suite SHALL include a JSON fixture file containing user records with
   nested profiles, addresses, and contacts for Response data access testing
2. THE Test_Suite SHALL include a JSON fixture file containing OAuth2 token
   response data for OAuth2 client testing
3. THE Test_Suite SHALL include a JSON fixture file containing various HTTP
   response scenarios (success, error, empty body) for Response class testing

### Requirement 14: PHPMD Compliance

**User Story:** As a developer, I want all test code to comply with PHPMD rules,
so that the test suite maintains the same code quality standards as the source
code.

#### Acceptance Criteria

1. THE Test_Suite SHALL pass PHPMD analysis using the project phpmd.xml ruleset
   without violations
2. THE Test_Suite SHALL use variable names with a minimum of 2 characters as
   configured in the PHPMD ShortVariable rule
3. THE Test_Suite SHALL use CamelCase naming for classes, methods, properties,
   parameters, and variables

### Requirement 15: PHPStan Compliance

**User Story:** As a developer, I want all test code to pass PHPStan level 8
analysis, so that type safety is enforced across the test suite.

#### Acceptance Criteria

1. WHEN PHPStan is run at level 8 on the test directory, THE Test_Suite SHALL
   produce zero errors
2. THE Test_Suite SHALL include proper PHPDoc type annotations on all test
   methods and data providers
3. THE Test_Suite SHALL use strict types declaration in all test files

### Requirement 16: Retry Logic Unit Tests

**User Story:** As a developer, I want unit tests for the retry logic, so that I
can verify retry conditions, wait delays, and custom retry callbacks work
correctly.

#### Acceptance Criteria

1. THE Test_Suite SHALL verify retry() throws InvalidArgumentException when
   times is less than 1
2. THE Test_Suite SHALL verify shouldRetry() returns true for retryable network
   errors (timeout, connection refused)
3. THE Test_Suite SHALL verify shouldRetry() returns true for server errors on
   idempotent methods (GET, HEAD, OPTIONS)
4. THE Test_Suite SHALL verify shouldRetry() returns false for server errors on
   non-idempotent methods (POST, PUT)
5. WHEN retryWhen() sets a custom callback, THE Test_Suite SHALL verify
   shouldRetry() delegates to the callback
6. THE Test_Suite SHALL verify shouldRetry() returns false when postFields is a
   non-seekable stream

### Requirement 17: Middleware Pipeline Unit Tests

**User Story:** As a developer, I want unit tests for the middleware pipeline,
so that I can verify middleware execution order and response transformation work
correctly.

#### Acceptance Criteria

1. THE Test_Suite SHALL verify middleware closures are executed in registration
   order
2. THE Test_Suite SHALL verify middleware can modify the response before
   returning
3. THE Test_Suite SHALL verify middleware can short-circuit the pipeline by not
   calling the next handler
4. WHEN middleware returns a non-Response object, THE Test_Suite SHALL verify a
   RuntimeException is thrown
