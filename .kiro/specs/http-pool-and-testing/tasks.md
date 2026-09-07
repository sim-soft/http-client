# Implementation Plan: HttpPool & Testing

## Overview

This plan implements three features for the Simsoft HttpClient library:
HttpPool (concurrent requests via `curl_multi_*`), Connection Pooling
validation/documentation, and FakeHttpClient (built-in test double).
Implementation proceeds bottom-up — value objects and support classes first,
then core logic, then integration and documentation.

## Tasks

- [x]
    1. Connection pooling validation and HttpClient::buildHandle()

    - [x] 1.1 Add `buildHandle()` public method to HttpClient
        - Add a public method `buildHandle(): CurlHandle` that wraps
          `prepareHandle()` for external pool usage
        - Change `prepareHandle()` visibility from `private` to `protected` in
          `PrepareHandleTrait`
        - `buildHandle()` generates a unique request ID and delegates to
          `prepareHandle()`
        - _Requirements: 6.1, 6.2, 7.1, 7.2, 7.3, 13.1, 13.2_

    - [x] 1.2 Write property test for handle reuse invariant
        - **Property 7: Handle Reuse Invariant**
        - **Validates: Requirements 6.1, 6.2**
        - Use `Gen::choose(2, 20)` for request count, verify same CurlHandle
          instance via reflection

    - [x] 1.3 Write unit tests for connection pooling and buildHandle()
        - Test that `buildHandle()` returns a valid CurlHandle
        - Test that sequential requests reuse the same handle (reflection check)
        - Test that `__destruct()` closes the handle
        - Test that no new public methods are exposed beyond `buildHandle()`
        - _Requirements: 6.1, 6.2, 6.3, 7.1, 7.2, 7.3, 13.1, 13.2_

- [x]
    2. Implement HttpPoolResult value object

    - [x] 2.1 Create `src/HttpPoolResult.php`
        - Implement `Countable` interface
        - Constructor accepts `array<int, Response>` indexed responses
        - Implement `getResponses()`, `getSuccessful()`, `getFailed()`,
          `getResponse(int $index)`, `count()`
        - `getSuccessful()` filters where `successful()` is true
        - `getFailed()` filters where `failed()` is true
        - `getResponse()` throws `OutOfBoundsException` for invalid index
        - _Requirements: 1.2, 4.3, 4.4_

    - [x] 2.2 Write property test for result partitioning
        - **Property 5: Pool Result Partitioning**
        - **Validates: Requirements 4.3, 4.4**
        - Use `Gen::choose(1, 50)` for count, `Gen::elements` for status codes (
          mix of 2xx, 4xx, 5xx)

    - [x] 2.3 Write unit tests for HttpPoolResult
        - Test empty result (zero responses)
        - Test all successful responses
        - Test all failed responses
        - Test mixed results partitioning
        - Test `getResponse()` with invalid index throws exception
        - Test `count()` returns correct total
        - _Requirements: 1.2, 4.3, 4.4_

- [x]
    3. Checkpoint - Ensure all tests pass

    - Ensure all tests pass, ask the user if questions arise.

- [x]
    4. Implement FakeHttpClient support classes

    - [x] 4.1 Create `src/Testing/RecordedRequest.php`
        - Simple readonly DTO with `method`, `url`, `headers`, `body` properties
        - Constructor uses PHP 8.1 readonly promoted properties
        - _Requirements: 9.1_

    - [x] 4.2 Create `src/Testing/FakeRoute.php`
        - Properties: `matcher` (string|Closure), `responses` (array of
          Response), `cursor` (int)
        - `match(string $method, string $url): bool` — supports exact match,
          wildcard `*`, method prefix, callable
        - `nextResponse(): Response` — returns current response and advances
          cursor, clamped to last index
        - Pattern matching: "METHOD URL" format, URL-only format, wildcard
          expansion via `fnmatch()`
        - _Requirements: 8.2, 8.4, 10.1, 10.2_

    - [x] 4.3 Write property test for pattern matching
        - **Property 8: Fake Pattern Matching**
        - **Validates: Requirements 8.2, 8.4**
        - Use `Gen::asciiStrings` for URLs, `Gen::elements` for pattern types

    - [x] 4.4 Write property test for response sequencing
        - **Property 12: Fake Response Sequencing**
        - **Validates: Requirements 10.1, 10.2**
        - Use `Gen::choose(1, 10)` for sequence length, `Gen::choose(1, 20)` for
          total requests

