<?php

namespace Simsoft\HttpClient\Clients;

use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Simsoft\HttpClient\Clients\Helpers\SessionStorage;
use Simsoft\HttpClient\Interfaces\StorageInterface;
use Throwable;

/**
 * OAuth2 class.
 *
 * Handles OAuth2 token acquisition and refresh using the league/oauth2-client package.
 * Defaults to the client_credentials grant type, which is the most common server-to-server flow.
 *
 * Usage:
 *   class MyApiOAuth2 extends OAuth2 {
 *       protected string $accessTokenEndpoint = 'https://api.example.com/oauth/token';
 *   }
 *
 *   $token = MyApiOAuth2::request('client-id', 'client-secret')->getAccessToken();
 */
class OAuth2
{
    /** @var string Production access token endpoint. */
    protected string $accessTokenEndpoint = '';

    /** @var string Sandbox access token endpoint. */
    protected string $sandboxAccessTokenEndpoint = '';

    /** @var bool Sandbox mode flag. */
    protected bool $sandboxMode = false;

    /** @var GenericProvider|null Lazily initialized OAuth2 provider. */
    private ?GenericProvider $provider = null;

    /** @var bool Enable PKCE (Proof Key for Code Exchange). */
    protected bool $enablePKCE = false;

    /** @var string Token storage key prefix. */
    protected string $tokenStorageName = 'oauth_token';

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
     * @param StorageInterface|null $storage Custom storage implementation. Defaults to SessionStorage.
     */
    public function __construct(
        protected string  $clientId,
        protected string  $clientSecret,
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
    public static function request(
        string            $clientId,
        string            $clientSecret,
        ?StorageInterface $storage = null,
    ): static
    {
        return new static($clientId, $clientSecret, $storage);
    }

    /**
     * Enable sandbox mode — uses $sandboxAccessTokenEndpoint instead of $accessTokenEndpoint.
     *
     * @return $this
     */
    public function sandbox(): self
    {
        $this->sandboxMode = true;
        $this->provider = null; // reset the provider so it rebuilds with the sandbox URL
        return $this;
    }

    /**
     * Get the active token endpoint URL.
     *
     * @return string
     */
    public function getEndpoint(): string
    {
        return $this->sandboxMode
            ? $this->sandboxAccessTokenEndpoint
            : $this->accessTokenEndpoint;
    }

    /**
     * Get (or lazily initialize) the OAuth2 GenericProvider.
     *
     * @return GenericProvider
     */
    public function getProvider(): GenericProvider
    {
        if ($this->provider === null) {
            $this->provider = new GenericProvider([
                'clientId' => $this->clientId,
                'clientSecret' => $this->clientSecret,
                'urlAuthorize' => '',
                'urlAccessToken' => $this->getEndpoint(),
                'urlResourceOwnerDetails' => '',
                'pkceMethod' => $this->enablePKCE
                    ? AbstractProvider::PKCE_METHOD_S256
                    : null,
            ]);
        }

        return $this->provider;
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
            if ($this->storage->has($this->clientId)) {
                /** @var AccessTokenInterface $token */
                $token = $this->storage->get($this->clientId);

                if ($token->hasExpired()) {
                    $refreshToken = $token->getRefreshToken();

                    if ($refreshToken !== null) {
                        // Refresh the token using the refresh_token grant
                        $token = $this->refreshToken($token);
                    } else {
                        // No refresh token available — acquire a fresh one
                        $token = $this->fetchNewToken();
                    }

                    $this->storage->set($this->clientId, $token);
                }

                return $token->getToken();
            }

            $token = $this->fetchNewToken();
            $this->storage->set($this->clientId, $token);
            return $token->getToken();

        } catch (Throwable $throwable) {
            error_log(sprintf(
                '[OAuth2] Failed to get access token for client "%s": %s',
                $this->clientId,
                $throwable->getMessage()
            ));
        }

        return null;
    }

    /**
     * Fetch a fresh access token using the configured grant type.
     *
     * @return AccessTokenInterface
     * @throws IdentityProviderException
     * @throws GuzzleException
     */
    protected function fetchNewToken(): AccessTokenInterface
    {
        $params = array_filter([
            'scope' => $this->scope, // array_filter removes a null scope
        ]);

        return $this->getProvider()->getAccessToken($this->grantType, $params);
    }

    /**
     * Refresh an existing access token using its refresh token.
     *
     * @param AccessTokenInterface $token The expired token with a refresh token.
     * @return AccessTokenInterface
     * @throws IdentityProviderException
     * @throws InvalidArgumentException|GuzzleException If the token has no refresh token.
     */
    public function refreshToken(AccessTokenInterface $token): AccessTokenInterface
    {
        $refreshToken = $token->getRefreshToken();

        if ($refreshToken === null) {
            throw new InvalidArgumentException(
                'Cannot refresh token: no refresh token is available. '
                . 'The current grant type may not support refresh tokens.'
            );
        }

        return $this->getProvider()->getAccessToken('refresh_token', [
            'refresh_token' => $refreshToken,
        ]);
    }
}
