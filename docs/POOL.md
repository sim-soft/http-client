# HttpPool — Concurrent Requests

Execute multiple HTTP requests concurrently using PHP's `curl_multi_*` API with
automatic HTTP/2 multiplexing, configurable concurrency limits, and per-response
callbacks.

> **Note:** The examples below omit `use` imports for brevity. All examples
> require `use Simsoft\HttpClient\HttpClient` and
> `use Simsoft\HttpClient\HttpPool` at minimum. Some examples also use
> `use Simsoft\HttpClient\PoolBuilder` and
`use Simsoft\HttpClient\HttpPoolResult`.

## Basic Usage

Send multiple requests concurrently and collect all responses:

```php
use Simsoft\HttpClient\HttpClient;
use Simsoft\HttpClient\HttpPool;

$result = HttpPool::create()->send([
    HttpClient::make()->withBaseUrl('https://api.example.com')->resource('/users')->withMethod('GET'),
    HttpClient::make()->withBaseUrl('https://api.example.com')->resource('/posts')->withMethod('GET'),
    HttpClient::make()->withBaseUrl('https://api.example.com')->resource('/comments')->withMethod('GET'),
]);

// Access all responses (indexed in the same order as input)
foreach ($result as $index => $response) {
    echo "Request {$index}: HTTP {$response->getStatusCode()}\n";
}

// Get a specific response by index
$usersResponse = $result[0];
$users = $usersResponse->data();
```

> **Important:** Do not use `get()`, `post()`, `put()`, `patch()`, or `delete()`
> inside `send()`. These methods execute the request immediately and return a
> `Response` — the pool would receive completed responses instead of pending
> clients. Use `resource()` and `withMethod()` to configure without executing.
>
> ```php
> // ✗ Wrong — executes immediately, pool receives Response objects
> HttpPool::create()->send([
>     HttpClient::make()->get('https://api.example.com/users'),
> ]);
>
> // ✓ Correct — configures without executing
> HttpPool::create()->send([
>     HttpClient::make()->resource('https://api.example.com/users'),
> ]);
> ```

### HttpPoolResult Methods

| Method                          | Returns                        | Description                                                      |
|---------------------------------|--------------------------------|------------------------------------------------------------------|
| `$result['key']`                | `Response`                     | Get a response by key (array access)                             |
| `$result('key')`                | `Response`                     | Get a response by key (invokable)                                |
| `getResponse(int\|string $key)` | `Response`                     | Get a response by key (throws `OutOfBoundsException` if missing) |
| `getResponses()`                | `array<int\|string, Response>` | All responses                                                    |
| `getSuccessful()`               | `array<int\|string, Response>` | Only 2xx responses                                               |
| `getFailed()`                   | `array<int\|string, Response>` | Only 4xx, 5xx, and network errors                                |
| `count()`                       | `int`                          | Total number of responses                                        |
| `foreach ($result as ...)`      | —                              | Iterate all responses                                            |
| `isset($result['key'])`         | `bool`                         | Check if a key exists                                            |

## Using Closures for Lazy Request Creation

Pass closures that return HttpClient instances. The closure is resolved just
before the request is added to the execution window:

```php
$result = HttpPool::create()->send([
    'users'         => fn() => HttpClient::make()->resource('https://api.example.com/users'),
    'notifications' => fn() => HttpClient::make()->resource('https://api.example.com/notifications')->withMethod('POST'),
    'comments'      => fn() => HttpClient::make()->resource('https://api.example.com/comments'),
]);

$users = $result['users']->data();
$notifications = $result['notifications']->data();
```

This is useful when request construction is expensive or depends on runtime
state that should be evaluated at execution time rather than at array-build
time.

## Using the Pool Builder

`HttpPool::run()` provides a cleaner syntax using a `PoolBuilder` that mirrors
HttpClient's `get()`, `post()`, `put()`, `patch()`, and `delete()` methods —
but configures requests without executing them:

```php
use Simsoft\HttpClient\HttpPool;
use Simsoft\HttpClient\PoolBuilder;

$result = HttpPool::run(fn (PoolBuilder $pool) => [
    'users'    => $pool->get('https://api.example.com/users'),
    'posts'    => $pool->post('https://api.example.com/posts', ['title' => 'Hello']),
    'comments' => $pool->get('https://api.example.com/comments', ['page' => 2]),
]);

$users = $result['users']->data();
$posts = $result['posts']->data();
```

Set concurrency via the second argument:

```php
$result = HttpPool::run(fn (PoolBuilder $pool) => [
    $pool->get('https://api.example.com/users/1'),
    $pool->get('https://api.example.com/users/2'),
    $pool->get('https://api.example.com/users/3'),
], concurrency: 5);
```

The builder supports shared configuration — set a base URL, headers, or token
that applies to all requests:

```php
$result = HttpPool::run(function (PoolBuilder $pool) {
    $pool->withBaseUrl('https://api.example.com')
        ->withBearerToken('YOUR_TOKEN')
        ->withHeaders(['Accept' => 'application/json']);

    return [
        'users'    => $pool->get('/users'),
        'posts'    => $pool->post('/posts', ['title' => 'New Post']),
        'comments' => $pool->get('/comments', ['limit' => 10]),
    ];
}, concurrency: 10);
```

Use `asJson()` to send all POST/PUT/PATCH bodies as JSON:

```php
$result = HttpPool::run(function (PoolBuilder $pool) {
    $pool->withBaseUrl('https://api.example.com')
        ->withBearerToken('YOUR_TOKEN')
        ->asJson();

    return [
        'create' => $pool->post('/users', ['name' => 'Alice', 'email' => 'alice@example.com']),
        'update' => $pool->patch('/users/1', ['name' => 'Bob']),
        'delete' => $pool->delete('/users/2'),
    ];
});
```

Since `$pool->get()`, `$pool->post()`, etc. return `HttpClient` instances, you
can chain any HttpClient method on individual requests — middleware, JSON mode,
custom headers, timeouts:

```php
$result = HttpPool::run(function (PoolBuilder $pool) {
    $pool->withBaseUrl('https://api.example.com')
        ->withBearerToken('YOUR_TOKEN');

    return [
        'users' => $pool->get('/users')
            ->withMiddleware(function (HttpClient $request, Closure $next): Response {
                $request->withHeader('X-Cache', 'skip');
                return $next();
            }, 'cache-bypass'),

        'posts' => $pool->post('/posts')
            ->asJson()
            ->withJson(['title' => 'Hello', 'body' => 'World']),

        'report' => $pool->get('/reports/daily')
            ->timeout(60)
            ->withQuery(['format' => 'csv']),
    ];
});
```

## Cloning a Shared Base Client

When multiple requests share the same base configuration (base URL, headers,
auth tokens), clone a template client to avoid repeating the setup:

```php
$client = HttpClient::make()
    ->withBaseUrl('https://api.example.com')
    ->withBearerToken('YOUR_TOKEN')
    ->withHeaders(['Accept' => 'application/json']);

$result = HttpPool::create()->send([
    (clone $client)->resource('/users'),
    (clone $client)->resource('/posts'),
    (clone $client)->resource('/comments'),
]);
```

Each clone gets its own cURL handle — the shared configuration is copied, but
the connections are independent, so concurrent execution is safe.

## Named Requests

Use string keys to name each request. Retrieve responses by name instead of
remembering numeric indices:

```php
$client = HttpClient::make()
    ->withBaseUrl('https://api.example.com')
    ->withBearerToken('YOUR_TOKEN');

$result = HttpPool::create()->send([
    'users'    => (clone $client)->resource('/users'),
    'posts'    => (clone $client)->resource('/posts'),
    'comments' => (clone $client)->resource('/comments'),
]);

$users = $result['users']->data();
$posts = $result['posts']->data();
$comments = $result['comments']->data();
```

### Accessing Responses

`HttpPoolResult` supports three access styles — use whichever reads best in
your context:

```php
// Array access (recommended)
$users = $result['users']->data();

// Method call
$users = $result->getResponse('users')->data();

// Invokable
$users = $result('users')->data();
```

Array access also supports `isset()`:

```php
if (isset($result['users'])) {
    $users = $result['users']->data();
}
```

The result is immutable — attempting to set or unset a key throws
`RuntimeException`.

Named requests work with all pool features — callbacks receive the string key,
and filtering methods preserve the original keys:

```php
$result = HttpPool::create()
    ->onResponse(function ($response, $key) {
        echo "{$key}: HTTP {$response->getStatusCode()}\n";
        // Output: "users: HTTP 200", "posts: HTTP 200", etc.
    })
    ->send([
        'users'  => (clone $client)->resource('/users'),
        'posts'  => (clone $client)->resource('/posts')->withMethod('POST'),
        'health' => (clone $client)->resource('/health'),
    ]);

// Filter by outcome — keys are preserved
$failed = $result->getFailed(); // e.g. ['health' => Response]
```

