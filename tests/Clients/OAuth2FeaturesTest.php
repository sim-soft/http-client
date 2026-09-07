<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests\Clients;

require_once __DIR__ . '/OAuth2PropertyTest.php';

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Simsoft\HttpClient\Clients\OAuth2;
use Simsoft\HttpClient\Clients\Responses\OAuth2TokenResponse;
use Simsoft\HttpClient\Clients\TokenData;
use Simsoft\HttpClient\Exceptions\ScopeEscalationException;
use Simsoft\HttpClient\HttpClient;
use Simsoft\HttpClient\Interfaces\StorageInterface;
use Simsoft\HttpClient\Testing\FakeHttpClient;
use Throwable;

/**
 * FeatureTestOAuth2 class.
 *
 * Concrete test subclass for testing OAuth2 features:
 * getHttpClient, revocation, introspection, invalidation, callbacks, and expiry buffer.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class FeatureTestOAuth2 extends OAuth2
{
    /** @var string Production access token endpoint. */
    protected string $accessTokenEndpoint = 'https://example.com/oauth/token';

    /** @var string Token revocation endpoint. */
    protected string $revocationEndpoint = 'https://example.com/oauth/revoke';

    /** @var string Token introspection endpoint. */
    protected string $introspectEndpoint = 'https://example.com/oauth/introspect';

    /** @var OAuth2TokenResponse|null Response to return from buildTokenRequest(). */
    public ?OAuth2TokenResponse $nextResponse = null;

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

        if ($this->nextResponse !== null) {
            return $this->nextResponse;
        }

        return self::createTokenResponse(200, [
            'access_token' => 'test-token-' . $this->requestCount,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]);
    }

    /**
     * Create an OAuth2TokenResponse with given status and body.
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

    /**
     * Expose expiryBuffer for testing.
     *
     * @return int
     */
    public function getExpiryBuffer(): int
    {
        return $this->expiryBuffer;
    }

    /**
     * Expose the composed storage key so assertions do not restate its format.
     *
     * @param string $suffix Optional discriminator for related entries.
     * @return string The storage key.
     */
    public function exposedStorageKey(string $suffix = ''): string
    {
        return $this->storageKey($suffix);
    }
}

/**
 * FeatureTestOAuth2NoRevocation class.
 *
 * Subclass with no revocation endpoint for testing error handling.
 */
class FeatureTestOAuth2NoRevocation extends FeatureTestOAuth2
{
    /** @var string Empty revocation endpoint. */
    protected string $revocationEndpoint = '';
}

/**
 * FeatureTestOAuth2FakeRevocation class.
 *
 * Reports every revocation request as successful without performing HTTP, so
 * the storage eviction that follows a successful revocation can be observed.
 * revokeToken() itself is inherited, not overridden.
 */
class FeatureTestOAuth2FakeRevocation extends FeatureTestOAuth2
{
    /**
     * Return a successful response without touching the network.
     *
     * @return HttpClient
     */
    public function getHttpClient(): HttpClient
    {
        return FakeHttpClient::fake([
            '*' => ['status' => 200, 'body' => '{}'],
        ]);
    }
}

/**
 * FeatureTestOAuth2NoIntrospection class.
 *
 * Subclass with no introspection endpoint for testing error handling.
 */
class FeatureTestOAuth2NoIntrospection extends FeatureTestOAuth2
{
    /** @var string Empty introspection endpoint. */
    protected string $introspectEndpoint = '';
}

/**
 * FeatureTestOAuth2WithScope class.
 *
 * Subclass with a configured scope for testing scope override.
 */
class FeatureTestOAuth2WithScope extends FeatureTestOAuth2
{
    /** @var string|null OAuth2 scope. */
    protected ?string $scope = 'default:read default:write';
}

/**
 * FeatureTestOAuth2WithAuthEndpoint class.
 *
 * Subclass with an authorization endpoint for testing PKCE code verifier generation.
 */
class FeatureTestOAuth2WithAuthEndpoint extends FeatureTestOAuth2
{
    /** @var string Authorization endpoint. */
    protected string $authorizeEndpoint = 'https://auth.example.com/authorize';

    /** @var string Redirect URI. */
    protected string $redirectUri = 'https://myapp.com/callback';
}

