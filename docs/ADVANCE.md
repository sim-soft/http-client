# Advance Usage

Create your own HttpClient and Response object.

## Create Custom API Client

Create your own sets of SDK client libraries.

```php
namespace App;

use Simsoft\HttpClient\HttpClient;

class MyCustomClient extends HttpClient
{
    protected string $profileEndpoint = 'https://api.domain.com/profile';

    protected string $updateEndpoint = 'https://api.domain.com/update';

    /**
    * Constructor
    *
    * @param string $clientId
    * @param string $secret
    */
    public function __construct(protected string $clientId, protected string $secret)
    {
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
    public function getUserProfile(int $id)
    {
        return $this
            ->withBaseUri($this->profileEndpoint)
            ->get(['userId' => $id]);
    }

    /**
    * Update user profile.
    *
    * @param int $id User id
    * @param array $post
    * @return mixed
    */
    public function updateProfile(int $id, array $post)
    {
        return $this
            ->withBaseUri($this->updateEndpoint)
            ->query(['userId' => $id])
            ->formData($post)
            ->post();
    }

}

$client = new MyCustomClient({CLIENT_ID}, {CLIENT_SECRET});
$response = $client->getUserProfile(99);

echo $response->getAttribute('data.name');
// Output:
John Doe.

$response = $client->updateProfile(1, ['name' => 'John Wayne']);

if ($response->ok()) {
    echo 'Update profile successful.';
} else {
    echo 'Update profile failed.';
}

```

## Create Custom Response

```php
namespace App\HttpClient;

use Simsoft\HttpClient\Response;

class MyCustomResponse extends Response
{
    public function getName(): ?string
    {
        return $this->getAttribute('data.name', 'Anonymous');
    }

    public function getAge(): ?int
    {
        return $this->getAttribute('data.profile.age')
    }
}
```
Set the `$responseClass` property of the HttpClient to use the custom response.

```php
namespace App\HttpClient;

use App\HttpClient\MyCustomResponse;
use Simsoft\HttpClient\HttpClient;

class MyCustomClient extends HttpClient
{
    protected string $endpoint = 'https://api.domain.com';

    /** @inheritdoc  */
    protected string $responseClass = MyCustomResponse::class;

}
```
Or set the response class via public method.

```php
$client = new MyCustomClient({CLIENT_ID}, {CLIENT_SECRET});

$client->withResponseClass(MyCustomResponse::class);

$response = $client->getUserProfile(99);

echo $response->getName();
echo $response->getAge();

```
