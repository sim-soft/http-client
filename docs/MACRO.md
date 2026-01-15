# Macro

Add additional methods to Simsoft\HttpClient\HttpClient.

### Example:

```php
<?php
require_once 'vendor/autoload.php';

use Simsoft\HttpClient\HttpClient;

// Add bearerAuth method to HttpClient
HttpClient::macro('bearerAuth', function(string $token) {
    return $this->withHeaders([
        'Authorization' => "Bearer $token",
    ]);
});

// Add connectionTimeout method to HttpClient.
HttpClient::macro('connectionTimeout', function (int $timeout) {
    $this->options[CURLOPT_CONNECTTIMEOUT] = $timeout;
    return $this;
});

$client = new HttpClient();
$response = $client
    ->withBaseUri('https://domain.com/api/endpoint');
    ->bearerAuth('YOUR_SECRET_TOKEN')  // Using macro method bearerAuth()
    ->connectionTimeout(5); // Using macro method connectionTimeout 5 seconds
    ->formData(['foo' => 'bar'])->post();
```
