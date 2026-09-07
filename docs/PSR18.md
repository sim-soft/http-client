# PSR-18 Usage<a id="psr18"></a>

`HttpClient` implements `Psr\Http\Client\ClientInterface`, making it a drop-in
HTTP client for any library or framework that depends on PSR-18 — including
API SDK packages, test doubles, and service containers.

### Install a PSR-17 factory

PSR-18 sends PSR-7 `RequestInterface` objects. You need a PSR-17 factory to
build them. Any compliant package works; `nyholm/psr7` is the most lightweight:

```shell
composer require nyholm/psr7
```

### Basic PSR-18 usage

```php
use Nyholm\Psr7\Factory\Psr17Factory;
use Simsoft\HttpClient\HttpClient;

$factory = new Psr17Factory();

// Build a PSR-7 request using the factory
$psrRequest = $factory->createRequest('GET', 'https://api.example.com/users')
    ->withHeader('Accept', 'application/json')
    ->withHeader('Authorization', 'Bearer YOUR_TOKEN');

// Send via PSR-18
$response = HttpClient::make()->sendRequest($psrRequest);

echo $response->getStatusCode();           // 200
echo $response->getBody()->getContents();  // raw JSON body
```

### POST with a body

```php
use Nyholm\Psr7\Factory\Psr17Factory;
use Simsoft\HttpClient\HttpClient;

$factory = new Psr17Factory();
$body    = $factory->createStream(json_encode(['name' => 'John']));

$psrRequest = $factory->createRequest('POST', 'https://api.example.com/users')
    ->withHeader('Content-Type', 'application/json')
    ->withHeader('Accept', 'application/json')
    ->withBody($body);

$response = HttpClient::make()->sendRequest($psrRequest);
```

### Injecting into a PSR-18-dependent SDK

Many third-party SDKs accept any `ClientInterface` implementation:

```php
use Simsoft\HttpClient\HttpClient;

// e.g., an OpenAI, Stripe, or any other PSR-18-compatible SDK client
$sdk = new SomeApiSdk(
    httpClient: HttpClient::make(),
    // ...
);
```

### PSR-18 exception handling

PSR-18 it does **not** throw exceptions for HTTP-level errors (4xx, 5xx).
Those are returned as normal `ResponseInterface` objects.
Only network-level failures throw:

```php
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;

try {
    $response = $client->sendRequest($psrRequest);

    // HTTP errors are returned, not thrown
    if ($response->getStatusCode() >= 400) {
        echo 'HTTP error: ' . $response->getStatusCode();
    }

} catch (NetworkExceptionInterface $e) {
    // Connection refused, timeout, DNS failure, SSL error, etc.
    echo 'Network failure: ' . $e->getMessage();

} catch (RequestExceptionInterface $e) {
    // Malformed request — bad method, invalid URI, etc.
    echo 'Bad request: ' . $e->getMessage();
}
```

### Mixing PSR-18 and the fluent API

Both APIs share the same underlying client instance. You can use them together
in either order:

```php
$client = HttpClient::make();

// Fluent API — for your own code
$response = $client
    ->withBaseUrl('https://api.example.com')
    ->withBearerToken('YOUR_TOKEN')
    ->get('/profile');

// PSR-18 — when passing to a third-party SDK
$sdk = new SomeApiSdk(httpClient: $client);

// The base URL and token are untouched by the SDK's PSR-18 sends
$response = $client->get('/settings');  // still api.example.com, still authenticated
```

A `sendRequest()` call takes its target, method, headers and body from the
PSR-7 request, and leaves the client's connection-scoped configuration as it
found it.

### Credentials and PSR-18

A token set with `withBearerToken()` is **withheld when a PSR-7 request targets
an origin other than the client's base URL**, matching how cURL drops
`Authorization` across a cross-host redirect. This matters when handing a
configured client to an SDK that talks to a different host:

```php
$client = HttpClient::make()
    ->withBaseUrl('https://api.example.com')
    ->withBearerToken('YOUR_TOKEN');

// Same origin as the base URL — the token is sent
$client->sendRequest($factory->createRequest('GET', 'https://api.example.com/users'));

// Different origin — the token is NOT sent
$client->sendRequest($factory->createRequest('GET', 'https://other-host.example/data'));
```

Scheme and authority must both match; a port difference counts as a different
origin. A client with no base URL has no origin to conflict with, so its token
is applied to every PSR-18 send.

To authenticate a cross-origin request, set the header on the PSR-7 request
itself — an explicit per-request `Authorization` always wins:

```php
$psrRequest = $factory->createRequest('GET', 'https://other-host.example/data')
    ->withHeader('Authorization', 'Bearer OTHER_TOKEN');

$client->sendRequest($psrRequest);  // Authorization: Bearer OTHER_TOKEN
```

---

## See Also

- [Custom SDK](CUSTOM_SDK.md) — build typed SDK clients
- [Testing](TESTING.md) — mock PSR-18 requests
- [← Back to README](/)