/**
 * FeatureTestOAuth2ScopeSequence class.
 *
 * Subclass whose fake provider grants a different scope on each call, so the
 * scope a refresh returns can be made to differ from the one it replaces.
 * A null entry omits the `scope` member from the response entirely, which
 * RFC 6749 §5.1 defines as meaning the grant is unchanged.
 */
class FeatureTestOAuth2ScopeSequence extends FeatureTestOAuth2
{
    /** @var array<int, string|null> Scope granted per call, in order. */
    public array $grantSequence = [];

    /**
     * Return a token whose scope comes from the configured sequence.
     *
     * @param array<string, string> $params Form parameters.
     * @return OAuth2TokenResponse
     */
    protected function buildTokenRequest(array $params): OAuth2TokenResponse
    {
        $this->capturedParams[] = $params;
        $granted = $this->grantSequence[$this->requestCount] ?? null;
        $this->requestCount++;

        $body = [
            'access_token' => 'test-token-' . $this->requestCount,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => 'test-refresh-' . $this->requestCount,
        ];

        if ($granted !== null) {
            $body['scope'] = $granted;
        }

        return self::createTokenResponse(200, $body);
    }
}

/**
 * OAuth2FeaturesTest class.
 *
 * Tests for OAuth2 improvements: getHttpClient, token revocation,
 * token introspection, cache invalidation, event callbacks, and configurable expiry buffer.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 */
class OAuth2FeaturesTest extends TestCase
{
    /** @var string Test client ID. */
    private string $clientId = 'test-client-id';

    /** @var string Test client secret. */
    private string $clientSecret = 'test-client-secret';

    /**
     * Create a FeatureTestOAuth2 instance with InMemoryStorage.
     *
     * @return array{0: FeatureTestOAuth2, 1: InMemoryStorage}
     */
    private function createInstance(): array
    {
        $storage = new InMemoryStorage();
        $client = new FeatureTestOAuth2(
            $this->clientId,
            $this->clientSecret,
            $storage,
        );

        return [$client, $storage];
    }

    // ---------------------------------------------------------------
    // getHttpClient
    // ---------------------------------------------------------------

    #[Test]
    public function getHttpClientReturnsHttpClientInstance(): void
    {
        [$client] = $this->createInstance();

        $httpClient = $client->getHttpClient();

        $this->assertInstanceOf(HttpClient::class, $httpClient);
    }

    #[Test]
    public function getHttpClientReturnsSameInstanceOnMultipleCalls(): void
    {
        [$client] = $this->createInstance();

        $first = $client->getHttpClient();
        $second = $client->getHttpClient();

        $this->assertSame($first, $second);
    }

    #[Test]
    public function getHttpClientIsConfigurable(): void
    {
        [$client] = $this->createInstance();

        $httpClient = $client->getHttpClient();
        $returned = $httpClient->timeout(60)->connectionTimeout(10);

        $this->assertSame($httpClient, $returned);
    }

    // ---------------------------------------------------------------
    // Configurable Expiry Buffer
    // ---------------------------------------------------------------

    #[Test]
    public function expiryBufferSetsCustomBuffer(): void
    {
        [$client] = $this->createInstance();

        $returned = $client->expiryBuffer(10);

        $this->assertSame($client, $returned);
        $this->assertSame(10, $client->getExpiryBuffer());
    }

    #[Test]
    public function defaultExpiryBufferIsThirtySeconds(): void
    {
        [$client] = $this->createInstance();

        $this->assertSame(30, $client->getExpiryBuffer());
    }

    #[Test]
    public function expiryBufferZeroMeansNoBuffer(): void
    {
        [$client] = $this->createInstance();

        $client->expiryBuffer(0);

        $tokenData = $client->getTokenData();

        $this->assertNotNull($tokenData);
        $this->assertGreaterThan(time() + 3500, $tokenData->expiresAt);
    }

    #[Test]
    public function customExpiryBufferIsAppliedToTokenData(): void
    {
        [$client] = $this->createInstance();

        $client->expiryBuffer(60);

        $tokenData = $client->getTokenData();

        $this->assertNotNull($tokenData);
        $this->assertLessThanOrEqual(time() + 3540, $tokenData->expiresAt);
        $this->assertGreaterThan(time() + 3400, $tokenData->expiresAt);
    }

    #[Test]
    public function expiryBufferRejectsNegativeValues(): void
    {
        [$client] = $this->createInstance();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expiry buffer must be zero or greater');

        $client->expiryBuffer(-1);
    }

