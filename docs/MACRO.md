# Macro

Add additional methods to `Simsoft\HttpClient\HttpClient` at runtime without
subclassing.

## When to use macros vs subclassing

| Approach        | Best for                                                                                                                                    |
|-----------------|---------------------------------------------------------------------------------------------------------------------------------------------|
| **Macros**      | Quick helpers shared across a project — shortcuts for headers, timeouts, or common option bundles. No new class file needed.                |
| **Subclassing** | Full SDK clients with typed return values, constructor-injected credentials, and domain-specific methods (see [Custom SDK](CUSTOM_SDK.md)). |

> **Note:** Macros are not type-safe — your IDE won't autocomplete them and
> PHPStan won't check their signatures. For anything beyond simple helpers,
> prefer a subclass.

## Example

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

## Overriding a macro

Calling `macro()` with the same name replaces the previous implementation:

```php
// Override with a different token format
HttpClient::macro('withToken', function(string $token) {
    return $this->withHeaders([
        'Authorization' => "Token $token",
    ]);
});
```
