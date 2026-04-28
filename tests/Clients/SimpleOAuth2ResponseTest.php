<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests\Clients;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\HttpClient\Clients\Responses\SimpleOAuth2Response;

/**
 * SimpleOAuth2ResponseTest class
 *
 * Tests for SimpleOAuth2Response accessor methods: getToken(), getExpiresIn(),
 * getExpiresAt(), hasExpired(), getRefreshToken(), getTokenType(), getScope().
 */
class SimpleOAuth2ResponseTest extends TestCase
{
    /** @var SimpleOAuth2Response Response built from the oauth2-token.json fixture. */
    private SimpleOAuth2Response $response;

    /**
     * Set up a response from the oauth2-token.json fixture before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $fixturePath = __DIR__ . '/../fixtures/oauth2-token.json';
        $body = (string)file_get_contents($fixturePath);

        $this->response = new SimpleOAuth2Response(
            curlInfo: ['http_code' => 200],
            body: $body,
            rawHeaders: "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n",
        );
    }

    /**
     * Test that getToken() returns the access_token from the fixture.
     *
     * @return void
     */
    #[Test]
    public function getTokenReturnsAccessToken(): void
    {
        $this->assertSame(
            'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.test',
            $this->response->getToken(),
        );
    }

    /**
     * Test that getExpiresIn() returns the expires_in value as integer.
     *
     * @return void
     */
    #[Test]
    public function getExpiresInReturnsIntegerSeconds(): void
    {
        $this->assertSame(3600, $this->response->getExpiresIn());
    }

    /**
     * Test that getExpiresAt() returns a future Unix timestamp.
     *
     * @return void
     */
    #[Test]
    public function getExpiresAtReturnsFutureTimestamp(): void
    {
        $expiresAt = $this->response->getExpiresAt();

        $this->assertNotNull($expiresAt);
        $this->assertGreaterThan(time(), $expiresAt);

        $expectedApprox = time() + 3600 - 30;
        $this->assertEqualsWithDelta($expectedApprox, $expiresAt, 2);
    }

    /**
     * Test that hasExpired() returns false for a freshly created token.
     *
     * @return void
     */
    #[Test]
    public function hasExpiredReturnsFalseForFreshToken(): void
    {
        $this->assertFalse($this->response->hasExpired());
    }

    /**
     * Test that hasExpired() returns true when no expires_in is present.
     *
     * @return void
     */
    #[Test]
    public function hasExpiredReturnsTrueWhenNoExpiresIn(): void
    {
        $response = new SimpleOAuth2Response(
            curlInfo: ['http_code' => 200],
            body: json_encode(['access_token' => 'token-no-expiry']) ?: '',
        );

        $this->assertTrue($response->hasExpired());
    }

    /**
     * Test that getRefreshToken() returns the refresh_token from the fixture.
     *
     * @return void
     */
    #[Test]
    public function getRefreshTokenReturnsRefreshToken(): void
    {
        $this->assertSame('def50200abc123refresh', $this->response->getRefreshToken());
    }

    /**
     * Test that getTokenType() returns the token_type from the fixture.
     *
     * @return void
     */
    #[Test]
    public function getTokenTypeReturnsTokenType(): void
    {
        $this->assertSame('Bearer', $this->response->getTokenType());
    }

    /**
     * Test that getScope() returns the scope from the fixture.
     *
     * @return void
     */
    #[Test]
    public function getScopeReturnsScope(): void
    {
        $this->assertSame('read write', $this->response->getScope());
    }

    /**
     * Test that accessors return null when fields are missing from the body.
     *
     * @return void
     */
    #[Test]
    public function accessorsReturnNullForMissingFields(): void
    {
        $response = new SimpleOAuth2Response(
            curlInfo: ['http_code' => 200],
            body: json_encode(['status' => 'ok']) ?: '',
        );

        $this->assertNull($response->getToken());
        $this->assertNull($response->getExpiresIn());
        $this->assertNull($response->getExpiresAt());
        $this->assertNull($response->getRefreshToken());
        $this->assertNull($response->getTokenType());
        $this->assertNull($response->getScope());
    }
}
