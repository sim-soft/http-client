<?php

namespace Simsoft\HttpClient\Clients;

use Exception;
use Simsoft\HttpClient\Clients\Helpers\SessionStorage;
use Simsoft\HttpClient\Clients\Responses\SimpleOAuth2Response;
use Simsoft\HttpClient\Clients\TokenData;
use Simsoft\HttpClient\HttpClient;
use Simsoft\HttpClient\Interfaces\StorageInterface;
use Throwable;

/**
 * SimpleOAuth2 class.
 *
 * Abstract base for OAuth2-authenticated API clients that manage their own
 * access token lifecycle without requiring the league/oauth2-client package.
 *
 * Subclasses must implement postRequest() to define how the token is acquired
 * (e.g., client_credentials POST to the token endpoint).
 *
 * Usage:
 *   class MyApiClient extends SimpleOAuth2 {
 *       protected function postRequest(): SimpleOAuth2Response {
 *           return $this->withBaseUrl('https://api.example.com')
 *               ->withForm(['grant_type' => 'client_credentials'])
 *               ->withBasicAuth($this->clientId, $this->clientSecret)
 *               ->post('/oauth/token');
 *       }
 *   }
 *
 *   $token = MyApiClient::makeWith('client-id', 'client-secret')->getAccessToken();
 */
abstract class SimpleOAuth2 extends HttpClient
{
    /** @var string Token storage key prefix. */
    protected string $tokenStorageName = 'oauth_token';

    /** @var string Response class */
    protected string $responseClass = SimpleOAuth2Response::class;

    /** @var StorageInterface Token persistence storage. */
    protected StorageInterface $storage;

    /**
     * Constructor.
     *
     * @param string $clientId OAuth2 client ID.
     * @param string $clientSecret OAuth2 client secret.
     * @param StorageInterface|null $storage Custom storage. Defaults to SessionStorage.
     */
    final public function __construct(
        protected string $clientId,
        protected string $clientSecret,
        ?StorageInterface $storage = null,
    )
    {
        $this->storage = $storage ?? new SessionStorage($this->tokenStorageName);
    }

    /**
     * Factory method.
     *
     * @param string $clientId
     * @param string $clientSecret
     * @param StorageInterface|null $storage
     * @return static
     */
    public static function makeWith(
        string $clientId,
        string $clientSecret,
        ?StorageInterface $storage = null,
    ): static
    {
        return new static($clientId, $clientSecret, $storage);
    }

    /**
     * Execute the token request.
     *
     * Implement this method to perform the actual HTTP request to the token
     * endpoint. Each call must set up the full request (base URL, headers,
     * body) since flush() clears the state after every request.
     *
     * @return SimpleOAuth2Response
     */
    abstract protected function postRequest(): SimpleOAuth2Response;

    /**
     * Get a valid access token string, acquiring a new one if needed.
     *
     * Returns null on failure — check error_log() for details.
     *
     * @return string|null
     */
    public function getAccessToken(): ?string
    {
        try {
            if ($this->storage->has($this->clientId)) {
                /** @var TokenData $tokenData */
                $tokenData = $this->storage->get($this->clientId);

                if (!$tokenData->hasExpired()) {
                    return $tokenData->accessToken;
                }

                // Token expired — remove it and fall through to acquire a new one
                $this->storage->remove($this->clientId);
            }

            $response = $this->postRequest();

            if ($response->ok()) {
                $tokenData = $this->toTokenData($response);
                $this->storage->set($this->clientId, $tokenData);
                return $tokenData->accessToken;
            }

            throw new Exception(sprintf(
                'Token request failed [HTTP %d]: %s',
                $response->getStatusCode(),
                $response->getMessage() ?? 'Unknown error'
            ));
        } catch (Throwable $throwable) {
            error_log(sprintf(
                '[SimpleOAuth2] Failed to get access token for client "%s": %s',
                $this->clientId,
                $throwable->getMessage()
            ));
        }

        return null;
    }

    /**
     * Convert a SimpleOAuth2Response to a serializable TokenData value object.
     *
     * Applies a 30-second safety buffer to the expiry time to account for
     * clock skew and network latency.
     *
     * @param SimpleOAuth2Response $response The parsed token response.
     * @return TokenData
     */
    protected function toTokenData(SimpleOAuth2Response $response): TokenData
    {
        $expiresAt = $response->getExpiresAt();
        $safeExpiresAt = $expiresAt ?? (time() + 3570);

        return new TokenData(
            accessToken: (string)$response->getToken(),
            expiresAt: $safeExpiresAt,
            refreshToken: $response->getRefreshToken(),
            tokenType: $response->getTokenType(),
            scope: $response->getScope(),
        );
    }
}