- [x]
    5. Implement FakeHttpClient

    - [x] 5.1 Create `src/Testing/FakeHttpClient.php`
        - Extends `HttpClient`, overrides `getCoreHandler()` to intercept
          execution
        - `fake(array $responses): static` — static factory
        - `addFake(string|Closure $matcher, Response|array|int $response): self`
        - `sequence(string $pattern, array $responses): self`
        - `getCoreHandler()` records request, matches against routes, returns
          fake Response
        - Throws `UnexpectedRequestException` when no route matches
        - Response factory: int → Response with status code; array → Response
          with status/headers/body; Response → used directly
        - _Requirements: 8.1, 8.2, 8.3, 8.4, 8.5, 9.1_

    - [x] 5.2 Add assertion methods to FakeHttpClient
        - `assertSent(string $method, string $url): void`
        - `assertNotSent(string $method, string $url): void`
        - `assertNothingSent(): void`
        - `assertSentCount(int $count): void`
        - `getRecordedRequests(): array`
        - Assertions throw `\PHPUnit\Framework\AssertionFailedError` on failure
        - _Requirements: 9.2, 9.3, 9.4, 9.5, 11.1, 11.2, 11.3_

    - [x] 5.3 Create `src/Testing/UnexpectedRequestException.php`
        - Extends `RuntimeException`
        - Includes method and URL in the exception message
        - _Requirements: 8.3_

    - [x] 5.4 Write property test for unmatched request exception
        - **Property 9: Fake Unmatched Request Exception**
        - **Validates: Requirements 8.3**
        - Use `Gen::asciiStrings` for non-matching URLs

    - [x] 5.5 Write property test for fake response construction
        - **Property 10: Fake Response Construction**
        - **Validates: Requirements 8.5**
        - Use `Gen::choose(100, 599)` for status, `Gen::asciiStrings` for body

    - [x] 5.6 Write property test for request recording
        - **Property 11: Fake Request Recording**
        - **Validates: Requirements 9.1, 9.2, 9.3, 9.4**
        - Use `Gen::choose(1, 20)` for request count, `Gen::elements` for
          methods

    - [x] 5.7 Write unit tests for FakeHttpClient
        - Test `fake()` static factory creates instance
        - Test exact URL matching
        - Test wildcard pattern matching (`*` segments)
        - Test method + URL matching (`"GET /users"`)
        - Test callable matcher
        - Test `assertNothingSent()` on fresh instance
        - Test `assertNothingSent()` fails after request
        - Test PHPUnit assertion exceptions on failure
        - Test response from array config (status, headers, body)
        - Test response from integer (status code only)
        - Test mixing sequenced and single responses
        - Test response sequencing repeats last response
        - _Requirements: 8.1, 8.2, 8.3, 8.4, 8.5, 9.1, 9.2, 9.3, 9.4, 9.5, 10.1,
          10.2, 10.3, 11.1, 11.2, 11.3_

- [x]
    6. Checkpoint - Ensure all tests pass

    - Ensure all tests pass, ask the user if questions arise.

