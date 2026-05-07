<?php

namespace Simsoft\HttpClient\Testing;

use RuntimeException;

/**
 * UnexpectedRequestException class.
 *
 * Thrown when FakeHttpClient receives a request that does not match
 * any configured fake route pattern.
 */
class UnexpectedRequestException extends RuntimeException
{
    /**
     * Create a new UnexpectedRequestException instance.
     *
     * @param string $method The HTTP method of the unmatched request.
     * @param string $url The URL of the unmatched request.
     */
    public function __construct(string $method, string $url)
    {
        parent::__construct("Unexpected request: $method $url");
    }
}
