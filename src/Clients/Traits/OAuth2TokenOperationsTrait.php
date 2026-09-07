<?php

namespace Simsoft\HttpClient\Clients\Traits;

use RuntimeException;
use Simsoft\HttpClient\Clients\TokenData;

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
     * Invalidates the token on the provider side and, when the cached entry for
     * this client actually holds that token, removes it from local storage.
     *
     * The cache is keyed by client, subject, endpoint and scope, so the entry a
     * client instance points at is not necessarily the one holding the token
     * passed here. Evicting unconditionally would drop an unrelated — and still
     * valid — token whenever the two disagree. `StorageInterface` cannot be
     * enumerated, so the entry holding a token belonging to a different subject
     * cannot be located; revoke through the client bound to that subject, or
     * call `invalidate()` on it.
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
            $this->evictIfCachedTokenMatches($token);
            return true;
        }

        return false;
    }

    /**
     * Remove this client's cached entry when it carries the revoked token.
     *
     * Both the access token and the refresh token are compared: revoking a
     * refresh token invalidates the pair at most providers, so the cached
     * access token is no longer renewable and must not be kept.
     *
     * @param string $token The token string that was revoked.
     * @return void
     */
    private function evictIfCachedTokenMatches(string $token): void
    {
        $key = $this->storageKey();

        if (!$this->storage->has($key)) {
            return;
        }

        $cached = $this->storage->get($key);

        if (!$cached instanceof TokenData) {
            // Not a usable entry; drop it rather than leave it behind.
            $this->storage->remove($key);
            return;
        }

        if (
            hash_equals($cached->accessToken, $token)
            || ($cached->refreshToken !== null && hash_equals($cached->refreshToken, $token))
        ) {
            $this->storage->remove($key);
        }
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
