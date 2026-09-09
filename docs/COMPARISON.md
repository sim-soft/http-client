# Comparison with Other Libraries

Measured on 2026-09-09 against the current stable release of each library:
Simsoft HttpClient 4.0.0, Guzzle 8.2.0, Symfony HttpClient 8.1.6, and Laravel
13.31.0. Package counts and sizes come from a real `composer require` with
`--update-no-dev` into an empty project, so they are what a consumer actually
downloads.

## Feature Matrix

| Feature                         | **Simsoft HttpClient**                | **Guzzle**                          | **Symfony HttpClient**             | **Laravel HTTP Client** |
|---------------------------------|---------------------------------------|-------------------------------------|------------------------------------|-------------------------|
| **PHP requirement**             | 8.4+                                  | 7.4+                                | 8.4.1+                             | 8.3+ (framework)        |
| **Composer packages pulled**    | 3                                     | 8                                   | 6                                  | 73 (framework)          |
| **Runtime dependencies**        | `ext-curl` + 2 PSR interface pkgs     | 7 packages incl. promises, psr7     | 5 packages incl. Symfony contracts | Wraps Guzzle            |
| **Architecture**                | Single class + traits, direct cURL    | Handler stack, middleware, promises | Contracts + multiple transports    | Facade over Guzzle      |
| **PSR-18**                      | ✅                                     | ✅                                   | ✅ (adapter)                        | ❌ (Guzzle underneath)   |
| **PSR-7**                       | ✅ (response + stream)                 | ✅ (full)                            | ❌ (own contracts)                  | ❌ (own contracts)       |
| **Transport**                   | cURL directly                         | cURL or stream                      | cURL, stream, amphp                | Guzzle (cURL)           |
| **HTTP/2**                      | ✅ native + multiplexing               | ✅ via cURL + multiplexing           | ✅ native + multiplexing            | ✅ via Guzzle            |
| **Fluent API**                  | ✅                                     | ❌ (options array)                   | ✅                                  | ✅                       |
| **Middleware pipeline**         | ✅ named closures                      | ✅ HandlerStack                      | ✅ event listeners                  | ✅ (limited)             |
| **Retry built-in**              | ✅ + custom callback                   | Via middleware                      | ✅ RetryableHttpClient              | ✅                       |
| **OAuth2 built-in**             | ✅ client_credentials, auth_code, PKCE | ❌ (basic/digest only)               | ❌ (`auth_bearer` only)             | ❌                       |
| **Async / concurrent**          | ✅ HttpPool (curl_multi)               | ✅ promises                          | ✅ native                           | ✅ via Guzzle            |
| **Streaming upload/download**   | ✅                                     | ✅                                   | ✅                                  | ✅                       |
| **File attachments**            | ✅ CURLFile, path, resource, string    | ✅                                   | ✅                                  | ✅                       |
| **Response dot-notation**       | ✅ + wildcards                         | ❌                                   | ❌                                  | ❌                       |
| **Built-in test double**        | ✅ FakeHttpClient                      | ✅ MockHandler                       | ✅ MockHttpClient                   | ✅ Http::fake()          |
| **Connection reuse**            | ✅ shared handle, `curl_reset`         | ✅                                   | ✅                                  | ✅ via Guzzle            |
| **Standalone**                  | ✅                                     | ✅                                   | ✅                                  | ❌ requires Laravel      |
| **Installed size (`--no-dev`)** | ~823 KB                               | ~2.3 MB                             | ~1.0 MB                            | ~36 MB (framework)      |

### What the dependency count means

`composer require simsoft/http-client` installs three packages: this library plus
`psr/http-message` and `psr/http-client`. Both contain **interfaces only** — no
implementation code, no transitive dependencies of their own — so nothing is
pulled in that could conflict with your stack or need its own updates. Beyond
`ext-curl` there is no functional dependency, but the `require` block is not
empty and this documentation does not claim otherwise.

`psr/http-factory` is deliberately not required. This library implements PSR-18,
not PSR-17: it consumes PSR-7 requests but builds none, so it references no
factory interface. Code that needs to *build* PSR-7 requests installs a factory
package such as `nyholm/psr7`, which depends on `psr/http-factory` itself — so
PSR-18 users get it either way, and everyone else no longer carries it.

The comparison that matters is the shape of the tree, not the count. Guzzle's
eight packages include a promise engine, a PSR-7 implementation, and two Symfony
polyfills; Symfony's six include the contracts packages, a PSR-11 container
interface, and PSR-3 logging. Each is a real body of code you inherit, version,
and audit.

## Key Differentiators

- **Simpler mental model** — one class, trait composition, no handler stacks or
  DI containers
- **Interface-only dependency tree** — `ext-curl` plus two PSR packages that
  ship no code
- **OAuth2 without a second library** — client credentials, authorization code,
  and PKCE with transparent token caching and refresh, where the alternatives
  need `league/oauth2-client` (ten packages installed, Guzzle 7.x among them —
  which can pin you to an older Guzzle than you would otherwise install) or
  handwritten token handling
- **Dot-notation response access** — `$response->data('data.users.*.name')` with
  wildcards
- **Direct cURL control** — every cURL option accessible without abstraction
  layers
- **Concurrent requests without promises** — `HttpPool` uses `curl_multi_*`
  directly, with HTTP/2 multiplexing enabled
- **Built-in test double** — FakeHttpClient with pattern matching and PHPUnit
  assertions

## Trade-offs

- **No pluggable transports** — locked to cURL (intentional: predictable
  behavior, direct access to every option)
- **No promise-based async** — uses `curl_multi` polling (explicit about what
  actually happens in PHP's request lifecycle)
- **PSR-7 on the response side only** — `Response` and the `Stream` classes
  implement their PSR-7 interfaces in full, and `sendRequest()` accepts any
  PSR-7 `RequestInterface`, but this library ships no request or URI
  implementation of its own. Code that needs to *build* PSR-7 requests should
  pair it with a PSR-7 package such as `nyholm/psr7`. See
  [PSR-18 Interoperability](PSR18).
- **No provider ecosystem** — Guzzle and `league/oauth2-client` have years of
  third-party middleware and provider packages; here you subclass and override

## When to Choose Each

| Choose                 | When                                                                                                                                 |
|------------------------|--------------------------------------------------------------------------------------------------------------------------------------|
| **Simsoft HttpClient** | Standalone services, CLI tools, or libraries needing a minimal dependency tree, full cURL control, built-in OAuth2, and a fluent API |
| **Guzzle**             | You need promise-based async, a full PSR-7 implementation, broad ecosystem support, or are in a Guzzle-dependent stack               |
| **Symfony HttpClient** | You need multiple transport backends (amphp, native streams), or are in a Symfony project                                            |
| **Laravel HTTP**       | You're in Laravel and want the framework's testing fakes and collection integration                                                  |

---

## See Also

- [← Back to README](/)
- [PSR-18 Interoperability](PSR18)
- [OAuth2 Authentication](OAUTH2)
