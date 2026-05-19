# Testing with FakeHttpClient

`Simsoft\HttpClient\Testing\FakeHttpClient` is a built-in test double that
intercepts HTTP requests and returns predefined responses. No real network calls
are made — your tests stay fast, deterministic, and isolated.

> **Note:** FakeHttpClient extends `HttpClient`, so all fluent configuration
> methods (headers, base URL, query params, etc.) work exactly as in production
> code.

## Installation

FakeHttpClient ships with the library. No additional packages are required
beyond PHPUnit (already in `require-dev`):

```bash
composer require --dev phpunit/phpunit
```

## Basic Request Mocking

Use the static `fake()` factory to create a client with predefined responses:

```php
<?php

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\HttpClient\Testing\FakeHttpClient;

class UserServiceTest extends TestCase
{
    #[Test]
    public function fetchesUserFromApi(): void
    {
        $client = FakeHttpClient::fake([
            'GET https://api.example.com/users/1' => [
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode(['id' => 1, 'name' => 'Alice']),
            ],
        ]);

        $response = $client->get('https://api.example.com/users/1');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Alice', $response->data('name'));
    }
}
```

### Response Formats

Fake responses can be defined in three ways:

```php
// 1. Full config array — status, headers, and body
$client = FakeHttpClient::fake([
    'GET /users' => [
        'status' => 200,
        'headers' => ['X-Request-Id' => 'abc-123'],
        'body' => '{"users": []}',
    ],
]);

// 2. Integer — status code only (empty body)
$client = FakeHttpClient::fake([
    'DELETE /users/1' => 204,
]);

// 3. Response object — full control
use Simsoft\HttpClient\Response;

$response = new Response(curlInfo: ['http_code' => 200], body: '{"ok": true}');
$client = FakeHttpClient::fake([
    'POST /webhook' => $response,
]);
```

### Adding Fakes After Construction

Use `addFake()` to register routes incrementally:

```php
$client = new FakeHttpClient();
$client->addFake('GET https://api.example.com/health', 200);
$client->addFake('POST https://api.example.com/users', [
    'status' => 201,
    'body' => json_encode(['id' => 42]),
]);

$response = $client->get('https://api.example.com/health');
$this->assertTrue($response->successful());
```

## URL Pattern Matching with Wildcards

FakeHttpClient supports several pattern formats for flexible route matching.

### Exact URL Match

```php
$client = FakeHttpClient::fake([
    'https://api.example.com/users/1' => 200,
]);

// Matches any HTTP method to this exact URL
$client->get('https://api.example.com/users/1');  // ✓ matches
$client->post('https://api.example.com/users/1'); // ✓ matches
```

### Wildcard Patterns

Use `*` to match any path segment(s):

```php
$client = FakeHttpClient::fake([
    'GET https://api.example.com/users/*' => [
        'status' => 200,
        'body' => json_encode(['id' => 1, 'name' => 'Alice']),
    ],
]);

$client->get('https://api.example.com/users/1');       // ✓ matches
$client->get('https://api.example.com/users/42');      // ✓ matches
$client->get('https://api.example.com/users/42/posts');// ✓ matches
```

### Method + URL Pattern

Prefix the pattern with an HTTP method to restrict matching:

```php
$client = FakeHttpClient::fake([
    'GET /api/users' => [
        'status' => 200,
        'body' => '[]',
    ],
    'POST /api/users' => [
        'status' => 201,
        'body' => json_encode(['id' => 1]),
    ],
]);

$client->get('/api/users');  // returns 200
$client->post('/api/users'); // returns 201
```

### Callable Matcher

For complex matching logic, pass a closure:

```php
$client = new FakeHttpClient();
$client->addFake(
    fn(string $method, string $url): bool => str_contains($url, '/admin'),
    403
);

$client->get('https://api.example.com/admin/settings'); // returns 403
$client->get('https://api.example.com/admin/users');    // returns 403
```

## Response Sequencing for Retry Testing

Use `sequence()` to define ordered responses for the same endpoint. This is
ideal for testing retry logic, pagination, or state transitions.

```php
#[Test]
public function retriesOnServerError(): void
{
    $client = FakeHttpClient::fake([]);
    $client->sequence('GET https://api.example.com/data', [
        500,  // First attempt: server error
        500,  // Second attempt: server error
        [     // Third attempt: success
            'status' => 200,
            'body' => json_encode(['result' => 'ok']),
        ],
    ]);

    // Simulate retry logic
    $attempts = 0;
    $response = null;

    do {
        $response = $client->get('https://api.example.com/data');
        $attempts++;
    } while ($response->failed() && $attempts < 3);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(3, $attempts);
}
```

