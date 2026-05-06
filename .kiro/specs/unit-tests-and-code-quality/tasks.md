# Implementation Plan: Unit Tests and Code Quality

## Overview

Implement comprehensive PHPUnit 11 test suite for the simsoft/http-client
library. Tests are structured to mirror the source layout, use JSON fixtures for
deterministic data, and avoid real HTTP calls. All test code must comply with
PHPMD, PHPStan level 8, PSR-12, and use `declare(strict_types=1)`.
Property-based tests use phpqc/phpquickcheck for StringStream and Response
correctness properties.

## Tasks

- [x]
    1. Set up test infrastructure and fixtures

    - [x] 1.1 Create test directory structure and JSON fixture files
        - Create `tests/fixtures/` directory
        - Create `tests/fixtures/user.json` (copy existing `tests/user.json`
          content)
        - Create `tests/fixtures/oauth2-token.json` with access_token,
          token_type, expires_in, refresh_token, scope fields
        - Create `tests/fixtures/responses.json` with success (200), not_found (
          404), server_error (500), empty_body (204), redirect_chain scenarios
        - _Requirements: 13.1, 13.2, 13.3_

    - [x] 1.2 Install phpqc/phpquickcheck and update PHPStan config
        - Run `composer require --dev phpqc/phpquickcheck`
        - Update `phpstan.neon` to add `tests/` to the paths array
        - Ensure `composer.json` autoload-dev maps test namespace
        - _Requirements: 15.1_

- [ ]
    2. Implement StringStream tests

    - [x] 2.1 Create StringStreamTest with construction, read, write, seek,
      close, eof, getSize, __toString tests
        - Create `tests/Streams/StringStreamTest.php`
        - Test construction with empty and non-empty content
        - Test read() returns correct substring and advances position
        - Test write() inserts at current position and updates content length
        - Test seek() with SEEK_SET, SEEK_CUR, SEEK_END
        - Test close() detaches stream and subsequent operations throw
          RuntimeException
        - Test __toString() returns full content when attached, empty when
          detached
        - Test seek() to negative position throws RuntimeException
        - Test getSize() returns content length when attached, null when
          detached
        - Test eof() returns true when position >= content length
        - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7, 1.8, 1.9_

    - [x] 2.2 Write property test for StringStream write/read round-trip
        - **Property 1: StringStream write/read round-trip**
        - For any valid string content, write then rewind then read SHALL
          produce the original content
        - **Validates: Requirements 1.10**

    - [x] 2.3 Write property test for StringStream read correctness
        - **Property 2: StringStream read returns correct substring**
        - For any non-empty content and valid read length, read() returns
          matching substr() result
        - **Validates: Requirements 1.2**

  - [ ]* 2.4 Write property test for StringStream seek correctness
    - **Property 3: StringStream seek positions correctly**
    - For any content of length N and valid offset, seek with each whence mode
      sets correct position
    - **Validates: Requirements 1.4**

  - [ ]* 2.5 Write property test for StringStream write splice correctness
    - **Property 4: StringStream write at position splices content correctly**
    - After seeking to a valid position and writing, content equals original
      with splice applied
    - **Validates: Requirements 1.3**

- [ ]
    3. Implement FileStream tests

    - [x] 3.1 Create FileStreamTest with read, seek, write rejection,
      non-existent file, getSize, getContents, __toString tests
        - Create `tests/Streams/FileStreamTest.php`
        - Use temp files created in setUp() and cleaned in tearDown()
        - Test construction with valid file path and read operations
        - Test read() returns correct number of bytes
        - Test seek() changes position correctly
        - Test isWritable() returns false and write() throws RuntimeException
        - Test construction with non-existent file throws RuntimeException on
          first access
        - Test getSize() returns actual file size
        - Test getContents() returns remaining content from current position
        - Test __toString() returns full content for files under 5MB
        - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 2.7, 2.8_

- [x]
    4. Checkpoint - Ensure all stream tests pass

    - Ensure all tests pass, ask the user if questions arise.

