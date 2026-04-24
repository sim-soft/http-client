<?php

namespace Simsoft\HttpClient\Streams;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Stream class
 */
abstract class Stream implements StreamInterface
{
    protected bool $detached = false;

    /**
     * Centralized attachment check.
     * Using a trait method reduces the call stack overhead.
     */
    protected function assertAttached(): void
    {
        if ($this->detached) {
            throw new RuntimeException('Stream is detached');
        }
    }

    /**
     * Rewinds the internal pointer to the beginning.
     *
     * @return void This method does not return a value.
     */
    public function rewind(): void
    {
        $this->seek(0);
    }

    /**
     * Retrieves metadata associated with the stream.
     * If a specific key is provided, only the value associated
     * with that key will be returned. If the stream is detached,
     * it returns null for a specific key or an empty array if no key is given.
     *
     * @param string|null $key The specific metadata key to retrieve or null to retrieve all metadata.
     * @return mixed The value of the specified metadata key, an array of all metadata, or null if the stream is detached.
     */
    public function getMetadata(?string $key = null): mixed
    {
        if ($this->detached) {
            return $key ? null : [];
        }

        $meta = $this->getStreamMetadata();
        return $key ? ($meta[$key] ?? null) : $meta;
    }

    /**
     * Retrieves metadata associated with a stream.
     *
     * @return array<string, mixed> An associative array containing metadata information.
     */
    abstract protected function getStreamMetadata(): array;
}
