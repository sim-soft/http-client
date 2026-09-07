# Project Structure

```
src/
├── HttpClient.php          # Main client class (implements PSR-18 ClientInterface)
├── HttpPool.php            # Concurrent request execution via curl_multi_*
├── HttpPoolResult.php      # Pool result value object (Countable, ArrayAccess, IteratorAggregate)
├── PoolBuilder.php         # Request factory for HttpPool::run()
├── Response.php            # Response class (implements PSR-7 ResponseInterface)
├── Clients/                # Specialized client implementations
│   ├── OAuth2.php          # OAuth2 client (client_credentials, authorization_code)
│   ├── SimpleOAuth2.php    # Simplified OAuth2 client
│   ├── TokenData.php       # Token value object
│   ├── Helpers/
│   │   ├── FileStorage.php
│   │   └── SessionStorage.php
│   └── Responses/
│       ├── OAuth2TokenResponse.php
│       └── SimpleOAuth2Response.php
├── Exceptions/             # PSR-18 compliant exceptions
│   ├── ClientException.php
│   ├── NetworkException.php
│   └── RequestException.php
├── Interfaces/
│   └── StorageInterface.php
├── Streams/                # PSR-7 StreamInterface implementations
│   ├── Stream.php          # Base stream
│   ├── FileStream.php      # File-backed stream
│   └── StringStream.php    # String-backed stream
├── Testing/                # Built-in test doubles
│   ├── FakeHttpClient.php  # Request mocking with pattern matching
│   ├── FakeRoute.php       # Route matcher for fake responses
│   ├── RecordedRequest.php # Recorded request DTO
│   └── UnexpectedRequestException.php
└── Traits/                 # Composable behavior traits
    ├── AttachmentTrait.php
    ├── CurlOptionsTrait.php
    ├── DebugTrait.php
    ├── DeprecatedTrait.php
    ├── Macroable.php
    ├── PrepareHandleTrait.php
    ├── RequestBodyTrait.php
    ├── RetryTrait.php
    ├── Sandbox.php
    └── SinkTrait.php

tests/
├── HttpClientTest.php
├── HttpPoolTest.php
├── HttpPoolPropertyTest.php
├── HttpPoolResultTest.php
├── HttpPoolResultPropertyTest.php
├── ConnectionPoolTest.php
├── ConnectionPoolPropertyTest.php
├── ResponseTest.php
├── MiddlewareTest.php
├── Clients/
├── Exceptions/
├── Streams/
├── Traits/
└── fixtures/               # JSON fixtures for test data
```

## Architecture Patterns

- **Trait composition**: HttpClient uses multiple traits (Macroable, DebugTrait,
  CurlOptionsTrait, RetryTrait, etc.) to separate concerns while keeping a
  single public class.
- **Fluent API**: All configuration methods return `$this` for chaining.
- **PSR compliance**: Response implements `ResponseInterface`, HttpClient
  implements `ClientInterface`.
- **Immutable responses**: PSR-7 `with*` methods on Response return clones.
- **Custom response classes**: Extensible via `withResponseClass()` — subclasses
  of `Response` can be injected.
- **Middleware**: Named closures stored in an associative array, receiving
  `(HttpClient, Closure): Response`.
- **Concurrent execution**: HttpPool uses `curl_multi_*` with a sliding window,
  FakeHttpClient path for testing, and supports named requests via string keys.
- **Built-in test doubles**: FakeHttpClient intercepts requests without network,
  supports pattern matching, response sequencing, and PHPUnit assertions.
