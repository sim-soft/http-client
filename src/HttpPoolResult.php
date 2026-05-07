<?php

namespace Simsoft\HttpClient;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use OutOfBoundsException;
use Traversable;

/**
 * HttpPoolResult class.
 *
 * A value object wrapping the results of concurrent HTTP pool execution.
 * Provides convenience methods for filtering successful and failed responses.
 * Supports array access ($result['key']), iteration (foreach), and invokable access ($result('key')).
 *
 * @implements ArrayAccess<int|string, Response>
 * @implements IteratorAggregate<int|string, Response>
 */
class HttpPoolResult implements Countable, ArrayAccess, IteratorAggregate
{
    /**
     * Constructor.
     *
     * @param array<int|string, Response> $responses Indexed responses matching input order.
     */
    public function __construct(private array $responses)
    {
    }

    /**
     * Get all responses.
     *
     * @return array<int|string, Response> All responses indexed by their original key.
     */
    public function getResponses(): array
    {
        return $this->responses;
    }

    /**
     * Get only successful (2xx) responses.
     *
     * @return array<int|string, Response> Responses where successful() is true, preserving original keys.
     */
    public function getSuccessful(): array
    {
        return array_filter($this->responses, static fn(Response $response): bool => $response->successful());
    }

    /**
     * Get only failed responses (4xx, 5xx, network errors).
     *
     * @return array<int|string, Response> Responses where failed() is true, preserving original keys.
     */
    public function getFailed(): array
    {
        return array_filter($this->responses, static fn(Response $response): bool => $response->failed());
    }

    /**
     * Get a response by its key.
     *
     * @param int|string $key The key of the response to retrieve.
     *
     * @return Response The response at the specified key.
     *
     * @throws OutOfBoundsException When the key does not exist in the results.
     */
    public function getResponse(int|string $key): Response
    {
        if (!array_key_exists($key, $this->responses)) {
            throw new OutOfBoundsException("No response at key '$key'.");
        }

        return $this->responses[$key];
    }

    /**
     * Invokable shorthand for getResponse().
     *
     * Allows `$result('users')` syntax as an alternative to `$result->getResponse('users')`.
     *
     * @param int|string $key The key of the response to retrieve.
     *
     * @return Response The response at the specified key.
     *
     * @throws OutOfBoundsException When the key does not exist in the results.
     */
    public function __invoke(int|string $key): Response
    {
        return $this->getResponse($key);
    }

    /**
     * Check if a response exists at the given offset.
     *
     * @param mixed $offset The key to check.
     *
     * @return bool True if the key exists.
     */
    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->responses);
    }

    /**
     * Get a response by array access.
     *
     * @param mixed $offset The key of the response.
     *
     * @return Response The response at the specified key.
     *
     * @throws OutOfBoundsException When the key does not exist.
     */
    public function offsetGet(mixed $offset): Response
    {
        return $this->getResponse($offset);
    }

    /**
     * Not supported — HttpPoolResult is immutable.
     *
     * @param mixed $offset The key.
     * @param mixed $value The value.
     *
     * @throws \RuntimeException Always thrown — result is read-only.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \RuntimeException('HttpPoolResult is immutable.');
    }

    /**
     * Not supported — HttpPoolResult is immutable.
     *
     * @param mixed $offset The key.
     *
     * @throws \RuntimeException Always thrown — result is read-only.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new \RuntimeException('HttpPoolResult is immutable.');
    }

    /**
     * Get an iterator for the responses.
     *
     * @return Traversable<int|string, Response>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->responses);
    }

    /**
     * Get the total number of responses.
     *
     * @return int Total number of responses in the result set.
     */
    public function count(): int
    {
        return count($this->responses);
    }
}
