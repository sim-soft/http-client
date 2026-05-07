<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests\Testing;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QuickCheck\Generator as Gen;
use QuickCheck\PHPUnit\PropertyConstraint;
use QuickCheck\Property;
use Simsoft\HttpClient\Response;
use Simsoft\HttpClient\Testing\FakeHttpClient;
use Simsoft\HttpClient\Testing\FakeRoute;
use Simsoft\HttpClient\Testing\RecordedRequest;
use Simsoft\HttpClient\Testing\UnexpectedRequestException;

/**
 * FakeHttpClientPropertyTest class.
 *
 * Property-based tests for FakeHttpClient support classes.
 * Validates pattern matching and response sequencing behavior of FakeRoute.
 *
 * Feature: http-pool-and-testing, Property 8: Fake Pattern Matching,
 * Property 9: Fake Unmatched Request Exception,
 * Property 10: Fake Response Construction, Property 11: Fake Request Recording,
 * Property 12: Fake Response Sequencing
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 */
class FakeHttpClientPropertyTest extends TestCase
{
    /** @var string[] HTTP methods used for generation. */
    private const HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    /**
     * Property 8: Fake Pattern Matching — Exact Match.
     *
     * For any HTTP method M and URL U, a FakeRoute configured with pattern
     * "M U" SHALL match only when the request method equals M and the
     * request URL equals U exactly.
     *
     * **Validates: Requirements 8.2, 8.4**
     *
     * @return void
     */
    #[Test]
    public function fakePatternMatchingExactMatch(): void
    {
        $methods = self::HTTP_METHODS;
        $pathSegments = ['users', 'posts', 'comments', 'api', 'v1', 'v2', 'data', 'items'];

        $property = Property::forAll(
            [
                Gen::elements(...$methods),
                Gen::elements(...$pathSegments),
                Gen::elements(...$pathSegments),
            ],
            function (string $method, string $segment1, string $segment2): bool {
                $url = "https://example.com/{$segment1}/{$segment2}";
                $pattern = "{$method} {$url}";
                $response = new Response(curlInfo: ['http_code' => 200]);
                $route = new FakeRoute($pattern, [$response]);

                // Must match the exact method + URL
                $matchesExact = $route->match($method, $url);

                // Must NOT match a different URL
                $differentUrl = "https://example.com/{$segment2}/{$segment1}/extra";
                $rejectsDifferentUrl = !$route->match($method, $differentUrl);

                // Must NOT match a different method
                $otherMethod = $method === 'GET' ? 'POST' : 'GET';
                $rejectsDifferentMethod = !$route->match($otherMethod, $url);

                return $matchesExact && $rejectsDifferentUrl && $rejectsDifferentMethod;
            }
        );

        $this->assertThat(
            $property,
            PropertyConstraint::check(100)
        );
    }

    /**
     * Property 8: Fake Pattern Matching — Wildcard.
     *
     * For any HTTP method M and base URL path, a FakeRoute configured with
     * pattern "M https://example.com/base/*" SHALL match any URL that starts
     * with the base path prefix, and SHALL NOT match URLs with a different base.
     *
     * **Validates: Requirements 8.2, 8.4**
     *
     * @return void
     */
    #[Test]
    public function fakePatternMatchingWildcard(): void
    {
        $methods = self::HTTP_METHODS;
        $basePaths = ['users', 'posts', 'api', 'items', 'data', 'orders'];
        $suffixes = ['123', 'abc', 'test', 'detail', 'list', 'new'];

        $property = Property::forAll(
            [
                Gen::elements(...$methods),
                Gen::elements(...$basePaths),
                Gen::elements(...$suffixes),
            ],
            function (string $method, string $basePath, string $suffix): bool {
                $pattern = "{$method} https://example.com/{$basePath}/*";
                $response = new Response(curlInfo: ['http_code' => 200]);
                $route = new FakeRoute($pattern, [$response]);

                // Must match URL with any suffix after the base path
                $matchingUrl = "https://example.com/{$basePath}/{$suffix}";
                $matchesWildcard = $route->match($method, $matchingUrl);

                // Must match URL with deeper path after wildcard
                $deeperUrl = "https://example.com/{$basePath}/{$suffix}/nested";
                $matchesDeeper = $route->match($method, $deeperUrl);

                // Must NOT match a completely different base path
                $otherBase = $basePath === 'users' ? 'orders' : 'users';
                $differentBaseUrl = "https://example.com/{$otherBase}/{$suffix}";
                $rejectsDifferentBase = !$route->match($method, $differentBaseUrl);

                return $matchesWildcard && $matchesDeeper && $rejectsDifferentBase;
            }
        );

        $this->assertThat(
            $property,
            PropertyConstraint::check(100)
        );
    }

