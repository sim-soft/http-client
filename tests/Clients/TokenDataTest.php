<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests\Clients;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\HttpClient\Clients\TokenData;

/**
 * TokenDataTest class.
 *
 * Unit tests for the TokenData value object covering construction,
 * expiry detection, array serialization, and reconstruction.
 */
class TokenDataTest extends TestCase
{
    #[Test]
    public function constructionWithAllParameters(): void
    {
        $token = new TokenData(
            accessToken: 'abc123',
            expiresAt: 1700000000,
            refreshToken: 'refresh-xyz',
            tokenType: 'Bearer',
            scope: 'read write',
        );

        $this->assertSame('abc123', $token->accessToken);
        $this->assertSame(1700000000, $token->expiresAt);
        $this->assertSame('refresh-xyz', $token->refreshToken);
        $this->assertSame('Bearer', $token->tokenType);
        $this->assertSame('read write', $token->scope);
    }

    #[Test]
    public function constructionWithOnlyRequiredParameters(): void
    {
        $token = new TokenData(
            accessToken: 'token-only',
            expiresAt: 1700000000,
        );

        $this->assertSame('token-only', $token->accessToken);
        $this->assertSame(1700000000, $token->expiresAt);
        $this->assertNull($token->refreshToken);
        $this->assertNull($token->tokenType);
        $this->assertNull($token->scope);
    }

    #[Test]
    public function hasExpiredReturnsTrueWhenExpiresAtIsInThePast(): void
    {
        $token = new TokenData(
            accessToken: 'expired-token',
            expiresAt: time() - 3600,
        );

        $this->assertTrue($token->hasExpired());
    }

    #[Test]
    public function hasExpiredReturnsFalseWhenExpiresAtIsInTheFuture(): void
    {
        $token = new TokenData(
            accessToken: 'valid-token',
            expiresAt: time() + 3600,
        );

        $this->assertFalse($token->hasExpired());
    }

    #[Test]
    public function toArrayProducesExpectedStructure(): void
    {
        $token = new TokenData(
            accessToken: 'my-token',
            expiresAt: 1700000000,
            refreshToken: 'my-refresh',
            tokenType: 'Bearer',
            scope: 'admin',
        );

        $expected = [
            'access_token' => 'my-token',
            'expires_at' => 1700000000,
            'refresh_token' => 'my-refresh',
            'token_type' => 'Bearer',
            'scope' => 'admin',
        ];

        $this->assertSame($expected, $token->toArray());
    }

    #[Test]
    public function toArrayIncludesNullsForOptionalFields(): void
    {
        $token = new TokenData(
            accessToken: 'minimal',
            expiresAt: 1700000000,
        );

        $result = $token->toArray();

        $this->assertNull($result['refresh_token']);
        $this->assertNull($result['token_type']);
        $this->assertNull($result['scope']);
    }

    #[Test]
    public function fromArrayReconstructsIdenticalObject(): void
    {
        $original = new TokenData(
            accessToken: 'round-trip',
            expiresAt: 1700000000,
            refreshToken: 'refresh-rt',
            tokenType: 'Bearer',
            scope: 'read',
        );

        $reconstructed = TokenData::fromArray($original->toArray());

        $this->assertSame($original->accessToken, $reconstructed->accessToken);
        $this->assertSame($original->expiresAt, $reconstructed->expiresAt);
        $this->assertSame($original->refreshToken, $reconstructed->refreshToken);
        $this->assertSame($original->tokenType, $reconstructed->tokenType);
        $this->assertSame($original->scope, $reconstructed->scope);
    }

    #[Test]
    public function fromArrayHandlesMissingOptionalFields(): void
    {
        $token = TokenData::fromArray([
            'access_token' => 'partial-token',
            'expires_at' => 1700000000,
        ]);

        $this->assertSame('partial-token', $token->accessToken);
        $this->assertSame(1700000000, $token->expiresAt);
        $this->assertNull($token->refreshToken);
        $this->assertNull($token->tokenType);
        $this->assertNull($token->scope);
    }

    #[Test]
    public function fromArrayHandlesCompletelyEmptyArray(): void
    {
        $token = TokenData::fromArray([]);

        $this->assertSame('', $token->accessToken);
        $this->assertSame(0, $token->expiresAt);
        $this->assertNull($token->refreshToken);
        $this->assertNull($token->tokenType);
        $this->assertNull($token->scope);
    }
}
