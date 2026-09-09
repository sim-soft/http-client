<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests\Traits;

use CurlHandle;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
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

    /**
     * Test that CURLOPT_TIMEOUT given to withOptions() reaches the timeout property.
     *
     * applyTransferOptions() writes the property into the option array on every
     * request, so an entry left in the array alone would be overwritten and the
     * caller's timeout silently ignored.
     *
     * @return void
     */
    #[Test]
    public function withOptionsRoutesTimeoutToTheTimeoutProperty(): void
    {
        $this->host->withOptions([CURLOPT_TIMEOUT => 7]);

        $this->assertSame(7, $this->getProperty('timeout'));
    }

    /**
     * Test that CURLOPT_CONNECTTIMEOUT given to withOptions() reaches its property.
     *
     * @return void
     */
    #[Test]
    public function withOptionsRoutesConnectTimeoutToItsProperty(): void
    {
        $this->host->withOptions([CURLOPT_CONNECTTIMEOUT => 3]);

        $this->assertSame(3, $this->getProperty('connectionTimeout'));
    }

    /**
     * Test that a timeout set through withOptions() survives applyTransferOptions().
     *
     * @return void
     */
    #[Test]
    public function withOptionsTimeoutSurvivesTransferOptions(): void
    {
        $handle = curl_init();
        $this->assertInstanceOf(CurlHandle::class, $handle);

        $this->host->withOptions([CURLOPT_TIMEOUT => 7, CURLOPT_CONNECTTIMEOUT => 3]);

        $apply = new ReflectionMethod($this->host, 'applyTransferOptions');
        $apply->invoke($this->host, $handle);

        /** @var array<int, mixed> $options */
        $options = $this->getProperty('options');

        $this->assertSame(7, $options[CURLOPT_TIMEOUT]);
        $this->assertSame(3, $options[CURLOPT_CONNECTTIMEOUT]);
    }

    /**
     * Test that the last timeout call wins regardless of which API set it.
     *
     * @return void
     */
    #[Test]
    public function lastTimeoutCallWinsAcrossBothApis(): void
    {
        $this->host->timeout(30)->withOptions([CURLOPT_TIMEOUT => 7]);
        $this->assertSame(7, $this->getProperty('timeout'));

        $this->host->withOptions([CURLOPT_TIMEOUT => 7])->timeout(12);
        $this->assertSame(12, $this->getProperty('timeout'));
    }

    /**
     * Test that withOptions() rejects a negative timeout the same way timeout() does.
     *
     * @return void
     */
    #[Test]
    public function withOptionsRejectsNegativeTimeout(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->host->withOptions([CURLOPT_TIMEOUT => -1]);
    }

    /**
     * Test that withOptions() rejects a negative connection timeout.
     *
     * @return void
     */
    #[Test]
    public function withOptionsRejectsNegativeConnectTimeout(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->host->withOptions([CURLOPT_CONNECTTIMEOUT => -1]);
    }

    /**
     * Test that withOptions() rejects a non-integer timeout naming the option.
     *
     * @return void
     */
    #[Test]
    public function withOptionsRejectsNonIntegerTimeout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CURLOPT_TIMEOUT must be an integer, string given');

        $this->host->withOptions([CURLOPT_TIMEOUT => '7']);
    }

    /**
     * Test that withOptions() rejects a non-integer connection timeout.
     *
     * @return void
     */
    #[Test]
    public function withOptionsRejectsNonIntegerConnectTimeout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CURLOPT_CONNECTTIMEOUT must be an integer, float given');

        $this->host->withOptions([CURLOPT_CONNECTTIMEOUT => 3.5]);
    }

    /**
     * Test that a zero timeout — cURL's "no limit" — passes through withOptions().
     *
     * @return void
     */
    #[Test]
    public function withOptionsAcceptsZeroTimeout(): void
    {
        $this->host->withOptions([CURLOPT_TIMEOUT => 0]);

        $this->assertSame(0, $this->getProperty('timeout'));
    }

    /**
     * Test that other options in the same call are still stored normally.
     *
     * @return void
     */
    #[Test]
    public function withOptionsStoresOtherOptionsAlongsideTimeouts(): void
    {
        $this->host->withOptions([
            CURLOPT_TIMEOUT => 7,
            CURLOPT_MAXREDIRS => 4,
        ]);

        /** @var array<int, mixed> $options */
        $options = $this->getProperty('options');

        $this->assertSame(4, $options[CURLOPT_MAXREDIRS]);
        $this->assertSame(7, $this->getProperty('timeout'));
    }
}