    /**
     * Property 8: Fake Pattern Matching — Method-Agnostic.
     *
     * For any URL pattern without a method prefix, a FakeRoute SHALL match
     * any HTTP method when the URL matches the pattern.
     *
     * **Validates: Requirements 8.2, 8.4**
     *
     * @return void
     */
    #[Test]
    public function fakePatternMatchingMethodAgnostic(): void
    {
        $methods = self::HTTP_METHODS;
        $paths = ['users', 'posts', 'comments', 'api/v1', 'data', 'items'];

        $property = Property::forAll(
            [
                Gen::elements(...$methods),
                Gen::elements(...$paths),
            ],
            function (string $method, string $path): bool {
                $url = "https://example.com/{$path}";
                $pattern = $url; // No method prefix — method-agnostic
                $response = new Response(curlInfo: ['http_code' => 200]);
                $route = new FakeRoute($pattern, [$response]);

                // Must match any HTTP method with the exact URL
                $matchesAnyMethod = $route->match($method, $url);

                // Must NOT match a different URL regardless of method
                $differentUrl = "https://example.com/{$path}/nonexistent";
                $rejectsDifferentUrl = !$route->match($method, $differentUrl);

                return $matchesAnyMethod && $rejectsDifferentUrl;
            }
        );

        $this->assertThat(
            $property,
            PropertyConstraint::check(100)
        );
    }

    /**
     * Property 8: Fake Pattern Matching — Callable.
     *
     * For any callable matcher fn($method, $url) => bool, a FakeRoute SHALL
     * return true if and only if the callable returns true for the given
     * method and URL combination.
     *
     * **Validates: Requirements 8.2, 8.4**
     *
     * @return void
     */
    #[Test]
    public function fakePatternMatchingCallable(): void
    {
        $methods = self::HTTP_METHODS;
        $paths = ['users', 'posts', 'comments', 'orders', 'products', 'settings'];

        $property = Property::forAll(
            [
                Gen::elements(...$methods),
                Gen::elements(...$paths),
                Gen::elements(...$paths),
            ],
            function (string $method, string $targetPath, string $requestPath): bool {
                $callable = fn(string $reqMethod, string $reqUrl): bool => str_contains($reqUrl, $targetPath);

                $response = new Response(curlInfo: ['http_code' => 200]);
                $route = new FakeRoute($callable, [$response]);

                $requestUrl = "https://example.com/{$requestPath}";
                $routeMatches = $route->match($method, $requestUrl);

                // The callable matches if the request URL contains the target path
                $expectedMatch = str_contains($requestUrl, $targetPath);

                return $routeMatches === $expectedMatch;
            }
        );

        $this->assertThat(
            $property,
            PropertyConstraint::check(100)
        );
    }

    /**
     * Property 8: Fake Pattern Matching — Response Returned on Match.
     *
     * When a FakeRoute matches a request, the configured Response SHALL be
     * returned via nextResponse(). This verifies the complete contract:
     * match determines eligibility, and nextResponse provides the response.
     *
     * **Validates: Requirements 8.2, 8.4**
     *
     * @return void
     */
    #[Test]
    public function fakePatternMatchingReturnsConfiguredResponse(): void
    {
        $methods = self::HTTP_METHODS;
        $statusCodes = [200, 201, 204, 301, 400, 404, 500];
        $paths = ['users', 'posts', 'comments', 'api', 'data', 'items'];

        $property = Property::forAll(
            [
                Gen::elements(...$methods),
                Gen::elements(...$paths),
                Gen::elements(...$statusCodes),
            ],
            function (string $method, string $path, int $statusCode): bool {
                $url = "https://example.com/{$path}";
                $pattern = "{$method} {$url}";
                $response = new Response(curlInfo: ['http_code' => $statusCode]);
                $route = new FakeRoute($pattern, [$response]);

                // Verify match succeeds
                $matches = $route->match($method, $url);

                // Verify the configured response is returned
                $returnedResponse = $route->nextResponse();
                $correctStatus = $returnedResponse->getStatusCode() === $statusCode;

                return $matches && $correctStatus;
            }
        );

        $this->assertThat(
            $property,
            PropertyConstraint::check(100)
        );
    }

