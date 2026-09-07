<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests;

use Closure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Simsoft\HttpClient\HttpClient;
use Simsoft\HttpClient\Response;

/**
 * Testable HttpClient subclass that overrides getCoreHandler()
 * to return a simple Response without making real HTTP calls.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class TestableHttpClient extends HttpClient
{
    /** @var string[] Execution log tracking middleware order. */
    public array $executionLog = [];

    /**
     * Override getCoreHandler to return a simple Response without cURL.
     *
     * @return Closure
     */
    protected function getCoreHandler(): Closure
    {
        return function (): Response {
            $this->executionLog[] = 'core';
            return new Response(
                curlInfo: ['http_code' => 200],
                body: '{"message":"ok"}',
                rawHeaders: "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n",
            );
        };
    }
}

/**
 * MiddlewareTest class.
 *
 * Tests the HttpClient middleware pipeline: execution order,
 * response modification, short-circuit behavior, and error handling.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class MiddlewareTest extends TestCase
{
    /**
     * Test middleware closures execute in registration order.
     *
     * @return void
     */
    #[Test]
    public function middlewareExecutesInRegistrationOrder(): void
    {
        $client = new TestableHttpClient();

        $client->withMiddleware(function (HttpClient $httpClient, Closure $next): Response {
            /** @var TestableHttpClient $httpClient */
            $httpClient->executionLog[] = 'first';
            return $next();
        });

        $client->withMiddleware(function (HttpClient $httpClient, Closure $next): Response {
            /** @var TestableHttpClient $httpClient */
            $httpClient->executionLog[] = 'second';
            return $next();
        });

        $client->withMiddleware(function (HttpClient $httpClient, Closure $next): Response {
            /** @var TestableHttpClient $httpClient */
            $httpClient->executionLog[] = 'third';
            return $next();
        });

        $client->resource('/test')->request();

        $this->assertSame(['first', 'second', 'third', 'core'], $client->executionLog);
    }

    /**
     * Test middleware can modify the response before returning.
     *
     * @return void
     */
    #[Test]
    public function middlewareCanModifyResponse(): void
    {
        $client = new TestableHttpClient();

        $client->withMiddleware(function (HttpClient $httpClient, Closure $next): Response {
            $next();

            return new Response(
                curlInfo: ['http_code' => 200],
                body: '{"modified":true}',
                rawHeaders: "HTTP/1.1 200 OK\r\nX-Modified: yes\r\n",
            );
        });

        $response = $client->resource('/test')->request();

        $this->assertSame('{"modified":true}', (string)$response->getBody());
    }

    /**
     * Test middleware can short-circuit the pipeline by not calling next.
     *
     * @return void
     */
    #[Test]
    public function middlewareCanShortCircuit(): void
    {
        $client = new TestableHttpClient();

        $client->withMiddleware(function (HttpClient $httpClient, Closure $next): Response {
            /** @var TestableHttpClient $httpClient */
            $httpClient->executionLog[] = 'first';
            return $next();
        });

        $client->withMiddleware(function (HttpClient $httpClient, Closure $next): Response {
            /** @var TestableHttpClient $httpClient */
            $httpClient->executionLog[] = 'short-circuit';
            // Do NOT call $next() — short-circuit the pipeline
            return new Response(
                curlInfo: ['http_code' => 403],
                body: '{"error":"forbidden"}',
                rawHeaders: "HTTP/1.1 403 Forbidden\r\n",
            );
        });

        $client->withMiddleware(function (HttpClient $httpClient, Closure $next): Response {
            /** @var TestableHttpClient $httpClient */
            $httpClient->executionLog[] = 'should-not-run';
            return $next();
        });

        $response = $client->resource('/test')->request();

        $this->assertSame(['first', 'short-circuit'], $client->executionLog);
        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * Test middleware returning a non-Response value throws RuntimeException.
     *
     * @return void
     */
    #[Test]
    public function middlewareReturningNonResponseThrowsRuntimeException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Middleware Closure must return an instance of');

        $client = new TestableHttpClient();

        $client->withMiddleware(function (HttpClient $httpClient, Closure $next): mixed {
            return 'not a response';
        });

        $client->resource('/test')->request();
    }
}
