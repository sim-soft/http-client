<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests\Traits;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use ReflectionProperty;
use Simsoft\HttpClient\HttpClient;
use Simsoft\HttpClient\Response;

/**
 * RetryTraitTest class
 *
 * Tests for the RetryTrait: retry validation, shouldRetry conditions,
 * custom callbacks, and non-seekable stream handling.
 * Uses HttpClient::make() as the test subject since shouldRetry()
 * depends on $this->postFields and $this->method from HttpClient.
 */
class RetryTraitTest extends TestCase
{
    /** @var HttpClient The HTTP client instance under test. */
    private HttpClient $client;

    /**
     * Set up a fresh HttpClient instance for each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->client = HttpClient::make();
    }

    /**
     * Test that retry() throws InvalidArgumentException when times is less than 1.
     *
     * @return void
     */
    #[Test]
    public function retryThrowsInvalidArgumentExceptionWhenTimesLessThanOne(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The number of retries must be at least 1.');

        $this->client->retry(0);
    }

    /**
     * Test that shouldRetry() returns true for a retryable network error (timeout).
     *
     * @return void
     */
    #[Test]
    public function shouldRetryReturnsTrueForTimeoutNetworkError(): void
    {
        $response = new Response(false, '', '', null, CURLE_OPERATION_TIMEOUTED);

        $this->assertTrue($this->client->shouldRetry($response));
    }

    /**
     * Test that shouldRetry() returns true for a retryable network error (connection refused).
     *
     * @return void
     */
    #[Test]
    public function shouldRetryReturnsTrueForConnectionRefusedError(): void
    {
        $response = new Response(false, '', '', null, CURLE_COULDNT_CONNECT);

        $this->assertTrue($this->client->shouldRetry($response));
    }

    /**
     * Test that shouldRetry() returns true for server errors on idempotent GET method.
     *
     * @return void
     */
    #[Test]
    public function shouldRetryReturnsTrueForServerErrorOnGetMethod(): void
    {
        $this->setProtectedProperty($this->client, 'method', 'GET');
        $response = new Response(['http_code' => 500]);

        $this->assertTrue($this->client->shouldRetry($response));
    }

    /**
     * Test that shouldRetry() returns true for server errors on idempotent HEAD method.
     *
     * @return void
     */
    #[Test]
    public function shouldRetryReturnsTrueForServerErrorOnHeadMethod(): void
    {
        $this->setProtectedProperty($this->client, 'method', 'HEAD');
        $response = new Response(['http_code' => 502]);

        $this->assertTrue($this->client->shouldRetry($response));
    }

    /**
     * Test that shouldRetry() returns true for server errors on idempotent OPTIONS method.
     *
     * @return void
     */
    #[Test]
    public function shouldRetryReturnsTrueForServerErrorOnOptionsMethod(): void
    {
        $this->setProtectedProperty($this->client, 'method', 'OPTIONS');
        $response = new Response(['http_code' => 503]);

        $this->assertTrue($this->client->shouldRetry($response));
    }

    /**
     * Test that shouldRetry() returns false for server errors on non-idempotent POST method.
     *
     * @return void
     */
    #[Test]
    public function shouldRetryReturnsFalseForServerErrorOnPostMethod(): void
    {
        $this->setProtectedProperty($this->client, 'method', 'POST');
        $response = new Response(['http_code' => 500]);

        $this->assertFalse($this->client->shouldRetry($response));
    }

    /**
     * Test that shouldRetry() returns false for server errors on non-idempotent PUT method.
     *
     * @return void
     */
    #[Test]
    public function shouldRetryReturnsFalseForServerErrorOnPutMethod(): void
    {
        $this->setProtectedProperty($this->client, 'method', 'PUT');
        $response = new Response(['http_code' => 500]);

        $this->assertFalse($this->client->shouldRetry($response));
    }

    /**
     * Test that retryWhen() custom callback is used by shouldRetry().
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    #[Test]
    public function retryWhenCustomCallbackIsUsedByShouldRetry(): void
    {
        $this->client->retryWhen(function (Response $response, string $method, int $attempt): bool {
            return $response->getStatusCode() === 429 && $attempt <= 3;
        });

        $retryableResponse = new Response(['http_code' => 429]);
        $this->assertTrue($this->client->shouldRetry($retryableResponse, 1));
        $this->assertTrue($this->client->shouldRetry($retryableResponse, 3));
        $this->assertFalse($this->client->shouldRetry($retryableResponse, 4));

        $nonRetryableResponse = new Response(['http_code' => 200]);
        $this->assertFalse($this->client->shouldRetry($nonRetryableResponse, 1));
    }

    /**
     * Test that shouldRetry() returns false when postFields is a non-seekable stream.
     *
     * @return void
     */
    #[Test]
    public function shouldRetryReturnsFalseForNonSeekableStream(): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('isSeekable')->willReturn(false);

        $this->setProtectedProperty($this->client, 'postFields', $stream);

        $response = new Response(false, '', '', null, CURLE_OPERATION_TIMEOUTED);

        $this->assertFalse($this->client->shouldRetry($response));
    }

    /**
     * Set a protected property on an object via reflection.
     *
     * @param object $object The target object.
     * @param string $property The property name.
     * @param mixed $value The value to set.
     * @return void
     */
    private function setProtectedProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new ReflectionProperty($object, $property);
        $reflection->setValue($object, $value);
    }
}
