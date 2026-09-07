<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests\Clients;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\HttpClient\Clients\Responses\OAuth2TokenResponse;

/**
 * OAuth2TokenResponseTest class
 *
 * Tests for the OAuth2TokenResponse class: typed accessors for standard
 * OAuth2 token endpoint fields.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class OAuth2TokenResponseTest extends TestCase
{
    /**
     * Create an OAuth2TokenResponse with the given body data.
     *
     * @param array<string, mixed> $data Response body data.
     * @param int $statusCode HTTP status code.
     * @return OAuth2TokenResponse
     */
    private function createResponse(array $data, int $statusCode = 200): OAuth2TokenResponse
    {
        return new OAuth2TokenResponse(
            curlInfo: ['http_code' => $statusCode],
            body: json_encode($data) ?: '',
        );
    }

    /**
     * Test getToken() returns the access_token value.
     *
     * @return void
     */
    #[Test]
    public function getTokenReturnsAccessToken(): void
    {
        $response = $this->createResponse(['access_token' => 'abc123']);

        $this->assertSame('abc123', $response->getToken());
    }

    /**
     * Test getToken() returns null when access_token is missing.
     *
     * @return void
     */
    #[Test]
    public function getTokenReturnsNullWhenMissing(): void
    {
        $response = $this->createResponse([]);

        $this->assertNull($response->getToken());
    }

    /**
     * Test getTokenType() returns the token_type value.
     *
     * @return void
     */
    #[Test]
    public function getTokenTypeReturnsValue(): void
    {
        $response = $this->createResponse(['token_type' => 'Bearer']);

        $this->assertSame('Bearer', $response->getTokenType());
    }

    /**
     * Test getTokenType() returns null when missing.
     *
     * @return void
     */
    #[Test]
    public function getTokenTypeReturnsNullWhenMissing(): void
    {
        $response = $this->createResponse([]);

        $this->assertNull($response->getTokenType());
    }

    /**
     * Test getExpiresIn() returns the expires_in value as integer.
     *
     * @return void
     */
    #[Test]
    public function getExpiresInReturnsInteger(): void
    {
        $response = $this->createResponse(['expires_in' => 3600]);

        $this->assertSame(3600, $response->getExpiresIn());
    }

    /**
     * Test getExpiresIn() returns null when missing.
     *
     * @return void
     */
    #[Test]
    public function getExpiresInReturnsNullWhenMissing(): void
    {
        $response = $this->createResponse([]);

        $this->assertNull($response->getExpiresIn());
    }

    /**
     * Test getExpiresAt() computes absolute timestamp from expires_in.
     *
     * @return void
     */
    #[Test]
    public function getExpiresAtComputesTimestamp(): void
    {
        $response = $this->createResponse(['expires_in' => 3600]);

        $expiresAt = $response->getExpiresAt();

        $this->assertNotNull($expiresAt);
        // Should be approximately now + 3600 (within 2 seconds tolerance)
        $this->assertEqualsWithDelta(time() + 3600, $expiresAt, 2);
    }

    /**
     * Test getExpiresAt() returns null when expires_in is missing.
     *
     * @return void
     */
    #[Test]
    public function getExpiresAtReturnsNullWhenExpiresInMissing(): void
    {
        $response = $this->createResponse([]);

        $this->assertNull($response->getExpiresAt());
    }

    /**
     * Test getRefreshToken() returns the refresh_token value.
     *
     * @return void
     */
    #[Test]
    public function getRefreshTokenReturnsValue(): void
    {
        $response = $this->createResponse(['refresh_token' => 'refresh_abc']);

        $this->assertSame('refresh_abc', $response->getRefreshToken());
    }

    /**
     * Test getRefreshToken() returns null when missing.
     *
     * @return void
     */
    #[Test]
    public function getRefreshTokenReturnsNullWhenMissing(): void
    {
        $response = $this->createResponse([]);

        $this->assertNull($response->getRefreshToken());
    }

    /**
     * Test getScope() returns the scope value.
     *
     * @return void
     */
    #[Test]
    public function getScopeReturnsValue(): void
    {
        $response = $this->createResponse(['scope' => 'read write']);

        $this->assertSame('read write', $response->getScope());
    }

    /**
     * Test getScope() returns null when missing.
     *
     * @return void
     */
    #[Test]
    public function getScopeReturnsNullWhenMissing(): void
    {
        $response = $this->createResponse([]);

        $this->assertNull($response->getScope());
    }

    /**
     * Test full token response with all fields populated.
     *
     * @return void
     */
    #[Test]
    public function fullResponseWithAllFields(): void
    {
        $response = $this->createResponse([
            'access_token' => 'eyJhbGciOiJSUzI1NiJ9',
            'token_type' => 'Bearer',
            'expires_in' => 7200,
            'refresh_token' => 'def50200abc',
            'scope' => 'openid profile email',
        ]);

        $this->assertSame('eyJhbGciOiJSUzI1NiJ9', $response->getToken());
        $this->assertSame('Bearer', $response->getTokenType());
        $this->assertSame(7200, $response->getExpiresIn());
        $this->assertNotNull($response->getExpiresAt());
        $this->assertSame('def50200abc', $response->getRefreshToken());
        $this->assertSame('openid profile email', $response->getScope());
    }

    /**
     * Test that numeric string values are cast correctly.
     *
     * @return void
     */
    #[Test]
    public function numericStringExpiresInIsCastToInt(): void
    {
        $response = $this->createResponse(['expires_in' => '3600']);

        $this->assertSame(3600, $response->getExpiresIn());
    }
}
