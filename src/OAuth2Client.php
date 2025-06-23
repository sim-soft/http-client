<?php

namespace Simsoft\HttpClient;

use GuzzleHttp\Exception\GuzzleException;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Simsoft\HttpClient\Interfaces\StorageInterface;

/**
 * OAuth2Client class.
 */
class OAuth2Client
{
    /** @var string Production endpoint */
    protected string $accessTokenEndpoint = '';

    /** @var string Sandbox endpoint. */
    protected string $sandboxAccessTokenEndpoint = '';

    /** @var bool Determine is sandbox mode. */
    protected bool $sandboxMode = false;

    /** @var GenericProvider|null Generic provider that handle access token request. */
    private ?GenericProvider $provider = null;

    /** @var bool Enable PKCE (Proof Key for Code Exchange) */
    private bool $enablePKCE = false;

    /** @var string Token storage name. */
    protected string $tokenStorageName = 'oauth_token';

    /** @var string Grant type. */
    protected string $grantType = 'authorization_code';

    /** @var string|null Scope. */
    protected ?string $scope = null;

    /** @var StorageInterface Storage for tokens.  */
    protected StorageInterface $storage;

    /**
     * Construct
     *
     * @param string $clientId Client ID.
     * @param string $clientSecret Client secret.
     */
    final public function __construct(protected string $clientId, protected string $clientSecret)
    {
        $this->storage = new SessionStorage($this->tokenStorageName);
    }

    /**
     * Factory method.
     *
     * @param string $clientId
     * @param string $clientSecret
     * @return static
     */
    public static function request(string $clientId, string $clientSecret): static
    {
        return new static($clientId, $clientSecret);
    }

    /**
     * Get provider.
     *
     * @return GenericProvider
     */
    public function getProvider(): GenericProvider
    {
        if($this->provider === null){
            $this->provider = new GenericProvider([
                'clientId' => $this->clientId,
                'clientSecret' => $this->clientSecret,
                'urlAuthorize' => '',
                'urlAccessToken' => $this->getEndPoint(),
                'urlResourceOwnerDetails' => '',
                'pkceMethod' => $this->enablePKCE ? AbstractProvider::PKCE_METHOD_S256: null,
            ]);
        }
        return $this->provider;
    }

    /**
     * Enable sandbox mode.
     *
     * @return $this
     */
    public function sandbox(): self
    {
        $this->sandboxMode = true;
        return $this;
    }

    /**
     * Get endpoint.
     *
     * @return string
     */
    public function getEndpoint(): string
    {
        return $this->sandboxMode ? $this->sandboxAccessTokenEndpoint:  $this->accessTokenEndpoint;
    }

    /**
     * Get access token.
     *
     * @return string|null
     */
    public function getAccessToken(): ?string
    {
        try {
            if ($this->storage->has($this->clientId)) {
                $token = $this->storage->get($this->clientId);
                if ($token->hasExpired()) {
                    $token = $this->refreshToken($token);
                    $this->storage->set($this->clientId, $token);
                    return $token;
                } else {
                    return $this->storage->get($this->clientId)->getToken();
                }
            }

            $token = $this->getProvider()->getAccessToken($this->grantType, [
                'scope' => $this->scope,
            ]);
            $this->storage->set($this->clientId, $token);
            return $token->getToken();

        } catch (GuzzleException|IdentityProviderException $throwable) {
            error_log($throwable->getMessage());
        }

        return null;
    }

    /**
     * @throws GuzzleException
     * @throws IdentityProviderException
     */
    public function refreshToken(AccessTokenInterface $token): AccessTokenInterface
    {
        return $this->getProvider()->getAccessToken('refresh_token', [
            'refresh_token' => $token->getRefreshToken(),
        ]);
    }
}
