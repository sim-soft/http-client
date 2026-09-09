<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests\Traits;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Simsoft\HttpClient\Traits\SinkTrait;

/**
 * SinkHost class
 *
 * Concrete host class using the SinkTrait for testing.
 */
class SinkHost
{
    use SinkTrait;

    /** @var array<int, mixed> cURL options storage. */
    protected array $options = [];

    /**
     * Get the current options array.
     *
     * @return array<int, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }
}

/**
 * SinkTraitTest class
 *
 * Tests for the SinkTrait: sink() file-based mode, sinkStream() stream-based mode,
 * and destination validation.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
class SinkTraitTest extends TestCase
{
    /** @var SinkHost Host object using the SinkTrait. */
    private SinkHost $host;

    /**
     * Set up a fresh host instance using the trait.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->host = new SinkHost();
    }

    /**
     * Read a protected property value via reflection.
     *
     * @param string $property The property name to read.
     * @return mixed
     */
    private function getProperty(string $property): mixed
    {
        $reflection = new ReflectionProperty(SinkHost::class, $property);

        return $reflection->getValue($this->host);
    }

    /**
     * Test that sink() sets CURLOPT_FILE for file-based download mode.
     *
     * @return void
     */
    #[Test]
    public function sinkSetsFileOption(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'sink_test_');
        $this->assertNotFalse($tmpFile);

