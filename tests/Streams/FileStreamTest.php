<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests\Streams;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Simsoft\HttpClient\Streams\FileStream;

/**
 * FileStreamTest class
 *
 * Tests for FileStream construction, read, seek, write rejection,
 * non-existent file handling, getSize, getContents, and __toString.
 */
class FileStreamTest extends TestCase
{
    private string $tempFile = '';

    /**
     * Set up temp file with known content before each test.
     */
    protected function setUp(): void
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'filestream_test_');
        if ($this->tempFile === false) {
            $this->fail('Unable to create temp file');
        }
        file_put_contents($this->tempFile, 'hello world');
    }

    /**
     * Clean up temp file after each test.
     */
    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    /**
     * Test construction with valid file path and read operations return file contents.
     */
    #[Test]
    public function constructionWithValidFileAndReadReturnsContents(): void
    {
        $stream = new FileStream($this->tempFile);

        $this->assertTrue($stream->isReadable());
        $this->assertTrue($stream->isSeekable());
        $this->assertSame('hello world', $stream->read(11));
    }

    /**
     * Test read returns correct number of bytes.
     */
    #[Test]
    public function readReturnsCorrectNumberOfBytes(): void
    {
        $stream = new FileStream($this->tempFile);

        $result = $stream->read(5);
        $this->assertSame('hello', $result);
        $this->assertSame(5, $stream->tell());

        $result = $stream->read(6);
        $this->assertSame(' world', $result);
        $this->assertSame(11, $stream->tell());
    }

    /**
     * Test seek changes position correctly.
     */
    #[Test]
    public function seekChangesPositionCorrectly(): void
    {
        $stream = new FileStream($this->tempFile);

        $stream->seek(6);
        $this->assertSame(6, $stream->tell());
        $this->assertSame('world', $stream->read(5));
    }

    /**
     * Test seek with SEEK_CUR advances from current position.
     */
    #[Test]
    public function seekWithSeekCurAdvancesFromCurrentPosition(): void
    {
        $stream = new FileStream($this->tempFile);

        $stream->read(3);
        $stream->seek(2, SEEK_CUR);
        $this->assertSame(5, $stream->tell());
        $this->assertSame(' world', $stream->read(6));
    }

    /**
     * Test seek with SEEK_END positions from end of file.
     */
    #[Test]
    public function seekWithSeekEndPositionsFromEnd(): void
    {
        $stream = new FileStream($this->tempFile);

        $stream->seek(-5, SEEK_END);
        $this->assertSame('world', $stream->read(5));
    }

    /**
     * Test isWritable returns false.
     */
    #[Test]
    public function isWritableReturnsFalse(): void
    {
        $stream = new FileStream($this->tempFile);

        $this->assertFalse($stream->isWritable());
    }

    /**
     * Test write throws RuntimeException.
     */
    #[Test]
    public function writeThrowsRuntimeException(): void
    {
        $stream = new FileStream($this->tempFile);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stream is not writable');
        $stream->write('data');
    }

    /**
     * Test construction with non-existent file throws RuntimeException on first access.
     */
    #[Test]
    public function nonExistentFileThrowsRuntimeExceptionOnAccess(): void
    {
        $stream = new FileStream('/tmp/non_existent_file_' . uniqid('', true) . '.txt');

        $this->expectException(RuntimeException::class);
        $stream->read(1);
    }

    /**
     * Test getSize returns actual file size in bytes.
     */
    #[Test]
    public function getSizeReturnsActualFileSize(): void
    {
        $stream = new FileStream($this->tempFile);

        $this->assertSame(11, $stream->getSize());
    }

    /**
     * Test getContents returns remaining content from current position.
     */
    #[Test]
    public function getContentsReturnsRemainingContentFromCurrentPosition(): void
    {
        $stream = new FileStream($this->tempFile);

        $stream->read(6);
        $this->assertSame('world', $stream->getContents());
    }

    /**
     * Test getContents returns full content from beginning.
     */
    #[Test]
    public function getContentsReturnsFullContentFromBeginning(): void
    {
        $stream = new FileStream($this->tempFile);

        $this->assertSame('hello world', $stream->getContents());
    }

    /**
     * Test __toString returns full content for files under 5MB.
     */
    #[Test]
    public function toStringReturnsFullContentForSmallFiles(): void
    {
        $stream = new FileStream($this->tempFile);

        $this->assertSame('hello world', (string)$stream);
    }

    /**
     * Test __toString resets position and returns full content.
     */
    #[Test]
    public function toStringResetsPositionAndReturnsFullContent(): void
    {
        $stream = new FileStream($this->tempFile);

        $stream->read(5);
        $this->assertSame('hello world', (string)$stream);
    }

    /**
     * Test __toString returns large stream placeholder for files over 5MB.
     */
    #[Test]
    public function toStringReturnsPlaceholderForLargeFiles(): void
    {
        $largeFile = tempnam(sys_get_temp_dir(), 'filestream_large_');
        $this->assertNotFalse($largeFile);

        try {
            $fh = fopen($largeFile, 'w');
            $this->assertNotFalse($fh);
            $size = 5 * 1024 * 1024 + 1;
            fseek($fh, $size - 1);
            fwrite($fh, "\0");
            fclose($fh);

            $stream = new FileStream($largeFile);
            $this->assertSame("[Large Stream: $size bytes]", (string)$stream);
        } finally {
            if (file_exists($largeFile)) {
                unlink($largeFile);
            }
        }
    }
}
