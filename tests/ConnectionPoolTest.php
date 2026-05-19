<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests;

use CurlHandle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Simsoft\HttpClient\HttpClient;

/**
 * ConnectionPoolTest class.
 *
 * Tests connection pooling behavior: handle reuse via curl_reset(),
 * buildHandle() public method, destructor cleanup, and backward compatibility.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class ConnectionPoolTest extends TestCase
{
    /**
     * Helper to read a protected/private property via reflection.
     *
     * @param object $object The object to inspect.
     * @param string $propertyName The property name.
     * @return mixed
     */
    private function getProperty(object $object, string $propertyName): mixed
    {
        $reflection = new ReflectionProperty($object, $propertyName);

        return $reflection->getValue($object);
    }

    /**
     * Test that buildHandle() returns a valid CurlHandle instance.
     *
     * @return void
     */
    #[Test]
    public function buildHandleReturnsValidCurlHandle(): void
    {
        $client = HttpClient::make()
            ->withBaseUrl('http://example.com')
            ->resource('/test');

        $handle = $client->buildHandle();

        $this->assertInstanceOf(CurlHandle::class, $handle);
    }

    /**
     * Test that sequential buildHandle() calls reuse the same internal CurlHandle.
     *
     * Validates: Requirements 6.1, 6.2
     *
     * @return void
     */
    #[Test]
    public function sequentialBuildHandleCallsReuseSameHandle(): void
    {
        $client = HttpClient::make()
            ->withBaseUrl('http://example.com')
            ->resource('/test');

        $handleFirst = $client->buildHandle();
        $handleSecond = $client->buildHandle();

        // Both calls should return the same CurlHandle object (reused via curl_reset)
        $this->assertSame($handleFirst, $handleSecond);

        // Verify via reflection that the internal curlHandle property is the same instance
        $internalHandle = $this->getProperty($client, 'curlHandle');
        $this->assertSame($handleFirst, $internalHandle);
    }

    /**
     * Test that multiple buildHandle() calls all return the same handle instance.
     *
     * Validates: Requirements 6.1, 6.2
     *
     * @return void
     */
    #[Test]
    public function multipleBuildHandleCallsAllReturnSameInstance(): void
    {
        $client = HttpClient::make()
            ->withBaseUrl('http://example.com')
            ->resource('/users');

        $handles = [];
        for ($index = 0; $index < 5; $index++) {
            $handles[] = $client->buildHandle();
        }

        // All handles should be the exact same object
        for ($index = 1; $index < 5; $index++) {
            $this->assertSame($handles[0], $handles[$index]);
        }
    }

    /**
     * Test that __destruct() closes the cURL handle and sets it to null.
     *
     * Validates: Requirements 6.3
     *
     * @return void
     */
    #[Test]
    public function destructorClosesHandle(): void
    {
        $client = HttpClient::make()
            ->withBaseUrl('http://example.com')
            ->resource('/test');

        // Build a handle to ensure one exists
        $client->buildHandle();

        // Verify handle exists before destruction
        $handleBefore = $this->getProperty($client, 'curlHandle');
        $this->assertInstanceOf(CurlHandle::class, $handleBefore);

        // Call destructor explicitly
        $client->__destruct();

        // After destruction, the curlHandle property should be null
        $handleAfter = $this->getProperty($client, 'curlHandle');
        $this->assertNull($handleAfter);
    }

    /**
     * Test that no new public methods are exposed beyond buildHandle().
     *
     * This ensures backward compatibility by verifying the public API
     * only includes buildHandle() as the single new addition.
     *
     * Validates: Requirements 7.2, 13.1, 13.2
     *
     * @return void
     */
    #[Test]
    public function noNewPublicMethodsBeyondBuildHandle(): void
    {
        $reflection = new ReflectionClass(HttpClient::class);
        $publicMethods = array_map(
            static fn(\ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC)
        );
        sort($publicMethods);

        // Expected public methods: all original methods + buildHandle()
        // buildHandle() is the ONLY new public method added for connection pooling
        $expectedMethods = [
            '__call',
            '__clone',
            '__destruct',
            'asForm',
            'asJson',
            'asMultipart',
            'asRaw',
            'attach',
            'buildHandle',
            'connectionTimeout',
            'dd',
            'delete',
            'dump',
            'formData',
            'get',
            'getEndpoint',
            'getMethod',
            'getPoolSinkPath',
            'graphQL',
            'json',
            'macro',
            'make',
            'mixin',
            'patch',
            'post',
            'put',
            'query',
            'raw',
            'request',
            'resource',
            'retry',
            'retryWhen',
            'send',
            'sendRequest',
            'shouldRetry',
            'sink',
            'sinkStream',
            'timeout',
            'to',
            'verbose',
            'withBaseUrl',
            'withBearerToken',
            'withBody',
            'withBodyStream',
            'withBufferSize',
            'withDNSTimeout',
            'withForm',
            'withGraphQL',
            'withHeader',
            'withHeaders',
            'withJson',
            'withLogger',
            'withMethod',
            'withMiddleware',
            'withMultipart',
            'withOptions',
            'withQuery',
            'withRaw',
            'withResponseClass',
            'withoutReturnTransfer',
            'withoutVerifying',
        ];

        $this->assertSame($expectedMethods, $publicMethods);

        // Explicitly verify buildHandle and getPoolSinkPath are the only pool-related public methods
        $poolMethods = array_filter(
            $publicMethods,
            static fn(string $name): bool => str_contains($name, 'pool')
                || str_contains($name, 'Pool')
        );
        $this->assertSame(['getPoolSinkPath'], array_values($poolMethods), 'Only getPoolSinkPath should reference "pool" in its name');
    }

    /**
     * Test that buildHandle() works with configured URL and method options.
     *
     * @return void
     */
    #[Test]
    public function buildHandleRespectsConfiguredOptions(): void
    {
        $client = HttpClient::make()
            ->withBaseUrl('http://example.com')
            ->resource('/api/users')
            ->withMethod('POST');

        $handle = $client->buildHandle();

        $this->assertInstanceOf(CurlHandle::class, $handle);

        // The handle should have the URL set (verify via curl_getinfo)
        $effectiveUrl = curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
        $this->assertSame('http://example.com/api/users', $effectiveUrl);
    }
}
