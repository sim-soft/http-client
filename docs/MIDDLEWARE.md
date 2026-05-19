# Middleware

Passing middleware to the client is done by calling `withMiddleware` on the
client.

> **Note:** The examples below omit `use` imports for brevity. All middleware
> closures require `use Closure` and `use Simsoft\HttpClient\Response` at
> minimum. The first example shows the full imports.

```php
use Closure;
use Simsoft\HttpClient\HttpClient;
use Simsoft\HttpClient\Response;

$client = HttpClient::make()
    ->withBaseUrl('https://api.example.com')

     // withMiddleware(Closure, middleware_name) middleware_name is optional.
     // The closure receives 2 arguments: the request object and the next middleware.
     // It must return a Response instance.
    ->withMiddleware(function (HttpClient $request, Closure $next): Response {
        // Modify the request before it is sent
        $request->withHeader('X-Custom-Header', 'Custom Value');

        $response = $next();
        // Inspect or modify the response after it is received
        return $response;
    }, 'my-middleware')
    ->get('/users');
```

## Example Usages

#### Example 1. Authentication — inject Bearer token from a token store

Useful when the token may expire and needs to be refreshed per-request rather
than being hardcoded once at client construction.

```php
use Closure;
use Simsoft\HttpClient\HttpClient;
use Simsoft\HttpClient\Response;

$tokenStore = new MyTokenStore(); // your own token provider

$client = HttpClient::make()
    ->withBaseUrl('https://api.example.com')
    ->withMiddleware(function (HttpClient $request, Closure $next) use ($tokenStore): Response {
        $request->withBearerToken($tokenStore->getAccessToken());
        return $next();
    }, 'auth');

$response = $client->get('/users');
```

#### Example 2: Request signing — add HMAC signature header

```php
$secret = 'my-secret-key';

$client = HttpClient::make()
    ->withBaseUrl('https://api.example.com')
    ->withMiddleware(function (HttpClient $request, Closure $next) use ($secret): Response {
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . $request->getEndpoint(), $secret);
        $request->withHeaders([
            'X-Timestamp' => (string) $timestamp,
            'X-Signature' => $signature,
        ]);
        return $next();
    }, 'signing');

$response = $client->post('/orders', ['item' => 'book']);
```

#### Example 3: Logging — log every request and response with timing.

```php
$logger = new Monolog\Logger('http');

$client = HttpClient::make()
    ->withBaseUrl('https://api.example.com')
    ->withMiddleware(function (HttpClient $request, Closure $next) use ($logger): Response {
        $start = microtime(true);

        $response = $next();

        $logger->info('HTTP request completed', [
            'status'   => $response->getStatusCode(),
            'duration' => round((microtime(true) - $start) * 1000, 2) . 'ms',
            'errno'    => $response->getErrno(),
        ]);

        return $response;
    }, 'logging');
```

#### Example 4: Caching — return cached response, skip real request.

```php
// $cache is your PSR-16 (SimpleCache) implementation — e.g. Symfony Cache, Laravel Cache, etc.
/** @var \Psr\SimpleCache\CacheInterface $cache */

$client = HttpClient::make()
    ->withBaseUrl('https://api.example.com')
    ->withMiddleware(function (HttpClient $request, Closure $next) use ($cache): Response {
        // Only cache GET requests
        if ($request->getMethod() !== 'GET') {
            return $next();
        }

        $cacheKey = 'http_' . md5($request->getEndpoint());

        if ($cache->has($cacheKey)) {
            $cached = $cache->get($cacheKey);
            // Reconstruct a Response from cached data
            return (new Response())->withStatus(200)->withBody(
                new \Simsoft\HttpClient\Streams\StringStream($cached)
            );
        }

        $response = $next();

        if ($response->successful()) {
            $cache->set($cacheKey, $response->getRaw(), 300); // cache 5 minutes
        }

        return $response;
    }, 'cache');
```

#### Example 5: Error normalization — transform error responses into a standard shape

Different APIs return errors in different structures. This middleware normalizes
them all into a consistent format for the rest of the app.

```php
$client = HttpClient::make()
    ->withBaseUrl('https://api.example.com')
    ->withMiddleware(function (HttpClient $request, Closure $next): Response {
        $response = $next();

        if ($response->isClientError() || $response->isServerError()) {
            // Some APIs wrap errors under 'error', others under 'errors', 'message', etc.
            $message = $response->data('error.message')
                ?? $response->data('errors.0.message')
                ?? $response->data('message')
                ?? $response->getReasonPhrase();

            // Return the response as-is, but the caller can rely on getMessage()
            return $response->withStatus(
                $response->getStatusCode(),
                $message
            );
        }

        return $response;
    }, 'error_normaliser');
```

#### Example 6: Circuit breaker — stop sending requests after repeated failures.

```php
class CircuitBreaker
{
    private int $failures = 0;
    private ?float $openedAt = null;

    public function __construct(
        private int $threshold = 5,
        private int $resetAfterSeconds = 30
    ) {}

    public function isOpen(): bool
    {
        if ($this->openedAt && (time() - $this->openedAt) > $this->resetAfterSeconds) {
            $this->failures = 0;
            $this->openedAt = null;
        }
        return $this->openedAt !== null;
    }

    public function recordFailure(): void
    {
        $this->failures++;
        if ($this->failures >= $this->threshold) {
            $this->openedAt = time();
        }
    }

    public function recordSuccess(): void
    {
        $this->failures = 0;
        $this->openedAt = null;
    }
}

$breaker = new CircuitBreaker(threshold: 5, resetAfterSeconds: 30);

$client = HttpClient::make()
    ->withBaseUrl('https://api.example.com')
    ->withMiddleware(function (HttpClient $request, Closure $next) use ($breaker): Response {
        if ($breaker->isOpen()) {
            throw new RuntimeException('Circuit breaker is open — service unavailable.');
        }

        $response = $next();

        $response->isServerError() || $response->isNetworkError()
            ? $breaker->recordFailure()
            : $breaker->recordSuccess();

        return $response;
    }, 'circuit_breaker');
```

#### Example 7: Chaining multiple middlewares together

Middlewares execute in registration order (first registered = outermost). In
this example: auth wraps logging wraps error_normalizer wraps the request.

```php
$client = HttpClient::make()
    ->withBaseUrl('https://api.example.com')
    ->withMiddleware(function (HttpClient $request, Closure $next) use ($tokenStore): Response {
        $request->withBearerToken($tokenStore->getAccessToken());
        return $next();
    }, 'auth')
    ->withMiddleware(function (HttpClient $request, Closure $next) use ($logger): Response {
        $start = microtime(true);
        $response = $next();
        $logger->info('Request', [
            'status'   => $response->getStatusCode(),
            'duration' => round((microtime(true) - $start) * 1000, 2) . 'ms',
        ]);
        return $response;
    }, 'logging')
    ->withMiddleware(function (HttpClient $request, Closure $next): Response {
        $response = $next();
        if ($response->isClientError() || $response->isServerError()) {
            $message = $response->data('message') ?? $response->getReasonPhrase();
            return $response->withStatus($response->getStatusCode(), $message);
        }
        return $response;
    }, 'error_normaliser');

$response = $client->get('/dashboard');
```

---

## See Also

- [OAuth2 via Middleware](OAUTH2.md#oauth2-httpclient) — auto-inject tokens
- [Testing Middleware](TESTING.md) — mock requests with FakeHttpClient
- [Concurrent Requests](POOL.md) — execute requests in parallel
- [← Back to README](../README.md)
