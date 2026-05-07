# Requirements Document

## Introduction

This document specifies requirements for three new features in the Simsoft
HttpClient library: **HttpPool** (concurrent/async HTTP requests via
`curl_multi_*`), **Connection Pooling** (transparent cURL handle reuse within
HttpClient), and **Built-in Mocking** (a FakeHttpClient for PHPUnit testing).
These features address the current gaps identified in the comparison table (no
async/concurrent requests, no connection pooling, no built-in request mocking)
while preserving the library's zero-dependency philosophy and backward
compatibility.

## Glossary

- **HttpPool**: A companion class that executes multiple HTTP requests
  concurrently using the `curl_multi_*` API with non-blocking polling.
- **HttpClient**: The existing `Simsoft\HttpClient\HttpClient` class that
  performs single HTTP requests via `curl_*`.
- **Connection_Pool**: An internal mechanism within HttpClient that reuses cURL
  handles across sequential requests to the same host, reducing TCP/TLS
  handshake overhead.
- **FakeHttpClient**: A test double class extending HttpClient that returns
  predefined responses without making real network calls.
- **Response**: The existing `Simsoft\HttpClient\Response` class implementing
  PSR-7 ResponseInterface.
- **curl_multi**: The PHP `curl_multi_*` family of functions that enable
  concurrent HTTP request execution.
- **HTTP2_Multiplexing**: The HTTP/2 protocol feature that allows multiple
  requests to share a single TCP connection, enabled automatically when using
  `curl_multi_*` with HTTP/2.
- **Throughput**: The number of HTTP requests completed per unit of time.
- **Handle_Reuse**: The practice of calling `curl_reset()` on an existing
  CurlHandle instead of creating a new one via `curl_init()`.
- **Fake_Response**: A predefined Response object configured in FakeHttpClient
  to be returned when a matching request is made.
- **Request_Matcher**: A rule (URL pattern, method, or callable) used by
  FakeHttpClient to determine which fake response to return.
- **Concurrency_Limit**: The maximum number of requests executing simultaneously
  in HttpPool at any given time.

## Requirements

### Requirement 1: HttpPool Concurrent Request Execution

**User Story:** As a developer, I want to send multiple HTTP requests
concurrently, so that I can reduce total execution time when communicating with
multiple endpoints or fetching batch data.

#### Acceptance Criteria

1. WHEN an array of HttpClient instances or closures returning HttpClient
   instances is provided, THE HttpPool SHALL execute all requests concurrently
   using `curl_multi_*` functions.
2. WHEN concurrent requests complete, THE HttpPool SHALL return an indexed array
   of Response objects in the same order as the input requests.
3. WHEN a concurrency limit is configured, THE HttpPool SHALL execute no more
   than the specified number of requests simultaneously.
4. IF no concurrency limit is specified, THEN THE HttpPool SHALL default to a
   concurrency limit of 25 requests.
5. WHEN executing requests, THE HttpPool SHALL use non-blocking polling via
   `curl_multi_exec` with `curl_multi_select` to avoid busy-waiting.
6. THE HttpPool SHALL not use promises, event loops, or any external concurrency
   abstraction.

### Requirement 2: HttpPool HTTP/2 Multiplexing Support

**User Story:** As a developer, I want HTTP/2 multiplexing to work automatically
when using HttpPool, so that multiple requests to the same host share a single
TCP connection without additional configuration.

#### Acceptance Criteria

1. THE HttpPool SHALL configure `CURL_HTTP_VERSION_2_0` on all managed cURL
   handles to enable HTTP/2 negotiation.
2. WHEN multiple requests target the same host, THE HttpPool SHALL enable HTTP/2
   multiplexing by setting `CURLMOPT_PIPELINING` to `CURLPIPE_MULTIPLEX`.
3. WHEN the server does not support HTTP/2, THE HttpPool SHALL fall back to
   HTTP/1.1 without error.

### Requirement 3: HttpPool Performance Targets

**User Story:** As a developer, I want HttpPool to outperform Guzzle Pool and
Symfony HttpClient in raw throughput, so that I can achieve maximum performance
for concurrent workloads.

#### Acceptance Criteria

