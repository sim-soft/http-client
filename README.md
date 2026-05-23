# Simsoft HttpClient

A fluent PHP HTTP client built on `ext-curl` with zero runtime dependencies.
PSR-7/PSR-18 compliant, concurrent requests, built-in retry, middleware, and
test doubles — all in a single lightweight package.

```php
$response = HttpClient::make()
    ->withBaseUrl('https://api.example.com')
    ->withBearerToken('YOUR_TOKEN')
    ->get('/users', ['page' => 1]);

echo $response->data('data.0.name'); // "John Doe"
```

## Requirements

- PHP 8.1+
- ext-curl

## Install

```shell
composer require simsoft/http-client
```

## Documentation

Full documentation is available at
[sim-soft.github.io/http-client](https://sim-soft.github.io/http-client/).

---

## Table of Contents

### Getting Started

- [Quick Start](#quick-start)
- [Sending Requests](#sending-requests)
- [Request Bodies](#request-bodies)

### Configuration

- [Headers](#headers)
- [Timeouts & cURL Options](#timeouts--curl-options)
- [Authentication](#authentication)

### Responses

- [Status Checks](#status-checks)
- [Reading Data (Dot-notation)](#reading-data)
- [Response Body (Stream)](#response-body)

### File Transfer

- [Uploading Files](#uploading-files)
- [Downloading Files](#downloading-files)

### Resilience

- [Retry](#retry)
- [Middleware](docs/MIDDLEWARE.md)

### Advanced

- [Concurrent Requests (HttpPool)](docs/POOL.md)
- [OAuth2 Authentication](docs/OAUTH2.md)
- [PSR-18 Interoperability](docs/PSR18.md)
- [Custom SDK / Response Classes](docs/CUSTOM_SDK.md)
- [Macro & Mixin](docs/MACRO.md)
- [Testing with FakeHttpClient](docs/TESTING.md)
- [Logging](#logging)
- [Debugging](#debugging)

### Reference

- [Comparison with Other Libraries](docs/COMPARISON.md)

---

## Quick Start

```php
use Simsoft\HttpClient\HttpClient;

$client = HttpClient::make()->withBaseUrl('https://api.example.com');

// GET with query params
$response = $client->get('/users', ['page' => 1, 'limit' => 10]);

// Check status and read JSON
if ($response->ok()) {
    $users = $response->data('data');          // array of users
    $names = $response->data('data.*.name');   // ["John", "Jane"]
}
```

## Sending Requests

```php
$client = HttpClient::make()->withBaseUrl('https://api.example.com');

$response = $client->get('/users');
$response = $client->get('/users', ['status' => 'active']);

$response = $client->post('/users', ['name' => 'Alice']);
$response = $client->put('/users/1', ['name' => 'Bob']);
$response = $client->patch('/users/1', ['email' => 'bob@example.com']);
$response = $client->delete('/users/1');
```

## Request Bodies

```php
$client = HttpClient::make()->withBaseUrl('https://api.example.com');

// JSON (application/json)
$client->withJson(['name' => 'Alice'])->post('/users');
$client->asJson()->post('/users', ['name' => 'Alice']);  // shorthand

// Form URL-encoded (application/x-www-form-urlencoded)
$client->withForm(['email' => 'a@b.com'])->post('/login');
$client->asForm()->post('/login', ['email' => 'a@b.com']);

// Multipart form-data
$client->withMultipart(['field' => 'value'])->post('/upload');
$client->post('/upload', ['field' => 'value']);  // default for POST arrays

// Raw body
$client->withRaw('<xml>data</xml>', 'application/xml')->post('/endpoint');

// Stream body (client takes ownership, closes after request)
$client->withBodyStream(new MyStream(), 'application/pdf')->post('/upload');

// GraphQL
$client->withGraphQL('query { users { name } }', ['limit' => 10])->post('/graphql');
```

## Headers

```php
$response = HttpClient::make()
    ->withBaseUrl('https://api.example.com')
    ->withHeader('X-Custom', 'value')
    ->withHeaders([
        'Accept' => 'application/json',
        'X-App-Version' => '2.0',
    ])
    ->get('/data');
```

## Timeouts & cURL Options

```php
$response = HttpClient::make()
    ->timeout(30)              // execution timeout (seconds)
    ->connectionTimeout(5)     // connection timeout (seconds)
    ->withoutVerifying()       // disable TLS verification (dev only)
    ->verbose()                // enable cURL verbose output
    ->withOptions([            // any cURL constant
        CURLOPT_MAXREDIRS => 3,
    ])
    ->get('https://api.example.com/data');
```

## Authentication

```php
// Bearer token
$client = HttpClient::make()->withBearerToken('YOUR_TOKEN');

// For OAuth2 flows, see docs/OAUTH2.md
```

---

## Status Checks

```php
$response->ok();              // 200
$response->created();         // 201
$response->noContent();       // 204
$response->successful();      // 2xx

$response->badRequest();      // 400
$response->unauthorized();    // 401
$response->forbidden();       // 403
$response->notFound();        // 404
$response->tooManyRequests(); // 429
$response->isClientError();   // 4xx

$response->isServerError();   // 5xx
$response->isNetworkError();  // cURL error (timeout, DNS, etc.)
$response->failed();          // 4xx or 5xx or network error

$response->getStatusCode();   // int
$response->getMessage();      // reason phrase or cURL error
$response->getTotalTime();    // float (seconds)
```

## Reading Data

Access JSON response data using dot-notation with wildcard support:

```php
// Given: {"status": 200, "data": [{"name": "John"}, {"name": "Jane"}]}

$response->data();                // full decoded array
$response->data('status');        // 200
$response->data('data.0.name');   // "John"
$response->data('data.*.name');   // ["John", "Jane"]
$response->data('missing', 'default'); // "default"

$response->json();    // decoded array (same as data())
$response->object();  // decoded as stdClass
$response->toArray(); // decoded array
```

Headers:

```php
$response->getHeaders();                  // all headers
$response->getHeaderLine('Content-Type'); // "application/json"
$response->hasHeader('X-Request-Id');     // bool
```

## Response Body

The body implements `Psr\Http\Message\StreamInterface`:

```php
// Quick access
$raw = $response->body();       // string
$raw = $response->getRaw();     // same
$raw = (string) $response->getBody();

// Stream operations
$body = $response->getBody();
$body->getSize();
$body->getContents();
$body->rewind();

// Chunked reading
while (!$body->eof()) {
    echo $body->read(8192);
}
```

---

## Uploading Files

Single file:

```php
$client = HttpClient::make()->withBaseUrl('https://api.example.com');

// CURLFile (recommended)
$client->attach('file', new CURLFile('path/to/doc.pdf'))->post('/upload');

// From path with custom name and MIME
$client->attach('doc', 'path/to/doc.pdf', 'report.pdf', 'application/pdf')->post('/upload');

// From resource
$client->attach('file', fopen('path/to/doc.pdf', 'r'), 'doc.pdf')->post('/upload');

// From string content
$client->attach('file', 'file content here', 'note.txt', 'text/plain')->post('/upload');
```

Multiple files:

```php
$client->attach('files', [
    new CURLFile('path/to/file1.pdf'),
    new CURLFile('path/to/file2.pdf'),
])->post('/upload');
```

## Downloading Files

```php
// Direct to file (CURLOPT_FILE)
HttpClient::make()->sink('path/to/output.zip')->get('https://example.com/file.zip');

// Stream-based (CURLOPT_WRITEFUNCTION) — for progress tracking or piping
$fp = fopen('path/to/output.zip', 'wb');
HttpClient::make()->sinkStream($fp)->get('https://example.com/file.zip');
fclose($fp);
```

---

## Retry

```php
// Retry 3 times with no delay
$response = HttpClient::make()->retry(3)->get('https://api.example.com/data');

// Retry 3 times, 500ms between attempts
$response = HttpClient::make()->retry(3, after: 500)->get('https://api.example.com/data');
```

Custom retry conditions with `retryWhen()`:

```php
use Simsoft\HttpClient\Response;

$response = HttpClient::make()
    ->retry(4)
    ->retryWhen(function (Response $response, string $method, int $attempt): bool {
        // Retry on 429 with Retry-After header
        if ($response->getStatusCode() === 429) {
            $wait = (int) $response->getHeaderLine('retry-after');
            sleep(max(1, $wait));
            return true;
        }
        return $response->isRetryableNetworkError();
    })
    ->get('https://api.example.com/search');
```

Exponential backoff:

```php
HttpClient::make()
    ->retry(5)
    ->retryWhen(function (Response $response, string $method, int $attempt): bool {
        if (!$response->isServerError() && !$response->isRetryableNetworkError()) {
            return false;
        }
        // 100ms, 200ms, 400ms, 800ms... with ±20% jitter
        $delay = (int) (100 * (2 ** ($attempt - 1)));
        $jitter = (int) ($delay * 0.2);
        usleep(($delay + random_int(-$jitter, $jitter)) * 1000);
        return true;
    })
    ->get('https://api.example.com/reports');
```

## Logging

```php
use Monolog\Logger;

$response = HttpClient::make()
    ->withLogger(new Logger('http'))  // any PSR-3 LoggerInterface
    ->get('https://api.example.com/data');
```

Logs method, URL, status, duration, and errno for every request. Errors are
logged at `error` level automatically.

## Debugging

```php
// dump() — prints request state, then continues execution
$response = HttpClient::make()->dump()->post('https://api.example.com/data', ['foo' => 'bar']);

// dd() — prints request state and exits immediately
HttpClient::make()->dd()->post('https://api.example.com/data', ['foo' => 'bar']);
```

---

## Advanced Topics

| Topic                               | Description                                                                        |
|-------------------------------------|------------------------------------------------------------------------------------|
| [Concurrent Requests](docs/POOL.md) | Execute requests in parallel with HttpPool, sliding window, retries, and callbacks |
| [OAuth2](docs/OAUTH2.md)            | Client credentials, authorization code with PKCE, token caching and refresh        |
| [PSR-18](docs/PSR18.md)             | Use as a drop-in PSR-18 client with any PSR-17 factory                             |
| [Custom SDK](docs/CUSTOM_SDK.md)    | Build typed SDK clients and response classes                                       |
| [Macro & Mixin](docs/MACRO.md)      | Add methods at runtime without subclassing                                         |
| [Middleware](docs/MIDDLEWARE.md)    | Auth injection, caching, circuit breaking, logging, error normalization            |
| [Testing](docs/TESTING.md)          | FakeHttpClient with pattern matching, sequencing, and PHPUnit assertions           |

## License

MIT — see [LICENSE](LICENSE)