Integer-indexed and string-keyed requests can be mixed in the same batch, but
using one style consistently is recommended for clarity.

## Concurrency Limit

Control how many requests execute simultaneously. The default limit is 25.
Requests beyond the limit are queued and executed as earlier requests complete
(sliding window).

```php
// Via static factory
$pool = HttpPool::create(concurrency: 5);

// Or via fluent method
$pool = HttpPool::create()->concurrency(10);

// Execute 100 requests, max 10 at a time
$requests = [];
for ($i = 1; $i <= 100; $i++) {
    $requests[] = HttpClient::make()
        ->withBaseUrl('https://api.example.com')
        ->resource("/items/{$i}");
}

$result = $pool->send($requests);
echo "Completed: {$result->count()} responses\n";
```

Choose a concurrency limit based on your target server's capacity and your
system resources. Lower values are gentler on the target; higher values maximize
throughput.

## Per-Request Timeout

Set a maximum duration for each individual request in the pool. Requests that
exceed the timeout are aborted and marked as failed:

```php
$result = HttpPool::create()
    ->concurrency(10)
    ->timeout(5) // each request must complete within 5 seconds
    ->send($requests);

// Timed-out requests appear in getFailed()
foreach ($result->getFailed() as $key => $response) {
    echo "{$key}: timed out or failed\n";
}
```

## Retries

Automatically retry failed requests (network errors or HTTP 5xx) before marking
them as failed:

```php
$result = HttpPool::create()
    ->concurrency(10)
    ->retries(3) // retry up to 3 times on failure
    ->send($requests);
```

Add a delay between retry attempts to avoid hammering the server:

```php
$result = HttpPool::create()
    ->concurrency(10)
    ->retries(3, after: 500) // retry up to 3 times, wait 500ms between attempts
    ->send($requests);
```

Retries apply to network errors (`errno > 0`) and server errors (5xx status
codes). Client errors (4xx) are not retried — they indicate a problem with the
request itself.

Combine with timeout to avoid hanging retries:

```php
$result = HttpPool::create()
    ->concurrency(5)
    ->timeout(10)
    ->retries(2)
    ->send($requests);
```

## Rate Limiting

Add a delay between completed requests to respect API rate limits:

```php
$result = HttpPool::create()
    ->concurrency(3)
    ->delay(200) // wait 200ms after each request completes
    ->send($requests);
```

This is different from concurrency — concurrency controls how many requests are
in-flight simultaneously, while delay adds a pause after each completion before
the next request starts. Use both together for gentle scraping:

```php
// Max 2 concurrent, 500ms pause between completions
$result = HttpPool::create()
    ->concurrency(2)
    ->delay(500)
    ->send($requests);
```

## Progress Tracking

Monitor batch progress with the `onProgress` callback:

```php
$result = HttpPool::create()
    ->concurrency(10)
    ->onProgress(function (int $completed, int $total) {
        $percent = round(($completed / $total) * 100);
        echo "\r{$completed}/{$total} ({$percent}%)";
    })
    ->send($requests);

echo "\nDone!\n";
```

The callback fires after each request completes, receiving the current count
and the total. Combine with other callbacks:

```php
$result = HttpPool::create()
    ->concurrency(10)
    ->onProgress(fn ($done, $total) => updateProgressBar($done, $total))
    ->onError(fn ($response, $key) => logFailure($key, $response))
    ->retries(2)
    ->timeout(15)
    ->send($requests);
```

## Per-Response Callbacks

Process responses as they arrive rather than waiting for the entire batch to
finish. Useful for progress reporting or streaming results to storage:

```php
$pool = HttpPool::create()
    ->concurrency(10)
    ->onResponse(function ($response, $index) {
        echo "[{$index}] Completed: HTTP {$response->getStatusCode()}\n";

        // Write a result to a database immediately
        saveToDatabase($index, $response->data());
    });

$result = $pool->send($requests);
```

The callback receives the `Response` object and its original index. The complete
`HttpPoolResult` is still returned after all requests finish.

## Error Handling

Individual request failures never abort the batch. Failed requests are included
in the results at their original index, and other requests continue executing.

