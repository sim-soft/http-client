# Project Structure

```
src/
├── HttpClient.php          # Main client class (implements PSR-18 ClientInterface)
├── Response.php            # Response class (implements PSR-7 ResponseInterface)
├── Clients/                # Specialized client implementations
│   ├── OAuth2.php          # OAuth2 client base
│   ├── SimpleOAuth2.php    # Simplified OAuth2 client
│   ├── Helpers/
│   │   └── SessionStorage.php
│   └── Responses/
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
└── Traits/                 # Composable behavior traits
    ├── CurlOptionsTrait.php
    ├── DebugTrait.php
    ├── DeprecatedTrait.php
    ├── Macroable.php
    ├── PrepareHandleTrait.php
    ├── RetryTrait.php
    └── Sandbox.php

tests/
├── HttpClientTest.php      # Main client tests
├── ResponseTest.php
├── MiddlewareTest.php
├── Clients/                # Mirrors src/Clients structure
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

## Naming Conventions

- Classes: PascalCase
- Methods/properties: camelCase
- Constants: UPPER_SNAKE_CASE (class constants use `const` without visibility in
  some places)
- Test methods: camelCase descriptive names with `#[Test]` attribute
- PHPMD enforces: CamelCaseClassName, CamelCaseMethodName,
  CamelCasePropertyName, CamelCaseParameterName, CamelCaseVariableName

## Code Style Rules (from phpmd.xml)

- No `else` expressions (use early returns)
- No boolean argument flags (except in `__construct`, `parse`, `formal`, `make`,
  `lookup`)
- No static access (except whitelisted classes)
- Minimum variable name length: 2 characters
- No unused private fields, methods, local variables, or formal parameters
- Cyclomatic complexity and NPath complexity limits enforced
