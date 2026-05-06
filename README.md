# Simsoft HttpClient

A fluent, production-grade PHP HTTP client built directly on the `curl_*`
extension.
It combines a zero-dependency core with full PSR compliance — giving you precise
control over cURL behavior while remaining interoperable with any PSR-7/PSR-18
compatible framework or library.

## Prerequisites

- PHP **8.1** or higher
- The `ext-curl` extension (enabled by default in most PHP distributions)
- Composer

## Strengths

- **Zero runtime overhead** — no deep object nesting, no hidden abstraction
  layers.
  The execution path from your call to the cURL handle is direct and auditable.
- **Full PSR compliance** — implements PSR-7 (HTTP messages), PSR-18 (HTTP
  client),
  and supports PSR-17 factories and PSR-3 logging out of the box.
- **Fluent API** — chainable methods read like natural language and require no
  configuration objects or builder classes.
- **Production-ready resilience** — built-in retry with exponential backoff,
  customizable retry conditions, connection reuse, and HTTP/2 support.
- **Precise cURL control** — every cURL option is accessible via
  `withOptions()`,
  buffer size is tunable, DNS cache timeout is configurable, and download
  resumption
  is handled automatically.
- **Memory-efficient streaming** — large uploads and downloads use PSR-7
  `StreamInterface` objects and cURL's native `CURLOPT_READFUNCTION`/
  `CURLOPT_FILE`
  rather than buffering the entire body in memory.
- **Extensible middleware pipeline** — named, ordered middleware closures
  intercept
  both the request and response, enabling auth injection, caching, circuit
  breaking,
  and error normalization without touching core logic.

## Comparison<a id="comparison"></a>

| Feature                       | **Simsoft HttpClient**             | **Guzzle**                                 | **Symfony HttpClient**            | **Laravel HTTP Client** |
|-------------------------------|------------------------------------|--------------------------------------------|-----------------------------------|-------------------------|
| **PHP requirement**           | 8.1+                               | 7.2.5+                                     | 8.2+                              | 8.2+ (framework)        |
| **Dependencies**              | `ext-curl` only                    | `psr/http-*`, `psr/log`, optional adapters | None (native PHP streams or curl) | Wraps Guzzle            |
| **Architecture**              | Single class + traits, direct cURL | Handler stack, middleware, promises        | Contracts + multiple transports   | Facade over Guzzle      |
| **PSR-18**                    | ✅                                  | ✅                                          | ✅ (adapter)                       | ❌ (Guzzle underneath)   |
| **PSR-7**                     | ✅ (response)                       | ✅ (full)                                   | ❌ (own contracts)                 | ❌ (own contracts)       |
| **Transport**                 | cURL directly                      | cURL or stream                             | cURL, stream, amphp               | Guzzle (cURL)           |
| **HTTP/2**                    | ✅ native                           | ✅ via cURL                                 | ✅ native + multiplexing           | ✅ via Guzzle            |
| **Fluent API**                | ✅                                  | ❌ (options array)                          | ✅                                 | ✅                       |
| **Middleware pipeline**       | ✅ named closures                   | ✅ HandlerStack                             | ✅ event listeners                 | ✅ (limited)             |
| **Retry built-in**            | ✅ + custom callback                | Via middleware                             | ✅ RetryableHttpClient             | ✅                       |
| **Async / concurrent**        | ❌                                  | ✅ promises                                 | ✅ native                          | ✅ via Guzzle            |
| **Streaming upload**          | ✅ StreamInterface                  | ✅                                          | ✅                                 | ✅                       |
| **Streaming download**        | ✅ sink / sinkStream                | ✅                                          | ✅                                 | ✅                       |
| **File attachments**          | ✅ CURLFile, path, resource, string | ✅                                          | ✅                                 | ✅                       |
| **Response dot-notation**     | ✅ + wildcards                      | ❌                                          | ❌                                 | ❌                       |
| **Request mocking / testing** | ❌ built-in                         | ✅ MockHandler                              | ✅ MockHttpClient                  | ✅ Http::fake()          |
| **Connection pooling**        | ❌                                  | ✅                                          | ✅                                 | ✅ via Guzzle            |
| **Standalone**                | ✅                                  | ✅                                          | ✅                                 | ❌ requires Laravel      |
| **Install size**              | ⭐ Tiny                             | Medium                                     | Medium                            | Large (framework)       |
| **Memory footprint**          | ⭐ Minimal                          | Moderate                                   | Low                               | Moderate + framework    |
| **Learning curve**            | Low                                | Medium                                     | Medium                            | Low (Laravel only)      |

