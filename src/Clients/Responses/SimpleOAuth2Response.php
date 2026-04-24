<?php

namespace Simsoft\HttpClient\Clients\Responses;

use Simsoft\HttpClient\Response;

/**
 * SimpleOAuth2Response class.
 *
 * Wraps a standard OAuth2 token endpoint response, providing typed accessors
 * for the common fields: access_token, refresh_token, expires_in, token_type, scope.
 *
 * IMPORTANT — Session storage:
 * This object contains a StreamInterface property (inherited from Response)
 * which is NOT serializable. Do not store instances of this class directly
 * in $_SESSION or any serialized cache.
 * Instead, extract a plain serializable TokenData object for persistence:
 *
 *   $tokenData = $response->toTokenData();
 *   $storage->set($key, $tokenData);
 */
class SimpleOAuth2Response extends Response
{
    /**
     * Absolute Unix timestamp when this token expires.
     * Computed once from expires_in on the first call to getExpiresAt().
     *
     * @var int|null
     */
    protected ?int $expiresAt = null;

    /**
     * Get the access token string.
     *
     * @return string|null
     */
    public function getToken(): ?string
    {
        $value = $this->data('access_token');
        return $value !== null ? (string)$value : null;
    }

    /**
     * Get the relative token lifetime in seconds as returned by the server.
     *
     * NOTE: This is a duration (e.g., 3600), not a Unix timestamp.
     * Use getExpiresAt() for an absolute expiry timestamp.
     *
     * @return int|null
     */
    public function getExpiresIn(): ?int
    {
        $value = $this->data('expires_in');
        return $value !== null ? (int)$value : null;
    }

    /**
     * Get the absolute Unix timestamp at which this token expires.
     *
     * Includes a 30-second safety buffer to avoid using a token that
     * expires in the middle of a request.
     *
     * Returns null if the server did not include expires_in.
     *
     * @return int|null
     */
    public function getExpiresAt(): ?int
    {
        if ($this->expiresAt === null) {
            $expiresIn = $this->getExpiresIn();
            if ($expiresIn !== null) {
                $this->expiresAt = time() + $expiresIn - 30;
            }
        }
        return $this->expiresAt;
    }

    /**
     * Determine whether this token has expired.
     *
     * Returns true if expires_in was not provided (safe default — forces re-fetch).
     *
     * @return bool
     */
    public function hasExpired(): bool
    {
        $expiresAt = $this->getExpiresAt();

        if ($expiresAt === null) {
            // No expiry information — treat as expired to force a fresh token fetch.
            return true;
        }

        return time() >= $expiresAt;
    }

    /**
     * Get the refresh token string.
     *
     * @return string|null
     */
    public function getRefreshToken(): ?string
    {
        $value = $this->data('refresh_token');
        return $value !== null ? (string)$value : null;
    }

    /**
     * Get the token type (e.g. "Bearer").
     *
     * @return string|null
     */
    public function getTokenType(): ?string
    {
        $value = $this->data('token_type');
        return $value !== null ? (string)$value : null;
    }

    /**
     * Get the granted scope string.
     *
     * @return string|null
     */
    public function getScope(): ?string
    {
        $value = $this->data('scope');
        return $value !== null ? (string)$value : null;
    }
}