1. THE HttpPool SHALL complete 100 concurrent requests with lower peak memory
   usage than Guzzle Pool executing the same workload.
2. THE HttpPool SHALL not create promise objects, generator wrappers, or event
   loop instances during request execution.
3. THE HttpPool SHALL allocate one `curl_multi` handle per pool execution and
   reuse it for all requests in that batch.
4. WHEN all requests in a batch complete, THE HttpPool SHALL release all cURL
   handles and the multi handle immediately.

### Requirement 4: HttpPool Error Handling

**User Story:** As a developer, I want HttpPool to handle individual request
failures gracefully, so that one failed request does not prevent other requests
from completing.

#### Acceptance Criteria

1. IF a single request within the pool fails with a network error, THEN THE
   HttpPool SHALL continue executing remaining requests and include the failed
   Response in the results array at its original index.
2. IF a single request returns an HTTP error status (4xx or 5xx), THEN THE
   HttpPool SHALL include that Response in the results array without
   interrupting other requests.
3. WHEN all requests complete, THE HttpPool SHALL provide a method to retrieve
   only failed responses from the batch.
4. WHEN all requests complete, THE HttpPool SHALL provide a method to retrieve
   only successful responses from the batch.

### Requirement 5: HttpPool Callback Support

**User Story:** As a developer, I want to process responses as they arrive
rather than waiting for the entire batch, so that I can handle results
incrementally for large batches.

#### Acceptance Criteria

1. WHERE a per-response callback is configured, THE HttpPool SHALL invoke the
   callback with the Response object and its index as each individual request
   completes.
2. WHERE a per-response callback is configured, THE HttpPool SHALL still return
   the complete results array after all requests finish.
3. WHERE an error callback is configured, THE HttpPool SHALL invoke the error
   callback for each request that fails with a network error or HTTP error
   status.

### Requirement 6: Connection Pooling in HttpClient

**User Story:** As a developer, I want my HttpClient instance to reuse cURL
handles across sequential requests to the same host, so that I benefit from
reduced TCP/TLS handshake overhead without changing my code.

#### Acceptance Criteria

1. WHEN multiple sequential requests are made on the same HttpClient instance,
   THE HttpClient SHALL reuse the internal cURL handle by calling `curl_reset()`
   instead of `curl_init()`.
2. THE HttpClient SHALL maintain a single reusable cURL handle per instance.
3. WHEN the HttpClient instance is destroyed, THE HttpClient SHALL close the
   reusable cURL handle via `curl_close()`.
4. THE HttpClient SHALL preserve full backward compatibility with the existing
   public API when connection pooling is active.
5. WHEN connection pooling is active, THE HttpClient SHALL reduce latency by 30
   percent or more for repeated requests to the same host compared to creating a
   new handle per request.

### Requirement 7: Connection Pooling Transparency

**User Story:** As a developer, I want connection pooling to work without any
API changes, so that existing code benefits from the optimization automatically.

#### Acceptance Criteria

1. THE HttpClient SHALL enable connection pooling by default without requiring
   any method call or configuration change.
2. THE HttpClient SHALL not expose any new public methods specifically for
   connection pool management.
3. WHEN a user upgrades to the new version, THE HttpClient SHALL behave
   identically to the previous version from the public API perspective.

### Requirement 8: FakeHttpClient Test Double

**User Story:** As a developer, I want a built-in test double for HttpClient, so
that I can write unit tests that verify HTTP interactions without making real
network calls.

#### Acceptance Criteria

1. THE FakeHttpClient SHALL extend HttpClient and override request execution to
   return predefined Response objects.
2. WHEN a request matches a configured URL pattern, THE FakeHttpClient SHALL
   return the corresponding fake Response.
3. WHEN a request does not match any configured pattern, THE FakeHttpClient
   SHALL throw an exception indicating an unexpected request was made.
4. THE FakeHttpClient SHALL support matching requests by exact URL, URL pattern
   with wildcards, HTTP method, or a custom callable matcher.
5. THE FakeHttpClient SHALL support defining fake responses with configurable
   status code, headers, and body content.

### Requirement 9: FakeHttpClient Request Recording

**User Story:** As a developer, I want to inspect which requests were made
during a test, so that I can assert that my code communicates with the correct
endpoints using the correct parameters.

