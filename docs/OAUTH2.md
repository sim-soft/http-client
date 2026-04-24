# OAuth2 Authentication Guide

This guide covers two OAuth2 helper classes included with `simsoft/http-client`:

| Class          | Best For                                                                          |
|----------------|-----------------------------------------------------------------------------------|
| `OAuth2`       | APIs that use `league/oauth2-client` (e.g. Google, GitHub, any standard provider) |
| `SimpleOAuth2` | APIs with a simple token endpoint — no third-party OAuth2 package required        |

Both classes handle token caching, expiry detection, and automatic re-fetch, so
your
application code never needs to manage tokens manually.

---

## Table of Contents

1. [Prerequisites](#prerequisites)
2. [Which Class Should I Use?](#which-class)
3. [OAuth2 — league/oauth2-client based](#oauth2)
    - [Basic Usage](#oauth2-basic)
    - [Sandbox Mode](#oauth2-sandbox)
    - [Custom Scope](#oauth2-scope)
    - [Custom Storage](#oauth2-storage)
    - [PKCE Support](#oauth2-pkce)
    - [Using with HttpClient](#oauth2-httpclient)
4. [SimpleOAuth2 — built-in, no extra dependencies](#simpleoauth2)
    - [Basic Usage](#simpleoauth2-basic)
    - [Common Token Request Patterns](#simpleoauth2-patterns)
    - [Using with HttpClient](#simpleoauth2-httpclient)
    - [Accessing Token Details](#simpleoauth2-response)
5. [Custom Storage](#custom-storage)
6. [Session Storage Limitation](#session-storage)

---

## Prerequisites<a id="prerequisites"></a>

**Always required:**

```shell
composer require simsoft/http-client
```

**Required only for `OAuth2`:**

```shell
composer require league/oauth2-client
```

`SimpleOAuth2` has no additional dependencies beyond `simsoft/http-client`
itself.

---

## Which Class Should I Use?<a id="which-class"></a>

**Use `OAuth2` when:**

- Your project already uses `league/oauth2-client`
- The API has a dedicated `league/oauth2-client` provider package (Google,
  GitHub, Stripe, etc.)
- You need PKCE support
- You need `authorization_code` or `password` grant flows

**Use `SimpleOAuth2` when:**

- You want zero extra dependencies
- The API uses a straightforward `client_credentials` POST with form data or
  Basic Auth
- You want to keep your stack lean for microservices or CLI tools

---

## OAuth2<a id="oauth2"></a>

`OAuth2` wraps `league/oauth2-client`'s `GenericProvider` and adds automatic
token caching, expiry checking, and transparent refresh.

Namespace: `Simsoft\HttpClient\Clients`

### Basic Usage<a id="oauth2-basic"></a>

Subclass `OAuth2` and set the token endpoint:

```php
namespace App\Clients;

use Simsoft\HttpClient\Clients\OAuth2;

class MyApiOAuth2 extends OAuth2
{
    protected string $accessTokenEndpoint = 'https://api.example.com/oauth/token';
}
```

Acquire a token:

```php
use App\Clients\MyApiOAuth2;

$token = MyApiOAuth2::request('your-client-id', 'your-client-secret')
    ->getAccessToken();

if ($token === null) {
    // Token acquisition failed — check error_log() for details
    throw new RuntimeException('Could not obtain access token.');
}

echo $token; // eyJhbGciOiJSUzI1NiJ9...
```

Use the token with `HttpClient`:

```php
use App\Clients\MyApiOAuth2;
use Simsoft\HttpClient\HttpClient;

$token = MyApiOAuth2::request('your-client-id', 'your-client-secret')
    ->getAccessToken();

$response = HttpClient::make()
    ->withBaseUrl('https://api.example.com')
    ->withBearerToken($token)
    ->get('/users');
```

---

### Sandbox Mode<a id="oauth2-sandbox"></a>

Set both endpoints in your subclass and call `->sandbox()` at runtime:

```php
namespace App\Clients;

use Simsoft\HttpClient\Clients\OAuth2;

class MyApiOAuth2 extends OAuth2
{
    protected string $accessTokenEndpoint        = 'https://api.example.com/oauth/token';
    protected string $sandboxAccessTokenEndpoint = 'https://sandbox.api.example.com/oauth/token';
}
```

```php
use App\Clients\MyApiOAuth2;

// Production
$token = MyApiOAuth2::request('client-id', 'client-secret')
    ->getAccessToken();

// Sandbox
$token = MyApiOAuth2::request('sandbox-client-id', 'sandbox-client-secret')
    ->sandbox()
    ->getAccessToken();
```

---

### Custom Scope<a id="oauth2-scope"></a>

Set `$scope` in the subclass or override per-request:

```php
namespace App\Clients;

use Simsoft\HttpClient\Clients\OAuth2;

class MyApiOAuth2 extends OAuth2
{
    protected string $accessTokenEndpoint = 'https://api.example.com/oauth/token';
    protected ?string $scope = 'read:users write:orders';
}
```

---

### Custom Storage<a id="oauth2-storage"></a>

By default, tokens are stored in the PHP session via `SessionStorage`. Pass any
`StorageInterface` implementation as the third argument to use a different
backend:

```php
use App\Clients\MyApiOAuth2;
use App\Storage\RedisStorage;

$storage = new RedisStorage($redisClient, ttl: 3600);

$token = MyApiOAuth2::request('client-id', 'client-secret', $storage)
    ->getAccessToken();
```

---

### PKCE Support<a id="oauth2-pkce"></a>

Enable PKCE (Proof Key for Code Exchange) by setting `$enablePKCE = true` in
your subclass:

```php
namespace App\Clients;

use Simsoft\HttpClient\Clients\OAuth2;

class MyApiOAuth2 extends OAuth2
{
    protected string $accessTokenEndpoint = 'https://api.example.com/oauth/token';
    protected bool $enablePKCE = true;
}
```

PKCE uses the `S256` method (`code_challenge_method=S256`) as required by RFC
7636.

---

### Using with HttpClient via Middleware <a id="oauth2-httpclient"></a>

The cleanest pattern is to inject token acquisition into middleware so all
requests
are automatically authenticated without the caller managing tokens:

```php
use Closure;
use App\Clients\MyApiOAuth2;
use Simsoft\HttpClient\HttpClient;
use Simsoft\HttpClient\Response;

$oauth = MyApiOAuth2::request('your-client-id', 'your-client-secret');

$client = HttpClient::make()
    ->withBaseUrl('https://api.example.com')
    ->withMiddleware(function (HttpClient $request, Closure $next) use ($oauth): Response {
        $token = $oauth->getAccessToken();
        if ($token === null) {
            throw new RuntimeException('Unable to obtain OAuth2 access token.');
        }
        $request->withBearerToken($token);
        return $next();
    }, 'oauth2');

// All requests through this client are automatically authenticated
$response = $client->get('/orders');
$response = $client->post('/orders', ['item_id' => 42, 'qty' => 1]);
```

---

## SimpleOAuth2<a id="simpleoauth2"></a>

`SimpleOAuth2` is an abstract base class that extends `HttpClient` directly. It
handles token caching and expiry, but leaves the actual token HTTP request up to
you
via the `postRequest()` abstract method. No `league/oauth2-client` is required.

Namespace: `Simsoft\HttpClient\Clients`

### Basic Usage<a id="simpleoauth2-basic"></a>

Subclass `SimpleOAuth2` and implement `postRequest()`:

```php
namespace App\Clients;

use Simsoft\HttpClient\Clients\SimpleOAuth2;
use Simsoft\HttpClient\Responses\SimpleOAuth2Response;

class MyApiClient extends SimpleOAuth2
{
    /**
     * Perform the token request.
     * This method is called automatically when a token is needed.
     * Full request setup is required each time since the state is flushed after each request.
     */
    protected function postRequest(): SimpleOAuth2Response
    {
        /** @var SimpleOAuth2Response */
        return $this
            ->withBaseUrl('https://api.example.com')
            ->withForm([
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
            ])
            ->post('/oauth/token');
    }
}
```

Acquire a token:

```php
use App\Clients\MyApiClient;

$token = MyApiClient::makeWith('your-client-id', 'your-client-secret')
    ->getAccessToken();

if ($token === null) {
    throw new RuntimeException('Could not obtain access token.');
}
```

---

### Common Token Request Patterns <a id="simpleoauth2-patterns"></a>

**Form POST with client credentials in the body** (most common):

```php
protected function postRequest(): SimpleOAuth2Response
{
    /** @var SimpleOAuth2Response */
    return $this
        ->withBaseUrl('https://api.example.com')
        ->withForm([
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
        ])
        ->post('/oauth/token');
}
```

**HTTP Basic Auth with form body** (RFC 6749 recommended):

```php
protected function postRequest(): SimpleOAuth2Response
{
    /** @var SimpleOAuth2Response */
    return $this
        ->withBaseUrl('https://api.example.com')
        ->withHeader('Authorization', 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret))
        ->withForm(['grant_type' => 'client_credentials'])
        ->post('/oauth/token');
}
```

**JSON body** (some non-standard APIs):

```php
protected function postRequest(): SimpleOAuth2Response
{
    /** @var SimpleOAuth2Response */
    return $this
        ->withBaseUrl('https://api.example.com')
        ->withJson([
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
        ])
        ->post('/oauth/token');
}
```

**With scope:**

```php
protected function postRequest(): SimpleOAuth2Response
{
    /** @var SimpleOAuth2Response */
    return $this
        ->withBaseUrl('https://api.example.com')
        ->withForm([
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope'         => 'read:users write:orders',
        ])
        ->post('/oauth/token');
}
```

**Sandbox support:**

```php
namespace App\Clients;

use Simsoft\HttpClient\Clients\SimpleOAuth2;
use Simsoft\HttpClient\Responses\SimpleOAuth2Response;

class MyApiClient extends SimpleOAuth2
{
    protected string $tokenEndpoint        = 'https://api.example.com/oauth/token';
    protected string $sandboxTokenEndpoint = 'https://sandbox.api.example.com/oauth/token';
    protected bool $sandboxMode = false;

    public function sandbox(): static
    {
        $this->sandboxMode = true;
        return $this;
    }

    protected function postRequest(): SimpleOAuth2Response
    {
        $endpoint = $this->sandboxMode
            ? $this->sandboxTokenEndpoint
            : $this->tokenEndpoint;

        /** @var SimpleOAuth2Response */
        return $this
            ->withBaseUrl($endpoint)
            ->withForm([
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
            ])
            ->post('/token');
    }
}
```

```php
// Production
$token = MyApiClient::makeWith('client-id', 'secret')->getAccessToken();

// Sandbox
$token = MyApiClient::makeWith('sandbox-id', 'sandbox-secret')
    ->sandbox()
    ->getAccessToken();
```

---

### Using with HttpClient via Middleware<a id="simpleoauth2-httpclient"></a>

Since `SimpleOAuth2` extends `HttpClient`, the subclass is also a full HTTP
client
and can make API requests directly. The cleanest pattern is to separate token
management from API requests using middleware:

```php
namespace App\Clients;

use Closure;
use Simsoft\HttpClient\Clients\SimpleOAuth2;
use Simsoft\HttpClient\HttpClient;
use Simsoft\HttpClient\Response;
use Simsoft\HttpClient\Responses\SimpleOAuth2Response;

class MyApiClient extends SimpleOAuth2
{
    protected string $apiBaseUrl = 'https://api.example.com';

    protected function postRequest(): SimpleOAuth2Response
    {
        /** @var SimpleOAuth2Response */
        return $this
            ->withBaseUrl($this->apiBaseUrl)
            ->withForm([
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
            ])
            ->post('/oauth/token');
    }

    /**
     * Get an authenticated HttpClient instance ready to make API requests.
     */
    public function getAuthenticatedClient(): HttpClient
    {
        return HttpClient::make()
            ->withBaseUrl($this->apiBaseUrl)
            ->withMiddleware(function (HttpClient $request, Closure $next): Response {
                $token = $this->getAccessToken();
                if ($token === null) {
                    throw new \RuntimeException('Unable to obtain access token.');
                }
                $request->withBearerToken($token);
                return $next();
            }, 'oauth2');
    }
}
```

```php
use App\Clients\MyApiClient;

$client = MyApiClient::makeWith('your-client-id', 'your-client-secret')
    ->getAuthenticatedClient();

// All requests are automatically authenticated
$users  = $client->get('/users');
$orders = $client->get('/orders', ['status' => 'pending']);
$result = $client->post('/orders', ['item_id' => 42]);
```

---

### Accessing Token Details<a id="simpleoauth2-response"></a>

When you need to inspect the token response directly, call `postRequest()`
manually
or work with the `SimpleOAuth2Response` from the response:

```php
use Simsoft\HttpClient\Responses\SimpleOAuth2Response;

/** @var SimpleOAuth2Response $response */
$response = MyApiClient::makeWith('client-id', 'secret')->postRequest();

if ($response->ok()) {
    echo $response->getToken();        // "eyJhbGci..."
    echo $response->getTokenType();    // "Bearer"
    echo $response->getExpiresIn();    // 3600 (seconds, relative)
    echo $response->getExpiresAt();    // 1714000770 (Unix timestamp, absolute)
    echo $response->getRefreshToken(); // "def502..." or null
    echo $response->getScope();        // "read:users write:orders" or null
    echo $response->hasExpired()
        ? 'Token has expired'
        : 'Token is still valid';
}
```

---

## Custom Storage<a id="custom-storage"></a>

Both `OAuth2` and `SimpleOAuth2` accept any `StorageInterface` implementation.
The interface requires four methods:

```php
namespace Simsoft\HttpClient\Interfaces;

interface StorageInterface
{
    public function has(string $key): bool;
    public function set(string $key, mixed $value): void;
    public function get(string $key): mixed;
    public function remove(string $key): void;
}
```

Example — Redis-backed storage:

```php
namespace App\Storage;

use Simsoft\HttpClient\Interfaces\StorageInterface;

class RedisStorage implements StorageInterface
{
    public function __construct(
        private \Redis $redis,
        private int $ttl = 3600,
    ) {}

    public function has(string $key): bool
    {
        return (bool) $this->redis->exists($key);
    }

    public function set(string $key, mixed $value): void
    {
        $this->redis->setex($key, $this->ttl, serialize($value));
    }

    public function get(string $key): mixed
    {
        $data = $this->redis->get($key);
        return $data !== false ? unserialize($data) : null;
    }

    public function remove(string $key): void
    {
        $this->redis->del($key);
    }
}
```

```php
use App\Clients\MyApiOAuth2;
use App\Storage\RedisStorage;

$redis   = new Redis();
$redis->connect('127.0.0.1', 6379);
$storage = new RedisStorage($redis, ttl: 3600);

$token = MyApiOAuth2::request('client-id', 'client-secret', $storage)
    ->getAccessToken();
```

---

## Session Storage Limitation<a id="session-storage"></a>

The default `SessionStorage` stores tokens in `$_SESSION`. This has two
important
limitations:

**1. Not suitable for CLI, queues, or workers.**
`$_SESSION` is only available in web request contexts. Use `RedisStorage`,
a database-backed storage, or a PSR-6/PSR-16 cache adapter for non-web
environments.

**2. `SimpleOAuth2Response` is not directly serializable.**
`SimpleOAuth2Response` extends `Response`, which contains a `StreamInterface`
property that PHP cannot serialize. If you use `SessionStorage` with
`SimpleOAuth2`,
you must store only the token string and expiry, not the response object.

The recommended pattern is to wrap the token data in a plain serializable
object:

```php
// Plain serializable token holder
class TokenData
{
    public function __construct(
        public readonly string $accessToken,
        public readonly int $expiresAt,
        public readonly ?string $refreshToken = null,
        public readonly ?string $tokenType = null,
        public readonly ?string $scope = null,
    ) {}
}
```

Then in `getAccessToken()` (override in your subclass), store `TokenData`
instead
of the raw `Response`:

```php
protected function fetchAndStoreToken(): ?string
{
    $response = $this->postRequest();
    if (!$response->ok()) {
        return null;
    }

    $tokenData = new TokenData(
        accessToken:  $response->getToken() ?? '',
        expiresAt:    $response->getExpiresAt() ?? (time() + 3600),
        refreshToken: $response->getRefreshToken(),
        tokenType:    $response->getTokenType(),
        scope:        $response->getScope(),
    );

    $this->storage->set($this->clientId, $tokenData);
    return $tokenData->accessToken;
}
```

This makes session storage safe since `TokenData` contains only scalar values.
