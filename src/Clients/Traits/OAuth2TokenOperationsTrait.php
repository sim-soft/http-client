<?php

namespace Simsoft\HttpClient\Clients\Traits;

use RuntimeException;

/**
 * OAuth2TokenOperationsTrait.
 *
 * Provides token revocation (RFC 7009) and introspection (RFC 7662)
 * capabilities for the OAuth2 class.
 */
trait OAuth2TokenOperationsTrait
{
    /** @var string Token revocation endpoint URL. Empty string means not supported. */
    protected string $revocationEndpoint = '';

    /** @var string Token introspection endpoint URL. Empty string means not supported. */
    protected string $introspectEndpoint = '';

    /**
     * Revoke an access or refresh token at the provider's revocation endpoint (RFC 7009).
     *
     * Invalidates the token on the provider side and removes the cached token
     * from local storage.
     *
     * @param string $token The token string to revoke.
     * @param string $tokenTypeHint Hint: 'access_token' or 'refresh_token'.
     * @return bool True if revocation succeeded (HTTP 2xx), false otherwise.
     * @throws RuntimeException When the revocation endpoint is not configured.
     */
    public function revokeToken(string $token, string $tokenTypeHint = 'access_token'): bool
    {
        if ($this->revocationEndpoint === '') {
            throw new RuntimeException(
                'Revocation endpoint not configured. Set $revocationEndpoint in your OAuth2 subclass.'
            );
        }

        $params = $this->buildRevocationParams($token, $tokenTypeHint);

        $response = $this->getHttpClient()
            ->withForm($params)
            ->post($this->revocationEndpoint);

        if ($response->successful()) {
            $this->storage->remove($this->clientId);
            return true;
        }

        return false;
    }

    /**
     * Build the POST body parameters for a token revocation request.
     *
     * Subclasses may override this method to add provider-specific parameters.
     *
     * @param string $token The token to revoke.
     * @param string $tokenTypeHint The token type hint.
     * @return array<string, string> The revocation request parameters.
     */
    protected function buildRevocationParams(string $token, string $tokenTypeHint): array
    {
        return [
            'token' => $token,
            'token_type_hint' => $tokenTypeHint,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ];
    }

    /**
     * Introspect a token at the provider's introspection endpoint (RFC 7662).
     *
     * Returns the introspection response data including whether the token is active.
     *
     * @param string $token The token string to introspect.
     * @param string $tokenTypeHint Hint: 'access_token' or 'refresh_token'.
     * @return array<string, mixed> The introspection response data.
     * @throws RuntimeException When endpoint is not configured or request fails.
     */
    public function introspectToken(string $token, string $tokenTypeHint = 'access_token'): array
    {
        if ($this->introspectEndpoint === '') {
            throw new RuntimeException(
                'Introspection endpoint not configured. Set $introspectEndpoint in your OAuth2 subclass.'
            );
        }

        $params = $this->buildIntrospectionParams($token, $tokenTypeHint);

        $response = $this->getHttpClient()
            ->withForm($params)
            ->post($this->introspectEndpoint);

        if (!$response->successful()) {
            throw new RuntimeException(sprintf(
                'Token introspection failed [HTTP %d]: %s',
                $response->getStatusCode(),
                $response->getMessage() ?? 'Unknown error'
            ));
        }

        return $response->toArray();
    }

    /**
     * Build the POST body parameters for a token introspection request.
     *
     * Subclasses may override this method to add provider-specific parameters.
     *
     * @param string $token The token to introspect.
     * @param string $tokenTypeHint The token type hint.
     * @return array<string, string> The introspection request parameters.
     */
    protected function buildIntrospectionParams(string $token, string $tokenTypeHint): array
    {
        return [
            'token' => $token,
            'token_type_hint' => $tokenTypeHint,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ];
    }
}
