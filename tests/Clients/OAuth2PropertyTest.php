<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests\Clients;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QuickCheck\Generator as Gen;
use QuickCheck\PHPUnit\PropertyConstraint;
use QuickCheck\Property;
use RuntimeException;
use Simsoft\HttpClient\Clients\OAuth2;
use Simsoft\HttpClient\Clients\Responses\OAuth2TokenResponse;
use Simsoft\HttpClient\Clients\TokenData;
use Simsoft\HttpClient\Interfaces\StorageInterface;

/**
 * InMemoryStorage class.
 *
 * Simple in-memory StorageInterface implementation for property-based testing.
 */
class InMemoryStorage implements StorageInterface
{
    /** @var array<string, mixed> Internal storage array. */
    private array $data = [];

    /**
     * Determine if storage has key.
     *
     * @param string $key The storage key.
     * @return bool
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * Set storage value with key.
     *
     * @param string $key The storage key.
     * @param mixed $value The value to store.
     * @return void
     */
    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    /**
     * Get storage value by key.
     *
     * @param string $key The storage key.
     * @return mixed
     */
    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    /**
     * Remove storage value by key.
     *
     * @param string $key The storage key.
     * @return void
     */
    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }
}

/**
 * PropertyTestOAuth2 class.
 *
 * Concrete test subclass of OAuth2 for property-based testing.
 * Allows configuring grant type and controlling buildTokenRequest behavior.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class PropertyTestOAuth2 extends OAuth2
{
    /** @var string Production access token endpoint. */
    protected string $accessTokenEndpoint = 'https://example.com/oauth/token';

    /** @var OAuth2TokenResponse|null Response to return from buildTokenRequest(). */
    public ?OAuth2TokenResponse $nextResponse = null;

    /** @var \Throwable|null Exception to throw from buildTokenRequest(). */
    public ?\Throwable $nextException = null;

    /** @var array<int, array<string, string>> Captured request params. */
    public array $capturedParams = [];

    /** @var int Count of buildTokenRequest() calls. */
    public int $requestCount = 0;

    /**
     * Set the grant type for testing.
     *
     * @param string $grantType The grant type value.
     * @return void
     */
    public function setGrantType(string $grantType): void
    {
        $this->grantType = $grantType;
    }

    /**
     * Override buildTokenRequest to return controlled responses.
     *
     * @param array<string, string> $params Form parameters.
     * @return OAuth2TokenResponse
     */
    protected function buildTokenRequest(array $params): OAuth2TokenResponse
    {
        $this->capturedParams[] = $params;
        $this->requestCount++;

        if ($this->nextException !== null) {
            throw $this->nextException;
        }

        if ($this->nextResponse !== null) {
            return $this->nextResponse;
        }

        return new OAuth2TokenResponse(
            curlInfo: ['http_code' => 200],
            body: json_encode([
                'access_token' => 'default-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ], JSON_THROW_ON_ERROR),
        );
    }
}

/**
 * OAuth2PropertyTest class.
 *
 * Property-based tests for the OAuth2 client class.
 * Validates caching behavior, storage semantics, grant type propagation,
 * and exception safety across many generated inputs.
 *
 * Feature: standalone-oauth2, Property 3: Cached non-expired token returned without network call
 * Feature: standalone-oauth2, Property 4: Successful acquisition stores TokenData keyed by client ID
 * Feature: standalone-oauth2, Property 5: Custom grant type propagates to request body
 * Feature: standalone-oauth2, Property 6: Exception safety
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class OAuth2PropertyTest extends TestCase
{
    /**
     * Property 3: Cached non-expired token returned without network call.
     *
     * For any TokenData instance where hasExpired() returns false, when that token
     * is present in storage keyed by the client ID, getAccessToken() returns the
     * accessToken string without making any HTTP request.
     *
     * **Validates: Requirements 3.1**
     *
     * @return void
     */
    #[Test]
    public function cachedNonExpiredTokenReturnedWithoutNetworkCall(): void
    {
        $property = Property::forAll(
            [
                Gen::asciiStrings()->notEmpty(),
                Gen::choose(1, 100000),
            ],
            function (string $accessToken, int $futureOffset): bool {
                $clientId = 'test-client';
                $storage = new InMemoryStorage();

                $token = new TokenData(
                    accessToken: $accessToken,
                    expiresAt: time() + $futureOffset,
                );

                $storage->set($clientId, $token);

                $oauth = new PropertyTestOAuth2($clientId, 'secret', $storage);
                $result = $oauth->getAccessToken();

                return $result === $accessToken && $oauth->requestCount === 0;
            }
        );

        $this->assertThat(
            $property,
            PropertyConstraint::check(100)
        );
    }

    /**
     * Property 4: Successful acquisition stores TokenData keyed by client ID.
     *
     * For any client ID and any successful token endpoint response containing a valid
     * access_token and expires_in, the value stored in StorageInterface is a TokenData
     * instance stored under the key equal to the client ID.
     *
     * **Validates: Requirements 3.4, 3.5**
     *
     * @return void
     */
    #[Test]
    public function successfulAcquisitionStoresTokenDataKeyedByClientId(): void
    {
        $property = Property::forAll(
            [
                Gen::asciiStrings()->notEmpty(),
                Gen::asciiStrings()->notEmpty(),
                Gen::choose(60, 7200),
            ],
            function (string $clientId, string $accessToken, int $expiresIn): bool {
                $storage = new InMemoryStorage();

                $oauth = new PropertyTestOAuth2($clientId, 'secret', $storage);
                $oauth->nextResponse = new OAuth2TokenResponse(
                    curlInfo: ['http_code' => 200],
                    body: json_encode([
                        'access_token' => $accessToken,
                        'token_type' => 'Bearer',
                        'expires_in' => $expiresIn,
                    ], JSON_THROW_ON_ERROR),
                );

                $oauth->getAccessToken();

                if (!$storage->has($clientId)) {
                    return false;
                }

                $stored = $storage->get($clientId);

                return $stored instanceof TokenData
                    && $stored->accessToken === $accessToken;
            }
        );

        $this->assertThat(
            $property,
            PropertyConstraint::check(100)
        );
    }

    /**
     * Property 5: Custom grant type propagates to request body.
     *
     * For any non-empty string set as the grantType property, when fetchNewToken()
     * is called, the POST body contains a grant_type parameter with that exact value.
     *
     * **Validates: Requirements 6.3**
     *
     * @return void
     */
    #[Test]
    public function customGrantTypePropagatestoRequestBody(): void
    {
        $property = Property::forAll(
            [Gen::asciiStrings()->notEmpty()],
            function (string $grantType): bool {
                $storage = new InMemoryStorage();

                $oauth = new PropertyTestOAuth2('client-id', 'secret', $storage);
                $oauth->setGrantType($grantType);

                $oauth->getAccessToken();

                if (empty($oauth->capturedParams)) {
                    return false;
                }

                return $oauth->capturedParams[0]['grant_type'] === $grantType;
            }
        );

        $this->assertThat(
            $property,
            PropertyConstraint::check(100)
        );
    }

    /**
     * Property 6: Exception safety.
     *
     * For any Throwable thrown during token acquisition, getAccessToken() returns
     * null without propagating the exception to the caller.
     *
     * **Validates: Requirements 9.2**
     *
     * @return void
     */
    #[Test]
    public function exceptionSafety(): void
    {
        $property = Property::forAll(
            [Gen::asciiStrings()->notEmpty()],
            function (string $message): bool {
                $storage = new InMemoryStorage();

                $oauth = new PropertyTestOAuth2('client-id', 'secret', $storage);
                $oauth->nextException = new RuntimeException($message);

                $result = @$oauth->getAccessToken();

                return $result === null;
            }
        );

        $this->assertThat(
            $property,
            PropertyConstraint::check(100)
        );
    }
}
