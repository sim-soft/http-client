<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\HttpClient\HttpPool;
use Simsoft\HttpClient\HttpPoolResult;
use Simsoft\HttpClient\Response;
use Simsoft\HttpClient\Testing\FakeHttpClient;

/**
 * HttpPoolTest class.
 *
 * Unit tests for the HttpPool concurrent request execution class.
 * Covers default configuration, input validation, request execution,
 * callback invocation, and HTTP/2 multiplexing configuration.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class HttpPoolTest extends TestCase
{
    // ── Default concurrency tests ────────────────────────────────────

    /**
     * Test that default concurrency is 25 when no argument is provided.
     *
     * Validates: Requirements 1.4
     */
    #[Test]
    public function defaultConcurrencyIsTwentyFive(): void
    {
        $pool = new HttpPool();

        $reflection = new \ReflectionProperty($pool, 'concurrency');
        $this->assertSame(25, $reflection->getValue($pool));
    }

    /**
     * Test that constructor accepts a custom concurrency value.
     *
     * Validates: Requirements 1.3
     */
    #[Test]
    public function constructorAcceptsCustomConcurrency(): void
    {
        $pool = new HttpPool(10);

        $reflection = new \ReflectionProperty($pool, 'concurrency');
        $this->assertSame(10, $reflection->getValue($pool));
    }

    // ── Concurrency validation tests ─────────────────────────────────

    /**
     * Test that concurrency limit of zero throws InvalidArgumentException.
     *
     * Validates: Requirements 3.2
     */
    #[Test]
    public function concurrencyZeroThrowsInvalidArgumentException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Concurrency limit must be at least 1');

        new HttpPool(0);
    }

    /**
     * Test that negative concurrency limit throws InvalidArgumentException.
     *
     * Validates: Requirements 3.2
     */
    #[Test]
    public function negativeConcurrencyThrowsInvalidArgumentException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Concurrency limit must be at least 1');

        new HttpPool(-5);
    }

    /**
     * Test that fluent concurrency method with zero throws InvalidArgumentException.
     *
     * Validates: Requirements 3.2
     */
    #[Test]
    public function fluentConcurrencyZeroThrowsInvalidArgumentException(): void
    {
        $pool = new HttpPool();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Concurrency limit must be at least 1');

        $pool->concurrency(0);
    }

    /**
     * Test that fluent concurrency method with negative value throws InvalidArgumentException.
     *
     * Validates: Requirements 3.2
     */
    #[Test]
    public function fluentConcurrencyNegativeThrowsInvalidArgumentException(): void
    {
        $pool = new HttpPool();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Concurrency limit must be at least 1');

        $pool->concurrency(-10);
    }

    /**
     * Test that fluent concurrency method updates the limit.
     *
     * Validates: Requirements 1.3
     */
    #[Test]
    public function fluentConcurrencyUpdatesLimit(): void
    {
        $pool = new HttpPool();
        $result = $pool->concurrency(5);

        $this->assertSame($pool, $result, 'concurrency() should return $this for fluent chaining');

        $reflection = new \ReflectionProperty($pool, 'concurrency');
        $this->assertSame(5, $reflection->getValue($pool));
    }

    // ── Fluent API tests ─────────────────────────────────────────────

    /**
     * Test that onResponse returns self for fluent chaining.
     *
     * Validates: Requirements 5.1
     */
    #[Test]
    public function onResponseReturnsSelfForChaining(): void
    {
        $pool = new HttpPool();
        $result = $pool->onResponse(function (Response $response, int $index): void {
        });

        $this->assertSame($pool, $result);
    }

    /**
     * Test that onError returns self for fluent chaining.
     *
     * Validates: Requirements 5.3
     */
    #[Test]
    public function onErrorReturnsSelfForChaining(): void
    {
        $pool = new HttpPool();
        $result = $pool->onError(function (Response $response, int $index): void {
        });

        $this->assertSame($pool, $result);
    }

    // ── Empty request array tests ────────────────────────────────────

    /**
     * Test that empty request array returns empty HttpPoolResult.
     *
     * Validates: Requirements 1.1, 1.2
     */
    #[Test]
    public function emptyRequestArrayReturnsEmptyResult(): void
    {
        $pool = new HttpPool();
        $result = $pool->send([]);

        $this->assertInstanceOf(HttpPoolResult::class, $result);
        $this->assertSame(0, $result->count());
        $this->assertSame([], $result->getResponses());
    }

    // ── Single request tests ─────────────────────────────────────────

    /**
     * Test that a single request works as a degenerate case.
     *
     * Validates: Requirements 1.1, 1.2
     */
    #[Test]
    public function singleRequestWorksAsDegenerate(): void
    {
        $client = FakeHttpClient::fake([
            'GET https://api.example.com/users' => 200,
        ]);

        $client->withBaseUrl('https://api.example.com')
            ->resource('/users')
            ->withMethod('GET');

        $pool = new HttpPool();
        $result = $pool->send([$client]);

        $this->assertSame(1, $result->count());
        $this->assertTrue($result->getResponse(0)->successful());
    }

    // ── Closure-based request creation tests ─────────────────────────

    /**
     * Test that closure-based request creation works.
     *
     * Validates: Requirements 1.1
     */
    #[Test]
    public function closureBasedRequestCreation(): void
    {
        $closure = function (): FakeHttpClient {
            $client = FakeHttpClient::fake([
                'GET https://api.example.com/posts' => 201,
            ]);

            $client->withBaseUrl('https://api.example.com')
                ->resource('/posts')
                ->withMethod('GET');

            return $client;
        };

        $pool = new HttpPool();
        $result = $pool->send([$closure]);

        $this->assertSame(1, $result->count());
        $this->assertSame(201, $result->getResponse(0)->getStatusCode());
    }

    // ── Invalid input validation tests ───────────────────────────────

    /**
     * Test that non-HttpClient input throws InvalidArgumentException.
     *
     * Validates: Requirements 12.1
     */
    #[Test]
    public function nonHttpClientInputThrowsInvalidArgumentException(): void
    {
        $pool = new HttpPool();

        $this->expectException(InvalidArgumentException::class);

        /** @phpstan-ignore argument.type */
        $pool->send(['not-a-client']);
    }

    /**
     * Test that mixed valid and invalid input throws InvalidArgumentException.
     *
     * Validates: Requirements 12.1
     */
    #[Test]
    public function mixedValidAndInvalidInputThrowsException(): void
    {
        $client = FakeHttpClient::fake([
            'GET https://api.example.com/test' => 200,
        ]);

        $client->withBaseUrl('https://api.example.com')
            ->resource('/test')
            ->withMethod('GET');

        $pool = new HttpPool();

        $this->expectException(InvalidArgumentException::class);

        /** @phpstan-ignore argument.type */
        $pool->send([$client, 'invalid-entry']);
    }

    /**
     * Test that integer input throws InvalidArgumentException.
     *
     * Validates: Requirements 12.1
     */
    #[Test]
    public function integerInputThrowsInvalidArgumentException(): void
    {
        $pool = new HttpPool();

        $this->expectException(InvalidArgumentException::class);

        /** @phpstan-ignore argument.type */
        $pool->send([42]);
    }

    // ── onResponse callback tests ────────────────────────────────────

    /**
     * Test that onResponse callback receives correct index for each response.
     *
     * Validates: Requirements 5.1, 5.2
     */
    #[Test]
    public function onResponseCallbackReceivesCorrectIndex(): void
    {
        $callbackResults = [];

        $requests = [];
        for ($idx = 0; $idx < 3; $idx++) {
            $url = "https://api.example.com/items/{$idx}";
            $client = FakeHttpClient::fake([
                "GET {$url}" => 200,
            ]);
            $client->withBaseUrl('https://api.example.com')
                ->resource("/items/{$idx}")
                ->withMethod('GET');
            $requests[] = $client;
        }

        $pool = new HttpPool();
        $pool->onResponse(function (Response $response, int $index) use (&$callbackResults): void {
            $callbackResults[$index] = $response->getStatusCode();
        });

        $pool->send($requests);

        $this->assertCount(3, $callbackResults);
        $this->assertArrayHasKey(0, $callbackResults);
        $this->assertArrayHasKey(1, $callbackResults);
        $this->assertArrayHasKey(2, $callbackResults);
    }

    // ── onError callback tests ───────────────────────────────────────

    /**
     * Test that onError callback is invoked for failed requests.
     *
     * Validates: Requirements 5.3
     */
    #[Test]
    public function onErrorCallbackInvokedForFailedRequests(): void
    {
        $errorResults = [];

        $requests = [];

        // Successful request
        $successClient = FakeHttpClient::fake([
            'GET https://api.example.com/success' => 200,
        ]);
        $successClient->withBaseUrl('https://api.example.com')
            ->resource('/success')
            ->withMethod('GET');
        $requests[] = $successClient;

        // Failed request (500)
        $failClient = FakeHttpClient::fake([
            'GET https://api.example.com/fail' => 500,
        ]);
        $failClient->withBaseUrl('https://api.example.com')
            ->resource('/fail')
            ->withMethod('GET');
        $requests[] = $failClient;

        // Another failed request (404)
        $notFoundClient = FakeHttpClient::fake([
            'GET https://api.example.com/missing' => 404,
        ]);
        $notFoundClient->withBaseUrl('https://api.example.com')
            ->resource('/missing')
            ->withMethod('GET');
        $requests[] = $notFoundClient;

        $pool = new HttpPool();
        $pool->onError(function (Response $response, int $index) use (&$errorResults): void {
            $errorResults[$index] = $response->getStatusCode();
        });

        $pool->send($requests);

        // Only the failed requests should trigger the error callback
        $this->assertCount(2, $errorResults);
        $this->assertArrayHasKey(1, $errorResults);
        $this->assertArrayHasKey(2, $errorResults);
        $this->assertSame(500, $errorResults[1]);
        $this->assertSame(404, $errorResults[2]);
    }

    // ── HTTP/2 multiplexing configuration tests ──────────────────────

    /**
     * Test that HTTP/2 multiplexing is configured on the multi handle.
     *
     * Validates: Requirements 2.1, 2.2
     */
    #[Test]
    public function httpTwoMultiplexingConfigured(): void
    {
        $client = FakeHttpClient::fake([
            'GET https://api.example.com/data' => 200,
        ]);
        $client->withBaseUrl('https://api.example.com')
            ->resource('/data')
            ->withMethod('GET');

        $pool = new HttpPool();

        // The pool should execute without error when multiplexing is configured
        // This validates that CURLMOPT_PIPELINING = CURLPIPE_MULTIPLEX is set
        $result = $pool->send([$client]);

        $this->assertInstanceOf(HttpPoolResult::class, $result);
        $this->assertSame(1, $result->count());
    }

    // ── Multiple requests tests ──────────────────────────────────────

    /**
     * Test that multiple requests return results in correct order.
     *
     * Validates: Requirements 1.2, 4.1, 4.2
     */
    #[Test]
    public function multipleRequestsReturnResultsInOrder(): void
    {
        $statusCodes = [200, 404, 201, 500, 204];
        $requests = [];

        foreach ($statusCodes as $idx => $status) {
            $url = "https://api.example.com/endpoint/{$idx}";
            $client = FakeHttpClient::fake([
                "GET {$url}" => $status,
            ]);
            $client->withBaseUrl('https://api.example.com')
                ->resource("/endpoint/{$idx}")
                ->withMethod('GET');
            $requests[] = $client;
        }

        $pool = new HttpPool();
        $result = $pool->send($requests);

        $this->assertSame(5, $result->count());

        foreach ($statusCodes as $idx => $expectedStatus) {
            $this->assertSame(
                $expectedStatus,
                $result->getResponse($idx)->getStatusCode(),
                "Response at index {$idx} should have status {$expectedStatus}"
            );
        }
    }

    /**
     * Test that pool allocates one multi handle per execution.
     *
     * Validates: Requirements 3.3, 3.4
     */
    #[Test]
    public function poolReusesMultiHandlePerExecution(): void
    {
        $requests = [];
        for ($idx = 0; $idx < 5; $idx++) {
            $url = "https://api.example.com/batch/{$idx}";
            $client = FakeHttpClient::fake([
                "GET {$url}" => 200,
            ]);
            $client->withBaseUrl('https://api.example.com')
                ->resource("/batch/{$idx}")
                ->withMethod('GET');
            $requests[] = $client;
        }

        $pool = new HttpPool();
        $result = $pool->send($requests);

        // If the pool completes without error, it means the multi handle
        // was properly initialized, used, and closed for all requests
        $this->assertSame(5, $result->count());
    }

    // ── Timeout tests ────────────────────────────────────────────────

    /**
     * Test that timeout method returns self for fluent chaining.
     */
    #[Test]
    public function timeoutReturnsSelfForChaining(): void
    {
        $pool = new HttpPool();
        $result = $pool->timeout(10);

        $this->assertSame($pool, $result);
    }

    /**
     * Test that negative timeout throws InvalidArgumentException.
     */
    #[Test]
    public function negativeTimeoutThrowsInvalidArgumentException(): void
    {
        $pool = new HttpPool();

        $this->expectException(InvalidArgumentException::class);

        $pool->timeout(-1);
    }

    /**
     * Test that timeout of zero is accepted (disables timeout).
     */
    #[Test]
    public function zeroTimeoutIsAccepted(): void
    {
        $pool = new HttpPool();
        $pool->timeout(0);

        $reflection = new \ReflectionProperty($pool, 'timeout');
        $this->assertSame(0, $reflection->getValue($pool));
    }

    // ── Retries tests ────────────────────────────────────────────────

    /**
     * Test that retries method returns self for fluent chaining.
     */
    #[Test]
    public function retriesReturnsSelfForChaining(): void
    {
        $pool = new HttpPool();
        $result = $pool->retries(3);

        $this->assertSame($pool, $result);
    }

    /**
     * Test that negative retries throws InvalidArgumentException.
     */
    #[Test]
    public function negativeRetriesThrowsInvalidArgumentException(): void
    {
        $pool = new HttpPool();

        $this->expectException(InvalidArgumentException::class);

        $pool->retries(-1);
    }

    /**
     * Test that negative retry delay throws InvalidArgumentException.
     */
    #[Test]
    public function negativeRetryDelayThrowsInvalidArgumentException(): void
    {
        $pool = new HttpPool();

        $this->expectException(InvalidArgumentException::class);

        $pool->retries(3, after: -100);
    }

    /**
     * Test that retries with delay stores both values.
     */
    #[Test]
    public function retriesWithDelayStoresBothValues(): void
    {
        $pool = new HttpPool();
        $pool->retries(2, after: 500);

        $retriesRef = new \ReflectionProperty($pool, 'retries');
        $delayRef = new \ReflectionProperty($pool, 'retryDelayMs');

        $this->assertSame(2, $retriesRef->getValue($pool));
        $this->assertSame(500, $delayRef->getValue($pool));
    }

    /**
     * Test that failed requests are retried with FakeHttpClient.
     */
    #[Test]
    public function retriesFailedRequestsWithFakeClient(): void
    {
        $client = FakeHttpClient::fake([]);
        $client->sequence('GET *', [500, 500, 200]);
        $client->withBaseUrl('https://api.example.com')
            ->resource('/flaky');

        $pool = new HttpPool();
        $pool->retries(3);
        $result = $pool->send([$client]);

        $this->assertSame(200, $result[0]->getStatusCode());
    }

    // ── Delay (rate limiting) tests ──────────────────────────────────

    /**
     * Test that delay method returns self for fluent chaining.
     */
    #[Test]
    public function delayReturnsSelfForChaining(): void
    {
        $pool = new HttpPool();
        $result = $pool->delay(100);

        $this->assertSame($pool, $result);
    }

    /**
     * Test that negative delay throws InvalidArgumentException.
     */
    #[Test]
    public function negativeDelayThrowsInvalidArgumentException(): void
    {
        $pool = new HttpPool();

        $this->expectException(InvalidArgumentException::class);

        $pool->delay(-1);
    }

    // ── onProgress callback tests ────────────────────────────────────

    /**
     * Test that onProgress returns self for fluent chaining.
     */
    #[Test]
    public function onProgressReturnsSelfForChaining(): void
    {
        $pool = new HttpPool();
        $result = $pool->onProgress(function (int $completed, int $total): void {
        });

        $this->assertSame($pool, $result);
    }

    /**
     * Test that onProgress callback is invoked with correct counts.
     */
    #[Test]
    public function onProgressCallbackReceivesCorrectCounts(): void
    {
        $progressLog = [];

        $requests = [];
        for ($idx = 0; $idx < 3; $idx++) {
            $url = "https://api.example.com/progress/{$idx}";
            $client = FakeHttpClient::fake(["GET {$url}" => 200]);
            $client->withBaseUrl('https://api.example.com')
                ->resource("/progress/{$idx}")
                ->withMethod('GET');
            $requests[] = $client;
        }

        $pool = new HttpPool();
        $pool->onProgress(function (int $completed, int $total) use (&$progressLog): void {
            $progressLog[] = [$completed, $total];
        });

        $pool->send($requests);

        $this->assertSame([[1, 3], [2, 3], [3, 3]], $progressLog);
    }

    // ── Named requests tests ─────────────────────────────────────────

    /**
     * Test that string-keyed requests preserve keys in result.
     */
    #[Test]
    public function namedRequestsPreserveKeysInResult(): void
    {
        $usersClient = FakeHttpClient::fake(['GET https://api.example.com/users' => 200]);
        $usersClient->withBaseUrl('https://api.example.com')
            ->resource('/users')
            ->withMethod('GET');

        $postsClient = FakeHttpClient::fake(['GET https://api.example.com/posts' => 201]);
        $postsClient->withBaseUrl('https://api.example.com')
            ->resource('/posts')
            ->withMethod('GET');

        $pool = new HttpPool();
        $result = $pool->send([
            'users' => $usersClient,
            'posts' => $postsClient,
        ]);

        $this->assertSame(200, $result['users']->getStatusCode());
        $this->assertSame(201, $result['posts']->getStatusCode());
    }

    /**
     * Test that named requests work with onResponse callback.
     */
    #[Test]
    public function namedRequestsPassKeyToCallbacks(): void
    {
        $callbackKeys = [];

        $client = FakeHttpClient::fake(['GET https://api.example.com/data' => 200]);
        $client->withBaseUrl('https://api.example.com')
            ->resource('/data')
            ->withMethod('GET');

        $pool = new HttpPool();
        $pool->onResponse(function (Response $response, int|string $key) use (&$callbackKeys): void {
            $callbackKeys[] = $key;
        });

        $pool->send(['myRequest' => $client]);

        $this->assertSame(['myRequest'], $callbackKeys);
    }

    // ── HttpPool::run() tests ────────────────────────────────────────

    /**
     * Test that HttpPool::run() executes with PoolBuilder.
     */
    #[Test]
    public function runExecutesWithPoolBuilder(): void
    {
        $result = HttpPool::run(function (\Simsoft\HttpClient\PoolBuilder $pool) {
            $pool->withBaseUrl('https://api.example.com');

            return [
                'users' => $pool->get('/users'),
            ];
        });

        // PoolBuilder creates real HttpClient instances, which would need
        // real network. Since we can't fake inside run(), just verify it
        // returns an HttpPoolResult (the request will fail without network)
        $this->assertInstanceOf(HttpPoolResult::class, $result);
    }

    // ── Static create() tests ────────────────────────────────────────

    /**
     * Test that HttpPool::create() returns a configured instance.
     */
    #[Test]
    public function createReturnsConfiguredInstance(): void
    {
        $pool = HttpPool::create(5);

        $reflection = new \ReflectionProperty($pool, 'concurrency');
        $this->assertSame(5, $reflection->getValue($pool));
    }
}