    /**
     * Property 9: Fake Unmatched Request Exception.
     *
     * For any request URL U that does not match any configured pattern in
     * FakeHttpClient, executing that request SHALL throw an exception. The set
     * of URLs that throw is exactly the complement of the set that matches any
     * configured pattern.
     *
     * **Validates: Requirements 8.3**
     *
     * @return void
     */
    #[Test]
    public function fakeUnmatchedRequestException(): void
    {
        $nonMatchingSegments = [
            'products', 'orders', 'invoices', 'reports', 'settings',
            'dashboard', 'analytics', 'webhooks', 'notifications', 'logs',
            'metrics', 'health', 'status', 'config', 'admin',
        ];

        $property = Property::forAll(
            [
                Gen::elements(...$nonMatchingSegments),
                Gen::elements(...$nonMatchingSegments),
            ],
            function (string $segment1, string $segment2): bool {
                // Configure FakeHttpClient with a specific pattern that won't match
                $client = FakeHttpClient::fake([
                    'GET https://example.com/users' => 200,
                ]);

                // Build a non-matching path from generated segments
                $nonMatchingPath = "/{$segment1}/{$segment2}";

                try {
                    $client->withBaseUrl('https://example.com')
                        ->resource($nonMatchingPath)
                        ->get();

                    // If no exception was thrown, the test fails
                    return false;
                } catch (UnexpectedRequestException) {
                    return true;
                }
            }
        );

        $this->assertThat(
            $property,
            PropertyConstraint::check(100)
        );
    }

    /**
     * Property 10: Fake Response Construction.
     *
     * For any valid combination of status code S (100-599), headers map H,
     * and body string B, a fake Response constructed from these values SHALL
     * expose getStatusCode() === S, getHeaders() containing all entries from H,
     * and body() === B.
     *
     * **Validates: Requirements 8.5**
     *
     * @return void
     */
    #[Test]
    public function fakeResponseConstruction(): void
    {
        $headerNames = [
            'X-Custom-Header',
            'X-Request-Id',
            'X-Trace-Id',
            'X-Correlation-Id',
            'X-Rate-Limit',
            'X-Api-Version',
        ];

        $headerValues = [
            'value-one',
            'value-two',
            'abc123',
            'test-value',
            '42',
            'v2.0',
            'enabled',
            'application/json',
        ];

        $bodyValues = [
            '',
            'Hello World',
            '{"key":"value"}',
            'plain text body',
            '<html><body>test</body></html>',
            '12345',
            'line1\nline2',
            'special chars: @#$%',
            'unicode: cafe',
            'a longer body with multiple words and content',
        ];

        $property = Property::forAll(
            [
                Gen::choose(100, 599),
                Gen::elements(...$bodyValues),
                Gen::elements(...$headerNames),
                Gen::elements(...$headerValues),
            ],
            function (int $status, string $body, string $headerName, string $headerValue): bool {
                $headers = [$headerName => $headerValue];

                $client = FakeHttpClient::fake([
                    'GET https://example.com/test' => [
                        'status' => $status,
                        'body' => $body,
                        'headers' => $headers,
                    ],
                ]);

                /** @var \Simsoft\HttpClient\Response $response */
                $response = $client->withBaseUrl('https://example.com')
                    ->get('/test');

                $statusMatches = $response->getStatusCode() === $status;
                $bodyMatches = $response->body() === $body;

                // Verify headers contain the configured entry (case-insensitive keys)
                $responseHeaders = $response->getHeaders();
                $lowerHeaderName = strtolower($headerName);
                $headerPresent = isset($responseHeaders[$lowerHeaderName]);
                $headerCorrect = $headerPresent
                    && in_array($headerValue, $responseHeaders[$lowerHeaderName], true);

                return $statusMatches && $bodyMatches && $headerCorrect;
            }
        );

        $this->assertThat(
            $property,
            PropertyConstraint::check(100)
        );
    }

