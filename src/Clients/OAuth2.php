<?php

namespace Simsoft\HttpClient\Clients;

use Closure;
use RuntimeException;
use Simsoft\HttpClient\Clients\Helpers\FileStorage;
use Simsoft\HttpClient\Clients\Responses\OAuth2TokenResponse;
use Simsoft\HttpClient\Clients\Traits\OAuth2AuthCodeTrait;
use Simsoft\HttpClient\Clients\Traits\OAuth2TokenOperationsTrait;
use Simsoft\HttpClient\HttpClient;
use Simsoft\HttpClient\Interfaces\StorageInterface;
use Throwable;

/**
 * OAuth2 class.
 *
 * Handles OAuth2 token acquisition, caching, and refresh using the library's own
 * HttpClient infrastructure. No external OAuth2 packages are required.
 *
 * Defaults to the client_credentials grant type, which is the most common
 * server-to-server flow.
 *
 * Usage:
 *   class MyApiOAuth2 extends OAuth2 {
 *       protected string $accessTokenEndpoint = 'https://api.example.com/oauth/token';
 *   }
 *
 *   $token = MyApiOAuth2::request('client-id', 'client-secret')->getAccessToken();
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Coupling is inherent to OAuth2 lifecycle management.
 */
abstract class OAuth2
{
    use OAuth2AuthCodeTrait;
    use OAuth2TokenOperationsTrait;

    /** @var string TokenData metadata key recording which grant issued the token. */
    protected const GRANT_META_KEY = 'grant_type';

    /** @var string Metadata value marking a token as belonging to an end user. */
    protected const AUTH_CODE_GRANT = 'authorization_code';

    /** @var string Production access token endpoint. */
    protected string $accessTokenEndpoint = '';

    /** @var string Sandbox access token endpoint. */
    protected string $sandboxEndpoint = '';

    /** @var bool Sandbox mode flag. */
    protected bool $sandboxMode = false;

    /**
     * Grant type for token requests.
     * Common values: 'client_credentials', 'authorization_code', 'password'.
     *
     * @var string
     */
    protected string $grantType = 'client_credentials';

    /** @var string|null OAuth2 scope. Null omits the scope parameter entirely. */
    protected ?string $scope = null;

    /** @var bool Whether token caching is enabled. */
    protected bool $cacheEnabled = true;

    /** @var int Safety buffer in seconds subtracted from token expiry. */
    protected int $expiryBuffer = 30;

    /** @var HttpClient|null Configured HttpClient instance for token requests. */
    protected ?HttpClient $httpClient = null;

    /** @var Closure|null Callback invoked when a token is acquired. Receives TokenData. */
    protected ?Closure $onTokenAcquired = null;

    /** @var Closure|null Callback invoked when a token is refreshed. Receives TokenData. */
    protected ?Closure $onTokenRefreshed = null;

    /** @var Closure|null Callback invoked when token acquisition fails. Receives Throwable. */
    protected ?Closure $onTokenFailed = null;

    /** @var StorageInterface Token persistence storage. */
    protected StorageInterface $storage;

    /** @var string|null Identifier of the end user this token belongs to. */
    protected ?string $subject = null;

    /**
     * Constructor.
     *
     * @param string $clientId OAuth2 client ID.
     * @param string $clientSecret OAuth2 client secret.
     * @param StorageInterface|null $storage Custom storage implementation. Defaults to FileStorage.
     */
    final public function __construct(
        protected string $clientId,
        protected string $clientSecret,
        ?StorageInterface $storage = null,
    ) {
        $this->storage = $storage ?? new FileStorage();
    }

    /**
     * Factory method.
     *
     * @param string $clientId OAuth2 client ID.
     * @param string $clientSecret OAuth2 client secret.
     * @param StorageInterface|null $storage Custom storage implementation.
     * @return static
     */
    public static function request(
        string $clientId,
        string $clientSecret,
        ?StorageInterface $storage = null,
    ): static {
        return new static($clientId, $clientSecret, $storage);
    }

