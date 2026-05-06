<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests\Traits;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Simsoft\HttpClient\Traits\CurlOptionsTrait;

/**
 * CurlOptionsHost class
 *
 * Concrete host class using the CurlOptionsTrait for testing.
 */
class CurlOptionsHost
{
    use CurlOptionsTrait;
}

/**
 * CurlOptionsTraitTest class
 *
 * Tests for the CurlOptionsTrait: timeout, connection timeout, buffer size,
 * SSL verification, arbitrary options, and verbose mode.
 */
class CurlOptionsTraitTest extends TestCase
{
    /** @var CurlOptionsHost Host object using the CurlOptionsTrait. */
    private CurlOptionsHost $host;

    /**
     * Set up a fresh host instance using the trait.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->host = new CurlOptionsHost();
    }

    /**
     * Read a protected property value via reflection.
     *
     * @param string $property The property name to read.
     * @return mixed
     */
    private function getProperty(string $property): mixed
    {
        $reflection = new ReflectionProperty(CurlOptionsHost::class, $property);

        return $reflection->getValue($this->host);
    }

    /**
     * Test that timeout() stores the correct value.
     *
     * @return void
     */
    #[Test]
    public function timeoutStoresCorrectValue(): void
    {
        $this->host->timeout(60);

        $this->assertSame(60, $this->getProperty('timeout'));
    }

    /**
     * Test that timeout() throws InvalidArgumentException for negative values.
     *
     * @return void
     */
    #[Test]
    public function timeoutThrowsExceptionForNegativeValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Timeout must be >= 0');

        $this->host->timeout(-1);
    }

    /**
     * Test that timeout() with zero value is accepted.
     *
     * @return void
     */
    #[Test]
    public function timeoutAcceptsZeroValue(): void
    {
        $this->host->timeout(0);

        $this->assertSame(0, $this->getProperty('timeout'));
    }

    /**
     * Test that connectionTimeout() stores the correct value.
     *
     * @return void
     */
    #[Test]
    public function connectionTimeoutStoresCorrectValue(): void
    {
        $this->host->connectionTimeout(10);

        $this->assertSame(10, $this->getProperty('connectionTimeout'));
    }

    /**
     * Test that connectionTimeout() throws InvalidArgumentException for negative values.
     *
     * @return void
     */
    #[Test]
    public function connectionTimeoutThrowsExceptionForNegativeValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Connection timeout must be >= 0');

        $this->host->connectionTimeout(-5);
    }

    /**
     * Test that connectionTimeout() with zero value is accepted.
     *
     * @return void
     */
    #[Test]
    public function connectionTimeoutAcceptsZeroValue(): void
    {
        $this->host->connectionTimeout(0);

        $this->assertSame(0, $this->getProperty('connectionTimeout'));
    }

    /**
     * Test that withBufferSize() stores the buffer size value.
     *
     * @return void
     */
    #[Test]
    public function withBufferSizeStoresValue(): void
    {
        $this->host->withBufferSize(131072);

        $this->assertSame(131072, $this->getProperty('bufferSize'));
    }

    /**
     * Test that withoutVerifying() disables SSL peer and host verification.
     *
     * @return void
     */
    #[Test]
    public function withoutVerifyingDisablesSslVerification(): void
    {
        $this->host->withoutVerifying();

        /** @var array<int, mixed> $options */
        $options = $this->getProperty('options');

        $this->assertFalse($options[CURLOPT_SSL_VERIFYPEER]);
        $this->assertSame(0, $options[CURLOPT_SSL_VERIFYHOST]);
    }

    /**
     * Test that withOptions() merges arbitrary cURL options into the options array.
     *
     * @return void
     */
    #[Test]
    public function withOptionsMergesArbitraryOptions(): void
    {
        $this->host->withOptions([
            CURLOPT_USERAGENT => 'TestAgent/1.0',
            CURLOPT_MAXREDIRS => 10,
        ]);

        /** @var array<int, mixed> $options */
        $options = $this->getProperty('options');

        $this->assertSame('TestAgent/1.0', $options[CURLOPT_USERAGENT]);
        $this->assertSame(10, $options[CURLOPT_MAXREDIRS]);
        $this->assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
    }

    /**
     * Test that verbose() enables CURLOPT_VERBOSE in the options array.
     *
     * @return void
     */
    #[Test]
    public function verboseEnablesCurloptVerbose(): void
    {
        $this->host->verbose();

        /** @var array<int, mixed> $options */
        $options = $this->getProperty('options');

        $this->assertTrue($options[CURLOPT_VERBOSE]);
    }
}