- [x]
    5. Implement Response tests

    - [x] 5.1 Create ResponseTest with status helpers, JSON decoding,
      dot-notation, headers, PSR-7 immutability tests
        - Create `tests/ResponseTest.php`
        - Load `tests/fixtures/responses.json` and `tests/fixtures/user.json`
          for test data
        - Test construction with various status codes and verify ok(),
          created(), notFound(), internalServerError(), successful(), failed(),
          isClientError(), isServerError(), isRedirect()
        - Test json() returns associative array for valid JSON body
        - Test object() returns stdClass for valid JSON body
        - Test RuntimeException for invalid JSON body
        - Test data() with dot-notation keys resolves nested values
        - Test data() with wildcard (*) segments collects values from all array
          items
        - Test PSR-7 header methods: getHeader(), getHeaderLine(), hasHeader(),
          withHeader(), withoutHeader() return new instances
        - Test non-zero errno makes isNetworkError() and failed() return true
        - Test withStatus() returns new instance with updated status
        - Test withProtocolVersion() returns new instance with updated version
        - Test getBody() returns StringStream for in-memory bodies
        - Test raw headers with multiple response blocks (redirects) parses only
          final block
        - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7, 3.8, 3.9, 3.10,
          3.11, 3.12_

    - [x] 5.2 Write property test for Response status code helper mapping
        - **Property 5: Response status code maps to correct helpers**
        - For any status code 100–599, exactly the matching helper methods
          return true
        - **Validates: Requirements 3.1, 3.7**

- [x]
    6. Implement HttpClient tests

    - [x] 6.1 Create HttpClientTest with fluent API, URL composition, content
      types, headers, middleware, retry, response class tests
        - Create `tests/HttpClientTest.php` (replace existing test file)
        - Test make() returns new HttpClient instance
        - Test withBaseUrl() and resource() compose endpoint via getEndpoint()
        - Test withMethod() stores GET, POST, PUT, PATCH, DELETE correctly (use
          reflection)
        - Test withHeaders() and withHeader() accumulate headers without
          duplicates
        - Test withBearerToken() sets authorization header with Bearer prefix
        - Test withQuery() merges query parameters
        - Test withJson() encodes data and sets application/json content type
        - Test withJson() with non-encodable data throws
          InvalidArgumentException
        - Test withForm() sets application/x-www-form-urlencoded and URL-encodes
        - Test withGraphQL() encodes query and variables as JSON
        - Test asJson(), asForm(), asMultipart(), asRaw() set correct content
          type constants
        - Test withMiddleware() registers closures and named middleware prevents
          duplicates
        - Test withResponseClass() accepts valid subclasses, rejects invalid
          classes
        - Test withResponseClass() with non-existent class throws
          InvalidArgumentException
        - Test retry() stores count/delay, throws InvalidArgumentException for
          times < 1
        - Test shouldRetry() returns false for non-seekable stream bodies
        - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 4.8, 4.9, 4.10,
          4.11, 4.12, 4.13, 4.14, 4.15, 4.16_

- [x]
    7. Checkpoint - Ensure all core tests pass

    - Ensure all tests pass, ask the user if questions arise.

- [x]
    8. Implement trait tests

    - [x] 8.1 Create MacroableTraitTest with macro registration, mixin, $this
      binding, undefined macro tests
        - Create `tests/Traits/MacroableTraitTest.php`
        - Use anonymous class that `use Macroable`
        - Test macro() registers closure and calling it executes the closure
        - Test calling non-existent macro throws BadMethodCallException
        - Test mixin() registers all public/protected methods from mixin object
        - Test mixin() with replace=false does not overwrite existing macros
        - Test macros have access to host object via $this binding
        - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5_

    - [x] 8.2 Create CurlOptionsTraitTest with timeout, buffer, SSL, options
      tests
        - Create `tests/Traits/CurlOptionsTraitTest.php`
        - Use anonymous class that `use CurlOptionsTrait`
        - Test timeout() and connectionTimeout() store values, throw
          InvalidArgumentException for negative
        - Test withBufferSize() stores buffer size
        - Test withoutVerifying() disables SSL verification
        - Test withOptions() merges arbitrary options
        - Test verbose() enables CURLOPT_VERBOSE
        - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5_

    - [x] 8.3 Create DeprecatedTraitTest verifying E_USER_DEPRECATED notices
        - Create `tests/Traits/DeprecatedTraitTest.php`
        - Use set_error_handler() to capture E_USER_DEPRECATED
        - Test query() triggers deprecation notice
        - Test formData() triggers deprecation notice
        - Test raw() triggers deprecation notice
        - Test json() triggers deprecation notice
        - Test graphQL() triggers deprecation notice
        - _Requirements: 11.1, 11.2, 11.3, 11.4, 11.5_

    - [x] 8.4 Create SandboxTraitTest with endpoint switching tests
        - Create `tests/Traits/SandboxTraitTest.php`
        - Use anonymous class that `use Sandbox` with production and sandbox
          endpoints
        - Test getEndpoint() returns production endpoint by default
        - Test sandbox() switches to sandbox endpoint
        - Test getEndpoint() appends URI parameter
        - _Requirements: 12.1, 12.2, 12.3_

    - [x] 8.5 Create RetryTraitTest with retry conditions, callbacks,
      non-seekable stream tests
        - Create `tests/Traits/RetryTraitTest.php`
        - Use anonymous class that `use RetryTrait`
        - Test retry() throws InvalidArgumentException for times < 1
        - Test shouldRetry() returns true for retryable network errors
        - Test shouldRetry() returns true for server errors on GET/HEAD/OPTIONS
        - Test shouldRetry() returns false for server errors on POST/PUT
        - Test retryWhen() custom callback is used by shouldRetry()
        - Test shouldRetry() returns false for non-seekable stream postFields
        - _Requirements: 16.1, 16.2, 16.3, 16.4, 16.5, 16.6_