    /**
     * Enable sandbox mode — uses $sandboxEndpoint instead of $accessTokenEndpoint.
     *
     * @return $this
     */
    public function sandbox(): self
    {
        $this->sandboxMode = true;
        return $this;
    }

    /**
     * Disable token caching — always fetches a fresh token from the endpoint.
     *
     * @return $this
     */
    public function withoutCache(): self
    {
        $this->cacheEnabled = false;
        return $this;
    }

    /**
     * Set the safety buffer subtracted from token expiry time.
     *
     * @param int $seconds Buffer in seconds. 0 means no buffer.
     * @return $this
     */
    public function expiryBuffer(int $seconds): self
    {
        $this->expiryBuffer = $seconds;
        return $this;
    }

    /**
     * Set the OAuth2 scope for token requests at runtime.
     *
     * @param string|null $scope The scope string, or null to omit.
     * @return $this
     */
    public function withScope(?string $scope): self
    {
        $this->scope = $scope;
        return $this;
    }

    /**
     * Bind this client's stored token to a specific end user.
     *
     * The client ID identifies the application, not the person who authorised
     * it, so in any flow where different people obtain tokens through the same
     * registered application — the authorization code flow above all — the
     * client ID alone is not a safe storage key. Set a subject to keep each
     * user's token, PKCE verifier and CSRF state separate.
     *
     * Give it a value that is stable for the user and unguessable by anyone
     * else, such as a session ID or an internal user ID.
     *
     * @param string|null $subject Identifier of the end user, or null to unbind.
     * @return $this
     */
    public function forSubject(?string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    /**
     * Build the storage key under which this client's token is cached.
     *
     * The key covers everything that changes which token a request would
     * produce: the client ID, the end user it was issued to, the endpoint it
     * came from — production and sandbox issue different tokens — and the
     * requested scope. Two clients that would receive interchangeable tokens
     * share a key; two that would not, do not.
     *
     * @param string $suffix Optional discriminator for related entries.
     * @return string The storage key.
     */
    protected function storageKey(string $suffix = ''): string
    {
        $parts = [
            $this->clientId,
            $this->subject ?? '',
            $this->getEndpoint(),
            $this->scope ?? '',
        ];

        $key = $this->clientId . ':' . hash('sha256', implode("\0", $parts));

        return $suffix === '' ? $key : "$key:$suffix";
    }

    /**
     * Get the HttpClient instance used for token requests.
     *
     * Configure timeouts, headers, retry, and other options directly
     * on this instance.
     *
     * @return HttpClient
     */
    public function getHttpClient(): HttpClient
    {
        if ($this->httpClient === null) {
            $this->httpClient = HttpClient::make();
        }

        return $this->httpClient;
    }

    /**
     * Register a callback invoked when a new token is acquired.
     *
     * @param Closure $callback Receives TokenData as argument.
     * @return $this
     */
    public function onTokenAcquired(Closure $callback): self
    {
        $this->onTokenAcquired = $callback;
        return $this;
    }

    /**
     * Register a callback invoked when a token is refreshed.
     *
     * @param Closure $callback Receives TokenData as argument.
     * @return $this
     */
    public function onTokenRefreshed(Closure $callback): self
    {
        $this->onTokenRefreshed = $callback;
        return $this;
    }

    /**
     * Register a callback invoked when token acquisition fails.
     *
     * @param Closure $callback Receives Throwable as argument.
     * @return $this
     */
    public function onTokenFailed(Closure $callback): self
    {
        $this->onTokenFailed = $callback;
        return $this;
    }

    /**
     * Invalidate the cached token for this client.
     *
     * @return $this
     */
    public function invalidate(): self
    {
        $this->storage->remove($this->storageKey());
        return $this;
    }

    /**
     * Get the active token endpoint URL.
     *
     * @return string
     */
    public function getEndpoint(): string
    {
        if ($this->sandboxMode) {
            return $this->sandboxEndpoint;
        }

        return $this->accessTokenEndpoint;
    }

    /**
     * Get a valid access token string, refreshing or acquiring a new one as needed.
     *
     * @return string|null
     */
    public function getAccessToken(): ?string
    {
        return $this->getTokenData()?->accessToken;
    }

    /**
     * Get the full TokenData object, refreshing or acquiring a new token as needed.
     *
     * @return TokenData|null
     */
    public function getTokenData(): ?TokenData
    {
        try {
            return $this->resolveToken();
        } catch (Throwable $throwable) {
            error_log(sprintf(
                '[OAuth2] Failed to get access token for client "%s": %s',
                $this->clientId,
                $throwable->getMessage()
            ));

            if ($this->onTokenFailed !== null) {
                ($this->onTokenFailed)($throwable);
            }
        }

        return null;
    }

    /**
     * Resolve a valid token from cache or by acquisition.
     *
     * @return TokenData
     * @throws RuntimeException When token acquisition fails.
     * @throws Throwable
     */
    private function resolveToken(): TokenData
    {
        if (!$this->cacheEnabled) {
            return $this->fetchNewToken();
        }

        $cached = $this->readCachedToken();

        if ($cached !== null) {
            return $this->handleCachedToken($cached);
        }

        $token = $this->fetchNewToken();
        $this->storage->set($this->storageKey(), $token);
        return $token;
    }

    /**
     * Read the cached token, discarding anything that is not usable.
     *
     * Storage is a shared, external resource: a file can be truncated, a cache
     * entry can be evicted mid-write, and a backend can return whatever it
     * likes. Anything that is not a TokenData is dropped along with its
     * storage entry, so a single corrupt record cannot permanently prevent the
     * client from acquiring a token.
     *
     * @return TokenData|null The cached token, or null if absent or unusable.
     */
    private function readCachedToken(): ?TokenData
    {
        $key = $this->storageKey();

        if (!$this->storage->has($key)) {
            return null;
        }

        $token = $this->storage->get($key);

        if ($token instanceof TokenData) {
            return $token;
        }

        error_log(sprintf(
            '[OAuth2] Discarding unusable cached token for client "%s": expected %s, got %s',
            $this->clientId,
            TokenData::class,
            get_debug_type($token)
        ));

        $this->storage->remove($key);

        return null;
    }

    /**
     * Handle a cached token — return if valid, refresh or re-acquire if expired.
     *
     * @param TokenData $token The token found in storage.
     * @return TokenData The valid token data.
     * @throws RuntimeException When token acquisition fails.
     * @throws Throwable
     */
    private function handleCachedToken(TokenData $token): TokenData
    {
        if (!$token->hasExpired()) {
            return $token;
        }

        $freshToken = $this->handleExpiredToken($token);
        $this->storage->set($this->storageKey(), $freshToken);
        return $freshToken;
    }

    /**
     * Handle an expired token by refreshing or acquiring a new one.
     *
     * @param TokenData $token The expired token.
     * @return TokenData A fresh token.
     * @throws RuntimeException When token acquisition fails.
     * @throws Throwable
     */
    private function handleExpiredToken(TokenData $token): TokenData
    {
        if ($token->refreshToken === null) {
            return $this->acquireReplacementFor($token);
        }

        return $this->attemptRefreshWithFallback($token);
    }

    /**
     * Acquire a token to replace one that expired and could not be refreshed.
     *
     * A token issued through the authorization code grant belongs to a user who
     * is no longer present. buildTokenParams() would send a client_credentials
     * request in their place, which either fails or — worse — succeeds and
     * stores the application's own token under the user's storage key, so that
     * every subsequent call acts with the application's authority while
     * appearing to act with the user's. The caller is told to re-authorise.
     *
     * @param TokenData $token The token being replaced.
     * @return TokenData A fresh token.
     * @throws RuntimeException When the expired token belongs to an end user.
     * @throws Throwable
     */
    private function acquireReplacementFor(TokenData $token): TokenData
    {
        if (($token->metadata[self::GRANT_META_KEY] ?? null) === self::AUTH_CODE_GRANT) {
            throw new RuntimeException(sprintf(
                'The stored token for client "%s" was issued for an end user and has expired. '
                . 'It cannot be renewed unattended; call getAuthorizationUrl() to re-authorise.',
                $this->clientId
            ));
        }

        return $this->fetchNewToken();
    }

    /**
     * Determine whether this client holds tokens on behalf of an end user.
     *
     * Two things establish this, and neither is a guess: the caller bound the
     * client to a subject with forSubject(), or the class declares the
     * authorization code grant outright. A configured authorization endpoint is
     * deliberately not a test — a subclass may expose both flows, offering the
     * code grant to users while still fetching its own client_credentials
     * token, and that client must keep working.
     *
     * @return bool True when tokens belong to a user rather than the application.
     */
    protected function isUserDelegated(): bool
    {
        return $this->subject !== null
            || $this->grantType === 'authorization_code';
    }

    /**
     * Record on a token that it was issued for an end user.
     *
     * The grant is stamped into the token's own metadata so that a later
     * process — a queue worker with no request context — can tell a
     * user-delegated token from an application one, and refuse to silently
     * replace it. An existing marker is left alone.
     *
     * @param TokenData $token The token returned by a code exchange.
     * @return TokenData The token carrying the delegation marker.
     */
    protected function markUserDelegated(TokenData $token): TokenData
    {
        if (($token->metadata[self::GRANT_META_KEY] ?? null) === self::AUTH_CODE_GRANT) {
            return $token;
        }

        return new TokenData(
            accessToken: $token->accessToken,
            expiresAt: $token->expiresAt,
            refreshToken: $token->refreshToken,
            tokenType: $token->tokenType,
            scope: $token->scope,
            metadata: [self::GRANT_META_KEY => self::AUTH_CODE_GRANT] + $token->metadata,
        );
    }

    /**
     * Attempt refresh, falling back to fresh acquisition on failure.
     *
     * @param TokenData $token The expired token with a refresh token.
     * @return TokenData A fresh token.
     * @throws RuntimeException When both refresh and fresh acquisition fail.
     * @throws Throwable
     */
    private function attemptRefreshWithFallback(TokenData $token): TokenData
    {
        try {
            return $this->refreshToken($token);
        } catch (Throwable $throwable) {
            error_log(sprintf(
                '[OAuth2] Refresh failed for client "%s": %s — attempting fresh token',
                $this->clientId,
                $throwable->getMessage()
            ));
        }

        return $this->acquireReplacementFor($token);
    }

    /**
     * Build the POST body parameters for a fresh token request.
     *
     * Subclasses may override to add provider-specific parameters.
     *
     * @return array<string, string> The token request parameters.
     */
    protected function buildTokenParams(): array
    {
        $params = [
            'grant_type' => $this->grantType,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ];

        if ($this->scope !== null) {
            $params['scope'] = $this->scope;
        }

        return $params;
    }

    /**
     * Fetch a fresh access token using the configured grant type.
     *
     * This is the unattended acquisition path, reached on a cold cache, on an
     * expired token with no refresh token, and after a failed refresh. A client
     * bound to a subject must not come through here: buildTokenParams() would
     * send a client_credentials request in that user's place, which either
     * fails or — worse — succeeds and stores the application's own token under
     * the user's storage key. The code exchange does not come through here
     * either; exchangeCode() calls buildTokenRequest() directly.
     *
     * @return TokenData
     * @throws RuntimeException|Throwable When the grant cannot be performed
     *                                    unattended, or the token endpoint
     *                                    returns a non-successful response.
     */
    protected function fetchNewToken(): TokenData
    {
        if ($this->isUserDelegated()) {
            throw new RuntimeException(sprintf(
                'No usable token for client "%s" and one cannot be obtained without the user. '
                . 'Call getAuthorizationUrl() to start the authorization code flow.',
                $this->clientId
            ));
        }

        $response = $this->buildTokenRequest($this->buildTokenParams());

        $this->assertTokenResponse($response, 'Token request');

        $token = $this->toTokenData($response);

        if ($this->onTokenAcquired !== null) {
            ($this->onTokenAcquired)($token);
        }

        return $token;
    }

    /**
     * Build the POST body parameters for a token refresh request.
     *
     * Subclasses may override to add provider-specific parameters.
     *
     * @param TokenData $token The expired token with a refresh token.
     * @return array<string, string> The refresh request parameters.
     */
    protected function buildRefreshParams(TokenData $token): array
    {
        return [
            'grant_type' => 'refresh_token',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => (string)$token->refreshToken,
        ];
    }

    /**
     * Refresh an existing access token using its refresh token.
     *
     * @param TokenData $token The expired token with a refresh token.
     * @return TokenData
     * @throws RuntimeException When the refresh request fails.
     * @throws Throwable
     */
    protected function refreshToken(TokenData $token): TokenData
    {
        $response = $this->buildTokenRequest($this->buildRefreshParams($token));

        $this->assertTokenResponse($response, 'Token refresh');

        $freshToken = $this->toTokenData($response);

        // A refreshed user token is still a user token; carry the marker across
        // so a later expiry is not mistaken for an application token.
        if (($token->metadata[self::GRANT_META_KEY] ?? null) === self::AUTH_CODE_GRANT) {
            $freshToken = $this->markUserDelegated($freshToken);
        }

        if ($this->onTokenRefreshed !== null) {
            ($this->onTokenRefreshed)($freshToken);
        }

        return $freshToken;
    }

    /**
     * Assert that a token endpoint response actually carries a token.
     *
     * A 2xx status is not on its own proof of success. RFC 6749 §5.1 makes
     * `access_token` REQUIRED in a successful response, and §5.2 defines an
     * `error` member for failures — which some providers return with a 200.
     * Accepting either as a token yields an empty credential that is then
     * cached and sent as `Bearer `, turning a diagnosable configuration fault
     * into 401s from the resource server.
     *
     * @param OAuth2TokenResponse $response The token endpoint response.
     * @param string $context Label for the operation, used in the message.
     * @return void
     * @throws RuntimeException When the response is unsuccessful or carries no token.
     */
    protected function assertTokenResponse(OAuth2TokenResponse $response, string $context): void
    {
        if (!$response->successful()) {
            throw new RuntimeException(sprintf(
                '%s failed [HTTP %d]: %s',
                $context,
                $response->getStatusCode(),
                $response->getError() ?? ($response->getMessage() ?: 'Unknown error')
            ));
        }

        $error = $response->getError();
        if ($error !== null) {
            throw new RuntimeException(sprintf('%s failed: %s', $context, $error));
        }

        if (($response->getToken() ?? '') === '') {
            throw new RuntimeException(sprintf(
                '%s failed [HTTP %d]: response contained no access_token.',
                $context,
                $response->getStatusCode()
            ));
        }
    }

    /**
     * Send a token request to the token endpoint.
     *
     * @param array<string, string> $params Form parameters for the token request.
     * @return OAuth2TokenResponse
     * @throws Throwable
     */
    protected function buildTokenRequest(array $params): OAuth2TokenResponse
    {
        /** @var OAuth2TokenResponse $response */
        $response = $this->getHttpClient()
            ->withResponseClass(OAuth2TokenResponse::class)
            ->withForm($params)
            ->post($this->getEndpoint());

        return $response;
    }

    /**
     * Convert an OAuth2 token response to a TokenData value object.
     *
     * Subclasses may override this method to add custom metadata or
     * handle non-standard response fields.
     *
     * @param OAuth2TokenResponse $response The parsed token response.
     * @return TokenData
     */
    protected function toTokenData(OAuth2TokenResponse $response): TokenData
    {
        $expiresAt = 0;

        if ($this->cacheEnabled) {
            $serverExpiresAt = $response->getExpiresAt();
            $expiresAt = $serverExpiresAt !== null
                ? $serverExpiresAt - $this->expiryBuffer
                : time() + 3600 - $this->expiryBuffer;
        }

        return new TokenData(
            accessToken: (string)$response->getToken(),
            expiresAt: $expiresAt,
            refreshToken: $response->getRefreshToken(),
            tokenType: $response->getTokenType(),
            scope: $response->getScope(),
        );
    }
}
