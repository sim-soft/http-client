<?php

namespace Simsoft\HttpClient\Clients;

/**
 * TokenData class.
 *
 * Serializable value object representing an OAuth2 access token and its metadata.
 * Contains only scalar properties to ensure safe persistence in sessions, caches,
 * and databases.
 */
final class TokenData
{
    /**
     * Create a new TokenData instance.
     *
     * @param string $accessToken The OAuth2 access token string.
     * @param int $expiresAt Unix timestamp when the token expires.
     * @param string|null $refreshToken Refresh token for getting new access tokens.
     * @param string|null $tokenType Token type (typically "Bearer").
     * @param string|null $scope Granted scope string.
     */
    public function __construct(
        public readonly string $accessToken,
        public readonly int    $expiresAt,
        public readonly ?string $refreshToken = null,
        public readonly ?string $tokenType = null,
        public readonly ?string $scope = null,
    )
    {
    }

    /**
     * Determine whether this token has expired.
     *
     * Compares the current time against the stored expiry timestamp.
     *
     * @return bool True if the token has expired, false otherwise.
     */
    public function hasExpired(): bool
    {
        return time() >= $this->expiresAt;
    }

    /**
     * Convert to a plain array for storage backends that prefer arrays.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'expires_at' => $this->expiresAt,
            'refresh_token' => $this->refreshToken,
            'token_type' => $this->tokenType,
            'scope' => $this->scope,
        ];
    }

    /**
     * Reconstruct a TokenData from a plain array.
     *
     * @param array<string, mixed> $data Array with keys: access_token, expires_at,
     *                                   and optionally refresh_token, token_type, scope.
     *
     * @return static
     */
    public static function fromArray(array $data): static
    {
        return new static(
            accessToken: (string)($data['access_token'] ?? ''),
            expiresAt: (int)($data['expires_at'] ?? 0),
            refreshToken: isset($data['refresh_token']) ? (string)$data['refresh_token'] : null,
            tokenType: isset($data['token_type']) ? (string)$data['token_type'] : null,
            scope: isset($data['scope']) ? (string)$data['scope'] : null,
        );
    }
}