        try {
            $result = $this->host->sink($tmpFile);

            $options = $this->host->getOptions();

            $this->assertSame($this->host, $result);
            $this->assertFalse($options[CURLOPT_RETURNTRANSFER]);
            $this->assertArrayHasKey(CURLOPT_FILE, $options);
            $this->assertIsResource($options[CURLOPT_FILE]);
            $this->assertArrayNotHasKey(CURLOPT_WRITEFUNCTION, $options);
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Test that sinkStream() sets CURLOPT_WRITEFUNCTION for stream-based download mode.
     *
     * @return void
     */
    #[Test]
    public function sinkStreamSetsWriteFunctionOption(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'sink_test_');
        $this->assertNotFalse($tmpFile);

        try {
            $result = $this->host->sinkStream($tmpFile);

            $options = $this->host->getOptions();

            $this->assertSame($this->host, $result);
            $this->assertFalse($options[CURLOPT_RETURNTRANSFER]);
            $this->assertArrayHasKey(CURLOPT_WRITEFUNCTION, $options);
            $this->assertIsCallable($options[CURLOPT_WRITEFUNCTION]);
            $this->assertArrayNotHasKey(CURLOPT_FILE, $options);
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Test that sink() accepts a resource destination.
     *
     * @return void
     */
    #[Test]
    public function sinkAcceptsResourceDestination(): void
    {
        $resource = fopen('php://memory', 'w+');
        $this->assertNotFalse($resource);

        try {
            $this->host->sink($resource);

            $options = $this->host->getOptions();

            $this->assertArrayHasKey(CURLOPT_FILE, $options);
            $this->assertSame($resource, $options[CURLOPT_FILE]);
        } finally {
            fclose($resource);
        }
    }

    /**
     * Test that sinkStream() accepts a resource destination.
     *
     * @return void
     */
    #[Test]
    public function sinkStreamAcceptsResourceDestination(): void
    {
        $resource = fopen('php://memory', 'w+');
        $this->assertNotFalse($resource);

        try {
            $this->host->sinkStream($resource);

            $options = $this->host->getOptions();

            $this->assertArrayHasKey(CURLOPT_WRITEFUNCTION, $options);
            $this->assertIsCallable($options[CURLOPT_WRITEFUNCTION]);
            $this->assertArrayNotHasKey(CURLOPT_FILE, $options);
        } finally {
            fclose($resource);
        }
    }

    /**
     * Test that sinkStream() produces identical behavior to old sink($dest, true).
     *
     * The old API used sink($dest, true) for stream-based mode. The new sinkStream()
     * must set the same options: CURLOPT_RETURNTRANSFER=false, CURLOPT_WRITEFUNCTION
     * as a callable, and no CURLOPT_FILE.
     *
     * @return void
     */
    #[Test]
    public function sinkStreamBehaviorMatchesOldStreamMode(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'sink_test_');
        $this->assertNotFalse($tmpFile);

        try {
            $this->host->sinkStream($tmpFile);

            $options = $this->host->getOptions();

            // Stream mode sets CURLOPT_WRITEFUNCTION (not CURLOPT_FILE)
            $this->assertFalse($options[CURLOPT_RETURNTRANSFER]);
            $this->assertArrayHasKey(CURLOPT_WRITEFUNCTION, $options);
            $this->assertArrayNotHasKey(CURLOPT_FILE, $options);

            // The write function should be a callable closure
            $this->assertIsCallable($options[CURLOPT_WRITEFUNCTION]);
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Test that sink() throws InvalidArgumentException for invalid destination.
     *
     * @return void
     */
    #[Test]
    public function sinkThrowsForInvalidDestination(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sink must be file path or resource');

        $this->host->sink(12345);
    }

    /**
     * Test that sinkStream() throws InvalidArgumentException for invalid destination.
     *
     * @return void
     */
    #[Test]
    public function sinkStreamThrowsForInvalidDestination(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sink must be file path or resource');

        $this->host->sinkStream(12345);
    }

    /**
     * Test that the write function writes data correctly to the sink resource.
     *
     * @return void
     */
    #[Test]
    public function writeCallbackWritesDataCorrectly(): void
    {
        $resource = fopen('php://memory', 'w+');
        $this->assertNotFalse($resource);

        try {
            $this->host->sinkStream($resource);

            $options = $this->host->getOptions();
            $writeFunction = $options[CURLOPT_WRITEFUNCTION];

            $curlHandle = curl_init();
            $bytesWritten = $writeFunction($curlHandle, 'hello world');

            $this->assertSame(11, $bytesWritten);

            rewind($resource);
            $this->assertSame('hello world', stream_get_contents($resource));
        } finally {
            fclose($resource);
        }
    }

    /**
     * Test that sink() sets sinkOwned flag when given a file path.
     *
     * @return void
     */
    #[Test]
    public function sinkSetsOwnedFlagForFilePath(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'sink_test_');
        $this->assertNotFalse($tmpFile);

        try {
            $this->host->sink($tmpFile);

            $this->assertTrue($this->getProperty('sinkOwned'));
            $this->assertSame($tmpFile, $this->getProperty('sinkPath'));
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Test that sink() does not set sinkOwned when given a resource.
     *
     * @return void
     */
    #[Test]
    public function sinkDoesNotSetOwnedFlagForResource(): void
    {
        $resource = fopen('php://memory', 'w+');
        $this->assertNotFalse($resource);

        try {
            $this->host->sink($resource);

            $this->assertFalse($this->getProperty('sinkOwned'));
            $this->assertNull($this->getProperty('sinkPath'));
        } finally {
            fclose($resource);
        }
    }

    /**
     * Test that replacing a path sink with a resource clears the ownership flag.
     *
     * The flag was set when the path was opened and never cleared, so the
     * caller's resource inherited it and the host would close a stream it never
     * opened.
     *
     * @return void
     */
    #[Test]
    public function sinkClearsOwnershipWhenReplacedByResource(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'sink_own');
        $this->assertNotFalse($tmpFile);

        $resource = fopen('php://memory', 'w+');
        $this->assertNotFalse($resource);

        try {
            $this->host->sink($tmpFile);
            $this->assertTrue($this->getProperty('sinkOwned'));

            $this->host->sink($resource);

            $this->assertFalse($this->getProperty('sinkOwned'));
            $this->assertSame($resource, $this->getProperty('sink'));
        } finally {
            fclose($resource);
            @unlink($tmpFile);
        }
    }

    /**
     * Test that a caller-supplied resource survives replacing a path sink.
     *
     * This is the consequence of the stale flag: releasing the sink would have
     * closed a stream belonging to the caller.
     *
     * @return void
     */
    #[Test]
    public function sinkLeavesCallerResourceOpenAfterPathSink(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'sink_own');
        $this->assertNotFalse($tmpFile);

        $resource = fopen('php://memory', 'w+');
        $this->assertNotFalse($resource);

        try {
            $this->host->sink($tmpFile);
            $this->host->sink($resource);

            $release = new ReflectionMethod($this->host, 'releaseOwnedSink');
            $release->invoke($this->host);

            $this->assertTrue(is_resource($resource));
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
            @unlink($tmpFile);
        }
    }

    /**
     * Test that replacing one path sink with another closes the first stream.
     *
     * The host opened both, so leaving the first open would leak it.
     *
     * @return void
     */
    #[Test]
    public function sinkClosesThePreviousOwnedStream(): void
    {
        $firstFile = tempnam(sys_get_temp_dir(), 'sink_one');
        $secondFile = tempnam(sys_get_temp_dir(), 'sink_two');
        $this->assertNotFalse($firstFile);
        $this->assertNotFalse($secondFile);

        try {
            $this->host->sink($firstFile);
            $firstHandle = $this->getProperty('sink');

            $this->host->sink($secondFile);

            $this->assertFalse(is_resource($firstHandle));
            $this->assertTrue(is_resource($this->getProperty('sink')));
            $this->assertTrue($this->getProperty('sinkOwned'));
            $this->assertSame($secondFile, $this->getProperty('sinkPath'));
        } finally {
            @unlink($firstFile);
            @unlink($secondFile);
        }
    }

    /**
     * Test that sinkStream() applies the same ownership handling as sink().
     *
     * @return void
     */
    #[Test]
    public function sinkStreamClearsOwnershipWhenReplacedByResource(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'sink_own');
        $this->assertNotFalse($tmpFile);

        $resource = fopen('php://memory', 'w+');
        $this->assertNotFalse($resource);

        try {
            $this->host->sinkStream($tmpFile);
            $this->assertTrue($this->getProperty('sinkOwned'));

            $this->host->sinkStream($resource);

            $this->assertFalse($this->getProperty('sinkOwned'));
            $this->assertTrue(is_resource($resource));
        } finally {
            fclose($resource);
            @unlink($tmpFile);
        }
    }

    /**
     * Test that a failed open leaves the existing sink untouched.
     *
     * prepareSinkDestination() opens the new destination before releasing the
     * old one, so a client that cannot open its next sink keeps a usable one.
     *
     * @return void
     */
    #[Test]
    public function failedOpenLeavesTheExistingSinkIntact(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'sink_own');
        $this->assertNotFalse($tmpFile);

        try {
            $this->host->sink($tmpFile);
            $existingHandle = $this->getProperty('sink');

            try {
                @$this->host->sink(__DIR__ . '/no_such_directory/file.txt');
                $this->fail('Expected InvalidArgumentException for an unopenable path.');
            } catch (InvalidArgumentException) {
                // Expected: the destination could not be opened.
            }

            $this->assertSame($existingHandle, $this->getProperty('sink'));
            $this->assertTrue(is_resource($existingHandle));
            $this->assertTrue($this->getProperty('sinkOwned'));
        } finally {
            @unlink($tmpFile);
        }
    }
}
