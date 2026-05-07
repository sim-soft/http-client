<?php

namespace Simsoft\HttpClient\Testing;

use Closure;
use PHPUnit\Framework\AssertionFailedError;
use Simsoft\HttpClient\HttpClient;
use Simsoft\HttpClient\Response;

/**
 * FakeHttpClient class.
 *
 * A test double extending HttpClient that intercepts request execution
 * to return predefined responses. Supports URL pattern matching, response
 * sequencing, and request recording for PHPUnit assertion integration.
 */
final class FakeHttpClient extends HttpClient
{
    /** @var array<int, FakeRoute> Configured fake routes. */
    private array $routes = [];

    /** @var array<int, RecordedRequest> Recorded requests. */
    private array $recorded = [];

    /**
     * Static factory with pre-configured responses.
     *
     * Accepts an associative array mapping URL patterns to response
     * configurations. Each key is a pattern (e.g., "GET /users") and
     * each value is a Response object, status code integer, or config array.
     *
     * @param array<string, Response|array<string, mixed>|int> $responses Map of pattern => response config.
     *
     * @return self
     */
    public static function fake(array $responses = []): self
    {
        $instance = new self();

        foreach ($responses as $pattern => $response) {
            $instance->addFake($pattern, $response);
        }

        return $instance;
    }

    /**
     * Add a fake response for a pattern.
     *
     * Response factory logic:
     * - int: creates a Response with that status code
     * - array: creates a Response with status, headers, and body from array keys
     * - Response: used directly
     *
     * @param string|Closure $matcher URL pattern (supports * wildcard) or callable.
     * @param Response|array<string, mixed>|int $response Response object, config array, or status code.
     *
     * @return self
     */
    public function addFake(string|Closure $matcher, Response|array|int $response): self
    {
        $this->routes[] = new FakeRoute($matcher, [$this->buildResponse($response)]);

        return $this;
    }

    /**
     * Add a sequence of responses for a pattern.
     *
     * Each response in the array goes through the same factory logic
     * as addFake(). Responses are returned in order for matching requests.
     *
     * @param string $pattern URL pattern.
     * @param array<int, Response|array<string, mixed>|int> $responses Ordered responses.
     *
     * @return self
     */
    public function sequence(string $pattern, array $responses): self
    {
        $built = array_map(fn(Response|array|int $item): Response => $this->buildResponse($item), $responses);

        $this->routes[] = new FakeRoute($pattern, $built);

        return $this;
    }

    /**
     * Get all recorded requests.
     *
     * @return array<int, RecordedRequest>
     */
    public function getRecordedRequests(): array
    {
        return $this->recorded;
    }

    /**
     * Assert a request was sent matching method and URL.
     *
     * Iterates through all recorded requests and checks if any match
     * the given HTTP method and URL combination.
     *
     * @param string $method HTTP method (e.g., "GET", "POST").
     * @param string $url URL or URL pattern to match.
     *
     * @return void
     *
     * @throws AssertionFailedError If no matching request was recorded.
     */
    public function assertSent(string $method, string $url): void
    {
        foreach ($this->recorded as $request) {
            if ($this->requestMatches($request, $method, $url)) {
                return;
            }
        }

        throw new AssertionFailedError(
            "Expected a request with method [{$method}] and URL [{$url}] to have been sent, but it was not."
        );
    }

    /**
     * Assert a request was NOT sent matching method and URL.
     *
     * Iterates through all recorded requests and verifies that none
     * match the given HTTP method and URL combination.
     *
     * @param string $method HTTP method (e.g., "GET", "POST").
     * @param string $url URL or URL pattern to match.
     *
     * @return void
     *
     * @throws AssertionFailedError If a matching request was recorded.
     */
    public function assertNotSent(string $method, string $url): void
    {
        foreach ($this->recorded as $request) {
            if ($this->requestMatches($request, $method, $url)) {
                throw new AssertionFailedError(
                    "Unexpected request with method [{$method}] and URL [{$url}] was sent."
                );
            }
        }
    }

