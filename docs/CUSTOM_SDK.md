# Create Custom SDK with Simsoft\HttpClient

Create your own HttpClient and Response object.

## Create a Custom SDK Client

Create your own sets of SDK client libraries.

```php
namespace App;

use Simsoft\HttpClient\HttpClient;
use Simsoft\HttpClient\Response;


class MySDKClient extends HttpClient
{
    /**
    * Constructor
    *
    * @param string $clientId
    * @param string $secret
    */
    public function __construct(protected string $clientId, protected string $secret)
    {
        parent::__construct();
        $this->withBaseUrl('https://api.domain.com');
        $this->withHeaders([
            'clientId' => $this->clientId,
            'secret' => $this->secret,
        ]);
    }

    /**
    * Get user profile.
    *
    * @param int $id User Id.
    * @return Response
    */
    public function getUserProfile(int $id): Response
    {
        return $this->get('/profile', ['userId' => $id]);
    }

    /**
    * Update user profile.
    *
    * @param int $id User id
    * @param array $post
    * @return Response
    */
    public function updateProfile(int $id, array $post): Response
    {
        return $this
            ->withQuery(['userId' => $id])
            ->post('/user/update', $post);
    }
}
```

### Usage Demo

```php
use App\MySDKClient;

$client = new MySDKClient('your-client-id', 'your-client-secret');

$response = $client->getUserProfile(99);

// Assuming JSON response: {"data": {"name": "John Wick", "age": 30}}

echo $response->data('data.name'); // outputs: John Wick
echo $response->data('data.age'); // outputs: 30

$response = $client->updateProfile(1, ['name' => 'John Wayne']);
if ($response->ok()) {
    echo 'Update profile successful.';
} else {
    echo 'Update profile failed.';
}
```

## Create Custom SDK Response

Create a custom response object for the SDK client.
```php
namespace App;

use Simsoft\HttpClient\Response;

class MySDKResponse extends Response
{
    public function getName(): ?string
    {
        return $this->data('data.name', 'Anonymous'); // Default value
    }

    public function getAge(): ?int
    {
        return $this->data('data.age');
    }

    public function isAdult(): bool
    {
        $age = $this->getAge();
        return $age !== null && $age >= 18;
    }
}
```

Modify the App\MySDKClient by set the `$responseClass` property to use the
custom SDK response.

```php
namespace App;

use App\MySDKResponse;
use Simsoft\HttpClient\HttpClient;

class MySDKClient extends HttpClient
{
    /*.. other code ...*/

    /** @inheritdoc  */
    protected string $responseClass = MySDKResponse::class;

    /*.. other code ...*/

   /**
    * Get user profile.
    *
    * @param int $id User Id.
    * @return MySDKResponse
    */
    public function getUserProfile(int $id): MySDKResponse
    {
        return $this->get('/profile', ['userId' => $id]);
    }

   /**
    * Update user profile.
    *
    * @param int $id User id
    * @param array $post
    * @return MySDKResponse
    */
    public function updateProfile(int $id, array $post): MySDKResponse
    {
        return $this
            ->withQuery(['userId' => $id])
            ->post('/user/update', $post);
    }
}
```

### Finally: Usage demo

By using the modified SDK client.

```php
use App\MySDKClient;

$client = new MySDKClient('your-client-id', 'your-client-secret');

$response = $client->getUserProfile(99);
echo $response->getName();
echo $response->getAge();
echo $response->isAdult() ? 'Is adult': 'Is not adult';
echo $response->data('data.name'); // You still can access the attribute via the data() method.

$response = $client->updateProfile(1, ['name' => 'John Wayne']);
if ($response->ok()) {
    echo 'Update profile successful.';
} else {
    echo 'Update profile failed.';
}
```

## Alternatively, set the response class at runtime.

```php
use App\MySDKClient;
use App\MySDKResponse;

$client = new MySDKClient('your-client-id', 'your-client-secret');
$client->withResponseClass(MySDKResponse::class);

/** @var MySDKResponse $response */
$response = $client->getUserProfile(99);
echo $response->getName();
echo $response->getAge();
echo $response->isAdult() ? 'Is adult': 'Is not adult';
```

Or without the custom SDK client.

```php
use Simsoft\HttpClient\HttpClient;
use App\MySDKResponse;

/** @var MySDKResponse $response */
$response = HttpClient::make()
    ->withBaseUrl('https://api.domain.com')
    ->withHeaders([
        'clientId' => 'your-client-id',
        'secret' => 'your-client-secret',
    ])
    ->withResponseClass(MySDKResponse::class)
    ->get('/profile', ['userId' => 99]);

echo $response->getName();
echo $response->getAge();
echo $response->isAdult() ? 'Is adult': 'Is not adult';
```
