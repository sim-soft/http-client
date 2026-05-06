# OAuth2 Authentication Guide

The `OAuth2` class handles the full OAuth2 token lifecycle — acquisition,
caching, expiry detection, and automatic refresh — using only the library's own
`HttpClient`. Zero external dependencies required.

Your application code never needs to manage tokens manually. Just call
`getAccessToken()` and the class handles everything: checking the cache,
refreshing expired tokens, and acquiring new ones as needed.

---

## Table of Contents

1. [Prerequisites](#prerequisites)
2. [Basic Usage](#oauth2-basic)
3. [Sandbox Mode](#oauth2-sandbox)
4. [Custom Scope](#oauth2-scope)
5. [Custom Grant Type](#oauth2-grant-type)
6. [Authorization Code Flow with PKCE](#oauth2-auth-code)
7. [Custom Storage](#oauth2-storage)
8. [Using with HttpClient via Middleware](#oauth2-httpclient)
9. [TokenData Value Object](#oauth2-tokendata)
10. [StorageInterface](#storage-interface)
11. [Storage Notes](#session-storage)
12. [Comparison with Other Libraries](#comparison)

---

## Prerequisites<a id="prerequisites"></a>

```shell
composer require simsoft/http-client
```

That's it — only `ext-curl` is required at runtime.

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

**How it works internally:**

1. Checks storage for a cached token keyed by client ID
2. If cached and not expired → returns the token immediately (no network call)
3. If expired and a refresh token exists → attempts refresh via `refresh_token`
   grant
4. If refresh fails or no refresh token → acquires a new token via the
   configured grant type
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
use App\Clients\MyApiOAuth2;

// Production
$token = MyApiOAuth2::request('client-id', 'client-secret')
    ->getAccessToken();

// Sandbox
$token = MyApiOAuth2::request('sandbox-client-id', 'sandbox-client-secret')
    ->sandbox()
    ->getAccessToken();
```

You can also inspect the active endpoint:

```php
$oauth = MyApiOAuth2::request('client-id', 'client-secret')->sandbox();
echo $oauth->getEndpoint(); // "https://sandbox.api.example.com/oauth/token"
```

---

## Custom Scope<a id="oauth2-scope"></a>

Set `$scope` in the subclass to include it in all token requests:

```php
namespace App\Clients;

use Simsoft\HttpClient\Clients\OAuth2;

class MyApiOAuth2 extends OAuth2
{
    protected string $accessTokenEndpoint = 'https://api.example.com/oauth/token';
    protected ?string $scope = 'read:users write:orders';
}
```

When `$scope` is `null` (the default), the `scope` parameter is omitted from
the token request entirely.

---

## Custom Grant Type<a id="oauth2-grant-type"></a>

The default grant type is `client_credentials`. Override `$grantType` in your
subclass for different flows:

```php
namespace App\Clients;

use Simsoft\HttpClient\Clients\OAuth2;

class MyApiOAuth2 extends OAuth2
{
    protected string $accessTokenEndpoint = 'https://api.example.com/oauth/token';
    protected string $grantType = 'client_credentials'; // default
}
```

The `grant_type` parameter is included automatically in all token requests.

---

## Authorization Code Flow with PKCE<a id="oauth2-auth-code"></a>

The authorization code flow is designed for applications where a user
authenticates via a browser redirect. PKCE (Proof Key for Code Exchange,
RFC 7636) is applied automatically to protect against authorization code
interception attacks.

**How it works:**

1. Your app generates an authorization URL and redirects the user to the
   provider's login page
2. The user authenticates and grants consent
3. The provider redirects back to your app with an authorization `code`
4. Your app exchanges the code for an access token

Once the token is stored, subsequent calls to `getAccessToken()` use the cached
token (or refresh it automatically if a refresh token is available) — identical
to the `client_credentials` flow.

### Basic Auth Code Setup

Subclass `OAuth2` and configure the authorization endpoint, token endpoint, and
redirect URI:

```php
namespace App\Clients;

use Simsoft\HttpClient\Clients\OAuth2;

class GoogleOAuth2 extends OAuth2
{
    protected string $accessTokenEndpoint = 'https://oauth2.googleapis.com/token';
    protected string $sandboxEndpoint     = 'https://oauth2.sandbox.googleapis.com/token';

    protected string $authorizeEndpoint    = 'https://accounts.google.com/o/oauth2/v2/auth';
    protected string $sandboxAuthEndpoint  = 'https://accounts.google.com/o/oauth2/v2/auth';

    protected string $redirectUri = 'https://myapp.example.com/oauth/callback';

    protected ?string $scope = 'openid email profile';
}
```

### Redirect the User

Generate the authorization URL and redirect the user's browser:

```php
use App\Clients\GoogleOAuth2;

$oauth = GoogleOAuth2::request('your-client-id', 'your-client-secret');

$authUrl = $oauth->getAuthorizationUrl();

// Redirect the user to the provider's login page
header('Location: ' . $authUrl);
exit;
```

The generated URL includes all required parameters: `client_id`, `redirect_uri`,
`response_type=code`, `scope`, `state` (CSRF protection), `code_challenge`, and
`code_challenge_method=S256`. The PKCE verifier and state are stored
automatically via the configured `StorageInterface`.

### Handle the Callback

When the provider redirects back to your `$redirectUri`, exchange the
authorization code for tokens:

```php
use App\Clients\GoogleOAuth2;

$oauth = GoogleOAuth2::request('your-client-id', 'your-client-secret');

// The provider sends ?code=...&state=... to your redirect URI
$code  = $_GET['code'];
$state = $_GET['state'];

$tokenData = $oauth->exchangeCode($code, $state);

// Token is now stored — use getAccessToken() for subsequent API calls
echo $tokenData->accessToken;
```

`exchangeCode()` validates the `state` parameter against the stored value
(CSRF protection), sends the PKCE `code_verifier` to the token endpoint, and
stores the resulting `TokenData`. If state validation fails, a
`RuntimeException` is thrown.

### Using the Token

After the initial exchange, use `getAccessToken()` exactly like the
`client_credentials` flow. The token is cached and refreshed automatically:

```php
use App\Clients\GoogleOAuth2;
use Simsoft\HttpClient\HttpClient;

$oauth = GoogleOAuth2::request('your-client-id', 'your-client-secret');

$token = $oauth->getAccessToken();

$response = HttpClient::make()
    ->withBaseUrl('https://www.googleapis.com')
    ->withBearerToken($token)
    ->get('/oauth2/v2/userinfo');
```

### Complete Working Example

Putting it all together — subclass definition, redirect, callback, and token
usage:

```php
// 1. Define your provider subclass (e.g., app/Clients/GoogleOAuth2.php)
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

```php
// 2. Redirect the user (e.g., routes/login.php)
use App\Clients\GoogleOAuth2;

$oauth   = GoogleOAuth2::request('your-client-id', 'your-client-secret');
$authUrl = $oauth->getAuthorizationUrl();

header('Location: ' . $authUrl);
exit;
```

```php
// 3. Handle the callback (e.g., routes/callback.php)
use App\Clients\GoogleOAuth2;

$oauth     = GoogleOAuth2::request('your-client-id', 'your-client-secret');
$tokenData = $oauth->exchangeCode($_GET['code'], $_GET['state']);

// Store user session, etc.
$_SESSION['user_token'] = $tokenData->accessToken;
```

```php
// 4. Use the token for API calls
use App\Clients\GoogleOAuth2;
use Simsoft\HttpClient\HttpClient;

$token = GoogleOAuth2::request('your-client-id', 'your-client-secret')
    ->getAccessToken();

$response = HttpClient::make()
    ->withBaseUrl('https://www.googleapis.com')
    ->withBearerToken($token)
    ->get('/oauth2/v2/userinfo');
```

### Provider Extensibility

The authorization code flow exposes three protected methods that provider
subclasses can override to accommodate provider-specific behavior:

| Method                       | Purpose                                              |
|------------------------------|------------------------------------------------------|
| `buildAuthorizationParams()` | Add custom query parameters to the authorization URL |
| `buildCodeExchangeParams()`  | Add or modify POST parameters for the token exchange |
| `parseTokenResponse()`       | Handle non-standard token response fields            |

#### Example — Google with offline access

Google requires `access_type=offline` to issue a refresh token and
`prompt=consent`
to force the consent screen on re-authentication:

```php
namespace App\Clients;

use Simsoft\HttpClient\Clients\OAuth2;

class GoogleOAuth2 extends OAuth2
{
    protected string $accessTokenEndpoint = 'https://oauth2.googleapis.com/token';
    protected string $authorizeEndpoint   = 'https://accounts.google.com/o/oauth2/v2/auth';
    protected string $redirectUri         = 'https://myapp.example.com/oauth/callback';
    protected ?string $scope              = 'openid email profile';

    protected function buildAuthorizationParams(string $state, string $codeChallenge): array
    {
        $params = parent::buildAuthorizationParams($state, $codeChallenge);

        $params['access_type'] = 'offline';
        $params['prompt'] = 'consent';

        return $params;
    }
}
```

#### Example — Microsoft with tenant-specific endpoint

```php
namespace App\Clients;

use Simsoft\HttpClient\Clients\OAuth2;

class MicrosoftOAuth2 extends OAuth2
{
    protected string $accessTokenEndpoint = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
    protected string $authorizeEndpoint   = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';
    protected string $redirectUri         = 'https://myapp.example.com/oauth/callback';
    protected ?string $scope              = 'openid profile email User.Read';

    protected function buildCodeExchangeParams(string $code, string $verifier): array
    {
        $params = parent::buildCodeExchangeParams($code, $verifier);

        // Microsoft requires tenant in some configurations
        $params['tenant'] = 'common';

        return $params;
    }
}
```

#### Example — Custom token response parsing

Some providers return non-standard fields. Override `parseTokenResponse()` to
handle them:

```php
namespace App\Clients;

use Simsoft\HttpClient\Clients\OAuth2;
use Simsoft\HttpClient\Clients\Responses\OAuth2TokenResponse;
use Simsoft\HttpClient\Clients\TokenData;

class CustomProviderOAuth2 extends OAuth2
{
    protected string $accessTokenEndpoint = 'https://provider.example.com/oauth/token';
    protected string $authorizeEndpoint   = 'https://provider.example.com/oauth/authorize';
    protected string $redirectUri         = 'https://myapp.example.com/oauth/callback';

    protected function parseTokenResponse(OAuth2TokenResponse $response): TokenData
    {
        // Provider uses "token" instead of "access_token"
        $data = $response->toArray();

        return new TokenData(
            accessToken:  $data['token'] ?? $data['access_token'] ?? '',
            expiresAt:    time() + ($data['expires_in'] ?? 3600) - 30,
            refreshToken: $data['refresh_token'] ?? null,
            tokenType:    $data['token_type'] ?? 'Bearer',
            scope:        $data['scope'] ?? null,
        );
    }
}
```

---

## Custom Storage<a id="oauth2-storage"></a>

By default, tokens are stored on the filesystem via `FileStorage` (in
`sys_get_temp_dir()/oauth_tokens/`). This works in all contexts — web, CLI,
queues, and workers. Pass any `StorageInterface` implementation as the third
argument to use a different backend:

```php
use App\Clients\MyApiOAuth2;
use App\Storage\RedisStorage;

$storage = new RedisStorage($redisClient, ttl: 3600);

$token = MyApiOAuth2::request('client-id', 'client-secret', $storage)
    ->getAccessToken();
```

See [StorageInterface](#storage-interface) below for the full interface and a
Redis example.

---

## Using with HttpClient via Middleware<a id="oauth2-httpclient"></a>

The cleanest pattern is to inject token acquisition into middleware so all
requests are automatically authenticated:

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

## TokenData Value Object<a id="oauth2-tokendata"></a>

Tokens are stored internally as `TokenData` — a serializable value object with
only scalar properties. This ensures safe persistence in PHP sessions, Redis,
databases, or any cache backend.

Namespace: `Simsoft\HttpClient\Clients`

```php
use Simsoft\HttpClient\Clients\TokenData;

// TokenData is created internally by OAuth2, but you can inspect it:
$tokenData = new TokenData(
    accessToken:  'eyJhbGciOiJSUzI1NiJ9...',
    expiresAt:    time() + 3600,
    refreshToken: 'def50200...',
    tokenType:    'Bearer',
    scope:        'read:users write:orders',
);

// Check expiry
$tokenData->hasExpired(); // false (if within the hour)

// Convert to array (useful for custom storage backends)
$array = $tokenData->toArray();
// [
//     'access_token'  => 'eyJhbGciOiJSUzI1NiJ9...',
//     'expires_at'    => 1714000770,
//     'refresh_token' => 'def50200...',
//     'token_type'    => 'Bearer',
//     'scope'         => 'read:users write:orders',
// ]

// Reconstruct from array
$restored = TokenData::fromArray($array);
```

**Properties:**

| Property       | Type      | Description                                    |
|----------------|-----------|------------------------------------------------|
| `accessToken`  | `string`  | The OAuth2 access token string                 |
| `expiresAt`    | `int`     | Unix timestamp when the token expires          |
| `refreshToken` | `?string` | Refresh token (null if not provided by server) |
| `tokenType`    | `?string` | Token type, typically "Bearer"                 |
| `scope`        | `?string` | Granted scope string                           |

**Methods:**

| Method         | Returns  | Description                                |
|----------------|----------|--------------------------------------------|
| `hasExpired()` | `bool`   | True if `time() >= expiresAt`              |
| `toArray()`    | `array`  | Plain array representation for storage     |
| `fromArray()`  | `static` | Reconstruct a TokenData from a plain array |

> **Note:** The `OAuth2` class applies a 30-second safety buffer when computing
> `expiresAt` from the server's `expires_in` value. This accounts for clock
> skew and network latency, ensuring tokens are refreshed slightly before they
> actually expire.

---

## StorageInterface<a id="storage-interface"></a>

The `OAuth2` class accepts any `StorageInterface` implementation for token
persistence. The interface requires four methods:

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

### Example — Redis-backed storage

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

$redis = new \Redis();
$redis->connect('127.0.0.1', 6379);
$storage = new RedisStorage($redis, ttl: 3600);

$token = MyApiOAuth2::request('client-id', 'client-secret', $storage)
    ->getAccessToken();
```

---

## Storage Notes<a id="session-storage"></a>

**Default: FileStorage**
The default `FileStorage` persists tokens as serialized files in the system temp
directory (`sys_get_temp_dir()/oauth_tokens/`). This works in web, CLI, queues,
and workers without any configuration.

**SessionStorage (optional)**
If you prefer session-based storage (e.g., tokens scoped per user session), pass
a `SessionStorage` instance explicitly:

```php
use App\Clients\MyApiOAuth2;
use Simsoft\HttpClient\Clients\Helpers\SessionStorage;

$token = MyApiOAuth2::request('client-id', 'client-secret', new SessionStorage('oauth'))
    ->getAccessToken();
```

Note: `SessionStorage` requires `session_start()` and is not suitable for CLI
or long-running processes.

**Stored objects must be serializable.**
The `OAuth2` class stores `TokenData` objects, which contain only scalar
properties and are fully serializable. This is safe for both file and session
storage.

---

## Comparison with Other Libraries<a id="comparison"></a>

| Aspect                   | **Simsoft OAuth2**                                    | **league/oauth2-client**      | **Laravel Socialite**         | **Guzzle + manual** |
|--------------------------|-------------------------------------------------------|-------------------------------|-------------------------------|---------------------|
| **Dependencies**         | None (ext-curl only)                                  | Guzzle + PSR packages         | Laravel framework             | Guzzle              |
| **Grant types**          | client_credentials, authorization_code, refresh_token | All (+ password, custom)      | Authorization code only       | Whatever you build  |
| **PKCE (S256)**          | ✅ Built-in, always-on                                 | ✅ Via provider option         | ❌ Not built-in                | Manual              |
| **Token caching**        | ✅ Built-in (FileStorage, Redis, or custom)            | ❌ You manage it               | Session-based                 | ❌ You manage it     |
| **Auto-refresh**         | ✅ Transparent                                         | Manual                        | Not applicable                | Manual              |
| **CSRF (state)**         | ✅ Auto-generated + validated                          | ✅ Built-in                    | ✅ Built-in                    | Manual              |
| **Provider packages**    | Override points (3 methods)                           | 100+ pre-built packages       | 20+ providers                 | None                |
| **Setup complexity**     | Subclass + 2–3 properties                             | Provider + manual token logic | Config + routes + controllers | Raw HTTP calls      |
| **Standalone**           | ✅                                                     | ✅                             | ❌ (Laravel only)              | ✅                   |
| **Lines of code to use** | ~10                                                   | ~20–30                        | ~30+ (with routes)            | ~30–40              |

### When to choose each

| Choose                   | When                                                                                                                                       |
|--------------------------|--------------------------------------------------------------------------------------------------------------------------------------------|
| **Simsoft OAuth2**       | You want zero dependencies, automatic token lifecycle, and a simple subclass-based API. Ideal for microservices, CLI tools, and libraries. |
| **league/oauth2-client** | You need a pre-built provider package (Google, GitHub, Stripe, etc.) with provider-specific user info fetching.                            |
| **Laravel Socialite**    | You're in Laravel and need social login (redirect → callback → user info) with minimal setup.                                              |
| **Guzzle + manual**      | You need full control over every aspect of the OAuth2 flow with custom logic at each step, or you're already deep in a Guzzle-based stack. |
