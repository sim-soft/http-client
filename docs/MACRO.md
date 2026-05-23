# Macro & Mixin

Add additional methods to `Simsoft\HttpClient\HttpClient` at runtime without
subclassing.

## Table of Contents

1. [When to use macros vs subclassing](#when-to-use)
2. [Registering a macro](#registering-a-macro)
3. [Overriding a macro](#overriding-a-macro)
4. [Mixin — register multiple macros from a class](#mixin)

## When to use macros vs subclassing<a id="when-to-use"></a>

| Approach        | Best for                                                                                                                                    |
|-----------------|---------------------------------------------------------------------------------------------------------------------------------------------|
| **Macros**      | Quick helpers shared across a project — shortcuts for headers, timeouts, or common option bundles. No new class file needed.                |
| **Mixins**      | Grouping related macros into a reusable class. Keeps macro definitions organized and testable.                                              |
| **Subclassing** | Full SDK clients with typed return values, constructor-injected credentials, and domain-specific methods (see [Custom SDK](CUSTOM_SDK.md)). |

> **Note:** Macros and mixins are not type-safe — your IDE won't autocomplete
> them and PHPStan won't check their signatures. For anything beyond simple
> helpers, prefer a subclass.

## Registering a macro<a id="registering-a-macro"></a>

```php
<?php
require_once 'vendor/autoload.php';

use Simsoft\HttpClient\HttpClient;

// Add withToken method to HttpClient
HttpClient::macro('withToken', function(string $token) {
    return $this->withHeaders([
        'Authorization' => "Bearer $token",
    ]);
});

// Add the connectionTimeout method to HttpClient.
HttpClient::macro('connectionTimeoutMS', function (int $timeout) {
    return $this->withOptions([CURLOPT_CONNECTTIMEOUT_MS => $timeout]);
});

$response = HttpClient::make()
    ->withToken('SECRET_TOKEN')  // Using macro method withToken()
    ->connectionTimeoutMS(2000) // 2000 milliseconds = 2 seconds connection timeout.
    ->post('https://domain.com/api/endpoint', ['foo' => 'bar']);
```

## Overriding a macro<a id="overriding-a-macro"></a>

Calling `macro()` with the same name replaces the previous implementation:

```php
// Override with a different token format
HttpClient::macro('withToken', function(string $token) {
    return $this->withHeaders([
        'Authorization' => "Token $token",
    ]);
});
```

## Mixin — register multiple macros from a class<a id="mixin"></a>

Use `mixin()` to register all public and protected methods of an object as
macros in a single call. This keeps related macros organized in one class.

```php
use Simsoft\HttpClient\HttpClient;

class ApiHelpers
{
    public function withApiKey(): \Closure
    {
        return function (string $key) {
            return $this->withHeader('X-Api-Key', $key);
        };
    }

    public function withPagination(): \Closure
    {
        return function (int $page, int $perPage = 25) {
            return $this->withQuery(['page' => $page, 'per_page' => $perPage]);
        };
    }

    public function asXml(): \Closure
    {
        return function () {
            return $this->withHeader('Accept', 'application/xml');
        };
    }
}

// Register all methods from ApiHelpers as macros
HttpClient::mixin(new ApiHelpers());

// Now use them on any HttpClient instance
$response = HttpClient::make()
    ->withApiKey('sk_live_abc123')
    ->withPagination(2, 50)
    ->asXml()
    ->get('https://api.example.com/products');
```

### How mixin resolution works

Each method in the mixin class is inspected:

- If the method's return type is `Closure`, the method is **invoked** and its
  returned closure is registered as the macro (factory pattern — shown above).
- If the method returns anything else, the method itself is registered as a
  callable macro.

The factory pattern (returning `Closure`) is recommended because it gives the
macro access to `$this` — the HttpClient instance the macro is called on.

### Additive vs overwrite mode

By default, `mixin()` replaces existing macros with the same name. Pass
`false` as the second argument to skip existing macros:

```php
// First mixin registers 'withApiKey'
HttpClient::mixin(new ApiHelpers());

// Second mixin also defines 'withApiKey' — but won't overwrite
HttpClient::mixin(new AlternativeHelpers(), replace: false);
// The original 'withApiKey' from ApiHelpers is preserved
```

### Practical example — environment-aware configuration

```php
class EnvironmentMixin
{
    public function forProduction(): \Closure
    {
        return function () {
            return $this
                ->timeout(10)
                ->connectionTimeout(3)
                ->withOptions([CURLOPT_SSL_VERIFYPEER => true]);
        };
    }

    public function forDevelopment(): \Closure
    {
        return function () {
            return $this
                ->timeout(30)
                ->connectionTimeout(10)
                ->withoutVerifying()
                ->verbose();
        };
    }
}

HttpClient::mixin(new EnvironmentMixin());

// In production bootstrap
$client = HttpClient::make()
    ->withBaseUrl('https://api.example.com')
    ->forProduction();

// In local development
$client = HttpClient::make()
    ->withBaseUrl('https://localhost:8443')
    ->forDevelopment();
```

---

## See Also

- [Custom SDK](CUSTOM_SDK.md) — full subclass approach for typed APIs
- [Middleware](MIDDLEWARE.md) — intercept requests without macros
- [← Back to README](/)
