<?php

namespace Simsoft\HttpClient\Clients;

use RuntimeException;
use Simsoft\HttpClient\Clients\Helpers\FileStorage;
use Simsoft\HttpClient\Clients\Responses\OAuth2TokenResponse;
use Simsoft\HttpClient\HttpClient;
use Simsoft\HttpClient\Interfaces\StorageInterface;
use Throwable;

/**
 * OAuth2 class.
 *
 * Handles OAuth2 token acquisition, caching, and refresh using the library's own
 * HttpClient infrastructure. No external OAuth2 packages required.
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
    /** @var string Production access token endpoint. */
    protected string $accessTokenEndpoint = '';

    /** @var string Sandbox access token endpoint. */
    protected string $sandboxEndpoint = '';

    /** @var string Authorization endpoint URL (production). */
    protected string $authorizeEndpoint = '';

    /** @var string Authorization endpoint URL (sandbox). */
    protected string $sandboxAuthEndpoint = '';

    /** @var string OAuth2 redirect URI (callback URL). */
    protected string $redirectUri = '';

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
        protected string  $clientId,
        protected string  $clientSecret,
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
        string            $clientId,
        string            $clientSecret,
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
     * Returns null on failure — check error_log() for details.
     *
     * @return string|null
     */
    public function getAccessToken(): ?string
    {
        try {
            return $this->resolveToken();
        } catch (Throwable $throwable) {
            \error_log(\sprintf(
                '[OAuth2] Failed to get access token for client "%s": %s',
                $this->clientId,
                $throwable->getMessage()
            ));
        }

        return null;
    }

    /**
     * Resolve a valid access token from cache or by acquisition.
     *
     * @return string
     * @throws RuntimeException When token acquisition fails.
     */
    private function resolveToken(): string
    {
        if ($this->storage->has($this->clientId)) {
            return $this->handleCachedToken();
        }

        $token = $this->fetchNewToken();
        $this->storage->set($this->clientId, $token);
        return $token->accessToken;
    }

    /**
     * Handle a cached token — return it if valid, refresh or re-acquire if expired.
     *
     * @return string The valid access token string.
     * @throws RuntimeException When token acquisition fails.
     */
    private function handleCachedToken(): string
    {
        /** @var TokenData $token */
        $token = $this->storage->get($this->clientId);

        if (!$token->hasExpired()) {
            return $token->accessToken;
        }

        $freshToken = $this->handleExpiredToken($token);
        $this->storage->set($this->clientId, $freshToken);
        return $freshToken->accessToken;
    }

    /**
     * Handle an expired token by refreshing or acquiring a new one.
     *
     * @param TokenData $token The expired token.
     * @return TokenData A fresh token.
     * @throws RuntimeException When token acquisition fails.
     */
    private function handleExpiredToken(TokenData $token): TokenData
    {
        if ($token->refreshToken === null) {
            return $this->fetchNewToken();
        }

        return $this->attemptRefreshWithFallback($token);
    }

    /**
     * Attempt to refresh the token, falling back to fresh acquisition on failure.
     *
     * @param TokenData $token The expired token with a refresh token.
     * @return TokenData A fresh token.
     * @throws RuntimeException When both refresh and fresh acquisition fail.
     */
    private function attemptRefreshWithFallback(TokenData $token): TokenData
    {
        try {
            return $this->refreshToken($token);
        } catch (Throwable $throwable) {
            \error_log(\sprintf(
                '[OAuth2] Refresh failed for client "%s": %s — attempting fresh token',
                $this->clientId,
                $throwable->getMessage()
            ));
        }

        return $this->fetchNewToken();
    }

    /**
     * Fetch a fresh access token using the configured grant type.
     *
     * @return TokenData
     * @throws RuntimeException When the token endpoint returns a non-successful response.
     */
    protected function fetchNewToken(): TokenData
    {
        $params = [
            'grant_type' => $this->grantType,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ];

        if ($this->scope !== null) {
            $params['scope'] = $this->scope;
        }

        $response = $this->buildTokenRequest($params);

        if (!$response->successful()) {
            throw new RuntimeException(\sprintf(
                'Token request failed [HTTP %d]: %s',
                $response->getStatusCode(),
                $response->getMessage() ?? 'Unknown error'
            ));
        }

        return $this->toTokenData($response);
    }

    /**
     * Refresh an existing access token using its refresh token.
     *
     * @param TokenData $token The expired token with a refresh token.
     * @return TokenData
     * @throws RuntimeException When the refresh request fails.
     */
    protected function refreshToken(TokenData $token): TokenData
    {
        $params = [
            'grant_type' => 'refresh_token',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => (string)$token->refreshToken,
        ];

        $response = $this->buildTokenRequest($params);

        if (!$response->successful()) {
            throw new RuntimeException(\sprintf(
                'Token refresh failed [HTTP %d]: %s',
                $response->getStatusCode(),
                $response->getMessage() ?? 'Unknown error'
            ));
        }

        return $this->toTokenData($response);
    }

    /**
     * Send a token request to the token endpoint.
     *
     * Creates a fresh HttpClient instance per request to avoid state leakage.
     *
     * @param array<string, string> $params Form parameters for the token request.
     * @return OAuth2TokenResponse
     */
    protected function buildTokenRequest(array $params): OAuth2TokenResponse
    {
        /** @var OAuth2TokenResponse $response */
        $response = HttpClient::make()
            ->withResponseClass(OAuth2TokenResponse::class)
            ->withForm($params)
            ->post($this->getEndpoint());

        return $response;
    }

    /**
     * Convert an OAuth2 token response to a TokenData value object.
     *
     * Applies a 30-second safety buffer to the expiry time to account for
     * clock skew and network latency.
     *
     * @param OAuth2TokenResponse $response The parsed token response.
     * @return TokenData
     */
    protected function toTokenData(OAuth2TokenResponse $response): TokenData
    {
        $expiresAt = $response->getExpiresAt();
        $safeExpiresAt = $expiresAt !== null ? $expiresAt - 30 : time() + 3570;

        return new TokenData(
            accessToken: (string)$response->getToken(),
            expiresAt: $safeExpiresAt,
            refreshToken: $response->getRefreshToken(),
            tokenType: $response->getTokenType(),
            scope: $response->getScope(),
        );
    }

    /**
     * Get the active authorization endpoint URL.
     *
     * Returns the sandbox authorize endpoint when sandbox mode is enabled,
     * otherwise returns the production authorize endpoint.
     *
     * @return string The authorization endpoint URL.
     * @throws RuntimeException When the resolved endpoint is empty.
     */
    private function getAuthorizeEndpoint(): string
    {
        $endpoint = $this->sandboxMode
            ? $this->sandboxAuthEndpoint
            : $this->authorizeEndpoint;

        if ($endpoint === '') {
            throw new RuntimeException(
                'Authorization endpoint not configured. Set $authorizeEndpoint in your OAuth2 subclass.'
            );
        }

        return $endpoint;
    }

    /**
     * Generate a cryptographically random PKCE code verifier.
     *
     * Produces a 128-character string using only unreserved characters
     * (A-Z, a-z, 0-9, -, ., _, ~) as defined by RFC 7636.
     *
     * @return string The generated code verifier.
     */
    private function generateCodeVerifier(): string
    {
        $unreserved = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';
        $length = 128;
        $verifier = '';
        $bytes = random_bytes($length);
        $charsetSize = strlen($unreserved);

        for ($i = 0; $i < $length; $i++) {
            $verifier .= $unreserved[ord($bytes[$i]) % $charsetSize];
        }

        return $verifier;
    }

    /**
     * Derive the S256 code challenge from a code verifier.
     *
     * Applies SHA-256 hashing and base64url encoding (no padding) as
     * specified by RFC 7636.
     *
     * @param string $verifier The PKCE code verifier.
     * @return string The base64url-encoded code challenge.
     */
    private function generateCodeChallenge(string $verifier): string
    {
        $hash = hash('sha256', $verifier, true);

        return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
    }

    /**
     * Generate a cryptographically random state value for CSRF protection.
     *
     * Produces a 64-character hexadecimal string from 32 random bytes.
     *
     * @return string The generated state value.
     */
    private function generateState(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Build the query parameters for the authorization URL.
     *
     * Subclasses may override this method to add provider-specific parameters
     * (e.g., `access_type=offline` for Google).
     *
     * @param string $state The CSRF state value.
     * @param string $codeChallenge The PKCE code challenge.
     * @return array<string, string> The authorization query parameters.
     */
    protected function buildAuthorizationParams(string $state, string $codeChallenge): array
    {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        if ($this->scope !== null) {
            $params['scope'] = $this->scope;
        }

        return $params;
    }

    /**
     * Generate the full authorization URL for redirecting the user.
     *
     * Generates PKCE verifier and state, stores them for later validation,
     * and constructs the complete authorization URL with all required parameters.
     *
     * @return string The complete authorization URL.
     * @throws RuntimeException When the authorization endpoint is not configured.
     */
    public function getAuthorizationUrl(): string
    {
        $endpoint = $this->getAuthorizeEndpoint();

        $verifier = $this->generateCodeVerifier();
        $this->storage->set("{$this->clientId}_pkce_verifier", $verifier);

        $state = $this->generateState();
        $this->storage->set("{$this->clientId}_oauth_state", $state);

        $codeChallenge = $this->generateCodeChallenge($verifier);

        $params = $this->buildAuthorizationParams($state, $codeChallenge);
        $params = array_filter($params, static fn($value) => $value !== null);

        $separator = str_contains($endpoint, '?') ? '&' : '?';
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return $endpoint . $separator . $query;
    }

    /**
     * Build the POST body parameters for the authorization code exchange.
     *
     * Subclasses may override this method to add or modify parameters
     * for provider-specific token exchange requirements.
     *
     * @param string $code The authorization code from the callback.
     * @param string $verifier The PKCE code verifier.
     * @return array<string, string> The token exchange parameters.
     */
    protected function buildCodeExchangeParams(string $code, string $verifier): array
    {
        return [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code_verifier' => $verifier,
        ];
    }

    /**
     * Parse a token endpoint response into a TokenData value object.
     *
     * Subclasses may override this method to handle non-standard response
     * fields from specific providers.
     *
     * @param OAuth2TokenResponse $response The token endpoint response.
     * @return TokenData The parsed token data.
     */
    protected function parseTokenResponse(OAuth2TokenResponse $response): TokenData
    {
        return $this->toTokenData($response);
    }

    /**
     * Exchange an authorization code for an access token.
     *
     * Validates the state parameter for CSRF protection, retrieves the stored
     * PKCE verifier, exchanges the code at the token endpoint, and stores the
     * resulting TokenData.
     *
     * @param string $code The authorization code from the callback.
     * @param string $state The state parameter from the callback.
     * @return TokenData The token data from the exchange.
     * @throws RuntimeException When state validation fails, verifier is missing, or the HTTP request fails.
     */
    public function exchangeCode(string $code, string $state): TokenData
    {
        $storedState = $this->storage->get("{$this->clientId}_oauth_state");

        if ($storedState === null) {
            throw new RuntimeException(\sprintf(
                'No stored state found for client "%s". The authorization flow may have expired or was not initiated.',
                $this->clientId
            ));
        }

        if ($state !== $storedState) {
            throw new RuntimeException(
                'State parameter mismatch: possible CSRF attack. Expected stored state does not match callback state.'
            );
        }

        $this->storage->remove("{$this->clientId}_oauth_state");

        $verifier = $this->storage->get("{$this->clientId}_pkce_verifier");

        if ($verifier === null) {
            throw new RuntimeException(\sprintf(
                'No stored PKCE verifier found for client "%s". The authorization flow may have expired or was not initiated.',
                $this->clientId
            ));
        }

        $this->storage->remove("{$this->clientId}_pkce_verifier");

        $params = $this->buildCodeExchangeParams($code, $verifier);
        $response = $this->buildTokenRequest($params);

        if (!$response->successful()) {
            throw new RuntimeException(\sprintf(
                'Code exchange failed [HTTP %d]: %s',
                $response->getStatusCode(),
                $response->getMessage() ?? 'Unknown error'
            ));
        }

        $tokenData = $this->parseTokenResponse($response);
        $this->storage->set($this->clientId, $tokenData);

        return $tokenData;
    }
}
