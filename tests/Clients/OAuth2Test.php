<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests\Clients;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Simsoft\HttpClient\Clients\OAuth2;
use Simsoft\HttpClient\Clients\Responses\OAuth2TokenResponse;
use Simsoft\HttpClient\Clients\TokenData;
use Simsoft\HttpClient\Interfaces\StorageInterface;

/**
 * TestOAuth2 class.
 *
 * Concrete test subclass of OAuth2 with configurable endpoints and
 * overridable buildTokenRequest() for controlled testing without HTTP calls.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TestOAuth2 extends OAuth2
{
    /** @var string Production access token endpoint. */
    protected string $accessTokenEndpoint = 'https://example.com/oauth/token';

    /** @var string Sandbox access token endpoint. */
    protected string $sandboxEndpoint = 'https://sandbox.example.com/oauth/token';

    /** @var OAuth2TokenResponse|null Response to return from buildTokenRequest(). */
    public ?OAuth2TokenResponse $nextResponse = null;

    /** @var \Throwable|null Exception to throw from buildTokenRequest(). */
    public ?\Throwable $nextException = null;

    /** @var array<int, array<string, string>> Captured request params. */
    public array $capturedParams = [];

    /** @var int Count of buildTokenRequest() calls. */
    public int $requestCount = 0;

    /**
     * Override buildTokenRequest to return controlled responses.
     *
     * @param array<string, string> $params Form parameters.
     * @return OAuth2TokenResponse
     */
    protected function buildTokenRequest(array $params): OAuth2TokenResponse
    {
        $this->capturedParams[] = $params;
        $this->requestCount++;

        if ($this->nextException !== null) {
            throw $this->nextException;
        }

        if ($this->nextResponse !== null) {
            return $this->nextResponse;
        }

        return self::createTokenResponse(200, [
            'access_token' => 'default-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]);
    }

    /**
     * Create an OAuth2TokenResponse with given status and body data.
     *
     * @param int $statusCode HTTP status code.
     * @param array<string, mixed> $data Response body data.
     * @return OAuth2TokenResponse
     */
    public static function createTokenResponse(int $statusCode, array $data): OAuth2TokenResponse
    {
        return new OAuth2TokenResponse(
            curlInfo: ['http_code' => $statusCode],
            body: json_encode($data, JSON_THROW_ON_ERROR),
        );
    }
}

/**
 * TestOAuth2WithScope class.
 *
 * Subclass with a configured scope for testing scope inclusion.
 */
class TestOAuth2WithScope extends TestOAuth2
{
    /** @var string|null OAuth2 scope. */
    protected ?string $scope = 'read write';
}

/**
 * OAuth2Test class.
 *
 * Tests for the standalone OAuth2 client: factory method, sandbox switching,
 * endpoint resolution, token lifecycle, refresh logic, and error handling.
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
     * Create a TestOAuth2 instance with test credentials and mock storage.
     *
     * @return TestOAuth2
     */
    private function createInstance(): TestOAuth2
    {
        return new TestOAuth2(
            $this->clientId,
            $this->clientSecret,
            $this->storage,
        );
    }

    #[Test]
    public function requestFactoryReturnsCorrectInstance(): void
    {
        $instance = TestOAuth2::request(
            $this->clientId,
            $this->clientSecret,
            $this->storage,
        );

        $this->assertInstanceOf(TestOAuth2::class, $instance);
    }

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

    #[Test]
    public function getEndpointReturnsProductionUrlByDefault(): void
    {
        $instance = $this->createInstance();

        $this->assertSame(
            'https://example.com/oauth/token',
            $instance->getEndpoint(),
        );
    }

    #[Test]
    public function getEndpointReturnsSandboxUrlAfterSandboxCall(): void
    {
        $instance = $this->createInstance();
        $instance->sandbox();

        $this->assertSame(
            'https://sandbox.example.com/oauth/token',
            $instance->getEndpoint(),
        );
    }

    #[Test]
    public function defaultGrantTypeIsClientCredentials(): void
    {
        $this->storage->method('has')->willReturn(false);
        $this->storage->method('set');

        $instance = $this->createInstance();
        $instance->getAccessToken();

        $this->assertNotEmpty($instance->capturedParams);
        $this->assertSame('client_credentials', $instance->capturedParams[0]['grant_type']);
    }

    #[Test]
    public function scopeIsIncludedInRequestWhenConfigured(): void
    {
        $this->storage->method('has')->willReturn(false);
        $this->storage->method('set');

        $instance = new TestOAuth2WithScope(
            $this->clientId,
            $this->clientSecret,
            $this->storage,
        );

        $instance->getAccessToken();

        $this->assertNotEmpty($instance->capturedParams);
        $this->assertSame('read write', $instance->capturedParams[0]['scope']);
    }

    #[Test]
    public function cachedNonExpiredTokenIsReturnedWithoutHttpCall(): void
    {
        $cachedToken = new TokenData(
            accessToken: 'cached-access-token',
            expiresAt: time() + 3600,
            refreshToken: null,
        );

        $this->storage->method('has')
            ->with($this->clientId)
            ->willReturn(true);

        $this->storage->method('get')
            ->with($this->clientId)
            ->willReturn($cachedToken);

        $instance = $this->createInstance();
        $result = $instance->getAccessToken();

        $this->assertSame('cached-access-token', $result);
        $this->assertSame(0, $instance->requestCount);
    }

    #[Test]
    public function expiredTokenWithoutRefreshTokenTriggersFetchNewToken(): void
    {
        $expiredToken = new TokenData(
            accessToken: 'expired-token',
            expiresAt: time() - 100,
            refreshToken: null,
        );

        $this->storage->method('has')
            ->with($this->clientId)
            ->willReturn(true);

        $this->storage->method('get')
            ->with($this->clientId)
            ->willReturn($expiredToken);

        $this->storage->expects($this->once())
            ->method('set')
            ->with($this->clientId, $this->isInstanceOf(TokenData::class));

        $instance = $this->createInstance();
        $instance->nextResponse = TestOAuth2::createTokenResponse(200, [
            'access_token' => 'fresh-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]);

        $result = $instance->getAccessToken();

        $this->assertSame('fresh-token', $result);
        $this->assertSame(1, $instance->requestCount);
        $this->assertSame('client_credentials', $instance->capturedParams[0]['grant_type']);
    }

    #[Test]
    public function expiredTokenWithRefreshTokenTriggersRefreshToken(): void
    {
        $expiredToken = new TokenData(
            accessToken: 'expired-token',
            expiresAt: time() - 100,
            refreshToken: 'my-refresh-token',
        );

        $this->storage->method('has')
            ->with($this->clientId)
            ->willReturn(true);

        $this->storage->method('get')
            ->with($this->clientId)
            ->willReturn($expiredToken);

        $this->storage->expects($this->once())
            ->method('set')
            ->with($this->clientId, $this->isInstanceOf(TokenData::class));

        $instance = $this->createInstance();
        $instance->nextResponse = TestOAuth2::createTokenResponse(200, [
            'access_token' => 'refreshed-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]);

        $result = $instance->getAccessToken();

        $this->assertSame('refreshed-token', $result);
        $this->assertSame(1, $instance->requestCount);
        $this->assertSame('refresh_token', $instance->capturedParams[0]['grant_type']);
        $this->assertSame('my-refresh-token', $instance->capturedParams[0]['refresh_token']);
    }

    #[Test]
    public function refreshFailureFallsBackToFreshAcquisition(): void
    {
        $expiredToken = new TokenData(
            accessToken: 'expired-token',
            expiresAt: time() - 100,
            refreshToken: 'bad-refresh-token',
        );

        $this->storage->method('has')
            ->with($this->clientId)
            ->willReturn(true);

        $this->storage->method('get')
            ->with($this->clientId)
            ->willReturn($expiredToken);

        $this->storage->expects($this->once())
            ->method('set')
            ->with($this->clientId, $this->isInstanceOf(TokenData::class));

        $callCount = 0;
        $instance = $this->createInstance();

        // Override buildTokenRequest behavior using a custom subclass approach
        // First call (refresh) fails, second call (fresh) succeeds
        $failThenSucceed = new class (
            $this->clientId,
            $this->clientSecret,
            $this->storage,
        ) extends TestOAuth2 {
            /** @var int Internal call counter. */
            private int $internalCount = 0;

            /**
             * First call throws, second call succeeds.
             *
             * @param array<string, string> $params Form parameters.
             * @return OAuth2TokenResponse
             */
            protected function buildTokenRequest(array $params): OAuth2TokenResponse
            {
                $this->capturedParams[] = $params;
                $this->requestCount++;
                $this->internalCount++;

                if ($this->internalCount === 1) {
                    throw new RuntimeException('Refresh token rejected');
                }

                return self::createTokenResponse(200, [
                    'access_token' => 'fallback-fresh-token',
                    'token_type' => 'Bearer',
                    'expires_in' => 3600,
                ]);
            }
        };

        $result = @$failThenSucceed->getAccessToken();

        $this->assertSame('fallback-fresh-token', $result);
        $this->assertSame(2, $failThenSucceed->requestCount);
        $this->assertSame('refresh_token', $failThenSucceed->capturedParams[0]['grant_type']);
        $this->assertSame('client_credentials', $failThenSucceed->capturedParams[1]['grant_type']);
    }

    #[Test]
    public function nonSuccessfulResponseCausesGetAccessTokenToReturnNull(): void
    {
        $this->storage->method('has')
            ->with($this->clientId)
            ->willReturn(false);

        $instance = $this->createInstance();
        $instance->nextResponse = TestOAuth2::createTokenResponse(401, [
            'error' => 'invalid_client',
            'error_description' => 'Client authentication failed',
        ]);

        $result = @$instance->getAccessToken();

        $this->assertNull($result);
    }

    #[Test]
    public function exceptionDuringAcquisitionReturnsNullAndLogsError(): void
    {
        $this->storage->method('has')
            ->with($this->clientId)
            ->willReturn(false);

        $instance = $this->createInstance();
        $instance->nextException = new RuntimeException('Network timeout');

        $result = @$instance->getAccessToken();

        $this->assertNull($result);
    }

    #[Test]
    public function noLeagueImportsExistInOAuth2Class(): void
    {
        $sourceFile = file_get_contents(__DIR__ . '/../../src/Clients/OAuth2.php');

        $this->assertIsString($sourceFile);
        $this->assertStringNotContainsString(
            'League\\OAuth2\\Client',
            $sourceFile,
            'OAuth2 class must not contain any League imports',
        );
        $this->assertStringNotContainsString(
            'GenericProvider',
            $sourceFile,
            'OAuth2 class must not reference GenericProvider',
        );
        $this->assertStringNotContainsString(
            'AccessTokenInterface',
            $sourceFile,
            'OAuth2 class must not reference AccessTokenInterface',
        );
    }
}
