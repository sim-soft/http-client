<?php

namespace Simsoft\HttpClient\Responses;

use RuntimeException;
use Simsoft\HttpClient\Response;

/**
 * SimpleOAuth2Response class.
 */
class SimpleOAuth2Response extends Response
{
    /**
     * Get access token.
     *
     * @return string|null
     */
    public function getToken(): ?string
    {
        return $this->getAttribute('access_token');
    }

    /**
     * Determine token has expired.
     *
     * @return bool
     */
    public function hasExpired(): bool
    {
        if ($expires = $this->getExpiresIn()) {
            return $expires < time();
        }

        throw new RuntimeException('"expires" is not set on the token');
    }

    /**
     * Get expires in.
     *
     * @return int|null
     */
    public function getExpiresIn(): ?int
    {
        return $this->getAttribute('expires_in');
    }

    /**
     * Get access token.
     *
     * @return string|null
     */
    public function getRefreshToken(): ?string
    {
        return $this->getAttribute('refresh_token');
    }

    /**
     * Get token type.
     *
     * @return string|null
     */
    public function getTokenType(): ?string
    {
        return $this->getAttribute('token_type');
    }

    /**
     * Get scope.
     *
     * @return string|null
     */
    public function getScope(): ?string
    {
        return $this->getAttribute('scope');
    }
}
