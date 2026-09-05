<?php

namespace Simsoft\HttpClient\Clients\Traits;

use Exception;
use RuntimeException;
use Simsoft\HttpClient\Clients\Responses\OAuth2TokenResponse;
use Simsoft\HttpClient\Clients\TokenData;

/**
 * OAuth2AuthCodeTrait.
 *
 * Provides authorization code flow with PKCE (RFC 7636) for the OAuth2 class.
 * Handles URL generation, state/verifier storage, and code exchange.
 */
trait OAuth2AuthCodeTrait
{
    /** @var string Authorization endpoint URL (production). */
    protected string $authorizeEndpoint = '';

    /** @var string Authorization endpoint URL (sandbox). */
    protected string $sandboxAuthEndpoint = '';

    /** @var string OAuth2 redirect URI (callback URL). */
    protected string $redirectUri = '';

    /**
     * Generate the full authorization URL for redirecting the user.
     *
     * Generates PKCE verifier and state, stores them for later validation,
     * and constructs the complete authorization URL with all required parameters.
     *
     * @return string The complete authorization URL.
     * @throws RuntimeException When the authorization endpoint is not configured.
     * @throws Exception
     */
    public function getAuthorizationUrl(): string
    {
        $endpoint = $this->getAuthorizeEndpoint();

        $verifier = $this->generateCodeVerifier();
        $this->storage->set($this->storageKey('pkce_verifier'), $verifier);

        $state = $this->generateState();
        $this->storage->set($this->storageKey('oauth_state'), $state);

        $codeChallenge = $this->generateCodeChallenge($verifier);

        $params = $this->buildAuthorizationParams($state, $codeChallenge);
        $params = array_filter($params, static fn($value) => $value !== null);

        $separator = str_contains($endpoint, '?') ? '&' : '?';
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return "$endpoint$separator$query";
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
     * @throws RuntimeException|Exception When state validation fails, verifier is missing, or HTTP request fails.
     */
    public function exchangeCode(string $code, string $state): TokenData
    {
        $this->validateState($state);
        $verifier = $this->consumeVerifier();

        $params = $this->buildCodeExchangeParams($code, $verifier);
        $response = $this->buildTokenRequest($params);

        $this->assertTokenResponse($response, 'Code exchange');

        $tokenData = $this->markUserDelegated($this->parseTokenResponse($response));
        $this->storage->set($this->storageKey(), $tokenData);

        return $tokenData;
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
     * Get the active authorization endpoint URL.
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
     * (A-Z, a-z, 0-9, -, _, ~) as defined by RFC 7636. Uses rejection
     * sampling to avoid modulo bias.
     *
     * @return string The generated code verifier.
     * @throws Exception
     */
    private function generateCodeVerifier(): string
    {
        $charset = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';
        $charsetSize = strlen($charset);
        $maxByte = $charsetSize * (int)(256 / $charsetSize) - 1;
        $verifier = '';

        while (strlen($verifier) < 128) {
            $bytes = random_bytes(128 - strlen($verifier));
            foreach (str_split($bytes) as $byte) {
                $index = ord($byte);
                if ($index > $maxByte) {
                    continue;
                }
                $verifier .= $charset[$index % $charsetSize];
                if (strlen($verifier) >= 128) {
                    break;
                }
            }
        }

        return $verifier;
    }

    /**
     * Derive the S256 code challenge from a code verifier.
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
     * @return string The generated state value.
     * @throws Exception
     */
    private function generateState(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Validate the OAuth state parameter against the stored value.
     *
     * The stored state is consumed whether or not it matches: a state is
     * single-use, and leaving a failed one in place would let an attacker keep
     * guessing against it. Comparison is timing-safe.
     *
     * @param string $state The state parameter from the callback.
     * @return void
     * @throws RuntimeException When state is missing or mismatched.
     */
    private function validateState(string $state): void
    {
        $key = $this->storageKey('oauth_state');
        $storedState = $this->storage->get($key);

        if (!is_string($storedState) || $storedState === '') {
            throw new RuntimeException(sprintf(
                'No stored state found for client "%s". The authorization flow may have expired or was not initiated.',
                $this->clientId
            ));
        }

        $this->storage->remove($key);

        if (!hash_equals($storedState, $state)) {
            throw new RuntimeException(
                'State parameter mismatch: possible CSRF attack. Expected stored state does not match callback state.'
            );
        }
    }

    /**
     * Retrieve and consume the stored PKCE verifier.
     *
     * @return string The PKCE code verifier.
     * @throws RuntimeException When no verifier is stored.
     */
    private function consumeVerifier(): string
    {
        $key = $this->storageKey('pkce_verifier');
        $verifier = $this->storage->get($key);

        if (!is_string($verifier) || $verifier === '') {
            throw new RuntimeException(sprintf(
                'No stored PKCE verifier found for client "%s". The authorization flow may have expired or was not initiated.',
                $this->clientId
            ));
        }

        $this->storage->remove($key);

        return $verifier;
    }
}