    #[Test]
    public function expiryBufferLeavesTheBufferUnchangedWhenRejected(): void
    {
        [$client] = $this->createInstance();

        try {
            $client->expiryBuffer(-600);
        } catch (InvalidArgumentException) {
            // Expected.
        }

        $this->assertSame(30, $client->getExpiryBuffer());
    }

    #[Test]
    public function expiryBufferAcceptsZero(): void
    {
        [$client] = $this->createInstance();

        $client->expiryBuffer(0);

        $this->assertSame(0, $client->getExpiryBuffer());
    }

    // ---------------------------------------------------------------
    // Short-lived tokens (expires_in below the buffer)
    // ---------------------------------------------------------------

    #[Test]
    public function tokenShorterThanTheBufferIsNotCachedAlreadyExpired(): void
    {
        [$client, $storage] = $this->createInstance();

        $client->nextResponse = FeatureTestOAuth2::createTokenResponse(200, [
            'access_token' => 'short-lived',
            'expires_in' => 10,
        ]);

        $client->getAccessToken();

        $cached = $storage->get($client->exposedStorageKey());

        $this->assertInstanceOf(TokenData::class, $cached);
        $this->assertFalse(
            $cached->hasExpired(),
            'A usable token was cached already expired, which silently disables caching.',
        );
    }

    #[Test]
    public function tokenShorterThanTheBufferIsServedFromCacheOnASecondCall(): void
    {
        [$client] = $this->createInstance();

        $client->nextResponse = FeatureTestOAuth2::createTokenResponse(200, [
            'access_token' => 'short-lived',
            'expires_in' => 10,
        ]);

        $client->getAccessToken();
        $client->getAccessToken();

        $this->assertSame(
            1,
            $client->requestCount,
            'The token endpoint was contacted again despite caching being enabled.',
        );
    }

    #[Test]
    public function tokenShorterThanTheBufferNeverOutlivesTheProviderExpiry(): void
    {
        [$client, $storage] = $this->createInstance();

        $client->nextResponse = FeatureTestOAuth2::createTokenResponse(200, [
            'access_token' => 'short-lived',
            'expires_in' => 10,
        ]);

        $client->getAccessToken();

        $cached = $storage->get($client->exposedStorageKey());

        $this->assertInstanceOf(TokenData::class, $cached);
        $this->assertLessThanOrEqual(
            time() + 10,
            $cached->expiresAt,
            'Clamping the buffer must not push expiry past what the provider reported.',
        );
    }

    #[Test]
    public function expiryBufferStillAppliesWhenTheTokenIsLongerThanTheBuffer(): void
    {
        [$client, $storage] = $this->createInstance();

        $client->expiryBuffer(30);
        $client->nextResponse = FeatureTestOAuth2::createTokenResponse(200, [
            'access_token' => 'normal',
            'expires_in' => 3600,
        ]);

        $client->getAccessToken();

        $cached = $storage->get($client->exposedStorageKey());

        $this->assertInstanceOf(TokenData::class, $cached);
        $this->assertLessThanOrEqual(time() + 3570, $cached->expiresAt);
        $this->assertGreaterThan(time() + 3500, $cached->expiresAt);
    }

    // ---------------------------------------------------------------
    // Cache Invalidation
    // ---------------------------------------------------------------

    #[Test]
    public function invalidateRemovesCachedToken(): void
    {
        [$client, $storage] = $this->createInstance();

        $client->getAccessToken();
        $this->assertTrue($storage->has($client->exposedStorageKey()));

        $returned = $client->invalidate();

        $this->assertSame($client, $returned);
        $this->assertFalse($storage->has($client->exposedStorageKey()));
    }

    #[Test]
    public function invalidateForcesNewFetchOnNextCall(): void
    {
        [$client] = $this->createInstance();

        $first = $client->getAccessToken();
        $this->assertSame(1, $client->requestCount);

        $client->invalidate();
        $second = $client->getAccessToken();

        $this->assertSame(2, $client->requestCount);
        $this->assertNotSame($first, $second);
    }

    #[Test]
    public function invalidateOnEmptyStorageDoesNotThrow(): void
    {
        [$client, $storage] = $this->createInstance();

        $client->invalidate();

        $this->assertFalse($storage->has($client->exposedStorageKey()));
    }

    // ---------------------------------------------------------------
    // Event Callbacks
    // ---------------------------------------------------------------

