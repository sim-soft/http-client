<?php

namespace Simsoft\HttpClient\Testing;

use Closure;
use Simsoft\HttpClient\Response;

/**
 * FakeRoute class.
 *
 * Internal value object managing a single fake route's URL matcher and
 * response sequence. Used by FakeHttpClient to determine which predefined
 * response to return for a given request.
 */
final class FakeRoute
{
    /** @var int Current position in the response sequence. */
    private int $cursor = 0;

    /**
     * Create a new FakeRoute instance.
     *
     * @param string|Closure $matcher URL pattern or callable matcher.
     * @param array<int, Response> $responses Ordered response sequence.
     */
    public function __construct(
        private readonly string|Closure $matcher,
        private readonly array $responses,
    )
    {
    }

    /**
     * Determine if this route matches the given HTTP method and URL.
     *
     * Supports the following pattern formats:
     * - Exact match: "GET https://example.com/users"
     * - Wildcard: "GET https://example.com/users/*"
     * - Method-agnostic: "https://example.com/users"
     * - Callable: fn(string $method, string $url): bool
     *
     * @param string $method The HTTP method (e.g., "GET", "POST").
     * @param string $url The full request URL.
     *
     * @return bool True if this route matches the request.
     */
    public function match(string $method, string $url): bool
    {
        if ($this->matcher instanceof Closure) {
            return ($this->matcher)($method, $url);
        }

        $pattern = $this->matcher;

        if ($this->hasMethodPrefix($pattern)) {
            return $this->matchWithMethod($method, $url, $pattern);
        }

        return $this->matchUrl($url, $pattern);
    }

    /**
     * Return the current response and advance the cursor.
     *
     * When all sequenced responses have been consumed, repeats the last
     * response for subsequent calls.
     *
     * @return Response The next response in the sequence.
     */
    public function nextResponse(): Response
    {
        $response = $this->responses[$this->cursor];

        if ($this->cursor < count($this->responses) - 1) {
            $this->cursor++;
        }

        return $response;
    }

    /**
     * Determine if the pattern contains a method prefix (e.g., "GET https://...").
     *
     * @param string $pattern The route pattern string.
     *
     * @return bool True if the pattern starts with an HTTP method.
     */
    private function hasMethodPrefix(string $pattern): bool
    {
        $parts = explode(' ', $pattern, 2);

        if (count($parts) < 2) {
            return false;
        }

        $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

        return in_array(strtoupper($parts[0]), $methods, true);
    }

    /**
     * Match a pattern that includes an HTTP method prefix.
     *
     * @param string $method The request HTTP method.
     * @param string $url The request URL.
     * @param string $pattern The pattern with method prefix.
     *
     * @return bool True if both method and URL match.
     */
    private function matchWithMethod(string $method, string $url, string $pattern): bool
    {
        $parts = explode(' ', $pattern, 2);
        $patternMethod = strtoupper($parts[0]);
        $patternUrl = $parts[1];

        if (strtoupper($method) !== $patternMethod) {
            return false;
        }

        return $this->matchUrl($url, $patternUrl);
    }

    /**
     * Match a URL against a pattern using fnmatch for wildcard support.
     *
     * @param string $url The request URL.
     * @param string $pattern The URL pattern (may contain * wildcards).
     *
     * @return bool True if the URL matches the pattern.
     */
    private function matchUrl(string $url, string $pattern): bool
    {
        if ($url === $pattern) {
            return true;
        }

        return fnmatch($pattern, $url);
    }
}
