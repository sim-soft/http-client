<?php

namespace Simsoft\HttpClient;

use Exception;
use Simsoft\HttpClient\Interfaces\StorageInterface;
use Simsoft\HttpClient\Responses\SimpleOAuth2Response;
use Throwable;

/**
 * SimpleOAuth2Client class.
 */
abstract class SimpleOAuth2Client extends HttpClient
{
    /** @var string Token storage name. */
    protected string $tokenStorageName = 'oauth_token';

    /** @var string Response object class. */
    protected string $responseClass = SimpleOAuth2Response::class;

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
     * Execute request to get access token.
     *
     * @return Response
     */
    abstract protected function postRequest(): Response;

    /**
     * Factory method.
     *
     * @param string $clientId
     * @param string $clientSecret
     * @return static
     */
    public static function make(string $clientId, string $clientSecret): static
    {
        return new static($clientId, $clientSecret);
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
                /** @var SimpleOAuth2Response $token */
                $token = $this->storage->get($this->clientId);
                if ($token->hasExpired()) {
                    $this->storage->remove($this->clientId);
                } else {
                    return $token->getToken();
                }
            }

            /** @var SimpleOAuth2Response $response */
            $response = $this->postRequest();
            if ($response->ok()) {
                $this->storage->set($this->clientId, $response);
                return $response->getToken();
            }

            throw new Exception($response->getMessage() ?? '');

        } catch (Throwable $throwable) {
            error_log($throwable->getMessage());
        }

        return null;
    }
}