### Key differentiators

- **Simpler mental model** — One class, trait composition, no handler stacks or
  DI containers. You chain methods and call `get()`/`post()`. No factory setup
  needed.
- **Zero-dependency core** — Only requires ext-curl. Guzzle pulls in 5+
  packages;
  Symfony needs its contracts package; Laravel needs the full framework.
- **Dot-notation response access** — `$response->data('data.users.*.name')` with
  wildcard support. Other clients require manual array traversal or separate
  packages.
- **Direct cURL control** — Every cURL option is accessible without abstraction
  layers. Buffer sizes, DNS cache, download resumption, and HTTP/2 are all
  first-class.

### Trade-offs

- No async/concurrent requests (Guzzle and Symfony both support this)
- No HTTP/2 multiplexing (Symfony excels here)
- No pluggable transports (locked to cURL; Symfony can use native PHP streams,
  amphp, etc.)
- No built-in request mocking (Guzzle, Symfony, and Laravel all provide test
  doubles)
- No connection pooling or persistent connection management

### When to choose each

| Choose                 | When                                                                                                                                                   |
|------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Simsoft HttpClient** | Standalone microservices, CLI tools, or libraries where you want minimal dependencies, full cURL control, and a fluent API without framework overhead. |
| **Guzzle**             | You need async, broad ecosystem support, or are already in a Guzzle-dependent stack.                                                                   |
| **Symfony HttpClient** | You need HTTP/2 multiplexing, async, multiple transport backends, or are in a Symfony project.                                                         |
| **Laravel HTTP**       | You're in Laravel and want the framework's testing fakes and collection integration.                                                                   |

