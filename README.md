# Introduction

A simple CURL HTTP client implementation.

## Install

```shell
composer require simsoft/http-client
```

## Basic Setup

```php
require "vendor/autoload.php";

use Simsoft\HttpClient\HttpClient;

$response = (new HttpClient())
     ->withBaseUri('https://domain.com/api')
     ->withMethod('GET')
     ->query(['foo' => 'bar'])
     ->withHeaders(['Authorization' => 'Bearer secret_token'])
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

## Custom Http Client

```php
namespace App;

use Simsoft\HttpClient\HttpClient;

class MyCustomClient extends HttpClient
{
    protected string $endpoint = 'https://api.domain.com';

    /**
    * Constructor
    *
    * @param string $clientId
    * @param string $secret
    */
    public function __construct(protected string $clientId, protected string $secret)
    {

    }

    /**
    * @param int $id
    * @return mixed
    */
    public function getUserProfile(int $id)
    {
        return $this
            ->withBaseUri($this->endpoint)
            ->withHeaders([
                'clientId' => $this->clientId,
                'secret' => $this->secret,
            ])
            ->get(['userId' => $id]);
    }
}


$client = new MyCustomClient(CLIENT_ID, CLIENT_SECRET);
$response = $client->getUserProfile(99);

echo $response->getAttribute('name');

// Output:
John Doe.
```

## License
The Simsoft HttpClient is licensed under the MIT License. See the [LICENSE](LICENSE) file for details