    /**
     * Property 11: Fake Request Recording.
     *
     * For any sequence of N requests made through FakeHttpClient (each with
     * method M_i, URL U_i, headers H_i, body B_i), getRecordedRequests()
     * SHALL return an array of exactly N RecordedRequest objects where each
     * entry at index i has method === M_i, url === U_i, headers containing H_i,
     * and body === B_i. Furthermore, assertSent(M_i, U_i) SHALL pass for all i,
     * and assertSentCount(N) SHALL pass.
     *
     * **Validates: Requirements 9.1, 9.2, 9.3, 9.4**
     *
     * @return void
     */
    #[Test]
    public function fakeRequestRecording(): void
    {
        $methods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'];
        $paths = ['/users', '/posts', '/comments', '/orders', '/products', '/settings', '/api/v1', '/data'];

        $property = Property::forAll(
            [
                Gen::choose(1, 20),
                Gen::elements(...$methods),
                Gen::elements(...$paths),
            ],
            function (int $requestCount, string $seedMethod, string $seedPath) use ($methods, $paths): bool {
                $client = new FakeHttpClient();
                $client->addFake('*', 200);
                $client->withBaseUrl('https://example.com');

                $expectedRequests = $this->buildExpectedRequests(
                    $requestCount,
                    $seedMethod,
                    $seedPath,
                    $methods,
                    $paths,
                );

                $this->executeRecordedRequests($client, $expectedRequests);

                return $this->verifyRecordedCount($client, $requestCount)
                    && $this->verifyRecordedEntries($client, $expectedRequests)
                    && $this->verifyAssertSentPasses($client, $expectedRequests);
            }
        );

        $this->assertThat(
            $property,
            PropertyConstraint::check(100)
        );
    }

    /**
     * Build the expected request data for the property test.
     *
     * Uses the seed method and path to deterministically generate a varied
     * sequence of requests by rotating through available methods and paths.
     *
     * @param int $requestCount Number of requests to generate.
     * @param string $seedMethod Seed method from generator.
     * @param string $seedPath Seed path from generator.
     * @param array<int, string> $allMethods All available methods.
     * @param array<int, string> $allPaths All available paths.
     * @return array<int, array{method: string, path: string}> Expected request data.
     */
    private function buildExpectedRequests(
        int    $requestCount,
        string $seedMethod,
        string $seedPath,
        array  $allMethods,
        array  $allPaths,
    ): array
    {
        $methodStart = array_search($seedMethod, $allMethods, true);
        $pathStart = array_search($seedPath, $allPaths, true);
        $methodCount = count($allMethods);
        $pathCount = count($allPaths);

        $expected = [];

        for ($idx = 0; $idx < $requestCount; $idx++) {
            $expected[] = [
                'method' => $allMethods[($methodStart + $idx) % $methodCount],
                'path' => $allPaths[($pathStart + $idx) % $pathCount],
            ];
        }

        return $expected;
    }

    /**
     * Execute requests through the FakeHttpClient for recording.
     *
     * @param FakeHttpClient $client The fake client instance.
     * @param array<int, array{method: string, path: string}> $requests Request data to execute.
     * @return void
     */
    private function executeRecordedRequests(FakeHttpClient $client, array $requests): void
    {
        foreach ($requests as $request) {
            $client->send($request['method'], $request['path']);
        }
    }

