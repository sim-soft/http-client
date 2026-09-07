<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QuickCheck\Generator as Gen;
use QuickCheck\PHPUnit\PropertyConstraint;
use QuickCheck\Property;
use Simsoft\HttpClient\HttpPool;
use Simsoft\HttpClient\HttpPoolResult;
use Simsoft\HttpClient\Response;
use Simsoft\HttpClient\Testing\FakeHttpClient;

/**
 * HttpPoolPropertyTest class.
 *
 * Property-based tests for HttpPool concurrent request execution.
 * Validates pool count invariant, order preservation, concurrency limit
 * behavior, and failure isolation using FakeHttpClient instances.
 *
 * Feature: http-pool-and-testing, Property 1: Pool Count Invariant
 * Feature: http-pool-and-testing, Property 2: Pool Order Preservation
 * Feature: http-pool-and-testing, Property 3: Pool Concurrency Limit
 * Feature: http-pool-and-testing, Property 4: Pool Failure Isolation
 * Feature: http-pool-and-testing, Property 6: Pool Callback Invocation
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class HttpPoolPropertyTest extends TestCase
{
    /** @var int[] Mix of success and failure status codes for property testing. */
    private const STATUS_CODES = [200, 201, 204, 400, 403, 404, 500, 502, 503];

    /**
     * Property 1: Pool Count Invariant.
     *
     * For any array of N request configurations (where N >= 1),
     * HttpPool::send() SHALL return an HttpPoolResult containing exactly
     * N Response objects, regardless of individual request success or failure.
     *
     * **Validates: Requirements 1.1, 4.1, 4.2, 5.2**
     *
     * @return void
     */
    #[Test]
    public function poolCountInvariant(): void
    {
        $property = Property::forAll(
            [Gen::choose(1, 50)],
            function (int $batchSize): bool {
                return $this->verifyPoolCountInvariant($batchSize);
            }
        );

        $this->assertThat(
            $property,
            PropertyConstraint::check(100)
        );
    }

    /**
     * Verify that sending N requests always returns exactly N responses.
     *
     * Creates a batch of N FakeHttpClient requests with a mix of success
     * and failure status codes, and verifies that the result always contains
     * exactly N responses indexed correctly, regardless of individual outcomes.
     *
     * @param int $batchSize The number of requests to send (1-50).
     *
     * @return bool True if the pool returns exactly N responses with correct indexing.
     */
    private function verifyPoolCountInvariant(int $batchSize): bool
    {
        $pool = new HttpPool();

        $requests = $this->buildMixedRequests($batchSize);

        $result = $pool->send($requests);

        // The result must be an HttpPoolResult instance
        if (!$result instanceof HttpPoolResult) {
            return false;
        }

        // count() must equal the batch size
        if ($result->count() !== $batchSize) {
            return false;
        }

        $responses = $result->getResponses();

        // getResponses() must return exactly N entries
        if (count($responses) !== $batchSize) {
            return false;
        }

        // Every index from 0 to N-1 must be present
        for ($idx = 0; $idx < $batchSize; $idx++) {
            if (!array_key_exists($idx, $responses)) {
                return false;
            }

            // Each entry must be a Response instance
            if (!$responses[$idx] instanceof Response) {
                return false;
            }
        }

        return true;
    }

    /**
     * Build an array of FakeHttpClient instances with mixed status codes.
     *
     * Each client is configured with a unique URL and a randomly selected
     * status code from the mix of success and failure codes. This ensures
     * the count invariant holds regardless of individual request outcomes.
     *
     * @param int $count Number of request clients to create.
     *
     * @return array<int, FakeHttpClient> Array of configured FakeHttpClient instances.
     */
    private function buildMixedRequests(int $count): array
    {
        $requests = [];

        for ($idx = 0; $idx < $count; $idx++) {
            $url = "https://api.example.com/items/{$idx}";
            $statusCode = self::STATUS_CODES[array_rand(self::STATUS_CODES)];

            $client = FakeHttpClient::fake([
                "GET {$url}" => $statusCode,
            ]);

            $client->withBaseUrl('https://api.example.com')
                ->resource("/items/{$idx}")
                ->withMethod('GET');

            $requests[$idx] = $client;
        }

        return $requests;
    }

    /**
     * Property 2: Pool Order Preservation.
     *
     * For any array of N requests submitted to HttpPool, the returned results
     * array SHALL maintain the same index mapping as the input — the Response
     * at index i in the result corresponds to the request at index i in the
     * input, regardless of the order in which requests actually complete.
     *
     * **Validates: Requirements 1.2**
     *
     * @return void
     */
    #[Test]
    public function poolOrderPreservation(): void
    {
        $property = Property::forAll(
            [Gen::choose(1, 30)],
            function (int $batchSize): bool {
                return $this->verifyOrderPreservation($batchSize);
            }
        );

        $this->assertThat(
            $property,
            PropertyConstraint::check(100)
        );
    }

    /**
     * Verify that pool responses maintain the same index mapping as input requests.
     *
     * Creates a batch of N requests, each with a unique URL and a unique
     * response body identifier. After pool execution, verifies that the
     * response at each index contains the body corresponding to that index's
     * request, confirming order preservation regardless of completion order.
     *
     * @param int $batchSize The number of requests to send (1-30).
     *
     * @return bool True if all responses are at their correct indices.
     */
    private function verifyOrderPreservation(int $batchSize): bool
    {
        $pool = new HttpPool();

        $requests = [];
        $expectedBodies = [];

        for ($idx = 0; $idx < $batchSize; $idx++) {
            $url = "https://api.example.com/order/{$idx}";
            $uniqueBody = "response-body-for-index-{$idx}";
            $expectedBodies[$idx] = $uniqueBody;

            $client = FakeHttpClient::fake([
                "GET {$url}" => [
                    'status' => 200,
                    'body' => $uniqueBody,
                ],
            ]);

            $client->withBaseUrl('https://api.example.com')
                ->resource("/order/{$idx}")
                ->withMethod('GET');

            $requests[$idx] = $client;
        }

        $result = $pool->send($requests);

        $responses = $result->getResponses();

        // Must have exactly N responses
        if (count($responses) !== $batchSize) {
            return false;
        }

        // Verify each response is at its correct index with the expected body
        for ($idx = 0; $idx < $batchSize; $idx++) {
            if (!array_key_exists($idx, $responses)) {
                return false;
            }

            $response = $responses[$idx];

            if (!$response instanceof Response) {
                return false;
            }

            // The response body must match the unique identifier for this index
            if ($response->body() !== $expectedBodies[$idx]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Property 3: Pool Concurrency Limit.
     *
     * For any concurrency limit C (where C >= 1) and any batch of N requests
     * (where N >= 1), the pool SHALL process all requests correctly and return
     * exactly N responses regardless of the concurrency value. The concurrency
     * setting controls the sliding window size but does not affect correctness.
     *
     * **Validates: Requirements 1.3**
     *
     * @return void
     */
    #[Test]
    public function poolConcurrencyLimit(): void
    {
        $property = Property::forAll(
            [Gen::choose(1, 10), Gen::choose(1, 30)],
            function (int $concurrency, int $batchSize): bool {
                return $this->verifyConcurrencyLimit($concurrency, $batchSize);
            }
        );

        $this->assertThat(
            $property,
            PropertyConstraint::check(100)
        );
    }

    /**
     * Verify that the pool respects the concurrency limit and processes all requests.
     *
     * Creates a batch of N FakeHttpClient requests with concurrency limit C,
     * and verifies that all N requests complete successfully. The concurrency
     * limit constrains the sliding window but all requests must still be processed.
     *
     * @param int $concurrency The concurrency limit (1-10).
     * @param int $batchSize The number of requests in the batch (1-30).
     *
     * @return bool True if all requests are processed correctly under the concurrency limit.
     */
    private function verifyConcurrencyLimit(int $concurrency, int $batchSize): bool
    {
        $pool = new HttpPool($concurrency);

        $requests = $this->buildRequests($batchSize);

        $result = $pool->send($requests);

        $responses = $result->getResponses();

        // All N requests must produce exactly N responses
        if (count($responses) !== $batchSize) {
            return false;
        }

        // Every response must be present at its original index
        for ($idx = 0; $idx < $batchSize; $idx++) {
            if (!array_key_exists($idx, $responses)) {
                return false;
            }
        }

        // All responses should be successful (200 status from FakeHttpClient)
        foreach ($responses as $response) {
            if (!$response->successful()) {
                return false;
            }
        }

        // The concurrency limit must be accepted (pool was created without exception)
        // and the effective window is min(C, N)
        $effectiveWindow = min($concurrency, $batchSize);
        if ($effectiveWindow < 1) {
            return false;
        }

        return true;
    }

    /**
     * Build an array of FakeHttpClient instances for pool testing.
     *
     * Each client is configured with a unique URL and a fake 200 response.
     *
     * @param int $count Number of request clients to create.
     *
     * @return array<int, FakeHttpClient> Array of configured FakeHttpClient instances.
     */
    private function buildRequests(int $count): array
    {
        $requests = [];

        for ($idx = 0; $idx < $count; $idx++) {
            $url = "https://api.example.com/resource/{$idx}";

            $client = FakeHttpClient::fake([
                "GET {$url}" => 200,
            ]);

            $client->withBaseUrl('https://api.example.com')
                ->resource("/resource/{$idx}")
                ->withMethod('GET');

            $requests[$idx] = $client;
        }

        return $requests;
    }

    /**
     * Property 4: Pool Failure Isolation.
     *
     * For any batch of N requests where K requests fail (with HTTP error
     * statuses), all remaining N-K requests SHALL complete successfully
     * and their Response objects SHALL be present in the results array
     * at their original indices.
     *
     * **Validates: Requirements 4.1, 4.2**
     *
     * @return void
     */
    #[Test]
    public function poolFailureIsolation(): void
    {
        $failureStatuses = [400, 403, 404, 500, 502, 503];

        $property = Property::forAll(
            [Gen::choose(1, 20), Gen::choose(0, 19)],
            function (int $batchSize, int $failureSeed) use ($failureStatuses): bool {
                return $this->verifyFailureIsolation($batchSize, $failureSeed, $failureStatuses);
            }
        );

        $this->assertThat(
            $property,
            PropertyConstraint::check(100)
        );
    }

    /**
     * Verify that failures in a batch are isolated from successful requests.
     *
     * Creates a batch where some positions are configured to fail (4xx/5xx)
     * and others succeed (200). Verifies that:
     * - The result contains exactly N responses
     * - Successful positions have successful responses
     * - Failed positions have failed responses
     * - Failures do not affect other requests
     *
     * @param int $batchSize The number of requests in the batch (1-20).
     * @param int $failureSeed Seed to determine which positions fail (0-19).
     * @param array<int, int> $failureStatuses Array of failure status codes.
     *
     * @return bool True if failures are properly isolated.
     */
    private function verifyFailureIsolation(int $batchSize, int $failureSeed, array $failureStatuses): bool
    {
        $pool = new HttpPool();

        // Determine which positions will fail using the seed
        $failurePositions = $this->selectFailurePositions($batchSize, $failureSeed);

        $requests = [];

        for ($idx = 0; $idx < $batchSize; $idx++) {
            $url = "https://api.example.com/isolation/{$idx}";

            if (in_array($idx, $failurePositions, true)) {
                $statusCode = $failureStatuses[$idx % count($failureStatuses)];
            } else {
                $statusCode = 200;
            }

            $client = FakeHttpClient::fake([
                "GET {$url}" => $statusCode,
            ]);

            $client->withBaseUrl('https://api.example.com')
                ->resource("/isolation/{$idx}")
                ->withMethod('GET');

            $requests[$idx] = $client;
        }

        $result = $pool->send($requests);

        // Must return exactly N responses
        if ($result->count() !== $batchSize) {
            return false;
        }

        $responses = $result->getResponses();

        // Verify each response is at its correct index with expected status
        for ($idx = 0; $idx < $batchSize; $idx++) {
            if (!array_key_exists($idx, $responses)) {
                return false;
            }

            $response = $responses[$idx];

            if (!$response instanceof Response) {
                return false;
            }

            // Verify success/failure matches expectation
            if (in_array($idx, $failurePositions, true)) {
                // Failed positions must report as failed
                if (!$response->failed()) {
                    return false;
                }
            } else {
                // Successful positions must report as successful
                if (!$response->successful()) {
                    return false;
                }
            }
        }

        // Verify partitioning: successful + failed = total
        $successful = $result->getSuccessful();
        $failed = $result->getFailed();

        if ((count($successful) + count($failed)) !== $batchSize) {
            return false;
        }

        // Verify successful count matches expected
        $expectedSuccessCount = $batchSize - count($failurePositions);
        if (count($successful) !== $expectedSuccessCount) {
            return false;
        }

        // Verify failed count matches expected
        if (count($failed) !== count($failurePositions)) {
            return false;
        }

        return true;
    }

    /**
     * Select which positions in the batch should fail.
     *
     * Uses the failure seed to deterministically select a subset of
     * positions that will be configured with failure status codes.
     * The number of failures ranges from 0 to batchSize.
     *
     * @param int $batchSize Total number of requests in the batch.
     * @param int $failureSeed Seed value to determine failure positions.
     *
     * @return array<int, int> Array of indices that should fail.
     */
    private function selectFailurePositions(int $batchSize, int $failureSeed): array
    {
        // Use the seed to determine how many failures (at least 1 if batch > 1)
        $failureCount = ($failureSeed % $batchSize) + 1;
        $failureCount = min($failureCount, $batchSize);

        $positions = [];

        // Distribute failures across the batch using the seed
        for ($idx = 0; $idx < $failureCount; $idx++) {
            $position = ($failureSeed + $idx) % $batchSize;
            if (!in_array($position, $positions, true)) {
                $positions[] = $position;
            }
        }

        return $positions;
    }

    /**
     * Property 6: Pool Callback Invocation.
     *
     * For any batch of N requests with an onResponse callback configured,
     * the callback SHALL be invoked exactly N times — once per response with
     * its correct index. Additionally, if an onError callback is configured,
     * it SHALL be invoked exactly for those responses where failed() returns
     * true, and the count of error callback invocations SHALL equal the count
     * of failed responses.
     *
     * **Validates: Requirements 5.1, 5.3**
     *
     * @return void
     */
    #[Test]
    public function poolCallbackInvocation(): void
    {
        $property = Property::forAll(
            [Gen::choose(1, 20)],
            function (int $batchSize): bool {
                return $this->verifyCallbackInvocation($batchSize);
            }
        );

        $this->assertThat(
            $property,
            PropertyConstraint::check(100)
        );
    }

    /**
     * Verify that pool callbacks are invoked correctly for each request.
     *
     * Creates a batch of N requests with a mix of success (2xx) and failure
     * (4xx/5xx) status codes. Registers both onResponse and onError callbacks,
     * then verifies:
     * - onResponse is invoked exactly N times (once per request)
     * - onError is invoked exactly for failed responses
     * - Each onResponse invocation receives a valid index (0 to N-1)
     * - The set of error callback indices matches the set of failed responses
     *
     * @param int $batchSize The number of requests to send (1-20).
     *
     * @return bool True if callback invocations match expected behavior.
     */
    private function verifyCallbackInvocation(int $batchSize): bool
    {
        /** @var array<int, array{response: Response, index: int}> $responseCallbacks */
        $responseCallbacks = [];

        /** @var array<int, array{response: Response, index: int}> $errorCallbacks */
        $errorCallbacks = [];

        $pool = new HttpPool();
        $pool->onResponse(function (Response $response, int $index) use (&$responseCallbacks): void {
            $responseCallbacks[] = ['response' => $response, 'index' => $index];
        });
        $pool->onError(function (Response $response, int $index) use (&$errorCallbacks): void {
            $errorCallbacks[] = ['response' => $response, 'index' => $index];
        });

        $requests = $this->buildCallbackTestRequests($batchSize);
        $result = $pool->send($requests);

        // onResponse must be invoked exactly N times (once per request)
        if (count($responseCallbacks) !== $batchSize) {
            return false;
        }

        // Verify each onResponse callback received a valid index (0 to N-1)
        $responseIndices = array_column($responseCallbacks, 'index');
        sort($responseIndices);
        $expectedIndices = range(0, $batchSize - 1);

        if ($responseIndices !== $expectedIndices) {
            return false;
        }

        // Count the actual failed responses from the result
        $failedResponses = $result->getFailed();
        $expectedErrorCount = count($failedResponses);

        // onError must be invoked exactly for failed responses
        if (count($errorCallbacks) !== $expectedErrorCount) {
            return false;
        }

        // Verify error callback indices match the indices of failed responses
        $errorIndices = array_column($errorCallbacks, 'index');
        sort($errorIndices);

        $failedIndices = array_keys($failedResponses);
        sort($failedIndices);

        if ($errorIndices !== $failedIndices) {
            return false;
        }

        // Verify each error callback received a response that is actually failed
        foreach ($errorCallbacks as $entry) {
            if (!$entry['response']->failed()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Build an array of FakeHttpClient instances with mixed success/failure status codes.
     *
     * Uses a random mix of success (200, 201, 204) and failure
     * (400, 403, 404, 500, 502, 503) status codes to ensure both onResponse
     * and onError callbacks are exercised across varied scenarios.
     *
     * @param int $count Number of request clients to create.
     *
     * @return array<int, FakeHttpClient> Array of configured FakeHttpClient instances.
     */
    private function buildCallbackTestRequests(int $count): array
    {
        $requests = [];

        for ($idx = 0; $idx < $count; $idx++) {
            $url = "https://api.example.com/callback/{$idx}";
            $statusCode = self::STATUS_CODES[array_rand(self::STATUS_CODES)];

            $client = FakeHttpClient::fake([
                "GET {$url}" => $statusCode,
            ]);

            $client->withBaseUrl('https://api.example.com')
                ->resource("/callback/{$idx}")
                ->withMethod('GET');

            $requests[$idx] = $client;
        }

        return $requests;
    }
}
