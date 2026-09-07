<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests\Streams;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QuickCheck\Generator;
use QuickCheck\PHPUnit\PropertyConstraint;
use QuickCheck\Property;
use RuntimeException;
use Simsoft\HttpClient\Streams\StringStream;

/**
 * StringStreamTest class
 *
 * Tests for StringStream construction, read, write, seek, close, eof, getSize, and __toString.
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class StringStreamTest extends TestCase
{
    /**
     * Test construction with empty content.
     */
    #[Test]
    public function constructionWithEmptyContent(): void
    {
        $stream = new StringStream();

        $this->assertSame(0, $stream->getSize());
        $this->assertSame('', (string)$stream);
        $this->assertTrue($stream->eof());
        $this->assertSame(0, $stream->tell());
    }

    /**
     * Test construction with non-empty content.
     */
    #[Test]
    public function constructionWithNonEmptyContent(): void
    {
        $stream = new StringStream('hello world');

        $this->assertSame(11, $stream->getSize());
        $this->assertSame('hello world', (string)$stream);
        $this->assertFalse($stream->eof());
        $this->assertSame(0, $stream->tell());
    }

    /**
     * Test read returns correct substring and advances position.
     */
    #[Test]
    public function readReturnsCorrectSubstringAndAdvancesPosition(): void
    {
        $stream = new StringStream('hello world');

        $result = $stream->read(5);
        $this->assertSame('hello', $result);
        $this->assertSame(5, $stream->tell());

        $result = $stream->read(6);
        $this->assertSame(' world', $result);
        $this->assertSame(11, $stream->tell());
    }

    /**
     * Test read returns empty string at eof.
     */
    #[Test]
    public function readReturnsEmptyStringAtEof(): void
    {
        $stream = new StringStream('ab');

        $stream->read(2);
        $this->assertSame('', $stream->read(1));
    }

    /**
     * Test read with zero length returns empty string.
     */
    #[Test]
    public function readWithZeroLengthReturnsEmptyString(): void
    {
        $stream = new StringStream('hello');

        $this->assertSame('', $stream->read(0));
        $this->assertSame(0, $stream->tell());
    }

    /**
     * Test write inserts at current position and updates content length.
     */
    #[Test]
    public function writeInsertsAtCurrentPositionAndUpdatesLength(): void
    {
        $stream = new StringStream('hello world');

        $stream->seek(5);
        $written = $stream->write('_there');
        $this->assertSame(6, $written);
        $this->assertSame('hello_there', (string)$stream);
        $this->assertSame(11, $stream->getSize());
    }

    /**
     * Test write appends at end of content.
     */
    #[Test]
    public function writeAppendsAtEnd(): void
    {
        $stream = new StringStream('hello');

        $stream->seek(0, SEEK_END);
        $stream->write(' world');
        $this->assertSame('hello world', (string)$stream);
        $this->assertSame(11, $stream->getSize());
    }

    /**
     * Test write to empty stream.
     */
    #[Test]
    public function writeToEmptyStream(): void
    {
        $stream = new StringStream();

        $stream->write('data');
        $this->assertSame('data', (string)$stream);
        $this->assertSame(4, $stream->getSize());
    }

    /**
     * Test seek with SEEK_SET.
     */
    #[Test]
    public function seekWithSeekSet(): void
    {
        $stream = new StringStream('hello world');

        $stream->seek(5, SEEK_SET);
        $this->assertSame(5, $stream->tell());
    }

    /**
     * Test seek with SEEK_CUR.
     */
    #[Test]
    public function seekWithSeekCur(): void
    {
        $stream = new StringStream('hello world');

        $stream->seek(3, SEEK_SET);
        $stream->seek(2, SEEK_CUR);
        $this->assertSame(5, $stream->tell());
    }

    /**
     * Test seek with SEEK_END.
     */
    #[Test]
    public function seekWithSeekEnd(): void
    {
        $stream = new StringStream('hello world');

        $stream->seek(-5, SEEK_END);
        $this->assertSame(6, $stream->tell());
    }

    /**
     * Test seek to beginning of stream with SEEK_END.
     */
    #[Test]
    public function seekToBeginningWithSeekEnd(): void
    {
        $stream = new StringStream('hello');

        $stream->seek(-5, SEEK_END);
        $this->assertSame(0, $stream->tell());
    }

    /**
     * Test seek to negative position throws RuntimeException.
     */
    #[Test]
    public function seekToNegativePositionThrowsRuntimeException(): void
    {
        $stream = new StringStream('hello');

        $this->expectException(RuntimeException::class);
        $stream->seek(-1, SEEK_SET);
    }

    /**
     * Test seek with SEEK_CUR to negative position throws RuntimeException.
     */
    #[Test]
    public function seekCurToNegativePositionThrowsRuntimeException(): void
    {
        $stream = new StringStream('hello');

        $this->expectException(RuntimeException::class);
        $stream->seek(-1, SEEK_CUR);
    }

    /**
     * Test close detaches stream and subsequent read throws RuntimeException.
     */
    #[Test]
    public function closeDetachesAndReadThrowsRuntimeException(): void
    {
        $stream = new StringStream('hello');
        $stream->close();

        $this->expectException(RuntimeException::class);
        $stream->read(1);
    }

    /**
     * Test close detaches stream and subsequent write throws RuntimeException.
     */
    #[Test]
    public function closeDetachesAndWriteThrowsRuntimeException(): void
    {
        $stream = new StringStream('hello');
        $stream->close();

        $this->expectException(RuntimeException::class);
        $stream->write('data');
    }

    /**
     * Test close detaches stream and subsequent seek throws RuntimeException.
     */
    #[Test]
    public function closeDetachesAndSeekThrowsRuntimeException(): void
    {
        $stream = new StringStream('hello');
        $stream->close();

        $this->expectException(RuntimeException::class);
        $stream->seek(0);
    }

    /**
     * Test close detaches stream and subsequent tell throws RuntimeException.
     */
    #[Test]
    public function closeDetachesAndTellThrowsRuntimeException(): void
    {
        $stream = new StringStream('hello');
        $stream->close();

        $this->expectException(RuntimeException::class);
        $stream->tell();
    }

    /**
     * Test __toString returns full content when attached.
     */
    #[Test]
    public function toStringReturnsFullContentWhenAttached(): void
    {
        $stream = new StringStream('hello world');

        $this->assertSame('hello world', (string)$stream);
    }

    /**
     * Test __toString returns empty string when detached.
     */
    #[Test]
    public function toStringReturnsEmptyStringWhenDetached(): void
    {
        $stream = new StringStream('hello world');
        $stream->close();

        $this->assertSame('', (string)$stream);
    }

    /**
     * Test getSize returns content length when attached.
     */
    #[Test]
    public function getSizeReturnsContentLengthWhenAttached(): void
    {
        $stream = new StringStream('hello');

        $this->assertSame(5, $stream->getSize());
    }

    /**
     * Test getSize returns null when detached.
     */
    #[Test]
    public function getSizeReturnsNullWhenDetached(): void
    {
        $stream = new StringStream('hello');
        $stream->close();

        $this->assertNull($stream->getSize());
    }

    /**
     * Test eof returns false when position is before content length.
     */
    #[Test]
    public function eofReturnsFalseBeforeEnd(): void
    {
        $stream = new StringStream('hello');

        $this->assertFalse($stream->eof());
    }

    /**
     * Test eof returns true when position equals content length.
     */
    #[Test]
    public function eofReturnsTrueAtEnd(): void
    {
        $stream = new StringStream('hello');

        $stream->read(5);
        $this->assertTrue($stream->eof());
    }

    /**
     * Test eof returns true when position exceeds content length.
     */
    #[Test]
    public function eofReturnsTrueWhenPositionExceedsLength(): void
    {
        $stream = new StringStream('hi');

        $stream->seek(10, SEEK_SET);
        $this->assertTrue($stream->eof());
    }

    /**
     * Test eof returns true when stream is detached.
     */
    #[Test]
    public function eofReturnsTrueWhenDetached(): void
    {
        $stream = new StringStream('hello');
        $stream->close();

        $this->assertTrue($stream->eof());
    }

    /**
     * Test read, seek, write, and getSize with a longer text payload.
     */
    #[Test]
    public function operationsWithLongerText(): void
    {
        $content = str_repeat('The quick brown fox jumps over the lazy dog. ', 30);
        $length = strlen($content);
        $stream = new StringStream($content);

        $this->assertSame($length, $stream->getSize());
        $this->assertSame($content, (string)$stream);

        // Read first 100 bytes
        $chunk = $stream->read(100);
        $this->assertSame(substr($content, 0, 100), $chunk);
        $this->assertSame(100, $stream->tell());

        // Seek to middle and read
        $midpoint = intdiv($length, 2);
        $stream->seek($midpoint, SEEK_SET);
        $midChunk = $stream->read(45);
        $this->assertSame(substr($content, $midpoint, 45), $midChunk);

        // Write at a position and verify splice
        $stream->seek(0, SEEK_SET);
        $replacement = 'REPLACED_SEGMENT';
        $stream->write($replacement);
        $expected = $replacement . substr($content, strlen($replacement));
        $this->assertSame($expected, (string)$stream);
        $this->assertSame(strlen($expected), $stream->getSize());

        // Seek to end and append
        $stream->seek(0, SEEK_END);
        $stream->write('_TAIL');
        $this->assertSame($expected . '_TAIL', (string)$stream);
    }

    /**
     * Property test: writing content then rewinding and reading produces the original content.
     *
     * Feature: unit-tests-and-code-quality, Property 1: StringStream write/read round-trip
     *
     * Validates: Requirements 1.10
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    #[Test]
    public function writeReadRoundTripProperty(): void
    {
        $property = Property::forAll(
            [Generator::strings()],
            function (string $content): bool {
                $stream = new StringStream();
                $stream->write($content);
                $stream->rewind();
                $result = $stream->read(\strlen($content));
                return $result === $content;
            },
        );

        $this->assertThat($property, PropertyConstraint::check(100));
    }

    /**
     * Property test: reading from a StringStream returns the correct substring and advances position.
     *
     * Feature: unit-tests-and-code-quality, Property 2: StringStream read returns correct substring
     *
     * Validates: Requirements 1.2
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    #[Test]
    public function readReturnsCorrectSubstringProperty(): void
    {
        $property = Property::forAll(
            [Generator::asciiStrings()->notEmpty(), Generator::choose(1, 256)],
            function (string $content, int $readLength): bool {
                $stream = new StringStream($content);
                $clampedLength = min($readLength, \strlen($content));
                $result = $stream->read($clampedLength);

                return $result === substr($content, 0, $clampedLength)
                    && $stream->tell() === \strlen($result);
            },
        );

        $this->assertThat($property, PropertyConstraint::check(100));
    }
}
