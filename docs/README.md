# Simsoft HttpClient

[![Packagist](https://img.shields.io/packagist/v/simsoft/http-client.svg?label=Packagist)](https://packagist.org/packages/simsoft/http-client)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/PHP-%5E8.2-8892BF.svg)](https://www.php.net/)

## Introduction

Simsoft HttpClient is a fluent PHP HTTP client built on `ext-curl` with zero
runtime dependencies. PSR-7/PSR-18 compliant, it supports concurrent requests,
built-in retry, middleware, and test doubles — all in a single lightweight
package.

```php
$response = HttpClient::make()
    ->withBaseUrl('https://api.example.com')
    ->withBearerToken('YOUR_TOKEN')
    ->get('/users', ['page' => 1]);

echo $response->data('data.0.name'); // "John Doe"
```

## Requirements

- PHP 8.2+
- ext-curl

## Install

```shell
composer require simsoft/http-client
```

---

## Table of Contents

### Getting Started

- [Introduction](#introduction)
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
- [Middleware](MIDDLEWARE)

### Advanced

- [Concurrent Requests (HttpPool)](POOL)
- [OAuth2 Authentication](OAUTH2)
- [PSR-18 Interoperability](PSR18)
- [Custom SDK / Response Classes](CUSTOM_SDK)
- [Macro & Mixin](MACRO)
- [Testing with FakeHttpClient](TESTING)
- [Logging](#logging)
- [Debugging](#debugging)

### Reference

- [Comparison with Other Libraries](COMPARISON)

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

Exactly one slash joins the base URL and the path, so a trailing slash
on the base or a missing leading slash on the path make no difference. A path
given as an absolute URL is used as-is, letting a configured client address
another host directly.

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
$client->asMultipart()->post('/upload', ['field' => 'value']);  // shorthand

// Raw body
$client->withRaw('<xml>data</xml>', 'application/xml')->post('/endpoint');
$client->asRaw()->post('/endpoint', 'plain text');  // text/plain

// Stream body (The client takes ownership, closes after request)
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

Header names must be valid RFC 7230 tokens, and values may not contain line
breaks or NUL bytes. Both throw `InvalidArgumentException`, so a user-supplied
value forwarded into a header cannot forge additional headers on the wire.

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

`CURLOPT_TIMEOUT` and `CURLOPT_CONNECTTIMEOUT` may also be passed to
`withOptions()`; they are routed to `timeout()` and `connectionTimeout()`, so
the last call wins whichever form is used. A noninteger value for either
throws `InvalidArgumentException`.

### Tuning the connection

These are optional. The defaults are fine for ordinary API calls, and are worth
changing mainly when moving large files or making many requests to one host:

```php
$response = HttpClient::make()
    ->withBufferSize(131072)   // read buffer in bytes (default 8192)
    ->withDNSTimeout(120)      // seconds to cache a resolved hostname
    ->get('https://api.example.com/large-file');
```

A larger buffer means fewer read calls, which helps when downloading big files;
128 KB (`131072`) is a reasonable choice. `withDNSTimeout()` keeps a resolved
hostname cached, saving a DNS lookup on every request to the same host.

There is also `withoutReturnTransfer()`, which makes cURL write the body
straight to PHP's output instead of returning it. The response body will then
be empty, so use it only when streaming a file directly to the browser — for
saving to disk, prefer `sink()` (see [Downloading Files](#downloading-files)).

## Authentication

```php
// Bearer token
$client = HttpClient::make()->withBearerToken('YOUR_TOKEN');

// The token is connection-scoped: set it once, and it is sent with every
// request made through this client. Headers added with withHeader() are
// per-request and are cleared after each request.
$client->get('/users');    // Authorization: Bearer YOUR_TOKEN
$client->get('/projects'); // Authorization: Bearer YOUR_TOKEN

// Replace the token by calling withBearerToken() again, or remove it:
$client->withoutBearerToken();

// For OAuth2 flows, see docs/OAUTH2.md
```

---

## Status Checks

Rather than comparing `getStatusCode()` yourself, ask the response a question.
Each method below returns a plain `true` or `false`.

```php
$response = HttpClient::make()->get('https://api.example.com/users');

if ($response->successful()) {
    // any 2xx — the usual "did it work?" check
}
```

### Broad checks

Start here. These cover whole ranges and are what most code needs:

```php
$response->successful();     // 2xx — request worked
$response->isRedirect();     // 3xx
$response->isClientError();  // 4xx — you sent something wrong
$response->isServerError();  // 5xx — the server broke
$response->isNetworkError(); // never reached the server (timeout, DNS, refused)
$response->failed();         // 4xx, 5xx, or a network error
$response->hasError();       // identical to failed(), read better in some code
```

`failed()` includes network errors, so it is the safest single check: a request
that timed out has no status code to inspect, and `isServerError()` alone would
report `false` for it.

### Exact status codes

Use these when one specific code changes what your program does — for example,
retrying only on 429, or treating 404 as an empty result rather than an error:

```php
// 2xx
$response->ok();                   // 200
$response->created();              // 201
$response->accepted();             // 202
$response->noContent();            // 204

// 3xx
$response->movedPermanently();     // 301
$response->found();                // 302
$response->notModified();          // 304

// 4xx
$response->badRequest();           // 400
$response->unauthorized();         // 401 — missing or invalid credentials
$response->forbidden();            // 403 — authenticated, but not allowed
$response->notFound();             // 404
$response->methodNotAllowed();     // 405
$response->conflict();             // 409
$response->unprocessableEntity();  // 422 — validation failed
$response->tooManyRequests();      // 429 — rate limited

// 5xx
$response->internalServerError();  // 500
```

The 3xx checks need one extra step. Redirects are followed automatically, so by
the time you get a response it is usually the 200 from the final destination.
To inspect the redirect itself, turn following off:

```php
$response = HttpClient::make()
    ->withOptions([CURLOPT_FOLLOWLOCATION => false])
    ->get('https://example.com/old-page');

$response->isRedirect();                  // true
$response->found();                       // true (302)
$response->getHeaderLine('Location');     // where it points
```

### Other response details

```php
$response->getStatusCode();   // int
$response->getMessage();      // reason phrase, or the cURL error message
$response->getTotalTime();    // float (seconds)
$response->isJson();          // true if the body is JSON
```

`isJson()` checks the `Content-Type` header first and, if that is missing or
unhelpful, looks at the first byte of the body — so it still works against APIs
that return JSON without labelling it.

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

Multiple files under one field name. The parts are named `files[0]`, `files[1]`
and so on, which a server reads as a list:

```php
$client->attach('files', [
    new CURLFile('path/to/file1.pdf'),
    new CURLFile('path/to/file2.pdf'),
])->post('/upload');
```

Calling `attach()` again with the same name appends rather than replaces.

The posted filename defaults to the file's basename, so the local directory is
never sent. Pass a filename explicitly to override it.

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

Without `retryWhen()`, the client decides for you, and the rules are
deliberately cautious:

- A retryable network error (timeout, connection refused, DNS failure) is
  always retried — the request may never have reached the server.
- A 5xx is retried **only for `GET`, `HEAD` and `OPTIONS`**. Repeating a failed
  `POST` could create a second order or charge a card twice, so it is not
  retried automatically. Use `retryWhen()` if your endpoint is safe to repeat.
- A 4xx is never retried. Sending the same bad request again will fail again.
- A request whose body is a non-seekable stream is never retried, because the
  body cannot be rewound and read a second time.

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

| Topic                       | Description                                                                        |
|-----------------------------|------------------------------------------------------------------------------------|
| [Concurrent Requests](POOL) | Execute requests in parallel with HttpPool, sliding window, retries, and callbacks |
| [OAuth2](OAUTH2)            | Client credentials, authorization code with PKCE, token caching and refresh        |
| [PSR-18](PSR18)             | Use as a drop-in PSR-18 client with any PSR-17 factory                             |
| [Custom SDK](CUSTOM_SDK)    | Build typed SDK clients and response classes                                       |
| [Macro & Mixin](MACRO)      | Add methods at runtime without subclassing                                         |
| [Middleware](MIDDLEWARE)    | Auth injection, caching, circuit breaking, logging, error normalization            |
| [Testing](TESTING)          | FakeHttpClient with pattern matching, sequencing, and PHPUnit assertions           |

## Changelog

See [CHANGELOG.md](https://github.com/sim-soft/http-client/blob/master/CHANGELOG.md).

## Security

Security-relevant defaults and how to report a vulnerability privately are
documented in
[SECURITY.md](https://github.com/sim-soft/http-client/blob/master/SECURITY.md).
Please do not open a public issue for a security problem.

## License

MIT
