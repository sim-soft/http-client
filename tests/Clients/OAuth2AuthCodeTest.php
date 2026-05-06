<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests\Clients;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Simsoft\HttpClient\Clients\OAuth2;
use Simsoft\HttpClient\Clients\Responses\OAuth2TokenResponse;
use Simsoft\HttpClient\Clients\TokenData;

/**
 * AuthCodeTestOAuth2 class.
 *
 * Concrete test subclass of OAuth2 for authorization code flow testing.
 * Configures authorize endpoint, sandbox auth endpoint, redirect URI,
 * and overrides buildTokenRequest() to capture params and return mock responses.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class AuthCodeTestOAuth2 extends OAuth2
{
    /** @var string Production authorization endpoint. */
    protected string $authorizeEndpoint = 'https://auth.example.com/authorize';

    /** @var string Sandbox authorization endpoint. */
    protected string $sandboxAuthEndpoint = 'https://sandbox-auth.example.com/authorize';

    /** @var string Production access token endpoint. */
    protected string $accessTokenEndpoint = 'https://auth.example.com/token';

    /** @var string OAuth2 redirect URI. */
    protected string $redirectUri = 'https://myapp.com/callback';

    /** @var OAuth2TokenResponse|null Response to return from buildTokenRequest(). */
    public ?OAuth2TokenResponse $nextResponse = null;

    /** @var array<int, array<string, string>> Captured request params. */
    public array $capturedParams = [];

    /** @var int Count of buildTokenRequest() calls. */
    public int $requestCount = 0;

    /**
     * Override buildTokenRequest to capture params and return mock responses.
     *
     * @param array<string, string> $params Form parameters.
     * @return OAuth2TokenResponse
     */
    protected function buildTokenRequest(array $params): OAuth2TokenResponse
    {
        $this->capturedParams[] = $params;
        $this->requestCount++;

        if ($this->nextResponse !== null) {
            return $this->nextResponse;
        }

        return new OAuth2TokenResponse(
            curlInfo: ['http_code' => 200],
            body: json_encode([
                'access_token' => 'test-access-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'refresh_token' => 'test-refresh-token',
            ], JSON_THROW_ON_ERROR),
        );
    }
}

/**
 * AuthCodeTestOAuth2WithScope class.
 *
 * Subclass with a configured scope for testing scope inclusion in authorization URL.
 */
class AuthCodeTestOAuth2WithScope extends AuthCodeTestOAuth2
{
    /** @var string|null OAuth2 scope. */
    protected ?string $scope = 'openid profile email';
}

/**
 * AuthCodeTestOAuth2NoEndpoint class.
 *
 * Subclass with no authorize endpoint configured for testing error handling.
 */
class AuthCodeTestOAuth2NoEndpoint extends AuthCodeTestOAuth2
{
    /** @var string Empty authorization endpoint. */
    protected string $authorizeEndpoint = '';

    /** @var string Empty sandbox authorization endpoint. */
    protected string $sandboxAuthEndpoint = '';
}

