<?php

namespace Simsoft\HttpClient\Clients\Responses;

use Simsoft\HttpClient\Response;

/**
 * OAuth2TokenResponse class.
 *
 * Parses standard OAuth2 token endpoint JSON responses, providing typed
 * accessors for access_token, token_type, expires_in, refresh_token, and scope.
 *
 * Used internally by the standalone OAuth2 client to extract token data
 * from authorization server responses without any external dependencies.
 */
class OAuth2TokenResponse extends Response
{
    /**
     * Get the access token string.
     *
     * @return string|null The access token, or null if not present in the response.
     */
    public function getToken(): ?string
    {
        $value = $this->data('access_token');
        return $value !== null ? (string)$value : null;
    }

    /**
     * Get the token type (typically "Bearer").
     *
     * @return string|null The token type, or null if not present in the response.
     */
    public function getTokenType(): ?string
    {
        $value = $this->data('token_type');
        return $value !== null ? (string)$value : null;
    }

    /**
     * Get the token lifetime in seconds as returned by the server.
     *
     * NOTE: This is a relative duration (e.g., 3600), not a Unix timestamp.
     * Use getExpiresAt() for an absolute expiry timestamp.
     *
     * @return int|null The lifetime in seconds, or null if not present in the response.
     */
    public function getExpiresIn(): ?int
    {
        $value = $this->data('expires_in');
        return $value !== null ? (int)$value : null;
    }

    /**
     * Get the absolute Unix timestamp at which this token expires.
     *
     * Computed by adding the expires_in duration to the current time.
     * Returns null if the server did not include expires_in.
     *
     * @return int|null The expiry timestamp, or null if expires_in is not available.
     */
    public function getExpiresAt(): ?int
    {
        $expiresIn = $this->getExpiresIn();
        return $expiresIn !== null ? time() + $expiresIn : null;
    }

    /**
     * Get the refresh token string.
     *
     * @return string|null The refresh token, or null if not present in the response.
     */
    public function getRefreshToken(): ?string
    {
        $value = $this->data('refresh_token');
        return $value !== null ? (string)$value : null;
    }

    /**
     * Get the granted scope string.
     *
     * @return string|null The scope, or null if not present in the response.
     */
    public function getScope(): ?string
    {
        $value = $this->data('scope');
        return $value !== null ? (string)$value : null;
    }
}
