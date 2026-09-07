<?php

namespace Simsoft\HttpClient\Streams;

use Exception;
use RuntimeException;

/**
 * FileStream
 */
class FileStream extends Stream
{
    protected mixed $handle = null;

    /**
     * Constructor.
     *
     * @param string $path
     * @param string $mode
     */
    public function __construct(protected string $path, protected string $mode = 'r')
    {
    }

    /**
     * Retrieves the file handle for the specified path and mode.
     * If the handle is not already initialized, it attempts to open the file and
     * sets an optimized internal buffer size.
     *
     * @return resource The file handle used for reading or writing operations.
     * @throws RuntimeException If the stream is detached or the file cannot be opened.
     */
    protected function getHandle()
    {
        $this->assertAttached();

        if ($this->handle === null) {
            $this->handle = @fopen($this->path, $this->mode);
            if (!$this->handle) {
                throw new RuntimeException("Unable to open file: $this->path");
            }

            // OPTIMIZATION: Set the internal PHP buffer size to 8KB.
            // This reduces context switching between PHP and the OS Kernel.
            stream_set_chunk_size($this->handle, 8192);
        }
        return $this->handle;
    }

    /**
     * Converts the stream to its string representation.
     *
     * @return string
     */
    public function __toString(): string
    {
        if ($this->detached) {
            return '';
        }

        try {
            $this->rewind();
            return $this->getContents();
        } catch (Exception $throwable) {
            return '';
        }
    }

    /**
     * Closes the stream and releases the underlying resource.
     *
     * @return void
     */
    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
        $this->detached = true;
    }

    /**
     * Separates the underlying resource from the stream.
     *
     * @return resource|null The underlying resource or null if already detached.
     */
    public function detach()
    {
        $result = $this->handle;
        $this->handle = null;
        $this->detached = true;
        return $result;
    }

    /**
     * Get the size of the stream.
     *
     * @return int|null
     */
    public function getSize(): ?int
    {
        if ($this->detached) {
            return null;
        }

        $handle = $this->getHandle();
        $stats = fstat($handle);
        return $stats['size'] ?? null;
    }

    /**
     * Returns the current position of the file read/write pointer.
     *
     * @return int
     * @throws RuntimeException
     */
    public function tell(): int
    {
        $result = ftell($this->getHandle());
        if ($result === false) {
            throw new RuntimeException('Unable to determine stream position');
        }
        return $result;
    }

    /**
     * Returns whether the stream is at the end.
     *
     * @return bool
     */
    public function eof(): bool
    {
        if ($this->detached) {
            return true;
        }

        // The handle is opened lazily, so testing is_resource() first reported
        // end-of-file on an untouched stream and made `while (!$s->eof())` read
        // nothing at all. Opening here gives feof() a real handle to answer for.
        return feof($this->getHandle());
    }

    /**
     * Returns whether the stream is seekable.
     *
     * @return bool
     */
    public function isSeekable(): bool
    {
        return !$this->detached;
    }

    /**
     * Seek to a position in the stream.
     *
     * @param int $offset
     * @param int $whence
     * @return void
     * @throws RuntimeException
     */
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if (fseek($this->getHandle(), $offset, $whence) === -1) {
            throw new RuntimeException('Error seeking in stream');
        }
    }

    /**
     * Returns whether the stream is writable.
     *
     * @return bool
     */
    public function isWritable(): bool
    {
        return false;
    }

    /**
     * Write data to the stream.
     *
     * @param string $string
     * @return int
     * @throws RuntimeException Always — this stream is read-only.
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function write(string $string): int
    {
        throw new RuntimeException('Stream is not writable');
    }

    /**
     * Returns whether the stream is readable.
     *
     * @return bool
     */
    public function isReadable(): bool
    {
        return !$this->detached;
    }

    /**
     * Read data from the stream.
     *
     * @param int $length
     * @return string
     * @throws RuntimeException
     */
    public function read(int $length): string
    {
        if ($length < 0) {
            throw new RuntimeException('Length must not be negative');
        }

        // PSR-7 returns an empty string for a zero-length read. Clamping to 1 —
        // as a guard against fread()'s rejection of 0 — consumed a byte and
        // advanced the pointer instead.
        if ($length === 0) {
            return '';
        }

        $result = fread($this->getHandle(), $length);
        if ($result === false) {
            throw new RuntimeException('Error reading from stream');
        }
        return $result;
    }

    /**
     * Returns the remaining contents of the stream.
     *
     * @return string
     * @throws RuntimeException
     */
    public function getContents(): string
    {
        $result = stream_get_contents($this->getHandle());
        if ($result === false) {
            throw new RuntimeException('Error reading from stream');
        }
        return $result;
    }

    /**
     * @inheritDoc
     */
    protected function getStreamMetadata(): array
    {
        return stream_get_meta_data($this->getHandle());
    }
}