- [x]
    9. Implement exception tests

    - [x] 9.1 Create ExceptionTest with PSR-18 interface compliance and context
      preservation tests
        - Create `tests/Exceptions/ExceptionTest.php`
        - Test ClientException implements
          Psr\Http\Client\ClientExceptionInterface
        - Test NetworkException implements NetworkExceptionInterface and
          getRequest() returns RequestInterface
        - Test RequestException implements RequestExceptionInterface and
          getRequest() returns RequestInterface
        - Test message, code, and previous exception are preserved
        - _Requirements: 7.1, 7.2, 7.3, 7.4_

- [x]
    10. Checkpoint - Ensure all trait and exception tests pass

    - Ensure all tests pass, ask the user if questions arise.

- [x]
    11. Implement OAuth2 and storage tests

    - [x] 11.1 Create OAuth2Test with token lifecycle, sandbox, refresh, and
      error handling tests
        - Create `tests/Clients/OAuth2Test.php`
        - Mock StorageInterface and GenericProvider
        - Test request() factory returns instance with correct credentials
        - Test sandbox() switches endpoint
        - Test getEndpoint() returns production by default, sandbox after
          sandbox()
        - Test getAccessToken() returns cached token when valid and not expired
        - Test expired token with refresh token triggers refreshToken()
        - Test refreshToken() without refresh token throws
          InvalidArgumentException
        - Test token acquisition failure returns null
        - _Requirements: 8.1, 8.2, 8.3, 8.4, 8.5, 8.6, 8.7_

    - [x] 11.2 Create SimpleOAuth2Test with token lifecycle and error handling
      tests
        - Create `tests/Clients/SimpleOAuth2Test.php`
        - Create concrete test subclass extending SimpleOAuth2 with mock
          postRequest()
        - Mock StorageInterface
        - Test makeWith() creates instance with correct credentials
        - Test getAccessToken() returns cached non-expired token
        - Test expired token triggers new postRequest()
        - Test postRequest() returning non-200 makes getAccessToken() return
          null
        - _Requirements: 9.1, 9.2, 9.3, 9.4_

    - [x] 11.3 Create SimpleOAuth2ResponseTest with accessor method tests
        - Create `tests/Clients/SimpleOAuth2ResponseTest.php`
        - Load `tests/fixtures/oauth2-token.json` for test data
        - Test getToken(), getExpiresIn(), getExpiresAt(), hasExpired(),
          getRefreshToken(), getTokenType(), getScope()
        - _Requirements: 9.5_

    - [x] 11.4 Create SessionStorageTest with CRUD operations
        - Create `tests/Clients/SessionStorageTest.php`
        - Simulate $_SESSION superglobal in setUp()
        - Test set() stores value retrievable by get()
        - Test has() returns true for existing, false for non-existing keys
        - Test remove() deletes key so has() returns false
        - Test get() returns null for non-existing keys
        - _Requirements: 10.1, 10.2, 10.3, 10.4_

- [x]
    12. Implement middleware tests

    - [x] 12.1 Create MiddlewareTest with execution order, response
      modification, short-circuit tests
        - Create `tests/MiddlewareTest.php`
        - Create testable HttpClient subclass exposing middleware pipeline
        - Test middleware closures execute in registration order
        - Test middleware can modify response before returning
        - Test middleware can short-circuit by not calling next handler
        - Test middleware returning non-Response throws RuntimeException
        - _Requirements: 17.1, 17.2, 17.3, 17.4_

- [x]
    13. Final checkpoint - Ensure all tests pass and code quality checks

    - Ensure all tests pass, ask the user if questions arise.
    - Run `vendor/bin/phpstan analyse --memory-limit=512M` including tests
      directory
    - Run `vendor/bin/phpmd tests text phpmd.xml`
    - Verify all test files have `declare(strict_types=1)`
    - Verify PHPDoc annotations on all test methods
    - _Requirements: 14.1, 14.2, 14.3, 15.1, 15.2, 15.3_

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation
- Property tests validate universal correctness properties from the design
  document (Properties 1–5)
- Unit tests validate specific examples and edge cases
- All tests use mocks, reflection, and direct construction — no real HTTP calls
- Test files must use `declare(strict_types=1)` and full PHPDoc annotations for
  PHPStan level 8
