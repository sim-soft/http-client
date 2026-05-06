<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests\Clients;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QuickCheck\Generator as Gen;
use QuickCheck\PHPUnit\PropertyConstraint;
use QuickCheck\Property;
use Simsoft\HttpClient\Clients\TokenData;

/**
 * TokenDataPropertyTest class.
 *
 * Property-based tests for the TokenData value object.
 * Validates serialization round-trip and expiry detection correctness
 * across many generated inputs.
 *
 * Feature: standalone-oauth2, Property 1: TokenData serialization round-trip
 * Feature: standalone-oauth2, Property 2: Token expiry detection correctness
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TokenDataPropertyTest extends TestCase
{
    /**
     * Property 1: TokenData serialization round-trip.
     *
     * For any valid TokenData instance, unserialize(serialize($token))
     * produces identical property values.
     *
     * **Validates: Requirements 8.2**
     *
     * @return void
     */
    #[Test]
    public function tokenDataSerializationRoundTrip(): void
    {
        $property = Property::forAll(
            [
                Gen::asciiStrings()->notEmpty(),
                Gen::ints(),
                Gen::oneOf(Gen::asciiStrings()->notEmpty(), Gen::choose(0, 0)->map(fn() => null)),
                Gen::oneOf(Gen::asciiStrings()->notEmpty(), Gen::choose(0, 0)->map(fn() => null)),
                Gen::oneOf(Gen::asciiStrings()->notEmpty(), Gen::choose(0, 0)->map(fn() => null)),
            ],
            function (
                string  $accessToken,
                int     $expiresAt,
                ?string $refreshToken,
                ?string $tokenType,
                ?string $scope,
            ): bool {
                $original = new TokenData(
                    accessToken: $accessToken,
                    expiresAt: $expiresAt,
                    refreshToken: $refreshToken,
                    tokenType: $tokenType,
                    scope: $scope,
                );

                /** @var TokenData $restored */
                $restored = unserialize(serialize($original));

                return $restored->accessToken === $original->accessToken
                    && $restored->expiresAt === $original->expiresAt
                    && $restored->refreshToken === $original->refreshToken
                    && $restored->tokenType === $original->tokenType
                    && $restored->scope === $original->scope;
            }
        );

        $this->assertThat(
            $property,
            PropertyConstraint::check(100)
        );
    }

    /**
     * Property 2: Token expiry detection correctness.
     *
     * For any integer expiresAt, hasExpired() returns true iff time() >= expiresAt.
     * Uses timestamps far in the future for "not expired" and timestamps in the past
     * for "expired" to avoid flaky tests at the boundary.
     *
     * **Validates: Requirements 8.3**
     *
     * @return void
     */
    #[Test]
    public function tokenExpiryDetectionCorrectness(): void
    {
        $property = Property::forAll(
            [Gen::choose(1, 100000)],
            function (int $offset): bool {
                $now = time();

                // Test expired case: expiresAt in the past
                $expiredToken = new TokenData(
                    accessToken: 'test-token',
                    expiresAt: $now - $offset,
                );

                if (!$expiredToken->hasExpired()) {
                    return false;
                }

                // Test not-expired case: expiresAt in the future
                $validToken = new TokenData(
                    accessToken: 'test-token',
                    expiresAt: $now + $offset,
                );

                if ($validToken->hasExpired()) {
                    return false;
                }

                return true;
            }
        );

        $this->assertThat(
            $property,
            PropertyConstraint::check(100)
        );
    }
}