/**
 * OAuth2AuthCodeTest class.
 *
 * Unit tests for the OAuth2 Authorization Code Flow with PKCE.
 * Tests cover URL generation, state/PKCE validation, code exchange,
 * error handling, and backward compatibility with client_credentials.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class OAuth2AuthCodeTest extends TestCase
{
    /** @var string Test client ID. */
    private string $clientId = 'test-client-id';

    /** @var string Test client secret. */
    private string $clientSecret = 'test-client-secret';

    /**
     * Create an AuthCodeTestOAuth2 instance with InMemoryStorage.
     *
     * @return array{0: AuthCodeTestOAuth2, 1: InMemoryStorage}
     */
    private function createInstance(): array
    {
        $storage = new InMemoryStorage();
        $client = new AuthCodeTestOAuth2(
            $this->clientId,
            $this->clientSecret,
            $storage,
        );

        return [$client, $storage];
    }

    /**
     * Extract query parameters from a URL string.
     *
     * @param string $url The URL to parse.
     * @return array<string, string> The parsed query parameters.
     */
    private function extractQueryParams(string $url): array
    {
        $parts = parse_url($url);
        $this->assertIsArray($parts);
        $this->assertArrayHasKey('query', $parts);

        /** @var array{query: string} $parts */
        $params = [];
        parse_str($parts['query'], $params);

        /** @var array<string, string> $params */
        return $params;
    }

    /**
     * Extract the state parameter from an authorization URL.
     *
     * @param string $url The authorization URL.
     * @return string The state value.
     */
    private function extractState(string $url): string
    {
        $params = $this->extractQueryParams($url);
        $this->assertArrayHasKey('state', $params);

        return (string)$params['state'];
    }

    #[Test]
    public function getAuthorizationUrlReturnsUrlWithAllRequiredParameters(): void
    {
        [$client] = $this->createInstance();

        $url = $client->getAuthorizationUrl();

        $this->assertStringStartsWith('https://auth.example.com/authorize?', $url);

        $params = $this->extractQueryParams($url);

        $this->assertSame($this->clientId, $params['client_id']);
        $this->assertSame('https://myapp.com/callback', $params['redirect_uri']);
        $this->assertSame('code', $params['response_type']);
        $this->assertArrayHasKey('state', $params);
        $this->assertNotEmpty($params['state']);
        $this->assertArrayHasKey('code_challenge', $params);
        $this->assertNotEmpty($params['code_challenge']);
        $this->assertSame('S256', $params['code_challenge_method']);
    }

    #[Test]
    public function getAuthorizationUrlOmitsScopeWhenNotConfigured(): void
    {
        [$client] = $this->createInstance();

        $url = $client->getAuthorizationUrl();

        $params = $this->extractQueryParams($url);

        $this->assertArrayNotHasKey('scope', $params);
    }

    #[Test]
    public function getAuthorizationUrlIncludesScopeWhenConfigured(): void
    {
        $storage = new InMemoryStorage();
        $client = new AuthCodeTestOAuth2WithScope(
            $this->clientId,
            $this->clientSecret,
            $storage,
        );

        $url = $client->getAuthorizationUrl();

        $params = $this->extractQueryParams($url);

        $this->assertArrayHasKey('scope', $params);
        $this->assertSame('openid profile email', $params['scope']);
    }

    #[Test]
    public function getAuthorizationUrlThrowsWhenNoAuthorizeEndpointConfigured(): void
    {
        $storage = new InMemoryStorage();
        $client = new AuthCodeTestOAuth2NoEndpoint(
            $this->clientId,
            $this->clientSecret,
            $storage,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Authorization endpoint not configured');

        $client->getAuthorizationUrl();
    }

    #[Test]
    public function sandboxModeUsesSandboxAuthEndpoint(): void
    {
        [$client] = $this->createInstance();
        $client->sandbox();

        $url = $client->getAuthorizationUrl();

        $this->assertStringStartsWith('https://sandbox-auth.example.com/authorize?', $url);
    }

    #[Test]
    public function exchangeCodeSucceedsWithValidStateAndStoresTokenData(): void
    {
        [$client, $storage] = $this->createInstance();

        // Generate authorization URL to store state and verifier
        $url = $client->getAuthorizationUrl();
        $state = $this->extractState($url);

        $tokenData = $client->exchangeCode('auth-code-123', $state);

        $this->assertInstanceOf(TokenData::class, $tokenData);
        $this->assertSame('test-access-token', $tokenData->accessToken);
        $this->assertSame('test-refresh-token', $tokenData->refreshToken);

        // Verify token is stored in storage
        $storedToken = $storage->get($this->clientId);
        $this->assertInstanceOf(TokenData::class, $storedToken);
        $this->assertSame('test-access-token', $storedToken->accessToken);
    }

    #[Test]
    public function exchangeCodeThrowsOnStateMismatch(): void
    {
        [$client] = $this->createInstance();

        // Generate authorization URL to store state and verifier
        $client->getAuthorizationUrl();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('State parameter mismatch');

        $client->exchangeCode('auth-code-123', 'wrong-state-value');
    }

    #[Test]
    public function exchangeCodeThrowsWhenNoStoredStateExists(): void
    {
        [$client] = $this->createInstance();

        // Do NOT call getAuthorizationUrl() — no state stored

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No stored state found');

        $client->exchangeCode('auth-code-123', 'some-state');
    }

    #[Test]
    public function exchangeCodeThrowsWhenNoStoredVerifierExists(): void
    {
        [$client, $storage] = $this->createInstance();

        // Manually store state but not verifier
        $storage->set("{$this->clientId}_oauth_state", 'valid-state');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No stored PKCE verifier found');

        $client->exchangeCode('auth-code-123', 'valid-state');
    }

    #[Test]
    public function exchangeCodeThrowsOnHttpErrorResponse(): void
    {
        [$client] = $this->createInstance();

        // Generate authorization URL to store state and verifier
        $url = $client->getAuthorizationUrl();
        $state = $this->extractState($url);

        // Set up error response
        $client->nextResponse = new OAuth2TokenResponse(
            curlInfo: ['http_code' => 400],
            body: json_encode([
                'error' => 'invalid_grant',
                'error_description' => 'Authorization code expired',
            ], JSON_THROW_ON_ERROR),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Code exchange failed [HTTP 400]');

        $client->exchangeCode('auth-code-123', $state);
    }

    #[Test]
    public function stateIsRemovedFromStorageAfterSuccessfulExchange(): void
    {
        [$client, $storage] = $this->createInstance();

        $url = $client->getAuthorizationUrl();
        $state = $this->extractState($url);

        // Verify state exists before exchange
        $this->assertTrue($storage->has("{$this->clientId}_oauth_state"));

        $client->exchangeCode('auth-code-123', $state);

        // Verify state is removed after exchange
        $this->assertFalse($storage->has("{$this->clientId}_oauth_state"));
    }

    #[Test]
    public function pkceVerifierIsRemovedFromStorageAfterExchange(): void
    {
        [$client, $storage] = $this->createInstance();

        $url = $client->getAuthorizationUrl();
        $state = $this->extractState($url);

        // Verify verifier exists before exchange
        $this->assertTrue($storage->has("{$this->clientId}_pkce_verifier"));

        $client->exchangeCode('auth-code-123', $state);

        // Verify verifier is removed after exchange
        $this->assertFalse($storage->has("{$this->clientId}_pkce_verifier"));
    }

    #[Test]
    public function existingClientCredentialsFlowStillWorks(): void
    {
        [$client] = $this->createInstance();

        $token = $client->getAccessToken();

        $this->assertSame('test-access-token', $token);
        $this->assertSame(1, $client->requestCount);
        $this->assertSame('client_credentials', $client->capturedParams[0]['grant_type']);
    }

    #[Test]
    public function getAccessTokenReturnsCachedAuthCodeTokenWithoutHttpCall(): void
    {
        [$client, $storage] = $this->createInstance();

        // Simulate a stored auth-code token
        $cachedToken = new TokenData(
            accessToken: 'cached-auth-code-token',
            expiresAt: time() + 3600,
            refreshToken: 'cached-refresh-token',
        );
        $storage->set($this->clientId, $cachedToken);

        $result = $client->getAccessToken();

        $this->assertSame('cached-auth-code-token', $result);
        $this->assertSame(0, $client->requestCount);
    }

    #[Test]
    public function expiredAuthCodeTokenWithRefreshTokenTriggersRefresh(): void
    {
        [$client, $storage] = $this->createInstance();

        // Store an expired token with a refresh token
        $expiredToken = new TokenData(
            accessToken: 'expired-auth-code-token',
            expiresAt: time() - 100,
            refreshToken: 'my-refresh-token',
        );
        $storage->set($this->clientId, $expiredToken);

        // Set up refresh response
        $client->nextResponse = new OAuth2TokenResponse(
            curlInfo: ['http_code' => 200],
            body: json_encode([
                'access_token' => 'refreshed-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ], JSON_THROW_ON_ERROR),
        );

        $result = $client->getAccessToken();

        $this->assertSame('refreshed-token', $result);
        $this->assertSame(1, $client->requestCount);
        $this->assertSame('refresh_token', $client->capturedParams[0]['grant_type']);
        $this->assertSame('my-refresh-token', $client->capturedParams[0]['refresh_token']);
    }
}
