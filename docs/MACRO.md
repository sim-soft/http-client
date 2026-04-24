# Macro

Add additional methods to Simsoft\HttpClient\HttpClient.

### Example:

```php
<?php
require_once 'vendor/autoload.php';

use Simsoft\HttpClient\HttpClient;

// Add withToken method to HttpClient
HttpClient::macro('withToken', function(string $token) {
    return $this->withHeaders([
        'Authorization' => "Bearer $token",
    ]);
});

// Add the connectionTimeout method to HttpClient.
HttpClient::macro('connectionTimeoutMS', function (int $timeout) {
    return $this->withOptions([CURLOPT_CONNECTTIMEOUT_MS => $timeout]);
});

$response = HttpClient::make()
    ->withToken('SECRET_TOKEN')  // Using macro method withToken()
    ->connectionTimeoutMS(2000) // 2000 milliseconds = 2 seconds connection timeout.
    ->post('https://domain.com/api/endpoint', ['foo' => 'bar']);
```