- [x]
    7. Implement HttpPool

    - [x] 7.1 Create `src/HttpPool.php` with core structure
        - Constructor accepts `int $concurrency = 25`
        - Fluent methods: `concurrency(int $limit): self`,
          `onResponse(Closure $callback): self`,
          `onError(Closure $callback): self`
        - Validate concurrency ≥ 1 (throw `InvalidArgumentException`)
        - _Requirements: 1.3, 1.4, 1.6, 3.2_

    - [x] 7.2 Implement `HttpPool::send()` execution loop
        - Accept `array<int, HttpClient|Closure>` — resolve closures to
          HttpClient instances
        - Validate all entries are HttpClient or Closure returning HttpClient
        - Initialize `curl_multi_init()`, set `CURLMOPT_PIPELINING` to
          `CURLPIPE_MULTIPLEX`
        - Sliding window: add handles up to concurrency limit, replace completed
          with pending
        - Use `curl_multi_exec()` + `curl_multi_select()` for non-blocking
          polling
        - Build Response from completed handles using `curl_multi_info_read()`
        - Capture headers per-handle via `CURLOPT_HEADERFUNCTION`
        - Invoke `onResponse` callback for each completed request
        - Invoke `onError` callback for failed responses (4xx, 5xx, network
          errors)
        - Release all handles and close multi handle on completion
        - Return `HttpPoolResult` with responses indexed to match input order
        - _Requirements: 1.1, 1.2, 1.3, 1.5, 2.1, 2.2, 2.3, 3.1, 3.3, 3.4, 4.1,
          4.2, 5.1, 5.2, 5.3_

    - [x] 7.3 Write property test for pool count invariant
        - **Property 1: Pool Count Invariant**
        - **Validates: Requirements 1.1, 4.1, 4.2, 5.2**
        - Use `Gen::choose(1, 50)` for batch size, FakeHttpClient instances for
          requests

    - [x] 7.4 Write property test for pool order preservation
        - **Property 2: Pool Order Preservation**
        - **Validates: Requirements 1.2**
        - Use `Gen::choose(1, 30)` for batch size, unique identifiers per
          request

    - [x] 7.5 Write property test for pool concurrency limit
        - **Property 3: Pool Concurrency Limit**
        - **Validates: Requirements 1.3**
        - Use `Gen::choose(1, 10)` for concurrency, `Gen::choose(1, 30)` for
          batch size

    - [x] 7.6 Write property test for pool failure isolation
        - **Property 4: Pool Failure Isolation**
        - **Validates: Requirements 4.1, 4.2**
        - Use `Gen::choose(1, 20)` for batch size, `Gen::elements` for failure
          positions

    - [x] 7.7 Write property test for pool callback invocation
        - **Property 6: Pool Callback Invocation**
        - **Validates: Requirements 5.1, 5.3**
        - Use `Gen::choose(1, 20)` for batch size, mixed success/failure

    - [x] 7.8 Write unit tests for HttpPool
        - Test default concurrency is 25
        - Test empty request array returns empty result
        - Test single request works (degenerate case)
        - Test closure-based request creation
        - Test invalid input (non-HttpClient) throws `InvalidArgumentException`
        - Test concurrency limit ≤ 0 throws `InvalidArgumentException`
        - Test `onResponse` callback receives correct index
        - Test `onError` callback invoked for failed requests
        - Test HTTP/2 multiplexing configuration
        - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 2.1, 2.2, 3.2, 3.3, 3.4,
          4.1, 4.2, 5.1, 5.2, 5.3, 12.1, 12.4_

- [x]
    8. Checkpoint - Ensure all tests pass

    - Ensure all tests pass, ask the user if questions arise.

- [x]
    9. Documentation

    - [x] 9.1 Create `docs/POOL.md` tutorial
        - Basic concurrent request execution example
        - Concurrency limit configuration example
        - Per-response callback usage example
        - Error handling in concurrent batches example
        - Performance comparison section (advantages over Guzzle Pool and
          Symfony HttpClient)
        - Connection pooling explanation (automatic handle reuse)
        - _Requirements: 14.1, 14.2, 14.3, 14.4, 14.5_

    - [x] 9.2 Create `docs/TESTING.md` tutorial
        - Basic request mocking with FakeHttpClient example
        - URL pattern matching with wildcards example
        - Response sequencing for retry testing example
        - Request assertion methods in PHPUnit tests example
        - Testing error handling with fake error responses example
        - _Requirements: 15.1, 15.2, 15.3, 15.4, 15.5_

- [x]
    10. Final checkpoint - Ensure all tests pass

    - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation
- Property tests validate universal correctness properties from the design
  document
- Unit tests validate specific examples and edge cases
- All property tests use `steos/quickcheck` ^2.0 (already in require-dev)
- FakeHttpClient is used internally by HttpPool property tests to avoid real
  network calls
- Connection pooling is already implemented in
  `CurlOptionsTrait::initCurlHandle()` — task 1 validates and exposes it

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "2.1", "4.1"] },
    { "id": 1, "tasks": ["1.2", "1.3", "2.2", "2.3", "4.2"] },
    { "id": 2, "tasks": ["4.3", "4.4", "5.1"] },
    { "id": 3, "tasks": ["5.2", "5.3"] },
    { "id": 4, "tasks": ["5.4", "5.5", "5.6", "5.7"] },
    { "id": 5, "tasks": ["7.1"] },
    { "id": 6, "tasks": ["7.2"] },
    { "id": 7, "tasks": ["7.3", "7.4", "7.5", "7.6", "7.7", "7.8"] },
    { "id": 8, "tasks": ["9.1", "9.2"] }
  ]
}
```
