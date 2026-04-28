<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests\Clients;

use InvalidArgumentException;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessTokenInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Simsoft\HttpClient\Clients\OAuth2;
use Simsoft\HttpClient\Interfaces\StorageInterface;

/**
 * TestableOAuth2 class
 *
 * Concrete subclass of OAuth2 with configurable endpoints for testing.
 */
class TestableOAuth2 extends OAuth2
{
    /** @var string Production access token endpoint. */
    protected string $accessTokenEndpoint = 'https://api.example.com/oauth/token';

    /** @var string Sandbox access token endpoint. */
    protected string $sandboxEndpoint = 'https://sandbox.example.com/oauth/token';
}

/**
 * OAuth2Test class
 *
 * Tests for the OAuth2 client: factory method, sandbox switching,
 * token lifecycle, refresh logic, and error handling.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class OAuth2Test extends TestCase
{
    /** @var StorageInterface&MockObject Mock storage for token persistence. */
    private StorageInterface&MockObject $storage;

    /** @var string Test client ID. */
    private string $clientId = 'test-client-id';

    /** @var string Test client secret. */
    private string $clientSecret = 'test-client-secret';

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
     * Create a TestableOAuth2 instance with test credentials and mock storage.
     *
     * @return TestableOAuth2
     */
    private function createInstance(): TestableOAuth2
    {
        return new TestableOAuth2(
            $this->clientId,
            $this->clientSecret,
            $this->storage,
        );
    }

    /**
     * Test that request() factory returns a new OAuth2 instance with correct credentials.
     *
     * @return void
     */
    #[Test]
    public function requestFactoryReturnsInstanceWithCorrectCredentials(): void
    {
        $instance = $this->createInstance();

        $this->assertInstanceOf(TestableOAuth2::class, $instance);

        $clientIdProperty = new ReflectionProperty(OAuth2::class, 'clientId');
        $this->assertSame($this->clientId, $clientIdProperty->getValue($instance));

        $clientSecretProperty = new ReflectionProperty(OAuth2::class, 'clientSecret');
        $this->assertSame($this->clientSecret, $clientSecretProperty->getValue($instance));
    }

    /**
     * Test that sandbox() switches the endpoint to the sandbox URL.
     *
     * @return void
     */
    #[Test]
    public function sandboxSwitchesEndpoint(): void
    {
        $instance = $this->createInstance();

        $returned = $instance->sandbox();

        $this->assertSame($instance, $returned);
        $this->assertSame(
            'https://sandbox.example.com/oauth/token',
            $instance->getEndpoint(),
        );
    }

    /**
     * Test that getEndpoint() returns the production endpoint by default
     * and sandbox endpoint after sandbox() is called.
     *
     * @return void
     */
    #[Test]
    public function getEndpointReturnsCorrectEndpointBasedOnMode(): void
    {
        $instance = $this->createInstance();

        $this->assertSame(
            'https://api.example.com/oauth/token',
            $instance->getEndpoint(),
        );

        $instance->sandbox();

        $this->assertSame(
            'https://sandbox.example.com/oauth/token',
            $instance->getEndpoint(),
        );
    }

    /**
     * Test that getAccessToken() returns cached token when valid and not expired.
     *
     * @return void
     */
    #[Test]
    public function getAccessTokenReturnsCachedTokenWhenValidAndNotExpired(): void
    {
        $token = $this->createMock(AccessTokenInterface::class);
        $token->method('hasExpired')->willReturn(false);
        $token->method('getToken')->willReturn('cached-access-token');

        $this->storage->method('has')
            ->with($this->clientId)
            ->willReturn(true);

        $this->storage->method('get')
            ->with($this->clientId)
            ->willReturn($token);

        $instance = $this->createInstance();

        $result = $instance->getAccessToken();

        $this->assertSame('cached-access-token', $result);
    }

    /**
     * Test that an expired token with a refresh token triggers refreshToken().
     *
     * @return void
     */
    #[Test]
    public function expiredTokenWithRefreshTokenTriggersRefresh(): void
    {
        $expiredToken = $this->createMock(AccessTokenInterface::class);
        $expiredToken->method('hasExpired')->willReturn(true);
        $expiredToken->method('getRefreshToken')->willReturn('refresh-token-value');

        $newToken = $this->createMock(AccessTokenInterface::class);
        $newToken->method('getToken')->willReturn('new-access-token');

        $this->storage->method('has')
            ->with($this->clientId)
            ->willReturn(true);

        $this->storage->method('get')
            ->with($this->clientId)
            ->willReturn($expiredToken);

        $this->storage->expects($this->once())
            ->method('set')
            ->with($this->clientId, $newToken);

        $mockProvider = $this->createMock(GenericProvider::class);
        $mockProvider->expects($this->once())
            ->method('getAccessToken')
            ->with('refresh_token', ['refresh_token' => 'refresh-token-value'])
            ->willReturn($newToken);

        $instance = $this->createInstance();

        $providerProperty = new ReflectionProperty(OAuth2::class, 'provider');
        $providerProperty->setValue($instance, $mockProvider);

        $result = $instance->getAccessToken();

        $this->assertSame('new-access-token', $result);
    }

    /**
     * Test that refreshToken() without a refresh token throws InvalidArgumentException.
     *
     * @return void
     */
    #[Test]
    public function refreshTokenWithoutRefreshTokenThrowsException(): void
    {
        $token = $this->createMock(AccessTokenInterface::class);
        $token->method('getRefreshToken')->willReturn(null);

        $instance = $this->createInstance();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot refresh token');

        $instance->refreshToken($token);
    }

    /**
     * Test that token acquisition failure returns null.
     *
     * @return void
     */
    #[Test]
    public function tokenAcquisitionFailureReturnsNull(): void
    {
        $this->storage->method('has')
            ->with($this->clientId)
            ->willReturn(false);

        $mockProvider = $this->createMock(GenericProvider::class);
        $mockProvider->method('getAccessToken')
            ->willThrowException(new \RuntimeException('Provider error'));

        $instance = $this->createInstance();

        $providerProperty = new ReflectionProperty(OAuth2::class, 'provider');
        $providerProperty->setValue($instance, $mockProvider);

        $result = @$instance->getAccessToken();

        $this->assertNull($result);
    }
}