```php
$pool = HttpPool::create()
    ->concurrency(10)
    ->onError(function ($response, $index) {
        echo "[{$index}] Failed: HTTP {$response->getStatusCode()}\n";
        echo "  Error: {$response->getMessage()}\n";
    });

$result = $pool->send($requests);

// Filter results after completion
$successful = $result->getSuccessful(); // Only 2xx responses
$failed = $result->getFailed();         // 4xx, 5xx, and network errors

echo "Success: " . count($successful) . "\n";
echo "Failed: " . count($failed) . "\n";

// Retry failed requests
if (count($failed) > 0) {
    $retryRequests = [];
    foreach ($failed as $index => $response) {
        $retryRequests[] = $requests[$index];
    }
    $retryResult = $pool->send($retryRequests);
}
```

### Combining onResponse and onError

Both callbacks can be used together. `onResponse` fires for every completed
request; `onError` fires only for failures:

```php
$pool = HttpPool::create()
    ->concurrency(15)
    ->onResponse(function ($response, $index) {
        // Fires for ALL completed requests (success and failure)
        logCompletion($index, $response->getStatusCode());
    })
    ->onError(function ($response, $index) {
        // Fires only for failed requests
        alertOnFailure($index, $response->getMessage());
    });
```

## Connection Pooling

Connection pooling is automatic. When you reuse an `HttpClient` instance for
multiple sequential requests, the underlying cURL handle is reused via
`curl_reset()` instead of creating a new handle with `curl_init()`. This
eliminates repeated TCP/TLS handshakes for requests to the same host.

```php
// The same HttpClient instance reuses its internal cURL handle
$client = HttpClient::make()->withBaseUrl('https://api.example.com');

// First request: TCP + TLS handshake
$users = $client->get('/users');

// Second request: a handle reused, no new handshake
$posts = $client->get('/posts');

// Third request: still the same handle
$comments = $client->get('/comments');
```

No configuration is needed — connection pooling is enabled by default and works
transparently with the existing API.

### How it works

1. On the first request, `curl_init()` creates a new handle
2. On later requests, `curl_reset()` clears the handle's options while
   preserving the underlying connection
3. When the HttpClient instance is destroyed, the handle is closed via
   `curl_close()`

This gives you connection reuse benefits (keep-alive, session caching) without
any code changes.

### HTTP/2 Multiplexing in HttpPool

HttpPool enables HTTP/2 multiplexing automatically by setting
`CURLMOPT_PIPELINING` to `CURLPIPE_MULTIPLEX`. When multiple requests target the
same host, they share a single TCP connection via HTTP/2 streams — reducing
connection overhead further.

```php
// All 10 requests to the same host share one TCP connection
$requests = [];
for ($i = 1; $i <= 10; $i++) {
    $requests[] = HttpClient::make()
        ->withBaseUrl('https://api.example.com')
        ->resource("/users/{$i}");
}

$result = HttpPool::create()->send($requests);
```

If the server does not support HTTP/2, cURL falls back to HTTP/1.1 without
error.

## How It Compares

HttpPool's main advantages are simplicity (no promises or event loops), zero
dependencies, and explicit concurrency control. The table below compares
architectural approaches — choose based on what matters for your project.

| Aspect                   | Simsoft HttpPool                          | Guzzle Pool                                           | Symfony HttpClient                   |
|--------------------------|-------------------------------------------|-------------------------------------------------------|--------------------------------------|
| Concurrency model        | Direct `curl_multi_*` with sliding window | Promise-based with event loop                         | Implicit async (responses are lazy)  |
| Control over concurrency | Configurable limit via `concurrency()`    | Manual chunking or pool size option                   | Limited (max_host_connections)       |
| Runtime overhead         | Zero allocations beyond cURL handles      | Promise objects + generator wrappers per request      | Contracts + transport abstraction    |
| Dependencies             | `ext-curl` only                           | `guzzlehttp/promises`, `psr/http-message`, event loop | `symfony/http-client` + contracts    |
| HTTP/2 multiplexing      | Automatic (`CURLPIPE_MULTIPLEX`)          | Requires manual cURL option configuration             | Automatic                            |
| Memory profile           | One multi handle + N cURL handles         | Promise chain + middleware stack per request          | Response objects + chunk buffers     |
| Error isolation          | Per-request, never aborts batch           | Per-promise, requires catch per request               | Throws on access unless wrapped      |
| Response access          | Immediate via `HttpPoolResult`            | Via promise resolution                                | Lazy evaluation on property access   |
| Callback support         | `onResponse` / `onError` per request      | `.then()` / `.otherwise()` per promise                | Stream-based with chunks             |
| API style                | Fluent, callback-based                    | Generator/yield or promise-based                      | Lazy responses with `->getContent()` |

