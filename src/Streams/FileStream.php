<?php

namespace Simsoft\HttpClient\Streams;

use Exception;
use RuntimeException;

/**
 * FileStream
 */
class FileStream extends Stream
{
    protected mixed $handle;

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
     * @throws RuntimeException If the file cannot be opened.
     */
    protected function getHandle()
    {
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

    public function __toString(): string
    {
        if ($this->getSize() > 5 * 1024 * 1024) { // 5MB limit
            return "[Large Stream: {$this->getSize()} bytes]";
        }

        try {
            $this->rewind();
            return $this->getContents();
        } catch (Exception $throwable) {
            return '';
        }
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    public function detach()
    {
        $result = $this->handle;
        $this->handle = null;
        return $result;
    }

    public function getSize(): ?int
    {
        if (!is_resource($this->getHandle())) return null;
        $stats = fstat($this->getHandle());
        return $stats['size'] ?? null;
    }

    public function tell(): int
    {
        $result = ftell($this->getHandle());
        if ($result === false) throw new RuntimeException('Unable to determine stream position');
        return $result;
    }

    public function eof(): bool
    {
        return !is_resource($this->getHandle()) || feof($this->handle);
    }

    public function isSeekable(): bool
    {
        return true;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if (fseek($this->getHandle(), $offset, $whence) === -1) {
            throw new RuntimeException('Error seeking in stream');
        }
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('Stream is not writable');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        $result = fread($this->getHandle(), max(1, $length));
        if ($result === false) throw new RuntimeException('Error reading from stream');
        return $result;
    }

    public function getContents(): string
    {
        $result = stream_get_contents($this->getHandle());
        if ($result === false) throw new RuntimeException('Error reading from stream');
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
