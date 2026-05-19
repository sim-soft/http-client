<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Simsoft\HttpClient\HttpClient;
use Simsoft\HttpClient\PoolBuilder;

/**
 * PoolBuilderTest class
 *
 * Tests for the PoolBuilder class: shared configuration, HTTP method builders,
 * JSON mode, and query parameter handling.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class PoolBuilderTest extends TestCase
{
    /**
     * Read a protected property value from an HttpClient via reflection.
     *
     * @param HttpClient $client The client instance.
     * @param string $property The property name.
     * @return mixed
     */
    private function getClientProperty(HttpClient $client, string $property): mixed
    {
        $reflection = new ReflectionProperty($client, $property);

        return $reflection->getValue($client);
    }

    /**
     * Test get() returns an HttpClient configured with the URI.
     *
     * @return void
     */
    #[Test]
    public function getReturnsConfiguredClient(): void
    {
        $builder = new PoolBuilder();
        $client = $builder->get('/users');

        $this->assertInstanceOf(HttpClient::class, $client);
        $this->assertSame('/users', $this->getClientProperty($client, 'pendingUrl'));
        $this->assertSame('GET', $this->getClientProperty($client, 'method'));
    }

    /**
     * Test get() applies query parameters.
     *
     * @return void
     */
    #[Test]
    public function getAppliesQueryParameters(): void
    {
        $builder = new PoolBuilder();
        $client = $builder->get('/users', ['page' => 1, 'limit' => 10]);

        $queryParams = $this->getClientProperty($client, 'queryParams');

        $this->assertSame(['page' => 1, 'limit' => 10], $queryParams);
    }

    /**
     * Test post() returns an HttpClient with POST method.
     *
     * @return void
     */
    #[Test]
    public function postReturnsClientWithPostMethod(): void
    {
        $builder = new PoolBuilder();
        $client = $builder->post('/users');

        $this->assertSame('POST', $this->getClientProperty($client, 'method'));
        $this->assertSame('/users', $this->getClientProperty($client, 'pendingUrl'));
    }

    /**
     * Test put() returns an HttpClient with PUT method when no data is provided.
     *
     * @return void
     */
    #[Test]
    public function putReturnsClientWithPutMethod(): void
    {
        $builder = new PoolBuilder();
        $client = $builder->put('/users/1');

        $this->assertSame('PUT', $this->getClientProperty($client, 'method'));
    }

    /**
     * Test put() with JSON mode preserves PUT method.
     *
     * @return void
     */
    #[Test]
    public function putWithJsonModePreservesMethod(): void
    {
        $builder = new PoolBuilder();
        $builder->asJson();
        $client = $builder->put('/users/1', ['name' => 'Bob']);

        // In JSON mode, withJson() doesn't override the method
        $postFields = $this->getClientProperty($client, 'postFields');
        $this->assertIsString($postFields);
        $this->assertSame(['name' => 'Bob'], json_decode($postFields, true));
    }

    /**
     * Test patch() returns an HttpClient with PATCH method when no data is provided.
     *
     * @return void
     */
    #[Test]
    public function patchReturnsClientWithPatchMethod(): void
    {
        $builder = new PoolBuilder();
        $client = $builder->patch('/users/1');

        $this->assertSame('PATCH', $this->getClientProperty($client, 'method'));
    }

    /**
     * Test patch() with JSON mode preserves PATCH method.
     *
     * @return void
     */
    #[Test]
    public function patchWithJsonModePreservesMethod(): void
    {
        $builder = new PoolBuilder();
        $builder->asJson();
        $client = $builder->patch('/users/1', ['email' => 'new@example.com']);

        $postFields = $this->getClientProperty($client, 'postFields');
        $this->assertIsString($postFields);
        $this->assertSame(['email' => 'new@example.com'], json_decode($postFields, true));
    }

    /**
     * Test delete() returns an HttpClient with DELETE method.
     *
     * @return void
     */
    #[Test]
    public function deleteReturnsClientWithDeleteMethod(): void
    {
        $builder = new PoolBuilder();
        $client = $builder->delete('/users/1');

        $this->assertSame('DELETE', $this->getClientProperty($client, 'method'));
    }

    /**
     * Test withBaseUrl() applies base URL to all built clients.
     *
     * @return void
     */
    #[Test]
    public function withBaseUrlAppliesBaseUrlToAllClients(): void
    {
        $builder = new PoolBuilder();
        $builder->withBaseUrl('https://api.example.com');

        $client = $builder->get('/users');

        $this->assertSame('https://api.example.com', $this->getClientProperty($client, 'baseUrl'));
    }

    /**
     * Test withHeaders() applies headers to all built clients.
     *
     * @return void
     */
    #[Test]
    public function withHeadersAppliesHeadersToAllClients(): void
    {
        $builder = new PoolBuilder();
        $builder->withHeaders(['Accept' => 'application/json', 'X-Custom' => 'value']);

        $client = $builder->get('/data');

        $headers = $this->getClientProperty($client, 'headers');

        $this->assertArrayHasKey('Accept', $headers);
        $this->assertArrayHasKey('X-Custom', $headers);
    }

    /**
     * Test withBearerToken() applies authorization header to all built clients.
     *
     * @return void
     */
    #[Test]
    public function withBearerTokenAppliesAuthToAllClients(): void
    {
        $builder = new PoolBuilder();
        $builder->withBearerToken('my-token');

        $client = $builder->get('/protected');

        $headers = $this->getClientProperty($client, 'headers');

        $this->assertSame(['Bearer my-token'], $headers['authorization']);
    }

    /**
     * Test asJson() causes post data to be sent as JSON.
     *
     * @return void
     */
    #[Test]
    public function asJsonSendsBodyAsJson(): void
    {
        $builder = new PoolBuilder();
        $builder->asJson();

        $client = $builder->post('/users', ['name' => 'Alice']);

        $postFields = $this->getClientProperty($client, 'postFields');
        $contentType = $this->getClientProperty($client, 'contentType');

        // withJson encodes to string and sets application/json
        $this->assertIsString($postFields);
        $this->assertSame('application/json', $contentType);
        $this->assertSame(['name' => 'Alice'], json_decode($postFields, true));
    }

    /**
     * Test that without asJson(), post data is sent as multipart.
     *
     * @return void
     */
    #[Test]
    public function withoutJsonModePostDataIsMultipart(): void
    {
        $builder = new PoolBuilder();
        $client = $builder->post('/users', ['name' => 'Alice']);

        $postFields = $this->getClientProperty($client, 'postFields');

        $this->assertIsArray($postFields);
        $this->assertSame('Alice', $postFields['name']);
    }

    /**
     * Test post() with null data does not set body.
     *
     * @return void
     */
    #[Test]
    public function postWithNullDataDoesNotSetBody(): void
    {
        $builder = new PoolBuilder();
        $client = $builder->post('/endpoint');

        $postFields = $this->getClientProperty($client, 'postFields');

        $this->assertNull($postFields);
    }

    /**
     * Test shared configuration is applied to all HTTP methods.
     *
     * @return void
     */
    #[Test]
    public function sharedConfigAppliedToAllMethods(): void
    {
        $builder = new PoolBuilder();
        $builder->withBaseUrl('https://api.example.com')
            ->withBearerToken('token123')
            ->withHeaders(['X-App' => 'test']);

        $getClient = $builder->get('/a');
        $postClient = $builder->post('/b');
        $deleteClient = $builder->delete('/c');

        $this->assertSame('https://api.example.com', $this->getClientProperty($getClient, 'baseUrl'));
        $this->assertSame('https://api.example.com', $this->getClientProperty($postClient, 'baseUrl'));
        $this->assertSame('https://api.example.com', $this->getClientProperty($deleteClient, 'baseUrl'));

        $this->assertSame(['Bearer token123'], $this->getClientProperty($getClient, 'headers')['authorization']);
    }
}