    #[Test]
    public function onTokenAcquiredCallbackIsInvokedOnFreshToken(): void
    {
        [$client] = $this->createInstance();

        $captured = null;
        $client->onTokenAcquired(function (TokenData $token) use (&$captured): void {
            $captured = $token;
        });

        $client->getAccessToken();

        $this->assertInstanceOf(TokenData::class, $captured);
        $this->assertSame('test-token-1', $captured->accessToken);
    }

    #[Test]
    public function onTokenRefreshedCallbackIsInvokedOnRefresh(): void
    {
        [$client, $storage] = $this->createInstance();

        $expiredToken = new TokenData(
            accessToken: 'expired',
            expiresAt: time() - 100,
            refreshToken: 'refresh-me',
        );
        $storage->set($client->exposedStorageKey(), $expiredToken);

        $captured = null;
        $client->onTokenRefreshed(function (TokenData $token) use (&$captured): void {
            $captured = $token;
        });

        $client->getAccessToken();

        $this->assertInstanceOf(TokenData::class, $captured);
        $this->assertSame('test-token-1', $captured->accessToken);
    }

    #[Test]
    public function onTokenFailedCallbackIsInvokedOnFailure(): void
    {
        [$client] = $this->createInstance();

        $client->nextResponse = FeatureTestOAuth2::createTokenResponse(500, [
            'error' => 'server_error',
        ]);

        $captured = null;
        $client->onTokenFailed(function (Throwable $error) use (&$captured): void {
            $captured = $error;
        });

        $result = @$client->getAccessToken();

        $this->assertNull($result);
        $this->assertInstanceOf(Throwable::class, $captured);
        $this->assertStringContainsString('Token request failed', $captured->getMessage());
    }

    #[Test]
    public function onTokenAcquiredIsNotCalledWhenTokenIsCached(): void
    {
        [$client, $storage] = $this->createInstance();

        $cachedToken = new TokenData(
            accessToken: 'cached-token',
            expiresAt: time() + 3600,
        );
        $storage->set($client->exposedStorageKey(), $cachedToken);

        $called = false;
        $client->onTokenAcquired(function () use (&$called): void {
            $called = true;
        });

        $client->getAccessToken();

        $this->assertFalse($called);
    }

    #[Test]
    public function callbackMethodsReturnSelf(): void
    {
        [$client] = $this->createInstance();

        $noop = function (): void {
        };

        $this->assertSame($client, $client->onTokenAcquired($noop));
        $this->assertSame($client, $client->onTokenRefreshed($noop));
        $this->assertSame($client, $client->onTokenFailed($noop));
    }

    // ---------------------------------------------------------------
    // Token Revocation
    // ---------------------------------------------------------------

