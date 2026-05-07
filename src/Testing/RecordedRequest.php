<?php

namespace Simsoft\HttpClient\Testing;

/**
 * RecordedRequest class.
 *
 * Immutable data transfer object capturing the details of an HTTP request
 * made through FakeHttpClient. Used for request inspection and assertions
 * during testing.
 */
final class RecordedRequest
{
    /**
     * Create a new RecordedRequest instance.
     *
     * @param string $method The HTTP method (e.g., "GET", "POST").
     * @param string $url The full request URL.
     * @param array<string, array<string>> $headers The request headers, keyed by header name.
     * @param mixed $body The request body content.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array  $headers,
        public readonly mixed  $body,
    )
    {
    }
}