Simsoft HttpPool operates directly on `curl_multi_*` without intermediate
abstractions. There are no promise objects, no generator wrappers, and no event
loop instances — just a tight polling loop with `curl_multi_exec` and
`curl_multi_select`.

### When to use HttpPool

#### API Aggregation — Fetching Multiple Endpoints in Parallel

Build a dashboard by fetching data from several endpoints at once:

```php
$client = HttpClient::make()
    ->withBaseUrl('https://api.example.com')
    ->withBearerToken($token);

$result = HttpPool::create()->send([
    'user'            => (clone $client)->resource('/me'),
    'notifications'   => (clone $client)->resource('/me/notifications'),
    'orders'          => (clone $client)->resource('/me/orders')->withQuery(['limit' => 5]),
    'recommendations' => (clone $client)->resource('/me/recommendations'),
]);

$dashboard = [
    'user'            => $result['user']->data(),
    'notifications'   => $result['notifications']->data('items'),
    'recent_orders'   => $result['orders']->data('data'),
    'recommendations' => $result['recommendations']->data('data'),
];
```

#### Batch Operations — Creating or Updating Many Resources

Update 200 product prices with a concurrency limit to avoid overwhelming the
API:

```php
$client = HttpClient::make()
    ->withBaseUrl('https://api.example.com')
    ->withBearerToken($token);

$requests = [];
foreach ($priceUpdates as $index => $update) {
    $req = (clone $client)
        ->resource("/products/{$update['id']}")
        ->withMethod('PATCH')
        ->asJson();
    $req->withJson(['price' => $update['new_price']]);
    $requests[$index] = $req;
}

$result = HttpPool::create(10) // max 10 concurrent updates
    ->onError(function ($response, $index) use ($priceUpdates) {
        echo "Failed to update product {$priceUpdates[$index]['id']}: "
            . "HTTP {$response->getStatusCode()}\n";
    })
    ->send($requests);

echo "Updated " . count($result->getSuccessful()) . " products\n";
echo "Failed: " . count($result->getFailed()) . "\n";
```

#### Health Checks — Monitoring Multiple Services

Check the health of all microservices in your infrastructure:

```php
$services = [
    'auth'    => 'https://auth.internal/health',
    'billing' => 'https://billing.internal/health',
    'search'  => 'https://search.internal/health',
    'email'   => 'https://email.internal/health',
    'storage' => 'https://storage.internal/health',
];

$requests = [];
foreach ($services as $name => $url) {
    $requests[$name] = HttpClient::make()->resource($url);
}

$result = HttpPool::create()->send($requests);

foreach ($result as $service => $response) {
    $status = $response->successful() ? '✓ UP' : '✗ DOWN';
    echo "{$status}  {$service} ({$response->getStatusCode()})\n";
}

$downServices = array_keys($result->getFailed());
if ($downServices !== []) {
    alertOpsTeam($downServices);
}
```

#### Web Scraping — Rate-Limited Concurrent Fetching

Scrape product pages with a low concurrency to respect the target server:

```php
$urls = [
    'https://shop.example.com/products/1',
    'https://shop.example.com/products/2',
    // ... hundreds of URLs
    'https://shop.example.com/products/500',
];

$requests = [];
foreach ($urls as $url) {
    $requests[] = HttpClient::make()->resource($url);
}

$products = [];

$result = HttpPool::create(3) // only 3 concurrent requests — be gentle
    ->onResponse(function ($response, $index) use (&$products, $urls) {
        $products[] = [
            'url'   => $urls[$index],
            'title' => $response->data('title'),
            'price' => $response->data('price'),
        ];
    })
    ->send($requests);

echo "Scraped " . count($products) . " products\n";
```

#### Sequential Bottleneck — Before and After

Without HttpPool (sequential — 10 requests × 200ms = 2 seconds):

```php
$responses = [];
foreach ($urls as $url) {
    $responses[] = HttpClient::make()->get($url); // blocks until complete
}
// Total time: ~2000ms
```

With HttpPool (concurrent — 10 requests, all in flight = ~200ms):

```php
$requests = [];
foreach ($urls as $url) {
    $requests[] = HttpClient::make()->resource($url);
}

$result = HttpPool::create()->send($requests);
// Total time: ~200ms (limited by the slowest single request)
```