### Last Response Repeats

When all sequenced responses are consumed, the last one repeats indefinitely:

```php
$client = FakeHttpClient::fake([]);
$client->sequence('GET /status', [200, 503]);

$client->get('/status'); // 200
$client->get('/status'); // 503
$client->get('/status'); // 503 (repeats last)
$client->get('/status'); // 503 (repeats last)
```

### Mixing Sequences with Single Responses

```php
$client = FakeHttpClient::fake([
    'GET /health' => 200,  // Always returns 200
]);
$client->sequence('POST /jobs', [
    ['status' => 202, 'body' => '{"id": "job-1"}'],
    ['status' => 202, 'body' => '{"id": "job-2"}'],
    ['status' => 429, 'body' => '{"error": "rate limited"}'],
]);

$client->get('/health');   // 200
$client->post('/jobs');    // 202 — job-1
$client->post('/jobs');    // 202 — job-2
$client->post('/jobs');    // 429 — rate limited
$client->get('/health');   // 200 (still works)
```

## Request Assertions in PHPUnit Tests

FakeHttpClient records every request made through it. Use the built-in assertion
methods to verify your code communicates with the correct endpoints.

```php
#[Test]
public function createsUserAndFetchesProfile(): void
{
    $client = FakeHttpClient::fake([
        'POST https://api.example.com/users' => [
            'status' => 201,
            'body' => json_encode(['id' => 5]),
        ],
        'GET https://api.example.com/users/*' => [
            'status' => 200,
            'body' => json_encode(['id' => 5, 'name' => 'Bob']),
        ],
    ]);

    // Simulate application logic
    $client->post('https://api.example.com/users', ['name' => 'Bob']);
    $client->get('https://api.example.com/users/5');

    // Assert specific requests were made
    $client->assertSent('POST', 'https://api.example.com/users');
    $client->assertSent('GET', 'https://api.example.com/users/5');

    // Assert a request was NOT made
    $client->assertNotSent('DELETE', 'https://api.example.com/users/5');

    // Assert the total request count
    $client->assertSentCount(2);
}
```

### Available Assertion Methods

| Method                                       | Description                                                  |
|----------------------------------------------|--------------------------------------------------------------|
| `assertSent(string $method, string $url)`    | Verify a request was made with the given method and URL.     |
| `assertNotSent(string $method, string $url)` | Verify a request was NOT made with the given method and URL. |
| `assertNothingSent()`                        | Verify no requests were made at all.                         |
| `assertSentCount(int $count)`                | Verify the exact number of requests made.                    |
| `getRecordedRequests()`                      | Retrieve all recorded requests for custom assertions.        |

### Inspecting Recorded Requests

For more granular assertions, access the recorded requests directly:

```php
#[Test]
public function sendsCorrectPayload(): void
{
    $client = FakeHttpClient::fake([
        'POST https://api.example.com/orders' => 201,
    ]);

    $client
        ->withHeaders(['X-Idempotency-Key' => 'abc-123'])
        ->post('https://api.example.com/orders', ['item' => 'book', 'qty' => 2]);

    $requests = $client->getRecordedRequests();

    $this->assertCount(1, $requests);
    $this->assertSame('POST', $requests[0]->method);
    $this->assertSame('https://api.example.com/orders', $requests[0]->url);
    $this->assertSame(['item' => 'book', 'qty' => 2], $requests[0]->body);
}
```

### Asserting Nothing Was Sent

Useful for testing conditional logic that should NOT trigger HTTP calls:

```php
#[Test]
public function doesNotCallApiWhenCacheIsHit(): void
{
    $client = FakeHttpClient::fake([
        'GET /data' => 200,
    ]);

    // Simulate cache hit — no request should be made
    $cachedValue = 'cached-result';
    if ($cachedValue === null) {
        $client->get('/data');
    }

    $client->assertNothingSent();
}
```

## Testing Error Handling

### HTTP Error Responses

Test how your code handles 4xx and 5xx responses:

```php
#[Test]
public function handlesNotFoundGracefully(): void
{
    $client = FakeHttpClient::fake([
        'GET https://api.example.com/users/999' => 404,
    ]);

    $response = $client->get('https://api.example.com/users/999');

    $this->assertSame(404, $response->getStatusCode());
    $this->assertTrue($response->isClientError());
    $this->assertTrue($response->failed());
}

#[Test]
public function handlesServerError(): void
{
    $client = FakeHttpClient::fake([
        'POST https://api.example.com/process' => [
            'status' => 500,
            'body' => json_encode(['error' => 'Internal Server Error']),
        ],
    ]);

    $response = $client->post('https://api.example.com/process', ['data' => 'value']);

    $this->assertTrue($response->isServerError());
    $this->assertSame('Internal Server Error', $response->data('error'));
}
```

### Unexpected Request Exception

When a request doesn't match any configured pattern, FakeHttpClient throws
`UnexpectedRequestException`. This catches unintended API calls in your tests:

```php
use Simsoft\HttpClient\Testing\UnexpectedRequestException;

#[Test]
public function throwsOnUnexpectedRequest(): void
{
    $client = FakeHttpClient::fake([
        'GET /expected-endpoint' => 200,
    ]);

    $this->expectException(UnexpectedRequestException::class);

    // This URL has no matching fake — exception thrown
    $client->get('/unexpected-endpoint');
}
```

### Testing Rate Limiting with Sequences

```php
#[Test]
public function backsOffOnRateLimit(): void
{
    $client = FakeHttpClient::fake([]);
    $client->sequence('GET https://api.example.com/resource', [
        ['status' => 429, 'headers' => ['Retry-After' => '1']],
        ['status' => 429, 'headers' => ['Retry-After' => '2']],
        ['status' => 200, 'body' => '{"data": "success"}'],
    ]);

    $maxAttempts = 5;
    $response = null;

    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        $response = $client->get('https://api.example.com/resource');

        if ($response->getStatusCode() !== 429) {
            break;
        }
        // In real code: sleep based on Retry-After header
    }

    $this->assertSame(200, $response->getStatusCode());
    $client->assertSentCount(3);
}
```

### Testing Timeout / Network Errors

Simulate network-level failures by returning a response with status code 0:

```php
#[Test]
public function handlesNetworkFailure(): void
{
    $client = FakeHttpClient::fake([
        'GET https://api.example.com/unreachable' => 0,
    ]);

    $response = $client->get('https://api.example.com/unreachable');

    $this->assertSame(0, $response->getStatusCode());
    $this->assertTrue($response->failed());
}
```

## Full Test Class Example

A complete PHPUnit test class demonstrating FakeHttpClient in a realistic
service test:

```php
<?php

namespace App\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\HttpClient\Testing\FakeHttpClient;
use Simsoft\HttpClient\Testing\UnexpectedRequestException;

class PaymentGatewayTest extends TestCase
{
    private FakeHttpClient $http;

    protected function setUp(): void
    {
        $this->http = FakeHttpClient::fake([
            'POST https://payments.example.com/charges' => [
                'status' => 201,
                'body' => json_encode([
                    'id' => 'ch_abc123',
                    'status' => 'succeeded',
                    'amount' => 2500,
                ]),
            ],
            'GET https://payments.example.com/charges/*' => [
                'status' => 200,
                'body' => json_encode([
                    'id' => 'ch_abc123',
                    'status' => 'succeeded',
                ]),
            ],
            'POST https://payments.example.com/refunds' => [
                'status' => 201,
                'body' => json_encode(['id' => 'rf_xyz789']),
            ],
        ]);
    }

    #[Test]
    public function createsCharge(): void
    {
        $response = $this->http->post(
            'https://payments.example.com/charges',
            ['amount' => 2500, 'currency' => 'usd']
        );

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('ch_abc123', $response->data('id'));
        $this->http->assertSent('POST', 'https://payments.example.com/charges');
    }

    #[Test]
    public function retrievesChargeDetails(): void
    {
        $response = $this->http->get('https://payments.example.com/charges/ch_abc123');

        $this->assertSame('succeeded', $response->data('status'));
        $this->http->assertSentCount(1);
    }

    #[Test]
    public function doesNotCallUnknownEndpoints(): void
    {
        $this->expectException(UnexpectedRequestException::class);
        $this->http->get('https://payments.example.com/unknown');
    }
}
```

---

## See Also

- [Concurrent Requests](POOL.md) — FakeHttpClient works with HttpPool
- [Middleware](MIDDLEWARE.md) — test middleware behavior
- [Custom SDK](CUSTOM_SDK.md) — test custom response classes
- [← Back to README](../README.md)
