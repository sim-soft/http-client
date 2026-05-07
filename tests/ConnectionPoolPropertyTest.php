<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests;

use CurlHandle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QuickCheck\Generator as Gen;
use QuickCheck\PHPUnit\PropertyConstraint;
use QuickCheck\Property;
use ReflectionProperty;
use Simsoft\HttpClient\HttpClient;

/**
 * ConnectionPoolPropertyTest class
 *
 * Property-based tests for the connection pooling behavior in HttpClient.
 * Validates that cURL handle reuse works correctly across sequential requests.
 *
 * Feature: http-pool-and-testing, Property 7: Handle Reuse Invariant
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ConnectionPoolPropertyTest extends TestCase
{
    /**
     * Property 7: Handle Reuse Invariant.
     *
     * For any sequence of M requests (where M >= 2) executed on the same
     * HttpClient instance, the curlHandle property SHALL reference the same
     * CurlHandle object after the first initialization — curl_init() is called
     * at most once, and subsequent requests use curl_reset() on the existing handle.
     *
     * **Validates: Requirements 6.1, 6.2**
     *
     * @return void
     */
    #[Test]
    public function handleReusedAcrossMultipleRequests(): void
    {
        $requestCountGen = Gen::choose(2, 20);

        $property = Property::forAll(
            [$requestCountGen],
            function (int $requestCount): bool {
                return $this->verifyHandleReuse($requestCount);
            }
        );

        $this->assertThat(
            $property,
            PropertyConstraint::check(100)
        );
    }

    /**
     * Verify that the curlHandle property references the same instance
     * across multiple buildHandle() calls on the same HttpClient.
     *
     * @param int $requestCount Number of sequential requests to simulate.
     * @return bool True if the same CurlHandle is reused for all requests.
     */
    private function verifyHandleReuse(int $requestCount): bool
    {
        $client = HttpClient::make()
            ->withBaseUrl('https://example.com');

        $curlHandleProperty = new ReflectionProperty($client, 'curlHandle');

        $firstHandle = null;

        for ($index = 0; $index < $requestCount; $index++) {
            $client->resource('/test/' . $index);
            $client->withMethod('GET');

            $returnedHandle = $client->buildHandle();

            $storedHandle = $curlHandleProperty->getValue($client);

            if ($firstHandle === null) {
                $firstHandle = $storedHandle;
            }

            // The stored handle must be the same object across all iterations
            if ($storedHandle !== $firstHandle) {
                return false;
            }

            // The returned handle must match the stored handle
            if ($returnedHandle !== $firstHandle) {
                return false;
            }

            // The handle must be a valid CurlHandle instance
            if (!$storedHandle instanceof CurlHandle) {
                return false;
            }
        }

        return true;
    }
}