## Usage Guide
1. [Installation](#installation)
2. [Basic Usage](#basic_usage)
3. [Sending Request](#sending_requests)
4. [Post Request](#post_requests)
5. [Set Headers](#set_headers)
6. [Set CURL options](#set_curl_options)
7. [Useful Methods](#useful_methods)
8. [Upload File](#upload)
9. [Download File](#download)
10. [Retry Failed Request](#retry)
11. [Logging](#logging)
12. [Middleware Usage](#middleware)
13. [Response Handling With Dot-notation](#response_handling)
14. [Response Body](#response_body)
15. [Create Custom SDK](docs/CUSTOM_SDK.md)
    1. [Create SDK Client](docs/CUSTOM_SDK.md)
    2. [Create SDK Response](docs/CUSTOM_SDK.md)
16. [PSR-18 Usage](docs/PSR18.md)
17. [Macro](docs/MACRO.md)

## Install<a id="installation"></a>

```shell
composer require simsoft/http-client
```
## Basic Usage<a id="basic_usage"></a>
```php
require "vendor/autoload.php";

use Simsoft\HttpClient\HttpClient;

$response = HttpClient::make()
     ->withBaseUrl('https://api.domain.com/api')
     ->withBearerToken('YOUR_TOKEN')
     ->get('/users', [
        'page' => 1,
        'limit' => 10,
     ]);

echo $response->getStatusCode() . PHP_EOL;

if ($response->ok()) {
    //{"status": 200, "data": [{"name": "John Doe","gender": "m"},{"name": "Jane Doe","gender": "f"}]}
    echo $response->data('status') . PHP_EOL;
    echo $response->data('data.0.name') . PHP_EOL;
    echo $response->data('data.1.name') . PHP_EOL;
} else {
    // {"errors": {"status": 404, "title": "The resource was not found"}}
    echo $response->data('errors.status') . PHP_EOL;
    echo $response->data('errors.title') . PHP_EOL;
}

// Output:
200
John Doe
Jane Doe
```

## Sending Requests<a id="sending_requests"></a>
```php
use Simsoft\HttpClient\HttpClient;

$client = new HttpClient();
$client->withBaseUrl('https://api.domain.com/api');

$response = $client->get('/resource'); // Perform GET request.
$response = $client->get('/resource', ['foo' => 'bar', 'foo1' => 'bar2']); // GET with query params: ?foo=bar&foo1=bar2

$response = $client->put('/resource', ['id' => 1, 'name' => 'updated']); // Perform PUT request.
$response = $client->patch('/resource', ['id' => 1]); // Perform PATCH request.
$response = $client->delete('/resource', ['id' => 2]); // Perform DELETE request.
```

## Post Requests<a id="post_requests"></a>
```php
use Simsoft\HttpClient\HttpClient;

$client = new HttpClient();
$client->withBaseUrl('https://api.domain.com');

$response = $client->withMultipart(['foo' => 'bar', 'baz' => 'qux'])->post('/user'); // Perform form-data post
$response = $client->asMultipart()->post('/user', ['foo' => 'bar', 'baz' => 'qux']); // Perform form-data post
$response = $client->post('/user', ['foo' => 'bar', 'baz' => 'qux']); // Perform form-data post

$response = $client->withForm(['foo' => 'bar', 'baz' => 'qux'])->post('/user'); // Perform x-www-form-urlencoded post
$response = $client->asForm()->post('/user', ['foo' => 'bar', 'baz' => 'qux']); // Perform x-www-form-urlencoded post

$response = $client->withJson(['foo' => 'bar', 'baz' => 'qux'])->post('/user'); // JSON content request
$response = $client->asJson()->post('/user', ['foo' => 'bar', 'baz' => 'qux']); // JSON content request

$response = $client->withRaw('hello world')->post('/user'); // Raw text/plain content request
$response = $client->withRaw('<xml>data</xml>', 'application/xml')->post('/user'); // Raw XML content request
$response = $client->asRaw()->post('/user', 'hello world'); // Raw text/plain content request

// Note: The client takes ownership of the stream and will close it after the request is complete.
// Do not reuse the stream after this call.
// For streams, you want to manage yourself, use withBody() instead.
$response = $client->withBodyStream(new MyStream())->post('/user'); // Post a stream.
$response = $client->withBodyStream(new MyPdfStream(), 'application/pdf')->post('/user'); // Post a PDF stream
$response = $client->withBodyStream(new MyVideoStream(), 'video/mp4')->post('/user'); // Post a video stream.

 // GraphQL request.
$response = $client->withGraphQL('
        query GetUser($id: ID!) {
          user(id: $id) {
            id
            name
          }
        }', ['id' => 123])
        ->post('/resource');
```

## Set Headers<a id="set_headers"></a>
```php
use Simsoft\HttpClient\HttpClient;

$response = HttpClient::make()
    ->withBaseUrl('https://domain.com/api')
    ->withHeader('x-Author', 'John Doe')
    ->withHeaders([
        'Accept' => 'application/json',
        'X-App-Version' => '1.0.0',
    ])
    ->post('/resource', ['foo' => 'bar']);
```

## Set CURL options<a id="set_curl_options"></a>
```php
use Simsoft\HttpClient\HttpClient;

$client = new HttpClient();
$response = $client
    ->withBaseUrl('https://domain.com/api')
    ->withOptions([
        CURLOPT_CONNECTTIMEOUT_MS => 2000, // 2 seconds
        CURLOPT_TIMEOUT_MS => 5000, // 5 seconds
    ])
    ->post('/resource', ['foo' => 'bar']);
```

## Useful methods<a id="useful_methods"></a>

```php
use Simsoft\HttpClient\HttpClient;

$response = HttpClient::make()
    ->withBaseUrl('https://domain.com/api')

    ->withBearerToken('YOUR_TOKEN') // set header Bearer YOUR_TOKEN

    ->timeout(30) // Request timeout in seconds (CURLOPT_TIMEOUT).

    ->connectionTimeout(5) // Connection timeout in seconds (CURLOPT_CONNECTTIMEOUT).

    ->withoutVerifying() // Disable TLS certificates verify.

    ->withoutReturnTransfer() // Disable return transfer.

    ->verbose() // Enable verbose mode

    ->post('/resource', ['foo' => 'bar']);
```

### dump() vs dd() for Debugging

```php
// dd() — dumps current state and immediately exits. Use during development.
HttpClient::make()
    ->withBaseUrl('https://domain.com/api')
    ->withJson(['foo' => 'bar'])
    ->dd()
    ->post('/resource', ['foo' => 'bar']); // triggers execution — dumps full state then exits before request send.

// dump() — dumps state inside the request pipeline after prepareHandle(),
// then continues and completes the request. Use to inspect the fully built state.
$response = HttpClient::make()
    ->withBaseUrl('https://domain.com/api')
    ->dump()
    ->post('/resource', ['foo' => 'bar']); // request still fires
```

## Upload File<a id="upload"></a>

Upload a single file
```php
use Simsoft\HttpClient\HttpClient;

$client = HttpClient::make()->withBaseUrl('https://domain.com/api/upload');

// Attach CURLFile object. (Recommended)
$response = $client->attach('file', new CURLFile('path/to/file.pdf'))->post();

// Note: Upload with a custom filename & MIME type.
// attach (field name, file path|CURLFile, file name, mime type)
// In practice, use only one per request unless your API accepts multiple fields.

// Upload a file from a stream resource
$response = $client->attach('attachment', fopen('path/to/file.pdf', 'r'), 'file.pdf', 'application/pdf')->post();

// Upload a file via a path
$response = $client->attach('document', 'path/to/file.pdf', 'file.pdf', 'application/pdf')->post();

// Upload from string.
$response = $client->attach('file', 'Hello world, file content here', 'note.txt')->post();
```

Upload multiple files.

```php
use Simsoft\HttpClient\HttpClient;

$client = HttpClient::make()->withBaseUrl('https://domain.com/api/upload')

// Upload CURLFile objects. (Recommended)
$response = $client->attach('files', [
        new CURLFile('path/to/file1.pdf'),
        new CURLFile('path/to/file2.pdf'),
    ])->post();

// or upload files from resources
$response = $client->attach('documents', [
        fopen('path/to/file1.pdf', 'r'),
        fopen('path/to/file2.pdf', 'r'),
    ], 'file.pdf')->post();

// or upload files from paths
$response = $client->attach('attachments', [
        'path/to/file1.pdf',
        'path/to/file2.pdf',
    ], 'file.pdf')->post();
```

## Download File<a id="download"></a>

Download a file to disk (uses `CURLOPT_FILE` internally).

```php
use Simsoft\HttpClient\HttpClient;

HttpClient::make()
    ->sink('path/to/file.zip')
    ->get('https://example.com/file.zip');
```

Stream download to a file handle (uses `CURLOPT_WRITEFUNCTION` internally).

```php
use Simsoft\HttpClient\HttpClient;

$fp = fopen('php://output', 'wb');
// or $fp = fopen('path/to/file.zip', 'wb');
HttpClient::make()
    ->sinkStream($fp)
    ->get('https://example.com/file.zip');
fclose($fp);
```

Both methods accept a file path (string) or an open resource handle.

## Retry Failed Request<a id="retry"></a>

> Imports (`use Simsoft\HttpClient\HttpClient`) are omitted in the examples
> below for brevity.

```php
use Simsoft\HttpClient\HttpClient;

$client = new HttpClient();
$client->withBaseUrl('https://domain.com/api/endpoint');

$response = $client->retry(3)->get(); // Retry 3 times. No wait in between attempts.

$response = $client->retry(3, after: 500)->get(); // Retry 3 times, wait 500ms between attempts.
$response = $client->retry(3, after: 2000)->get(); // Retry 3 times, wait 2 seconds between attempts.
// Note: the second argument is in milliseconds.
```

### Using retryWhen() to customize retry logic.

Example: Retry only network-level errors, never double-submit on 5xx.

```php
use Simsoft\HttpClient\Response;

HttpClient::make()
    ->retry(3, after: 500)
    ->retryWhen(function(Response $response, string $method, int $attempt): bool {
        // Only retry network-level failures, never server errors on POST
        return $response->isRetryableNetworkError();
    })
    ->withJson(['order_id' => 123])
    ->post('https://api.example.com/orders');
```

Example: Exponential backoff with jitter

```php
use Simsoft\HttpClient\Response;

HttpClient::make()
    ->retry(5)
    ->retryWhen(function(Response $response, string $method, int $attempt): bool {
        if (!$response->isServerError() && !$response->isRetryableNetworkError()) {
            return false;
        }

        // Exponential backoff: 100ms, 200ms, 400ms, 800ms...
        $delay = (int) (100 * (2 ** ($attempt - 1)));
        // Add jitter ±20% to avoid thundering herd
        $jitter = (int) ($delay * 0.2);
        $sleep = $delay + random_int(-$jitter, $jitter);
        usleep($sleep * 1000);

        return true;
    })
    ->get('https://api.example.com/reports/summary');
```

Example: Retry on specific HTTP status codes (e.g., 429 Too Many Requests)

```php
use Simsoft\HttpClient\Response;

HttpClient::make()
    ->withBaseUrl('https://api.example.com')
    ->retry(4)
    ->retryWhen(function(Response $response, string $method, int $attempt): bool {
        // Respect Retry-After header on 429
        if ($response->getStatusCode() === 429) {
            $retryAfter = (int) $response->getHeaderLine('retry-after');
            sleep(max(1, $retryAfter));
            return true;
        }

        return $response->isRetryableNetworkError();
    })
    ->get('/search');
```

## Logging<a id="logging"></a>

Set logger Psr\Log\LoggerInterface;

```php
use Simsoft\HttpClient\HttpClient;
use Monolog\Logger;

$logger = new Logger('app');

$response = HttpClient::make()
    ->withLogger($logger) // Log with LoggerInterface.
    ->post('https://domain.com/api/endpoint', ['foo' => 'bar']);
```

## Middleware Usage<a id="middleware"></a>

Add middleware to the request pipeline. The middleware must be a callable that
accepts a request instance and a closure and returns a response instance.
More examples can be found in [Middleware Examples](docs/MIDDLEWARE.md)

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

## Response Handling With Dot-notation<a id="response_handling"></a>

```php
use Simsoft\HttpClient\HttpClient;

$response = HttpClient::make()
               ->withBaseUrl('https://domain.com/api')
               ->post('/users', ['foo' => 'bar']);

print_r($response->getHeaders()); // Get all headers.
// output
[
    'content-type' => 'application/json',
    'cache-control' => 'no-cache',
]

echo $response->getHeaderLine('content-type'); // output: application/json
echo $response->getStatusCode(); // output: 200.
echo $response->getTotalTime(); // output: 0.0112 (seconds, e.g. 11.2ms).

if ($response->ok()) {  // Or $response->successful() for 2xx status codes.

    // Output: {"status": 200, "total_records": 2034 "data": [{"name": "John Doe","gender": "m"},{"name": "Jane Doe","gender": "f"}]}
    echo (string) $response->getBody(); // Get raw body

    // Convert to object
    $users = $response->object();
    echo $users->status . PHP_EOL;
    echo $users->total_records . PHP_EOL;
    foreach($users->data as $user) {
        echo $user->name . PHP_EOL;
        echo $user->gender . PHP_EOL;
    }

    //  {"status": 200, "data": [{"name": "John Doe","gender": "m"},{"name": "Jane Doe","gender": "f"}]}
    $data = $response->data(); // Get full decoded array. Equivalent to $response->toArray()
    echo $data['status'] . PHP_EOL;
    echo $data['data'][0]['name'] . PHP_EOL;
    echo $data['data'][1]['name'] . PHP_EOL;

    // Support Dot-notation
    echo $response->data('status') . PHP_EOL;       // 200
    echo $response->data('data.0.name') . PHP_EOL;  // 'John Doe'
    echo $response->data('data.1.name') . PHP_EOL;  // 'Jane Doe'

    // output all names using wildcard.
    foreach($response->data('data.*.name') as $name) {
        echo $name . PHP_EOL;
    }

} elseif ($response->failed()) { // for 4xx or 5xx or network error.

    echo $response->isNetworkError() ? 'Network Error' : 'Not Network Error';
    echo $response->isServerError() ? 'Server Error' : 'Not Server Error';
    echo $response->isClientError() ? 'Client Error' : 'Not Client Error';

    echo $response->getMessage() . PHP_EOL;

    // {"errors": {"status": 404, "title": "The resource was not found"}}
    echo $response->data('errors.status') . PHP_EOL;
    echo $response->data('errors.title') . PHP_EOL;
}
```

## Response Body<a id="response_body"></a>

The response body is an instance of Psr\Http\Message\StreamInterface.

```php
// 3 ways to get a raw body.
$raw = (string) $response->getBody();
$raw = $response->body();
$raw = $response->getRaw();

$body = $response->getBody();
echo $body->getSize(); // Get the size before reading.
echo $body->getContents(); // Read body full contents.

// Rewind the body to the beginning before read again.
$body->rewind();
echo $body->getContents();

// Incrementally read the body.
while (!$body->eof()) {
    echo $body->read(1024);
}
```

## License
The Simsoft HttpClient is licensed under the MIT License. See the [LICENSE](LICENSE) file for details