#### Acceptance Criteria

1. THE FakeHttpClient SHALL record all requests made during its lifetime
   including method, URL, headers, and body.
2. THE FakeHttpClient SHALL provide a method to assert that a specific request
   was made with given method and URL.
3. THE FakeHttpClient SHALL provide a method to assert the total number of
   requests made.
4. THE FakeHttpClient SHALL provide a method to retrieve all recorded requests
   as an array.
5. THE FakeHttpClient SHALL provide a method to assert that no requests were
   made.

### Requirement 10: FakeHttpClient Response Sequencing

**User Story:** As a developer, I want to define a sequence of responses for the
same endpoint, so that I can test retry logic and state transitions in my code.

#### Acceptance Criteria

1. WHEN multiple responses are configured for the same URL pattern, THE
   FakeHttpClient SHALL return them in sequence (first configured response
   first, then second, and so on).
2. WHEN all sequenced responses have been consumed, THE FakeHttpClient SHALL
   repeat the last response for subsequent matching requests.
3. THE FakeHttpClient SHALL support mixing sequenced responses with single
   responses for different URL patterns.

### Requirement 11: FakeHttpClient PHPUnit Integration

**User Story:** As a developer using PHPUnit, I want FakeHttpClient to integrate
naturally with PHPUnit assertions, so that my test code is concise and readable.

#### Acceptance Criteria

1. THE FakeHttpClient SHALL provide assertion methods that throw PHPUnit
   assertion exceptions on failure.
2. THE FakeHttpClient SHALL work without requiring any PHPUnit base class
   extension beyond the standard TestCase.
3. THE FakeHttpClient SHALL be instantiable with a simple static factory method
   or constructor for minimal test setup.

### Requirement 12: Zero External Dependencies

**User Story:** As a library maintainer, I want all new features to have zero
external runtime dependencies, so that the library remains lightweight and easy
to install.

#### Acceptance Criteria

1. THE HttpPool SHALL depend only on `ext-curl` and existing library classes at
   runtime.
2. THE Connection_Pool SHALL depend only on `ext-curl` and existing library
   classes at runtime.
3. THE FakeHttpClient SHALL depend only on existing library classes at runtime
   and PHPUnit as a dev dependency for assertion integration.
4. THE HttpPool SHALL not require any additional Composer packages beyond those
   already in the library's `require` section.

### Requirement 13: Backward Compatibility

**User Story:** As an existing user of the library, I want all new features to
be additive, so that my existing code continues to work without modification
after upgrading.

#### Acceptance Criteria

1. THE HttpClient SHALL maintain the same public method signatures as the
   current version.
2. THE HttpClient SHALL not change the behavior of any existing public method.
3. WHEN connection pooling is active, THE HttpClient SHALL produce identical
   Response objects to the current implementation for the same requests.
4. THE HttpPool SHALL be a new class that does not modify or extend HttpClient.

### Requirement 14: HttpPool Documentation

**User Story:** As a developer, I want comprehensive documentation with examples
for HttpPool, so that I can quickly understand how to use concurrent requests in
my application.

#### Acceptance Criteria

1. THE documentation SHALL include a tutorial demonstrating basic concurrent
   request execution with HttpPool.
2. THE documentation SHALL include examples showing concurrency limit
   configuration.
3. THE documentation SHALL include examples showing per-response callback usage.
4. THE documentation SHALL include examples showing error handling in concurrent
   batches.
5. THE documentation SHALL include a performance comparison section explaining
   the advantages over Guzzle Pool and Symfony HttpClient.

### Requirement 15: FakeHttpClient Documentation

**User Story:** As a developer, I want comprehensive documentation with examples
for FakeHttpClient, so that I can quickly set up HTTP mocking in my PHPUnit
tests.

#### Acceptance Criteria

1. THE documentation SHALL include a tutorial demonstrating basic request
   mocking with FakeHttpClient.
2. THE documentation SHALL include examples showing URL pattern matching with
   wildcards.
3. THE documentation SHALL include examples showing response sequencing for
   retry testing.
4. THE documentation SHALL include examples showing request assertion methods in
   PHPUnit tests.
5. THE documentation SHALL include examples showing how to test error handling
   with fake error responses.
