# Comparison with Other Libraries

## Feature Matrix

| Feature                       | **Simsoft HttpClient**             | **Guzzle**                                 | **Symfony HttpClient**            | **Laravel HTTP Client** |
|-------------------------------|------------------------------------|--------------------------------------------|-----------------------------------|-------------------------|
| **PHP requirement**           | 8.1+                               | 7.2.5+                                     | 8.2+                              | 8.2+ (framework)        |
| **Dependencies**              | `ext-curl` only                    | `psr/http-*`, `psr/log`, optional adapters | None (native PHP streams or curl) | Wraps Guzzle            |
| **Architecture**              | Single class + traits, direct cURL | Handler stack, middleware, promises        | Contracts + multiple transports   | Facade over Guzzle      |
| **PSR-18**                    | ✅                                  | ✅                                          | ✅ (adapter)                       | ❌ (Guzzle underneath)   |
| **PSR-7**                     | ✅ (response)                       | ✅ (full)                                   | ❌ (own contracts)                 | ❌ (own contracts)       |
| **Transport**                 | cURL directly                      | cURL or stream                             | cURL, stream, amphp               | Guzzle (cURL)           |
| **HTTP/2**                    | ✅ native + multiplexing            | ✅ via cURL                                 | ✅ native + multiplexing           | ✅ via Guzzle            |
| **Fluent API**                | ✅                                  | ❌ (options array)                          | ✅                                 | ✅                       |
| **Middleware pipeline**       | ✅ named closures                   | ✅ HandlerStack                             | ✅ event listeners                 | ✅ (limited)             |
| **Retry built-in**            | ✅ + custom callback                | Via middleware                             | ✅ RetryableHttpClient             | ✅                       |
| **Async / concurrent**        | ✅ HttpPool (curl_multi)            | ✅ promises                                 | ✅ native                          | ✅ via Guzzle            |
| **Streaming upload/download** | ✅                                  | ✅                                          | ✅                                 | ✅                       |
| **File attachments**          | ✅ CURLFile, path, resource, string | ✅                                          | ✅                                 | ✅                       |
| **Response dot-notation**     | ✅ + wildcards                      | ❌                                          | ❌                                 | ❌                       |
| **Built-in test double**      | ✅ FakeHttpClient                   | ✅ MockHandler                              | ✅ MockHttpClient                  | ✅ Http::fake()          |
| **Connection pooling**        | ✅ automatic handle reuse           | ✅                                          | ✅                                 | ✅ via Guzzle            |
| **Standalone**                | ✅                                  | ✅                                          | ✅                                 | ❌ requires Laravel      |
| **Install size**              | ⭐ Tiny                             | Medium                                     | Medium                            | Large (framework)       |

## Key Differentiators

- **Simpler mental model** — one class, trait composition, no handler stacks or
  DI containers
- **Zero-dependency core** — only requires ext-curl
- **Dot-notation response access** — `$response->data('data.users.*.name')` with
  wildcards
- **Direct cURL control** — every cURL option accessible without abstraction
  layers
- **Concurrent requests without promises** — `HttpPool` uses `curl_multi_*`
  directly
- **Built-in test double** — FakeHttpClient with pattern matching and PHPUnit
  assertions

## Trade-offs

- **No pluggable transports** — locked to cURL (intentional: predictable
  behavior, direct access to every option)
- **No promise-based async** — uses `curl_multi` polling (explicit about what
  actually happens in PHP's request lifecycle)

## When to Choose Each

| Choose                 | When                                                                                                 |
|------------------------|------------------------------------------------------------------------------------------------------|
| **Simsoft HttpClient** | Standalone services, CLI tools, or libraries needing minimal deps, full cURL control, and fluent API |
| **Guzzle**             | You need promise-based async, broad ecosystem support, or are in a Guzzle-dependent stack            |
| **Symfony HttpClient** | You need multiple transport backends (amphp, native streams), or are in a Symfony project            |
| **Laravel HTTP**       | You're in Laravel and want the framework's testing fakes and collection integration                  |

---

## See Also

- [← Back to README](/)