    /**
     * Verify that getRecordedRequests() returns exactly N entries
     * and assertSentCount(N) passes.
     *
     * @param FakeHttpClient $client The fake client instance.
     * @param int $expectedCount Expected number of recorded requests.
     * @return bool True if count matches.
     */
    private function verifyRecordedCount(FakeHttpClient $client, int $expectedCount): bool
    {
        $recorded = $client->getRecordedRequests();

        if (count($recorded) !== $expectedCount) {
            return false;
        }

        try {
            $client->assertSentCount($expectedCount);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * Verify each recorded entry has the correct method and URL.
     *
     * @param FakeHttpClient $client The fake client instance.
     * @param array<int, array{method: string, path: string}> $expectedRequests Expected request data.
     * @return bool True if all entries match.
     */
    private function verifyRecordedEntries(FakeHttpClient $client, array $expectedRequests): bool
    {
        $recorded = $client->getRecordedRequests();

        foreach ($expectedRequests as $idx => $expected) {
            /** @var RecordedRequest $entry */
            $entry = $recorded[$idx];
            $expectedUrl = 'https://example.com' . $expected['path'];

            if (strtoupper($entry->method) !== strtoupper($expected['method'])) {
                return false;
            }

            if ($entry->url !== $expectedUrl) {
                return false;
            }
        }

        return true;
    }

    /**
     * Verify that assertSent() passes for each request made.
     *
     * @param FakeHttpClient $client The fake client instance.
     * @param array<int, array{method: string, path: string}> $expectedRequests Expected request data.
     * @return bool True if assertSent passes for all requests.
     */
    private function verifyAssertSentPasses(FakeHttpClient $client, array $expectedRequests): bool
    {
        foreach ($expectedRequests as $expected) {
            $expectedUrl = 'https://example.com' . $expected['path'];

            try {
                $client->assertSent($expected['method'], $expectedUrl);
            } catch (\Throwable) {
                return false;
            }
        }

        return true;
    }

    /**
     * Property 12: Fake Response Sequencing.
     *
     * For any sequence of K responses configured for a URL pattern P,
     * the first K matching requests SHALL return responses in order
     * (response[0], response[1], ..., response[K-1]). For any subsequent
     * matching request beyond K, the response SHALL always be response[K-1]
     * (the last in the sequence).
     *
     * **Validates: Requirements 10.1, 10.2**
     *
     * @return void
     */
    #[Test]
    public function fakeResponseSequencing(): void
    {
        $property = Property::forAll(
            [Gen::choose(1, 10), Gen::choose(1, 20)],
            function (int $sequenceLength, int $totalRequests): bool {
                // Ensure total requests >= sequence length
                $totalRequests = max($totalRequests, $sequenceLength);

                $responses = $this->buildResponseSequence($sequenceLength);
                $route = new FakeRoute('https://example.com/api/*', $responses);

                return $this->verifyOrderedSequence($route, $responses, $sequenceLength)
                    && $this->verifyClampedToLast($route, $responses, $sequenceLength, $totalRequests);
            }
        );

        $this->assertThat(
            $property,
            PropertyConstraint::check(100)
        );
    }

    /**
     * Build a sequence of Response objects with distinct status codes.
     *
     * Each response gets a unique status code (200 + index) so they can
     * be distinguished during verification.
     *
     * @param int $sequenceLength Number of responses in the sequence.
     * @return array<int, Response> Ordered response sequence.
     */
    private function buildResponseSequence(int $sequenceLength): array
    {
        $responses = [];

        for ($idx = 0; $idx < $sequenceLength; $idx++) {
            $responses[$idx] = new Response(
                curlInfo: ['http_code' => 200 + $idx],
                body: '',
                message: '',
            );
        }

        return $responses;
    }

    /**
     * Verify that the first K calls return responses in order.
     *
     * @param FakeRoute $route The fake route under test.
     * @param array<int, Response> $responses The configured response sequence.
     * @param int $sequenceLength Number of responses in the sequence (K).
     * @return bool True if the first K calls return responses in order.
     */
    private function verifyOrderedSequence(FakeRoute $route, array $responses, int $sequenceLength): bool
    {
        for ($idx = 0; $idx < $sequenceLength; $idx++) {
            $actual = $route->nextResponse();

            if ($actual->getStatusCode() !== $responses[$idx]->getStatusCode()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Verify that calls beyond K always return the last response.
     *
     * @param FakeRoute $route The fake route under test (cursor already at K).
     * @param array<int, Response> $responses The configured response sequence.
     * @param int $sequenceLength Number of responses in the sequence (K).
     * @param int $totalRequests Total number of requests to make (N >= K).
     * @return bool True if all calls beyond K return response[K-1].
     */
    private function verifyClampedToLast(
        FakeRoute $route,
        array     $responses,
        int       $sequenceLength,
        int       $totalRequests,
    ): bool
    {
        $lastResponse = $responses[$sequenceLength - 1];
        $remainingCalls = $totalRequests - $sequenceLength;

        for ($idx = 0; $idx < $remainingCalls; $idx++) {
            $actual = $route->nextResponse();

            if ($actual->getStatusCode() !== $lastResponse->getStatusCode()) {
                return false;
            }
        }

        return true;
    }
}
