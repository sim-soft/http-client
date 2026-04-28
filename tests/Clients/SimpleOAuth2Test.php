<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests\Clients;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Simsoft\HttpClient\Clients\Responses\SimpleOAuth2Response;
use Simsoft\HttpClient\Clients\SimpleOAuth2;
use Simsoft\HttpClient\Interfaces\StorageInterface;

/**
 * ConcreteSimpleOAuth2 class
 *
 * Concrete test subclass extending SimpleOAuth2 with a controllable postRequest().
 */
class ConcreteSimpleOAuth2 extends SimpleOAuth2
{
    /** @var SimpleOAuth2Response|null The response to return from postRequest(). */
    public ?SimpleOAuth2Response $mockResponse = null;

    /**
     * Execute the token request using the pre-configured mock response.
     *
     * @return SimpleOAuth2Response
     */
    protected function postRequest(): SimpleOAuth2Response
    {
        /** @var SimpleOAuth2Response $response */
        $response = $this->mockResponse;
        return $response;
    }
}

/**
 * SimpleOAuth2Test class
 *
 * Tests for the SimpleOAuth2 abstract class: factory method,
 * cached token retrieval, expired token refresh, and error handling.
 */
class SimpleOAuth2Test extends TestCase
{
    /** @var StorageInterface&MockObject Mock storage for token persistence. */
    private StorageInterface&MockObject $storage;

    /** @var string Test client ID. */
    private string $clientId = 'simple-client-id';

    /** @var string Test client secret. */
    private string $clientSecret = 'simple-client-secret';

    /**
     * Set up mock storage before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->storage = $this->createMock(StorageInterface::class);
    }

    /**
     * Create a ConcreteSimpleOAuth2 instance with test credentials and mock storage.
     *
     * @return ConcreteSimpleOAuth2
     */
    private function createInstance(): ConcreteSimpleOAuth2
    {
        return new ConcreteSimpleOAuth2(
            $this->clientId,
            $this->clientSecret,
            $this->storage,
        );
    }

    /**
     * Test that makeWith() creates an instance with correct credentials.
     *
     * @return void
     */
    #[Test]
    public function makeWithCreatesInstanceWithCorrectCredentials(): void
    {
        $instance = $this->createInstance();

        $this->assertInstanceOf(ConcreteSimpleOAuth2::class, $instance);

        $clientIdProperty = new ReflectionProperty(SimpleOAuth2::class, 'clientId');
        $this->assertSame($this->clientId, $clientIdProperty->getValue($instance));

        $clientSecretProperty = new ReflectionProperty(SimpleOAuth2::class, 'clientSecret');
        $this->assertSame($this->clientSecret, $clientSecretProperty->getValue($instance));
    }

    /**
     * Test that getAccessToken() returns cached non-expired token.
     *
     * @return void
     */
    #[Test]
    public function getAccessTokenReturnsCachedNonExpiredToken(): void
    {
        $cachedResponse = $this->createMock(SimpleOAuth2Response::class);
        $cachedResponse->method('hasExpired')->willReturn(false);
        $cachedResponse->method('getToken')->willReturn('cached-simple-token');

        $this->storage->method('has')
            ->with($this->clientId)
            ->willReturn(true);

        $this->storage->method('get')
            ->with($this->clientId)
            ->willReturn($cachedResponse);

        $instance = $this->createInstance();

        $result = $instance->getAccessToken();

        $this->assertSame('cached-simple-token', $result);
    }

    /**
     * Test that an expired token triggers a new postRequest().
     *
     * @return void
     */
    #[Test]
    public function expiredTokenTriggersNewPostRequest(): void
    {
        $expiredResponse = $this->createMock(SimpleOAuth2Response::class);
        $expiredResponse->method('hasExpired')->willReturn(true);

        $this->storage->method('has')
            ->with($this->clientId)
            ->willReturnOnConsecutiveCalls(true, false);

        $this->storage->method('get')
            ->with($this->clientId)
            ->willReturn($expiredResponse);

        $newResponse = new SimpleOAuth2Response(
            curlInfo: ['http_code' => 200],
            body: json_encode([
                'access_token' => 'new-simple-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ]) ?: '',
        );

        $instance = $this->createInstance();
        $instance->mockResponse = $newResponse;

        $this->storage->expects($this->once())
            ->method('remove')
            ->with($this->clientId);

        $this->storage->expects($this->once())
            ->method('set')
            ->with($this->clientId, $newResponse);

        $result = $instance->getAccessToken();

        $this->assertSame('new-simple-token', $result);
    }

    /**
     * Test that postRequest() returning non-200 makes getAccessToken() return null.
     *
     * @return void
     */
    #[Test]
    public function nonOkPostRequestReturnsNull(): void
    {
        $this->storage->method('has')
            ->with($this->clientId)
            ->willReturn(false);

        $errorResponse = new SimpleOAuth2Response(
            curlInfo: ['http_code' => 401],
            body: json_encode(['error' => 'invalid_client']) ?: '',
            message: 'Unauthorized',
        );

        $instance = $this->createInstance();
        $instance->mockResponse = $errorResponse;

        $result = @$instance->getAccessToken();

        $this->assertNull($result);
    }
}