    #[Test]
    public function revokeTokenThrowsWhenEndpointNotConfigured(): void
    {
        $storage = new InMemoryStorage();
        $client = new FeatureTestOAuth2NoRevocation(
            $this->clientId,
            $this->clientSecret,
            $storage,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Revocation endpoint not configured');

        $client->revokeToken('some-token');
    }

    #[Test]
    public function buildRevocationParamsIncludesRequiredFields(): void
    {
        $storage = new InMemoryStorage();
        $testClient = new class (
            $this->clientId,
            $this->clientSecret,
            $storage,
        ) extends FeatureTestOAuth2 {
            /** @var array<string, string> Captured revocation params. */
            public array $revocationParams = [];

            /**
             * Capture params.
             *
             * @param string $token Token to revoke.
             * @param string $tokenTypeHint Token type hint.
             * @return array<string, string>
             */
            protected function buildRevocationParams(string $token, string $tokenTypeHint): array
            {
                $this->revocationParams = parent::buildRevocationParams($token, $tokenTypeHint);
                return $this->revocationParams;
            }

            /**
             * Override to avoid HTTP call.
             *
             * @param string $token Token to revoke.
             * @param string $tokenTypeHint Token type hint.
             * @return bool
             */
            public function revokeToken(string $token, string $tokenTypeHint = 'access_token'): bool
            {
                $this->buildRevocationParams($token, $tokenTypeHint);
                return true;
            }
        };

        $testClient->revokeToken('my-token');

        $this->assertSame('my-token', $testClient->revocationParams['token']);
        $this->assertSame('access_token', $testClient->revocationParams['token_type_hint']);
        $this->assertSame($this->clientId, $testClient->revocationParams['client_id']);
        $this->assertSame($this->clientSecret, $testClient->revocationParams['client_secret']);
    }

    #[Test]
    public function revokeTokenEvictsOnlyTheEntryHoldingTheRevokedToken(): void
    {
        $storage = new InMemoryStorage();

        $alice = new FeatureTestOAuth2FakeRevocation($this->clientId, $this->clientSecret, $storage);
        $alice->forSubject('alice');

        $bob = new FeatureTestOAuth2FakeRevocation($this->clientId, $this->clientSecret, $storage);
        $bob->forSubject('bob');

        $storage->set($alice->exposedStorageKey(), new TokenData('alice-token', time() + 3600));
        $storage->set($bob->exposedStorageKey(), new TokenData('bob-token', time() + 3600));

        // Alice's token is revoked at the provider, but the client instance in
        // hand is bound to Bob. Bob's cache entry must survive.
        $this->assertTrue($bob->revokeToken('alice-token'));

        $this->assertTrue(
            $storage->has($bob->exposedStorageKey()),
            "Bob's valid token was evicted by a revocation of Alice's token.",
        );
    }

    #[Test]
    public function revokeTokenEvictsTheSubjectEntryWhenRevokedThroughItsOwnClient(): void
    {
        $storage = new InMemoryStorage();

        $alice = new FeatureTestOAuth2FakeRevocation($this->clientId, $this->clientSecret, $storage);
        $alice->forSubject('alice');

        $bob = new FeatureTestOAuth2FakeRevocation($this->clientId, $this->clientSecret, $storage);
        $bob->forSubject('bob');

        $storage->set($alice->exposedStorageKey(), new TokenData('alice-token', time() + 3600));
        $storage->set($bob->exposedStorageKey(), new TokenData('bob-token', time() + 3600));

        $alice->revokeToken('alice-token');

        $this->assertFalse($storage->has($alice->exposedStorageKey()));
        $this->assertTrue($storage->has($bob->exposedStorageKey()));
    }

    #[Test]
    public function revokeTokenEvictsTheCurrentEntryWhenItHoldsThatToken(): void
    {
        $storage = new InMemoryStorage();

        $client = new FeatureTestOAuth2FakeRevocation($this->clientId, $this->clientSecret, $storage);
        $storage->set($client->exposedStorageKey(), new TokenData('my-token', time() + 3600));

        $this->assertTrue($client->revokeToken('my-token'));

        $this->assertFalse($storage->has($client->exposedStorageKey()));
    }

    #[Test]
    public function revokeTokenEvictsWhenTheRefreshTokenIsRevoked(): void
    {
        $storage = new InMemoryStorage();

        $client = new FeatureTestOAuth2FakeRevocation($this->clientId, $this->clientSecret, $storage);
        $storage->set(
            $client->exposedStorageKey(),
            new TokenData('my-access', time() + 3600, 'my-refresh'),
        );

        $this->assertTrue($client->revokeToken('my-refresh', 'refresh_token'));

        $this->assertFalse($storage->has($client->exposedStorageKey()));
    }

    #[Test]
    public function revokeTokenAcceptsRefreshTokenHint(): void
    {
        $storage = new InMemoryStorage();
        $testClient = new class (
            $this->clientId,
            $this->clientSecret,
            $storage,
        ) extends FeatureTestOAuth2 {
            /** @var array<string, string> Captured revocation params. */
            public array $revocationParams = [];

            /**
             * Capture params.
             *
             * @param string $token Token to revoke.
             * @param string $tokenTypeHint Token type hint.
             * @return array<string, string>
             */
            protected function buildRevocationParams(string $token, string $tokenTypeHint): array
            {
                $this->revocationParams = parent::buildRevocationParams($token, $tokenTypeHint);
                return $this->revocationParams;
            }

            /**
             * Override to avoid HTTP call.
             *
             * @param string $token Token to revoke.
             * @param string $tokenTypeHint Token type hint.
             * @return bool
             */
            public function revokeToken(string $token, string $tokenTypeHint = 'access_token'): bool
            {
                $this->buildRevocationParams($token, $tokenTypeHint);
                return true;
            }
        };

        $testClient->revokeToken('my-refresh', 'refresh_token');

        $this->assertSame('refresh_token', $testClient->revocationParams['token_type_hint']);
    }

    // ---------------------------------------------------------------
    // Token Introspection
    // ---------------------------------------------------------------

    #[Test]
    public function introspectTokenThrowsWhenEndpointNotConfigured(): void
    {
        $storage = new InMemoryStorage();
        $client = new FeatureTestOAuth2NoIntrospection(
            $this->clientId,
            $this->clientSecret,
            $storage,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Introspection endpoint not configured');

        $client->introspectToken('some-token');
    }

    #[Test]
    public function buildIntrospectionParamsIncludesRequiredFields(): void
    {
        $storage = new InMemoryStorage();
        $testClient = new class (
            $this->clientId,
            $this->clientSecret,
            $storage,
        ) extends FeatureTestOAuth2 {
            /** @var array<string, string> Captured introspection params. */
            public array $introspectionParams = [];

            /**
             * Capture params.
             *
             * @param string $token Token to introspect.
             * @param string $tokenTypeHint Token type hint.
             * @return array<string, string>
             */
            protected function buildIntrospectionParams(string $token, string $tokenTypeHint): array
            {
                $this->introspectionParams = parent::buildIntrospectionParams($token, $tokenTypeHint);
                return $this->introspectionParams;
            }

            /**
             * Override to avoid HTTP call.
             *
             * @param string $token Token to introspect.
             * @param string $tokenTypeHint Token type hint.
             * @return array<string, mixed>
             */
            public function introspectToken(string $token, string $tokenTypeHint = 'access_token'): array
            {
                $this->buildIntrospectionParams($token, $tokenTypeHint);
                return ['active' => true];
            }
        };

        $testClient->introspectToken('my-token', 'access_token');

        $this->assertSame('my-token', $testClient->introspectionParams['token']);
        $this->assertSame('access_token', $testClient->introspectionParams['token_type_hint']);
        $this->assertSame($this->clientId, $testClient->introspectionParams['client_id']);
        $this->assertSame($this->clientSecret, $testClient->introspectionParams['client_secret']);
    }

    // ---------------------------------------------------------------
    // Fluent API returns $this
    // ---------------------------------------------------------------

    #[Test]
    public function allFluentMethodsReturnSelf(): void
    {
        [$client] = $this->createInstance();

        $this->assertSame($client, $client->expiryBuffer(10));
        $this->assertSame($client, $client->invalidate());
        $this->assertSame($client, $client->withoutCache());
        $this->assertSame($client, $client->withScope('read write'));
    }

    // ---------------------------------------------------------------
    // withScope
    // ---------------------------------------------------------------

    #[Test]
    public function withScopeSetsRuntimeScope(): void
    {
        [$client] = $this->createInstance();

        $client->withScope('custom:read custom:write');
        $client->getAccessToken();

        $this->assertSame('custom:read custom:write', $client->capturedParams[0]['scope']);
    }

    #[Test]
    public function withScopeOverridesSubclassScope(): void
    {
        $storage = new InMemoryStorage();
        $client = new FeatureTestOAuth2WithScope(
            $this->clientId,
            $this->clientSecret,
            $storage,
        );

        $client->withScope('override:scope');
        $client->getAccessToken();

        $this->assertSame('override:scope', $client->capturedParams[0]['scope']);
    }

    #[Test]
    public function withScopeNullOmitsScopeParam(): void
    {
        $storage = new InMemoryStorage();
        $client = new FeatureTestOAuth2WithScope(
            $this->clientId,
            $this->clientSecret,
            $storage,
        );

        $client->withScope(null);
        $client->getAccessToken();

        $this->assertArrayNotHasKey('scope', $client->capturedParams[0]);
    }

    #[Test]
    public function withoutScopeDefaultOmitsScopeParam(): void
    {
        [$client] = $this->createInstance();

        $client->getAccessToken();

        $this->assertArrayNotHasKey('scope', $client->capturedParams[0]);
    }

    // ---------------------------------------------------------------
    // generateCodeVerifier (bias-free, via getAuthorizationUrl)
    // ---------------------------------------------------------------

    #[Test]
    public function codeVerifierContainsOnlyUnreservedCharacters(): void
    {
        $storage = new InMemoryStorage();
        $client = new FeatureTestOAuth2WithAuthEndpoint(
            $this->clientId,
            $this->clientSecret,
            $storage,
        );

        $client->getAuthorizationUrl();

        $verifier = $storage->get($client->exposedStorageKey('pkce_verifier'));
        $this->assertNotNull($verifier);
        $this->assertSame(128, strlen($verifier));
        $this->assertMatchesRegularExpression(
            '/^[A-Za-z0-9\-._~]{128}$/',
            $verifier
        );
    }

    #[Test]
    public function codeVerifierIsUniquePerCall(): void
    {
        $storage = new InMemoryStorage();
        $client = new FeatureTestOAuth2WithAuthEndpoint(
            $this->clientId,
            $this->clientSecret,
            $storage,
        );

        $client->getAuthorizationUrl();
        $first = $storage->get($client->exposedStorageKey('pkce_verifier'));

        $client->getAuthorizationUrl();
        $second = $storage->get($client->exposedStorageKey('pkce_verifier'));

        $this->assertNotSame($first, $second);
    }

    // ---------------------------------------------------------------
    // Scope reconciliation across a refresh
    // ---------------------------------------------------------------

    /**
     * Acquire a token, then force it to expire so the next call refreshes.
     *
     * @param array<int, string|null> $grantSequence Scope granted per call.
     * @param string|null $requestedScope Scope the client asks for.
     * @return array{0: FeatureTestOAuth2ScopeSequence, 1: InMemoryStorage, 2: TokenData}
     */
    private function createRefreshScenario(array $grantSequence, ?string $requestedScope): array
    {
        $storage = new InMemoryStorage();
        $client = new FeatureTestOAuth2ScopeSequence(
            $this->clientId,
            $this->clientSecret,
            $storage,
        );
        $client->grantSequence = $grantSequence;
        $client->withScope($requestedScope);

        /** @var TokenData $first */
        $first = $client->getTokenData();

        // Put the token back expired; the next resolve goes through refresh.
        $storage->set($client->exposedStorageKey(), new TokenData(
            accessToken: $first->accessToken,
            expiresAt: time() - 10,
            refreshToken: $first->refreshToken,
            tokenType: $first->tokenType,
            scope: $first->scope,
            metadata: $first->metadata,
        ));

        return [$client, $storage, $first];
    }

    #[Test]
    public function narrowedRefreshKeepsTheTokenAndFiresOnScopeChanged(): void
    {
        [$client] = $this->createRefreshScenario(['read write', 'read'], 'read write');

        $observed = [];
        $client->onScopeChanged(function (?string $was, ?string $now) use (&$observed): void {
            $observed[] = [$was, $now];
        });

        $refreshed = $client->getTokenData();

        $this->assertNotNull($refreshed, 'A narrowed refresh still yields a usable token.');
        $this->assertSame('read', $refreshed->scope);
        $this->assertSame('test-token-2', $refreshed->accessToken);
        $this->assertSame([['read write', 'read']], $observed);
    }

    #[Test]
    public function narrowedRefreshSucceedsWithNoCallbackRegistered(): void
    {
        [$client] = $this->createRefreshScenario(['read write', 'read'], 'read write');

        $refreshed = $client->getTokenData();

        $this->assertNotNull($refreshed);
        $this->assertSame('read', $refreshed->scope);
    }

    #[Test]
    public function narrowedRefreshPersistsTheNarrowedScope(): void
    {
        [$client, $storage] = $this->createRefreshScenario(['read write', 'read'], 'read write');

        $client->getTokenData();

        $cached = $storage->get($client->exposedStorageKey());

        $this->assertInstanceOf(TokenData::class, $cached);
        $this->assertSame('read', $cached->scope, 'The cache must reflect what was actually granted.');
    }

    #[Test]
    public function widenedRefreshRaisesScopeEscalationException(): void
    {
        [$client] = $this->createRefreshScenario(['read', 'read write admin'], 'read');

        $captured = null;
        $client->onTokenFailed(function (Throwable $throwable) use (&$captured): void {
            $captured = $throwable;
        });

        $result = $client->getTokenData();

        $this->assertNull($result, 'A grant the provider never made must not reach the caller.');
        $this->assertInstanceOf(ScopeEscalationException::class, $captured);
    }

    #[Test]
    public function widenedRefreshNamesTheScopeItGained(): void
    {
        [$client] = $this->createRefreshScenario(['read', 'read write admin'], 'read');

        $captured = null;
        $client->onTokenFailed(function (Throwable $throwable) use (&$captured): void {
            $captured = $throwable;
        });

        $client->getTokenData();

        $this->assertInstanceOf(ScopeEscalationException::class, $captured);
        $this->assertStringContainsString('exceeds', $captured->getMessage());
        $this->assertStringContainsString('write admin', $captured->getMessage());
    }

    #[Test]
    public function widenedRefreshDoesNotFireOnScopeChanged(): void
    {
        [$client] = $this->createRefreshScenario(['read', 'read write admin'], 'read');

        $observed = [];
        $client->onScopeChanged(function (?string $was, ?string $now) use (&$observed): void {
            $observed[] = [$was, $now];
        });

        $client->getTokenData();

        $this->assertSame([], $observed, 'Escalation is a failure, not a reported change.');
    }

    #[Test]
    public function widenedRefreshIsNotSwallowedByTheRefreshFallback(): void
    {
        // A third grant is available: if the fallback caught the escalation it
        // would acquire a token by client_credentials and return it.
        [$client] = $this->createRefreshScenario(
            ['read', 'read write admin', 'read'],
            'read'
        );

        $result = $client->getTokenData();

        $this->assertNull($result);
        $this->assertSame(2, $client->requestCount, 'No fallback acquisition may follow an escalation.');
    }

    #[Test]
    public function omittedScopeOnRefreshCarriesTheOriginalAcross(): void
    {
        // RFC 6749 §5.1: a scope omitted on refresh is identical to the original.
        [$client] = $this->createRefreshScenario(['read write', null], 'read write');

        $refreshed = $client->getTokenData();

        $this->assertNotNull($refreshed);
        $this->assertSame('read write', $refreshed->scope, 'An omitted scope must not erase what is held.');
    }

    #[Test]
    public function omittedScopeOnRefreshDoesNotFireOnScopeChanged(): void
    {
        [$client] = $this->createRefreshScenario(['read write', null], 'read write');

        $observed = [];
        $client->onScopeChanged(function (?string $was, ?string $now) use (&$observed): void {
            $observed[] = [$was, $now];
        });

        $client->getTokenData();

        $this->assertSame([], $observed, 'Nothing changed, so nothing is reported.');
    }

    #[Test]
    public function unchangedScopeDoesNotFireOnScopeChanged(): void
    {
        [$client] = $this->createRefreshScenario(['read write', 'read write'], 'read write');

        $observed = [];
        $client->onScopeChanged(function (?string $was, ?string $now) use (&$observed): void {
            $observed[] = [$was, $now];
        });

        $refreshed = $client->getTokenData();

        $this->assertSame('read write', $refreshed?->scope);
        $this->assertSame([], $observed);
    }

    #[Test]
    public function reorderedScopeIsNotTreatedAsAChange(): void
    {
        // RFC 6749 §3.3: scope is a space-delimited list whose order is not significant.
        [$client] = $this->createRefreshScenario(['read write', 'write read'], 'read write');

        $observed = [];
        $client->onScopeChanged(function (?string $was, ?string $now) use (&$observed): void {
            $observed[] = [$was, $now];
        });

        $refreshed = $client->getTokenData();

        $this->assertNotNull($refreshed, 'A reorder is neither a narrowing nor an escalation.');
        $this->assertSame([], $observed);
    }

    #[Test]
    public function repeatedWhitespaceIsNotTreatedAsAChange(): void
    {
        [$client] = $this->createRefreshScenario(['read write', "read   write"], 'read write');

        $observed = [];
        $client->onScopeChanged(function (?string $was, ?string $now) use (&$observed): void {
            $observed[] = [$was, $now];
        });

        $refreshed = $client->getTokenData();

        $this->assertNotNull($refreshed);
        $this->assertSame([], $observed);
    }

    #[Test]
    public function refreshWithNoPriorScopeAcceptsWhateverIsReturned(): void
    {
        // Nothing was recorded originally, so there is no baseline to judge against.
        [$client] = $this->createRefreshScenario([null, 'read write'], null);

        $observed = [];
        $client->onScopeChanged(function (?string $was, ?string $now) use (&$observed): void {
            $observed[] = [$was, $now];
        });

        $refreshed = $client->getTokenData();

        $this->assertNotNull($refreshed);
        $this->assertSame('read write', $refreshed->scope);
        $this->assertSame([], $observed);
    }

    #[Test]
    public function onScopeChangedReturnsTheClientForChaining(): void
    {
        [$client] = $this->createInstance();

        $this->assertSame($client, $client->onScopeChanged(function (): void {
        }));
    }
}
