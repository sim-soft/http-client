# Introduction

This is a simple CURL HTTP client implementation. For advance HTTP client, suggest [guzzlehttp/guzzle](https://docs.guzzlephp.org/) or [symfony/http-client](https://packagist.org/packages/symfony/http-client)

1. [Installation](#installation)
2. [Basic Usage](#basic_usage)
3. [Sending Request](#sending_requests)
4. [Post Request](#post_requests)
5. [Set Headers](#set_headers)
6. [Set CURL options](#set_curl_options)
7. [Response Object](#response_object)
8. [Advance Usage](docs/ADVANCE.md)
   1. [Create Custom API Client](docs/ADVANCE.md)
   1. [Create Custom Response](docs/ADVANCE.md)

## Install<a id="installation"></a>

```shell
composer require simsoft/http-client
```
## Basic Usage<a id="basic_usage"></a>
```php
require "vendor/autoload.php";

use Simsoft\HttpClient\HttpClient;

$response = (new HttpClient())
     ->withBaseUri('https://domain.com/api/endpoint')
     ->withMethod('GET')
     ->withHeaders(['Authorization' => 'Bearer secret_token'])
     ->query(['foo' => 'bar'])
     ->request();

if ($response->ok()) {
    //{"status": 200, "data": [{"name": "John Doe","gender": "m"},{"name": "Jane Doe","gender": "f"}]}
    echo $response->getAttribute('status') . PHP_EOL;
    echo $response->getAttribute('data.0.name') . PHP_EOL;
    echo $response->getAttribute('data.1.name') . PHP_EOL;
} else {
    // {"errors": {"status": 404, "title": "The resource was not found"}}
    echo $response->getAttribute('errors.status') . PHP_EOL;
    echo $response->getAttribute('errors.title') . PHP_EOL;
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
$client->withBaseUri('https://domain.com/api/endpoint');

$response = $client->get(); // Perform GET request.
$response = $client->get(['foo' => 'bar', 'foo1' => 'bar2']); // Perform GET request with query params. ?foo=bar&foo1=bar2

$response = $client->patch(); // Perform PATCH request.
$response = $client->delete(); // Perform DELETE request.
```

## Post requests<a id="post_requests"></a>
```php
use Simsoft\HttpClient\HttpClient;

$client = new HttpClient();
$client->withBaseUri('https://domain.com/api/endpoint');

$response = $client->formData(['foo' => 'bar'])->post(); // Perform form-data post

$response = $client->urlEncoded(['foo' => 'bar'])->post(); // Perform x-www-form-urlencoded post

$response = $client->raw(json_encode(['foo' => 'bar']))->post(); // Perform raw content post

$response = $client->graphQL('{
   users {
    id
    name
   }
}')->post(); // Perform GraphQL post.
```
## Set Headers<a id="set_headers"></a>
```php
use Simsoft\HttpClient\HttpClient;

$client = new HttpClient();
$client
    ->withBaseUri('https://domain.com/api/endpoint');
    ->withHeaders([
        'Authorization' => 'Bearer {{secret_token}}',
        'Content-Type' => 'application/json',
    ]);

$response = $client->formData(['foo' => 'bar'])->post();
```

## Set CURL options<a id="set_curl_options"></a>
```php
use Simsoft\HttpClient\HttpClient;

$client = new HttpClient();
$client
    ->withBaseUri('https://domain.com/api/endpoint');
    ->withOptions([
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

$response = $client->formData(['foo' => 'bar'])->post();
```

## Response Object<a id="response_object"></a>
```php
use Simsoft\HttpClient\HttpClient;

$response = $client = new HttpClient()
               ->withBaseUri('https://domain.com/api/endpoint')
               ->post(['foo' => 'bar']);

print_r($response->getHeaders());
// output
[
    'Content-Type' => 'application/json',
    'Cache-Control' => 'no-cache',
]

echo $response->getHeaderLine('Content-Type'); // output: application/json
echo $response->getStatusCode(); // output: 200.
echo $response->getTotalTime(); // output: 0.0112 micro seconds.

if ($response->ok()) {
    echo $response->getAttribute('data');

    //{"status": 200, "data": [{"name": "John Doe","gender": "m"},{"name": "Jane Doe","gender": "f"}]}
    echo $response->getAttribute('status') . PHP_EOL;       // 200
    echo $response->getAttribute('data.0.name') . PHP_EOL;  // 'John Doe'
    echo $response->getAttribute('data.1.name') . PHP_EOL;  // 'Jane Doe'

    // output all names.
    foreach($response->getAttribute('data.*.name') as $name) {
        echo $name . PHP_EOL;
    }

} elseif ($response->hasError()) {
    echo $response->getMessage() . PHP_EOL;

    // {"errors": {"status": 404, "title": "The resource was not found"}}
    echo $response->getAttribute('errors.status') . PHP_EOL;
    echo $response->getAttribute('errors.title') . PHP_EOL;
}

```

## License
The Simsoft HttpClient is licensed under the MIT License. See the [LICENSE](LICENSE) file for details
