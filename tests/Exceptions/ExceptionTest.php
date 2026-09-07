<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests\Exceptions;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Simsoft\HttpClient\Exceptions\ClientException;
use Simsoft\HttpClient\Exceptions\NetworkException;
use Simsoft\HttpClient\Exceptions\RequestException;

/**
 * ExceptionTest class
 *
 * Tests for PSR-18 exception interface compliance and context preservation
 * for ClientException, NetworkException, and RequestException.
 */
class ExceptionTest extends TestCase
{
    /**
     * Test that ClientException implements Psr\Http\Client\ClientExceptionInterface.
     *
     * @return void
     */
    #[Test]
    public function clientExceptionImplementsClientExceptionInterface(): void
    {
        $exception = new ClientException('client error');

        $this->assertInstanceOf(ClientExceptionInterface::class, $exception);
        $this->assertInstanceOf(RuntimeException::class, $exception);
    }

    /**
     * Test that NetworkException implements NetworkExceptionInterface and getRequest() returns RequestInterface.
     *
     * @return void
     */
    #[Test]
    public function networkExceptionImplementsNetworkExceptionInterfaceAndReturnsRequest(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $exception = new NetworkException($request, 'network error');

        $this->assertInstanceOf(NetworkExceptionInterface::class, $exception);
        $this->assertInstanceOf(ClientExceptionInterface::class, $exception);
        $this->assertSame($request, $exception->getRequest());
    }

    /**
     * Test that RequestException implements RequestExceptionInterface and getRequest() returns RequestInterface.
     *
     * @return void
     */
    #[Test]
    public function requestExceptionImplementsRequestExceptionInterfaceAndReturnsRequest(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $exception = new RequestException($request, 'request error');

        $this->assertInstanceOf(RequestExceptionInterface::class, $exception);
        $this->assertInstanceOf(ClientExceptionInterface::class, $exception);
        $this->assertSame($request, $exception->getRequest());
    }

    /**
     * Test that message, code, and previous exception are preserved for ClientException.
     *
     * @return void
     */
    #[Test]
    public function clientExceptionPreservesMessageCodeAndPreviousException(): void
    {
        $previous = new RuntimeException('root cause');
        $exception = new ClientException('client error', 42, $previous);

        $this->assertSame('client error', $exception->getMessage());
        $this->assertSame(42, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }

    /**
     * Test that message, code, and previous exception are preserved for NetworkException.
     *
     * @return void
     */
    #[Test]
    public function networkExceptionPreservesMessageCodeAndPreviousException(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $previous = new RuntimeException('timeout');
        $exception = new NetworkException($request, 'network failure', 28, $previous);

        $this->assertSame('network failure', $exception->getMessage());
        $this->assertSame(28, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }

    /**
     * Test that message, code, and previous exception are preserved for RequestException.
     *
     * @return void
     */
    #[Test]
    public function requestExceptionPreservesMessageCodeAndPreviousException(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $previous = new RuntimeException('bad request');
        $exception = new RequestException($request, 'invalid request', 400, $previous);

        $this->assertSame('invalid request', $exception->getMessage());
        $this->assertSame(400, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }
}