    /**
     * Assert no requests were made.
     *
     * Verifies that the recorded requests array is empty.
     *
     * @return void
     *
     * @throws AssertionFailedError If any requests were recorded.
     */
    public function assertNothingSent(): void
    {
        $count = count($this->recorded);

        if ($count === 0) {
            return;
        }

        throw new AssertionFailedError(
            "Expected no requests to have been sent, but {$count} request(s) were recorded."
        );
    }

    /**
     * Assert the total number of requests made.
     *
     * Verifies that the number of recorded requests matches the expected count.
     *
     * @param int $count Expected number of requests.
     *
     * @return void
     *
     * @throws AssertionFailedError If the actual count does not match.
     */
    public function assertSentCount(int $count): void
    {
        $actual = count($this->recorded);

        if ($actual === $count) {
            return;
        }

        throw new AssertionFailedError(
            "Expected {$count} request(s) to have been sent, but {$actual} request(s) were recorded."
        );
    }

    /**
     * Determine if a recorded request matches the given method and URL.
     *
     * @param RecordedRequest $request The recorded request to check.
     * @param string $method The expected HTTP method.
     * @param string $url The expected URL or URL pattern.
     *
     * @return bool True if the request matches.
     */
    private function requestMatches(RecordedRequest $request, string $method, string $url): bool
    {
        if (strtoupper($request->method) !== strtoupper($method)) {
            return false;
        }

        if ($request->url === $url) {
            return true;
        }

        return fnmatch($url, $request->url);
    }

    /**
     * Override: returns fake responses instead of executing cURL.
     *
     * Records the request details, iterates through configured routes
     * to find a match, and returns the matched route's next response.
     * Throws UnexpectedRequestException if no route matches.
     *
     * @return Closure
     */
    protected function getCoreHandler(): Closure
    {
        return function (): Response {
            $method = $this->method;
            $url = $this->getEndpoint();

            if (!empty($this->queryParams)) {
                $separator = str_contains($url, '?') ? '&' : '?';
                $url .= $separator . http_build_query($this->queryParams);
            }

            $this->recorded[] = new RecordedRequest(
                method: $method,
                url: $url,
                headers: $this->headers,
                body: $this->postFields,
            );

            foreach ($this->routes as $route) {
                if ($route->match($method, $url)) {
                    return $route->nextResponse();
                }
            }

            throw new UnexpectedRequestException($method, $url);
        };
    }

    /**
     * Build a Response object from various input formats.
     *
     * @param Response|array<string, mixed>|int $response The response configuration.
     *
     * @return Response
     */
    private function buildResponse(Response|array|int $response): Response
    {
        if ($response instanceof Response) {
            return $response;
        }

        if (is_int($response)) {
            return new Response(curlInfo: ['http_code' => $response]);
        }

        $status = $response['status'] ?? 200;
        $body = $response['body'] ?? '';
        $headers = $response['headers'] ?? [];

        $rawHeaders = $this->buildRawHeaders($status, $headers);

        return new Response(
            curlInfo: ['http_code' => $status],
            body: $body,
            rawHeaders: $rawHeaders,
        );
    }

    /**
     * Build a raw headers string from status code and headers array.
     *
     * @param int $status The HTTP status code.
     * @param array<string, string|array<string>> $headers The response headers.
     *
     * @return string
     */
    private function buildRawHeaders(int $status, array $headers): string
    {
        if ($headers === []) {
            return '';
        }

        $lines = ["HTTP/1.1 $status OK"];

        foreach ($headers as $name => $value) {
            $values = is_array($value) ? $value : [$value];
            foreach ($values as $val) {
                $lines[] = "$name: $val";
            }
        }

        return implode("\r\n", $lines) . "\r\n\r\n";
    }
}
