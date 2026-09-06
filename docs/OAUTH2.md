# OAuth2 Authentication Guide

The `OAuth2` class handles the full OAuth2 token lifecycle — acquisition,
caching, expiry detection, and automatic refresh — using only the library's own
`HttpClient`. Zero external dependencies required.

---

## Table of Contents

### Getting Started

1. [Prerequisites](#prerequisites)
2. [Basic Usage](#oauth2-basic)
3. [Sandbox Mode](#oauth2-sandbox)
4. [Custom Scope](#oauth2-scope)
5. [Custom Grant Type](#oauth2-grant-type)

### Token Management

6. [Getting TokenData](#oauth2-token-data-access)
7. [Disabling Token Cache](#oauth2-no-cache)
8. [Cache Invalidation](#oauth2-invalidation)
9. [Configurable Expiry Buffer](#oauth2-expiry-buffer)

### HTTP Configuration

10. [Configuring the HttpClient](#oauth2-http-config)

### Authorization Code Flow

11. [Authorization Code with PKCE](#oauth2-auth-code)

### Customization

12. [Customizing Token Parameters](#oauth2-custom-params)
13. [Custom Metadata](#oauth2-metadata)
14. [Custom Storage](#oauth2-storage)

### Integration

15. [Middleware Integration](#oauth2-httpclient)
16. [Event Callbacks](#oauth2-events)

### Token Operations

17. [Token Revocation](#oauth2-revocation)
18. [Token Introspection](#oauth2-introspection)

### Reference

19. [OAuth2TokenResponse](#oauth2-token-response)
20. [TokenData Value Object](#oauth2-tokendata)
21. [StorageInterface](#storage-interface)
22. [Storage Notes](#session-storage)
23. [Comparison with Other Libraries](#comparison)

---

## Prerequisites<a id="prerequisites"></a>

```shell
composer require simsoft/http-client
```

Only `ext-curl` is required at runtime.

---

## Basic Usage<a id="oauth2-basic"></a>

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

**How it works internally:**

1. Checks storage for a cached token keyed by client ID
2. If cached and not expired → returns immediately (no network call)
3. If expired and refresh token exists → attempts refresh
4. If refresh fails or no refresh token → acquires a new token
5. Stores the result as a serializable `TokenData` object

---

## Sandbox Mode<a id="oauth2-sandbox"></a>

Set both endpoints in your subclass and call `->sandbox()` at runtime:

```php
namespace App\Clients;

use Simsoft\HttpClient\Clients\OAuth2;

class MyApiOAuth2 extends OAuth2
{
    protected string $accessTokenEndpoint = 'https://api.example.com/oauth/token';
    protected string $sandboxEndpoint     = 'https://sandbox.api.example.com/oauth/token';
}
```

```php
// Production
$token = MyApiOAuth2::request('client-id', 'client-secret')->getAccessToken();

// Sandbox
$token = MyApiOAuth2::request('sandbox-id', 'sandbox-secret')
    ->sandbox()
    ->getAccessToken();
```

---

## Custom Scope<a id="oauth2-scope"></a>

If your application always asks for the same scope, declare it on the subclass:

```php
class MyApiOAuth2 extends OAuth2
{
    protected string $accessTokenEndpoint = 'https://api.example.com/oauth/token';
    protected ?string $scope = 'read:users write:orders';
}
```

When `$scope` is `null` (default), the `scope` parameter is omitted entirely.

### Setting the scope per call

Use `withScope()` when the scope depends on what the code is about to do, rather
than on the class:

```php
$reader = MyApiOAuth2::request('client-id', 'client-secret')
    ->withScope('read:users');

$writer = MyApiOAuth2::request('client-id', 'client-secret')
    ->withScope('read:users write:orders');
```

Pass `null` to drop the scope for one call, overriding whatever the subclass
declares.

**Each scope gets its own cached token.** The scope is part of the storage key
(see [How the storage key is composed](#how-the-storage-key-is-composed)), so `$reader` above
never receives `$writer`'s token — it requests one of its own on first use, and
reuses that one afterwards. This is what you want: a token granted `write:orders`
must not leak into code that only asked to read.

The practical consequence is that changing the scope means an extra round trip to
the token endpoint, not a cache hit. Ask for a narrow scope in the code paths
that need only that, and accept the one extra request — do not widen the scope
just to share a cache entry.

---

## Custom Grant Type<a id="oauth2-grant-type"></a>

The default is `client_credentials`. Override `$grantType` for different flows:

```php
class MyApiOAuth2 extends OAuth2
{
    protected string $accessTokenEndpoint = 'https://api.example.com/oauth/token';
    protected string $grantType = 'password';
}
```

---

## Getting TokenData<a id="oauth2-token-data-access"></a>

Use `getTokenData()` to get the full token object instead of just the string:

```php
$tokenData = MyApiOAuth2::request('client-id', 'client-secret')->getTokenData();

if ($tokenData === null) {
    throw new RuntimeException('Token acquisition failed.');
}

echo $tokenData->accessToken;   // "eyJhbGciOi..."
echo $tokenData->expiresAt;     // 1714000770
echo $tokenData->tokenType;     // "Bearer"
echo $tokenData->hasExpired();  // false
```

---

## Disabling Token Cache<a id="oauth2-no-cache"></a>

Call `withoutCache()` to always fetch a fresh token:

```php
$token = MyApiOAuth2::request('client-id', 'client-secret')
    ->withoutCache()
    ->getAccessToken();
```

When cache is disabled:

- Every call hits the token endpoint
- `TokenData::expiresAt` is `0` (irrelevant)
- Storage is not read or written

Set as default in a subclass:

```php
class DevApiOAuth2 extends OAuth2
{
    protected string $accessTokenEndpoint = 'https://dev.api.example.com/oauth/token';
    protected bool $cacheEnabled = false;
}
```

---

## Cache Invalidation<a id="oauth2-invalidation"></a>

Force-clear a cached token without disabling caching. Useful after receiving a
401 indicating the token was revoked externally:

```php
$oauth = MyApiOAuth2::request('client-id', 'client-secret');

$token = $oauth->getAccessToken();

// API returns 401 — invalidate and get a fresh token
$freshToken = $oauth->invalidate()->getAccessToken();
```

---

## Configurable Expiry Buffer<a id="oauth2-expiry-buffer"></a>

By default, 30 seconds are subtracted from token expiry to account for clock
skew. Customize for short-lived tokens or high-latency environments:

```php
// 5-second buffer for short-lived tokens
$token = MyApiOAuth2::request('client-id', 'client-secret')
    ->expiryBuffer(5)
    ->getAccessToken();

// No buffer
$token = MyApiOAuth2::request('client-id', 'client-secret')
    ->expiryBuffer(0)
    ->getAccessToken();
```

Set as default in a subclass:

```php
class ShortLivedTokenOAuth2 extends OAuth2
{
    protected string $accessTokenEndpoint = 'https://api.example.com/oauth/token';
    protected int $expiryBuffer = 5;
}
```

---

## Configuring the HttpClient<a id="oauth2-http-config"></a>

Use `getHttpClient()` to configure timeouts, headers, retry, and any other
HttpClient options. The same instance is used for all token endpoint requests.

### Timeouts

```php
$oauth = MyApiOAuth2::request('client-id', 'client-secret');
$oauth->getHttpClient()
    ->timeout(60)
    ->connectionTimeout(10)
    ->withDNSTimeout(120);

$token = $oauth->getAccessToken();
```

### Headers

```php
$oauth = MyApiOAuth2::request('client-id', 'client-secret');
$oauth->getHttpClient()->withHeaders([
    'Accept' => 'application/json',
    'X-Api-Version' => '2024-01-01',
]);

$token = $oauth->getAccessToken();
```

### Retry

```php
$oauth = MyApiOAuth2::request('client-id', 'client-secret');
$oauth->getHttpClient()->retry(3, after: 500);

$token = $oauth->getAccessToken();
```

### Full configuration example

```php
$oauth = MyApiOAuth2::request('client-id', 'client-secret');
$oauth->getHttpClient()
    ->timeout(30)
    ->connectionTimeout(5)
    ->retry(3, after: 200)
    ->withHeaders(['Accept' => 'application/json'])
    ->withoutVerifying(); // dev only

$token = $oauth->getAccessToken();
```

---

## Authorization Code with PKCE<a id="oauth2-auth-code"></a>

For applications where a user authenticates via browser redirect. PKCE
(RFC 7636) is applied automatically.

### 1. Define your subclass

```php
namespace App\Clients;

use Simsoft\HttpClient\Clients\OAuth2;

class GoogleOAuth2 extends OAuth2
{
    protected string $accessTokenEndpoint = 'https://oauth2.googleapis.com/token';
    protected string $authorizeEndpoint   = 'https://accounts.google.com/o/oauth2/v2/auth';
    protected string $redirectUri         = 'https://myapp.example.com/oauth/callback';
    protected ?string $scope              = 'openid email profile';
}
```

### 2. Redirect the user

```php
$oauth   = GoogleOAuth2::request('your-client-id', 'your-client-secret');
$authUrl = $oauth->getAuthorizationUrl();

header('Location: ' . $authUrl);
exit;
```

### 3. Handle the callback

```php
$oauth     = GoogleOAuth2::request('your-client-id', 'your-client-secret');
$tokenData = $oauth->exchangeCode($_GET['code'], $_GET['state']);

$_SESSION['user_token'] = $tokenData->accessToken;
```

### 4. Use the token

```php
$token = GoogleOAuth2::request('your-client-id', 'your-client-secret')
    ->getAccessToken();

$response = HttpClient::make()
    ->withBaseUrl('https://www.googleapis.com')
    ->withBearerToken($token)
    ->get('/oauth2/v2/userinfo');
```

### Provider-specific overrides

| Method                       | Purpose                                          |
|------------------------------|--------------------------------------------------|
| `buildAuthorizationParams()` | Add custom query params to the authorization URL |
| `buildCodeExchangeParams()`  | Modify POST params for the token exchange        |
| `parseTokenResponse()`       | Handle non-standard token response fields        |

#### Google — offline access

```php
protected function buildAuthorizationParams(string $state, string $codeChallenge): array
{
    $params = parent::buildAuthorizationParams($state, $codeChallenge);
    $params['access_type'] = 'offline';
    $params['prompt'] = 'consent';
    return $params;
}
```

#### Microsoft — tenant-specific

```php
protected function buildCodeExchangeParams(string $code, string $verifier): array
{
    $params = parent::buildCodeExchangeParams($code, $verifier);
    $params['tenant'] = 'common';
    return $params;
}
```

---

## Customizing Token Parameters<a id="oauth2-custom-params"></a>

Override protected methods to add provider-specific fields:

| Method                       | Called During                | Use Case                                  |
|------------------------------|------------------------------|-------------------------------------------|
| `buildTokenParams()`         | Fresh token acquisition      | Add `audience`, `resource`, custom fields |
| `buildRefreshParams()`       | Token refresh                | Add `scope`, `resource` on refresh        |
| `buildAuthorizationParams()` | Authorization URL generation | Add `access_type`, `prompt`               |
| `buildCodeExchangeParams()`  | Authorization code exchange  | Add `tenant`, provider-specific fields    |

### Auth0 — adding `audience`

```php
class Auth0OAuth2 extends OAuth2
{
    protected string $accessTokenEndpoint = 'https://your-tenant.auth0.com/oauth/token';

    protected function buildTokenParams(): array
    {
        $params = parent::buildTokenParams();
        $params['audience'] = 'https://api.your-app.com';
        return $params;
    }
}
```

### Azure AD — adding `resource`

```php
class AzureOAuth2 extends OAuth2
{
    protected string $accessTokenEndpoint = 'https://login.microsoftonline.com/tenant-id/oauth2/v2.0/token';

    protected function buildTokenParams(): array
    {
        $params = parent::buildTokenParams();
        $params['resource'] = 'https://graph.microsoft.com';
        return $params;
    }

    protected function buildRefreshParams(TokenData $token): array
    {
        $params = parent::buildRefreshParams($token);
        $params['resource'] = 'https://graph.microsoft.com';
        return $params;
    }
}
```

### Spotify — Basic Auth header

```php
class SpotifyOAuth2 extends OAuth2
{
    protected string $accessTokenEndpoint = 'https://accounts.spotify.com/api/token';

    protected function buildTokenParams(): array
    {
        $params = ['grant_type' => $this->grantType];
        if ($this->scope !== null) {
            $params['scope'] = $this->scope;
        }
        return $params;
    }

    protected function buildTokenRequest(array $params): OAuth2TokenResponse
    {
        $credentials = base64_encode($this->clientId . ':' . $this->clientSecret);

        /** @var OAuth2TokenResponse $response */
        $response = HttpClient::make()
            ->withResponseClass(OAuth2TokenResponse::class)
            ->withHeader('Authorization', 'Basic ' . $credentials)
            ->withForm($params)
            ->post($this->getEndpoint());

        return $response;
    }
}
```

---

## Custom Metadata<a id="oauth2-metadata"></a>

Override `toTokenData()` to extract provider-specific fields into the metadata
array:

```php
use Simsoft\HttpClient\Clients\OAuth2;
use Simsoft\HttpClient\Clients\Responses\OAuth2TokenResponse;
use Simsoft\HttpClient\Clients\TokenData;

class GoogleOAuth2 extends OAuth2
{
    protected string $accessTokenEndpoint = 'https://oauth2.googleapis.com/token';

    protected function toTokenData(OAuth2TokenResponse $response): TokenData
    {
        $token = parent::toTokenData($response);

        return new TokenData(
            accessToken: $token->accessToken,
            expiresAt: $token->expiresAt,
            refreshToken: $token->refreshToken,
            tokenType: $token->tokenType,
            scope: $token->scope,
            metadata: [
                'id_token' => $response->data('id_token'),
                'issued_at' => time(),
            ],
        );
    }
}
```

Access metadata:

```php
$tokenData = GoogleOAuth2::request('id', 'secret')->getTokenData();
$idToken = $tokenData->metadata['id_token'] ?? null;
```

---

## Custom Storage<a id="oauth2-storage"></a>

By default, tokens are stored via `FileStorage` in
`sys_get_temp_dir()/oauth_tokens/`.

Token files hold live access tokens, so they are created `0600` inside a `0700`
directory. On a shared host the system temp directory is world-writable, so
prefer an explicit path owned by the application user:

```php
$storage = new FileStorage('/var/lib/myapp/oauth_tokens');
```

Pass any `StorageInterface` implementation as the third argument:

```php
use App\Storage\RedisStorage;

$storage = new RedisStorage($redisClient, ttl: 3600);

$token = MyApiOAuth2::request('client-id', 'client-secret', $storage)
    ->getAccessToken();
```

See [StorageInterface](#storage-interface) for the full interface.

### How the storage key is composed

A cached token is only reusable by a caller that would have received an
interchangeable token. The key therefore covers the client ID, the subject (see
below), the active token endpoint, and the requested scope — so a sandbox client
never reads a production token, and a `read` client never reads a `write` one.

### Binding a token to an end user

In the authorization code flow the token belongs to a person, not to your
application. Call `forSubject()` with your own stable identifier for that person
so their token is stored under its own key:

```php
$client = GoogleOAuth2::request('client-id', 'client-secret', $storage)
    ->forSubject($currentUser->id);
```

Without it, every user of a shared storage backend reads and overwrites the same
entry, and one user is served another's token.

A subject-bound client will not fetch a token on its own: there is no way to
obtain a user's credential without the user. When there is no usable token —
never authorised, or expired with no working refresh token — `getTokenData()`
reports the failure and returns `null`, and you restart the flow with
`getAuthorizationUrl()`. The same applies to any token obtained through
`exchangeCode()`, which is recorded as user-delegated in its own metadata so a
later background process cannot silently replace it with your application's
`client_credentials` token.

---

## Middleware Integration<a id="oauth2-httpclient"></a>

Inject token acquisition into middleware so all requests are automatically
authenticated:

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

$response = $client->get('/orders');
```

### Auto-retry on 401

```php
$client = HttpClient::make()
    ->withBaseUrl('https://api.example.com')
    ->withMiddleware(function (HttpClient $request, Closure $next) use ($oauth): Response {
        $request->withBearerToken($oauth->getAccessToken());
        $response = $next();

        if ($response->unauthorized()) {
            $request->withBearerToken($oauth->invalidate()->getAccessToken());
            return $next();
        }

        return $response;
    }, 'oauth2-retry');
```

---

## Event Callbacks<a id="oauth2-events"></a>

Register callbacks to react to token lifecycle events:

| Method               | Triggered When               | Callback Receives |
|----------------------|------------------------------|-------------------|
| `onTokenAcquired()`  | Fresh token acquired         | `TokenData`       |
| `onTokenRefreshed()` | Token refreshed              | `TokenData`       |
| `onTokenFailed()`    | Acquisition or refresh fails | `Throwable`       |

```php
use Simsoft\HttpClient\Clients\TokenData;
use Throwable;

$oauth = MyApiOAuth2::request('client-id', 'client-secret')
    ->onTokenAcquired(function (TokenData $token): void {
        error_log('[OAuth2] Token acquired, expires: ' . $token->expiresAt);
    })
    ->onTokenRefreshed(function (TokenData $token): void {
        error_log('[OAuth2] Token refreshed, expires: ' . $token->expiresAt);
    })
    ->onTokenFailed(function (Throwable $error): void {
        error_log('[OAuth2] Failed: ' . $error->getMessage());
    });

$token = $oauth->getAccessToken();
```

---

## Token Revocation<a id="oauth2-revocation"></a>

Revoke tokens at the provider's endpoint (RFC 7009):

```php
class MyApiOAuth2 extends OAuth2
{
    protected string $accessTokenEndpoint = 'https://api.example.com/oauth/token';
    protected string $revocationEndpoint  = 'https://api.example.com/oauth/revoke';
}
```

```php
$oauth = MyApiOAuth2::request('client-id', 'client-secret');
$tokenData = $oauth->getTokenData();

// Revoke access token
$revoked = $oauth->revokeToken($tokenData->accessToken);

// Revoke refresh token
$oauth->revokeToken($tokenData->refreshToken, 'refresh_token');
```

On success, the local cached token is also removed.

Override `buildRevocationParams()` for provider-specific needs.

---

## Token Introspection<a id="oauth2-introspection"></a>

Check token validity at the provider's endpoint (RFC 7662):

```php
class MyApiOAuth2 extends OAuth2
{
    protected string $accessTokenEndpoint = 'https://api.example.com/oauth/token';
    protected string $introspectEndpoint  = 'https://api.example.com/oauth/introspect';
}
```

```php
$oauth = MyApiOAuth2::request('client-id', 'client-secret');
$token = $oauth->getAccessToken();

$info = $oauth->introspectToken($token);

if ($info['active'] ?? false) {
    echo "Valid, scope: " . ($info['scope'] ?? 'none');
}
```

Override `buildIntrospectionParams()` for provider-specific needs.

---

## OAuth2TokenResponse<a id="oauth2-token-response"></a>

The raw response from the token endpoint, before it becomes a
[TokenData](#oauth2-tokendata). Most applications never touch it — `OAuth2`
parses it for you. You meet it in one place: the `$response` argument when you
override `toTokenData()`, as in [Custom Metadata](#oauth2-metadata).

It extends `Response`, so everything there is available too —
[`successful()` and the other status checks](README.md#status-checks), and
[`data()`, `json()` and friends](README.md#reading-data).

**Methods:**

| Method              | Returns   | Description                                        |
|---------------------|-----------|----------------------------------------------------|
| `getToken()`        | `?string` | The `access_token`                                 |
| `getTokenType()`    | `?string` | The `token_type`, typically `"Bearer"`             |
| `getExpiresIn()`    | `?int`    | Lifetime **in seconds**, as the server sent it     |
| `getExpiresAt()`    | `?int`    | Absolute Unix timestamp: `time()` + `getExpiresIn()` |
| `getRefreshToken()` | `?string` | The `refresh_token`                                |
| `getScope()`        | `?string` | The scope the server actually granted              |
| `getError()`        | `?string` | The OAuth2 error, or `null` if there was none      |

Every one returns `null` when the server omitted that field.

```php
protected function toTokenData(OAuth2TokenResponse $response): TokenData
{
    $response->getToken();        // "eyJhbGciOi..."
    $response->getTokenType();    // "Bearer"
    $response->getExpiresIn();    // 3600      — seconds from now
    $response->getExpiresAt();    // 1714000770 — a timestamp
    $response->getRefreshToken(); // "def50200..."
    $response->getScope();        // "read:users"

    return parent::toTokenData($response);
}
```

### `getExpiresIn()` and `getExpiresAt()` are not the same number

`getExpiresIn()` is a **duration** — the 3600 the server sent, meaning "one hour
from whenever you asked". `getExpiresAt()` is an **instant** — that duration
added to the current time, which is what you store and compare against later.

Storing 3600 where a timestamp belongs produces a token that appears to have
expired in 1970; comparing a timestamp against 3600 produces one that never
expires. `TokenData::$expiresAt` is a timestamp, so `getExpiresAt()` is the one
that feeds it.

If the server omits `expires_in`, both return `null`. `OAuth2` then assumes a
one-hour lifetime rather than treating the token as immortal.

### The granted scope may not be the requested scope

`getScope()` reports what the server granted, which a provider is free to narrow.
Asking for `read:users write:orders` and receiving `read:users` is a normal
response, not an error — the write calls will simply come back 403. Read
`getScope()` if that distinction matters to you.

### `getError()` must be checked even on a 200

RFC 6749 §5.2 defines an `error` member for failed token requests, and not every
provider pairs it with a 4xx status. A response can be `successful()` and still
carry no token:

```php
// A real 200 response from a provider rejecting the request:
// {"error":"invalid_scope","error_description":"The requested scope is invalid"}

$response->successful();   // true  — the HTTP transfer worked
$response->getToken();     // null  — but there is no token
$response->getError();     // "invalid_scope: The requested scope is invalid"
```

`getError()` combines the `error` code with `error_description` when the server
supplies one, and returns `null` when there is no error at all.

You do not have to remember this for ordinary use. `OAuth2` checks `getError()`
on every token response and throws instead of caching an empty credential — the
failure surfaces at the token request, with the provider's own message, rather
than as unexplained 401s from the resource server later on. It reaches you
through `getTokenData()` returning `null` and through the
[`onTokenFailed()`](#oauth2-events) callback:

```php
$oauth = MyApiOAuth2::request('client-id', 'client-secret')
    ->onTokenFailed(function (Throwable $e): void {
        // "Token request failed: invalid_scope: The requested scope is invalid"
        error_log($e->getMessage());
    });

$oauth->getAccessToken(); // null
```

The check matters when you override `toTokenData()`: build your `TokenData` from
`parent::toTokenData($response)` as the examples above do, and the validation
stays in place.

---

## TokenData Value Object<a id="oauth2-tokendata"></a>

Serializable value object representing an OAuth2 token.

**Properties:**

| Property       | Type                   | Description                                     |
|----------------|------------------------|-------------------------------------------------|
| `accessToken`  | `string`               | The OAuth2 access token string                  |
| `expiresAt`    | `int`                  | Unix timestamp when the token expires (0 = N/A) |
| `refreshToken` | `?string`              | Refresh token (null if not provided)            |
| `tokenType`    | `?string`              | Token type, typically "Bearer"                  |
| `scope`        | `?string`              | Granted scope string                            |
| `metadata`     | `array<string, mixed>` | Custom provider-specific data                   |

**Methods:**

| Method         | Returns  | Description                    |
|----------------|----------|--------------------------------|
| `hasExpired()` | `bool`   | True if `time() >= expiresAt`  |
| `toArray()`    | `array`  | Plain array for storage        |
| `fromArray()`  | `static` | Reconstruct from a plain array |

```php
use Simsoft\HttpClient\Clients\TokenData;

$tokenData = new TokenData(
    accessToken:  'eyJhbGciOi...',
    expiresAt:    time() + 3600,
    refreshToken: 'def50200...',
    tokenType:    'Bearer',
    scope:        'read:users',
    metadata:     ['id_token' => 'eyJ...'],
);

$tokenData->hasExpired(); // false
$array = $tokenData->toArray();
$restored = TokenData::fromArray($array);
```

---

## StorageInterface<a id="storage-interface"></a>

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

### Redis example

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

---

## Storage Notes<a id="session-storage"></a>

- **FileStorage** (default) — persists in `sys_get_temp_dir()/oauth_tokens/`.
  Works in web, CLI, queues. An unreadable entry — truncated, evicted mid-write,
  hand-edited — is treated as absent and replaced, rather than failing every
  subsequent call.
- **SessionStorage** — tokens scoped per user session. Starts the session if one
  is not already active, and throws if it cannot (for example when headers have
  already been sent), rather than writing tokens into a `$_SESSION` that is never
  persisted.

```php
use Simsoft\HttpClient\Clients\Helpers\SessionStorage;

$token = MyApiOAuth2::request('client-id', 'client-secret', new SessionStorage('oauth'))
    ->getAccessToken();
```

Any backend shared between users needs `forSubject()` — see
[Custom Storage](#oauth2-storage). `SessionStorage` is already per-user, so it is
the exception.

---

## Comparison with Other Libraries<a id="comparison"></a>

| Aspect                | **Simsoft OAuth2**                     | **league/oauth2-client**      | **Laravel Socialite** | **Guzzle + manual** |
|-----------------------|----------------------------------------|-------------------------------|-----------------------|---------------------|
| **Dependencies**      | None (ext-curl only)                   | Guzzle + PSR packages         | Laravel framework     | Guzzle              |
| **Grant types**       | client_credentials, auth_code, refresh | All (+ password, custom)      | Auth code only        | Manual              |
| **PKCE (S256)**       | ✅ Built-in                             | ✅ Via provider option         | ❌                     | Manual              |
| **Token caching**     | ✅ Built-in                             | ❌ You manage it               | Session-based         | ❌                   |
| **Auto-refresh**      | ✅ Transparent                          | Manual                        | N/A                   | Manual              |
| **CSRF (state)**      | ✅ Auto                                 | ✅ Built-in                    | ✅ Built-in            | Manual              |
| **Provider packages** | Override methods                       | 100+ packages                 | 20+ providers         | None                |
| **Setup complexity**  | Subclass + 2–3 properties              | Provider + manual token logic | Config + routes       | Raw HTTP calls      |
| **Standalone**        | ✅                                      | ✅                             | ❌ (Laravel only)      | ✅                   |

### When to choose each

| Choose                   | When                                                                                       |
|--------------------------|--------------------------------------------------------------------------------------------|
| **Simsoft OAuth2**       | Zero dependencies, automatic token lifecycle, subclass-based API. Ideal for microservices. |
| **league/oauth2-client** | Need pre-built provider packages with user info fetching.                                  |
| **Laravel Socialite**    | Laravel app needing social login with minimal setup.                                       |
| **Guzzle + manual**      | Full control over every OAuth2 step, already in a Guzzle stack.                            |

---

## See Also

- [Middleware](MIDDLEWARE.md) — inject OAuth2 tokens automatically
- [Custom SDK](CUSTOM_SDK.md) — build authenticated SDK clients
- [Testing](TESTING.md) — mock OAuth2-protected endpoints
- [← Back to README](/)
