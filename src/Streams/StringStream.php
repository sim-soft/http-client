<?php

namespace Simsoft\HttpClient\Streams;

use InvalidArgumentException;
use RuntimeException;

/**
 * StringStream class
 */
class StringStream extends Stream
{
    protected int $position = 0;

    protected int $contentLength = 0;

    /**
     * Constructor method for initializing the object with content.
     *
     * @param string $content The content to initialize, defaults to an empty string.
     * @return void
     */
    public function __construct(protected string $content = '')
    {
        $this->contentLength = strlen($this->content);
    }

    /**
     * Converts the object to its string representation.
     *
     * @return string The string representation of the object. Returns an empty string if the object is detached; otherwise, returns the content property.
     */
    public function __toString(): string
    {
        if ($this->detached) {
            return '';
        }

        return $this->content;
    }

    /**
     * Closes the object and resets its state.
     *
     * @return void This method does not return a value. It clears the content, resets the position and content length, and marks the object as detached.
     */
    public function close(): void
    {
        $this->content = '';
        $this->contentLength = 0;
        $this->position = 0;
        $this->detached = true;
    }

    /**
     * Detaches the current process or resource by closing it.
     *
     * @return null Always returns null after detaching.
     */
    public function detach()
    {
        $this->close();
        return null;
    }

    /**
     * Retrieves the size of the content.
     *
     * @return int|null The size of the content in bytes, or null if the object is detached.
     */
    public function getSize(): ?int
    {
        return $this->detached ? null : $this->contentLength;
    }

    /**
     * Retrieves the current position of the object.
     *
     * @return int The current position of the object.
     */
    public function tell(): int
    {
        $this->assertAttached();
        return $this->position;
    }

    /**
     * Checks if the end of the content has been reached.
     *
     * @return bool True if the end of the content has been reached or if the object is detached; otherwise, false.
     */
    public function eof(): bool
    {
        if ($this->detached) {
            return true;
        }

        return $this->position >= $this->contentLength;
    }

    /**
     * Determines if the stream is seekable.
     *
     * @return bool True if the stream is seekable; false if it is detached.
     */
    public function isSeekable(): bool
    {
        return !$this->detached;
    }

    /**
     * Adjusts the current position of the object based on the specified offset and whence.
     *
     * @param int $offset The offset for the position adjustment. Its interpretation depends on the $whence parameter.
     * @param int $whence The reference point for the position adjustment. Valid values are:
     *                     SEEK_SET - Set position to $offset.
     *                     SEEK_CUR - Set position to current position plus $offset.
     *                     SEEK_END - Set position to the end of content plus $offset.
     *
     * @return void
     *
     * @throws InvalidArgumentException If $whence is invalid.
     * @throws RuntimeException If the computed position is negative.
     */
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->assertAttached();

        $pos = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => $this->contentLength + $offset,
            default => throw new InvalidArgumentException("Invalid whence"),
        };

        if ($pos < 0) {
            throw new RuntimeException("Cannot seek to negative position");
        }

        $this->position = $pos;
    }

    /**
     * Determines if the object is writable.
     *
     * @return bool True if the object is writable; false if it is detached.
     */
    public function isWritable(): bool
    {
        return !$this->detached;
    }

    /**
     * Writes the given string to the current position in the content, adjusting the content length and position accordingly.
     *
     * @param string $string The string to be written into the content.
     * @return int The length of the string written to the content.
     */
    public function write(string $string): int
    {
        $this->assertAttached();

        $length = strlen($string);

        if ($this->position > $this->contentLength) {
            $this->content .= str_repeat("\0", $this->position - $this->contentLength);
        }

        $this->content = $this->position === $this->contentLength
            ? $this->content . $string
            : substr($this->content, 0, $this->position)
                . $string
                . substr($this->content, $this->position + $length);

        $this->position += $length;
        $this->contentLength = strlen($this->content);

        return $length;
    }

    /**
     * Determines if the object is readable.
     *
     * @return bool True if the object is not detached, otherwise false.
     */
    public function isReadable(): bool
    {
        return !$this->detached;
    }

    /**
     * Reads a specified number of characters from the content.
     *
     * @param int $length The number of characters to read. Must be greater than or equal to 0.
     * @return string The read characters. Returns an empty string if the end of the content is reached or if the length is 0.
     */
    public function read(int $length): string
    {
        if ($length < 0) {
            throw new InvalidArgumentException('Length must be >= 0');
        }

        $this->assertAttached();

        if ($this->eof()) {
            return '';
        }

        $result = substr($this->content, $this->position, $length);
        $this->position += strlen($result);

        return $result;
    }

    /**
     * Retrieves the remaining contents and updates the position to the end.
     *
     * @return string The remaining portion of the content from the current position. Returns an empty string if at the end of the content or if the object is not attached.
     */
    public function getContents(): string
    {
        $this->assertAttached();

        if ($this->eof()) {
            return '';
        }

        if ($this->position === 0) {
            $this->position = $this->contentLength;
            return $this->content;
        }

        $result = substr($this->content, $this->position);
        $this->position = $this->contentLength;
        return $result;
    }

    /**
     * @inheritDoc
     */
    protected function getStreamMetadata(): array
    {
        if ($this->detached) {
            return [];
        }

        return [
            'seekable' => true,
            'readable' => true,
            'writable' => true,
            'uri' => null,
            //'eof' => $this->eof(),
        ];
    }
}
