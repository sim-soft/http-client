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
    )
    {
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
    ): static
    {
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
        $this->storage->remove($this->clientId);
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

        if ($this->storage->has($this->clientId)) {
            return $this->handleCachedToken();
        }

        $token = $this->fetchNewToken();
        $this->storage->set($this->clientId, $token);
        return $token;
    }

    /**
     * Handle a cached token — return if valid, refresh or re-acquire if expired.
     *
     * @return TokenData The valid token data.
     * @throws RuntimeException When token acquisition fails.
     * @throws Throwable
     */
    private function handleCachedToken(): TokenData
    {
        /** @var TokenData $token */
        $token = $this->storage->get($this->clientId);

        if (!$token->hasExpired()) {
            return $token;
        }

        $freshToken = $this->handleExpiredToken($token);
        $this->storage->set($this->clientId, $freshToken);
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
            return $this->fetchNewToken();
        }

        return $this->attemptRefreshWithFallback($token);
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

        return $this->fetchNewToken();
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
     * @return TokenData
     * @throws RuntimeException|Throwable When the token endpoint returns a non-successful response.
     */
    protected function fetchNewToken(): TokenData
    {
        $response = $this->buildTokenRequest($this->buildTokenParams());

        if (!$response->successful()) {
            throw new RuntimeException(sprintf(
                'Token request failed [HTTP %d]: %s',
                $response->getStatusCode(),
                $response->getMessage() ?? 'Unknown error'
            ));
        }

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

        if (!$response->successful()) {
            throw new RuntimeException(sprintf(
                'Token refresh failed [HTTP %d]: %s',
                $response->getStatusCode(),
                $response->getMessage() ?? 'Unknown error'
            ));
        }

        $freshToken = $this->toTokenData($response);

        if ($this->onTokenRefreshed !== null) {
            ($this->onTokenRefreshed)($freshToken);
        }

        return $freshToken;
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
