<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests\Traits;

use Closure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\HttpClient\HttpClient;
use Simsoft\HttpClient\Response;

/**
 * RequestInspectionTraitTest class
 *
 * Tests for getRequestHeaders() and getQueryParams(), read directly and from
 * inside the middleware pipeline.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
class RequestInspectionTraitTest extends TestCase
{
    /**
     * Build a client whose core handler returns a canned response without cURL.
     *
     * @return HttpClient
     */
    private function offlineClient(): HttpClient
    {
        return new class extends HttpClient {
            /**
             * Return a 200 response without performing a transfer.
             *
             * @return Closure
             */
            protected function getCoreHandler(): Closure
            {
                return fn(): Response => new Response(
                    curlInfo: ['http_code' => 200],
                    body: '{}',
                    rawHeaders: "HTTP/1.1 200 OK\r\n",
                );
            }
        };
    }

    /**
     * Test the README "Inspecting the Request" example returns the documented values.
     *
     * @return void
     */
    #[Test]
    public function documentedExampleReturnsDocumentedValues(): void
    {
        $seen = [];

        $this->offlineClient()
            ->withBaseUrl('https://api.example.com')
            ->withBearerToken('secret-token')
            ->withHeader('X-Trace-Id', 'abc123')
            ->withQuery(['page' => 2])
            ->withJson(['name' => 'Alice'])
            ->withMiddleware(function (HttpClient $request, Closure $next) use (&$seen): Response {
                $seen = [
                    'method' => $request->getMethod(),
                    'endpoint' => $request->getEndpoint(),
                    'query' => $request->getQueryParams(),
                    'headers' => $request->getRequestHeaders(),
                    'body' => $request->getRequestBody(),
                ];
                return $next();
            })
            ->post('/users');

        $this->assertSame([
            'method' => 'POST',
            'endpoint' => 'https://api.example.com/users',
            'query' => ['page' => 2],
            'headers' => [
                'x-trace-id' => ['abc123'],
                'authorization' => ['Bearer secret-token'],
                'content-type' => ['application/json'],
            ],
            'body' => '{"name":"Alice"}',
        ], $seen);
    }

    /**
     * Test a fresh client has no headers.
     *
     * @return void
     */
    #[Test]
    public function getRequestHeadersIsEmptyByDefault(): void
    {
        $this->assertSame([], HttpClient::make()->getRequestHeaders());
    }

    /**
     * Test header names are lower-cased and multiple values are kept.
     *
     * @return void
     */
    #[Test]
    public function getRequestHeadersLowerCasesNamesAndKeepsValues(): void
    {
        $client = HttpClient::make()
            ->withHeader('Accept', ['application/json', 'text/plain'])
            ->withHeaders(['X-App-Version' => '2.0']);

        $this->assertSame([
            'accept' => ['application/json', 'text/plain'],
            'x-app-version' => ['2.0'],
        ], $client->getRequestHeaders());
    }

    /**
     * Test the bearer token is included.
     *
     * @return void
     */
    #[Test]
    public function getRequestHeadersIncludesBearerToken(): void
    {
        $client = HttpClient::make()->withBearerToken('tok');

        $this->assertSame(['authorization' => ['Bearer tok']], $client->getRequestHeaders());
    }

    /**
     * Test withoutBearerToken() removes the token from the result.
     *
     * @return void
     */
    #[Test]
    public function getRequestHeadersOmitsRemovedBearerToken(): void
    {
        $client = HttpClient::make()->withBearerToken('tok')->withoutBearerToken();

        $this->assertSame([], $client->getRequestHeaders());
    }

    /**
     * Test a per-request header overrides a connection-scoped one of the same name.
     *
     * @return void
     */
    #[Test]
    public function getRequestHeadersPrefersPerRequestOverBearerToken(): void
    {
        $client = HttpClient::make()
            ->withBearerToken('tok')
            ->withHeader('Authorization', 'Basic abc');

        $this->assertSame(['authorization' => ['Basic abc']], $client->getRequestHeaders());
    }

    /**
     * Test the content type shorthand is resolved to a MIME type.
     *
     * @return void
     */
    #[Test]
    public function getRequestHeadersResolvesContentTypeShorthand(): void
    {
        $this->assertSame(
            ['content-type' => ['application/x-www-form-urlencoded']],
            HttpClient::make()->asForm()->getRequestHeaders()
        );
        $this->assertSame(
            ['content-type' => ['multipart/form-data']],
            HttpClient::make()->asMultipart()->getRequestHeaders()
        );
        $this->assertSame(
            ['content-type' => ['text/plain']],
            HttpClient::make()->asRaw()->getRequestHeaders()
        );
    }

    /**
     * Test a custom content type from withRaw() is returned as given.
     *
     * @return void
     */
    #[Test]
    public function getRequestHeadersReturnsCustomContentType(): void
    {
        $client = HttpClient::make()->withRaw('<a/>', 'application/xml');

        $this->assertSame(['content-type' => ['application/xml']], $client->getRequestHeaders());
    }

    /**
     * Test an explicit Content-Type header wins over the body's content type.
     *
     * @return void
     */
    #[Test]
    public function getRequestHeadersPrefersExplicitContentTypeHeader(): void
    {
        $client = HttpClient::make()
            ->withJson(['a' => 1])
            ->withHeader('Content-Type', 'application/vnd.api+json');

        $this->assertSame(['content-type' => ['application/vnd.api+json']], $client->getRequestHeaders());
    }

    /**
     * Test Content-Type is dropped when files are attached.
     *
     * @return void
     */
    #[Test]
    public function getRequestHeadersOmitsContentTypeWithAttachments(): void
    {
        $client = HttpClient::make()
            ->withHeader('Content-Type', 'multipart/form-data')
            ->withHeader('X-Upload', 'yes')
            ->attach('file', 'raw file contents', 'note.txt');

        $this->assertSame(['x-upload' => ['yes']], $client->getRequestHeaders());
    }

    /**
     * Test send-time headers are not part of the result.
     *
     * @return void
     */
    #[Test]
    public function getRequestHeadersExcludesSendTimeHeaders(): void
    {
        $headers = HttpClient::make()->withJson(['a' => 1])->getRequestHeaders();

        $this->assertArrayNotHasKey('x-request-id', $headers);
        $this->assertArrayNotHasKey('user-agent', $headers);
        $this->assertArrayNotHasKey('content-length', $headers);
    }

    /**
     * Test reading headers does not change the headers that are sent.
     *
     * @return void
     */
    #[Test]
    public function getRequestHeadersDoesNotAlterRequestState(): void
    {
        $client = HttpClient::make()->withHeader('X-A', '1');
        $client->getRequestHeaders();
        $client->withHeader('X-B', '2');

        $this->assertSame(['x-a' => ['1'], 'x-b' => ['2']], $client->getRequestHeaders());
    }

    /**
     * Test a fresh client has no query params.
     *
     * @return void
     */
    #[Test]
    public function getQueryParamsIsEmptyByDefault(): void
    {
        $this->assertSame([], HttpClient::make()->getQueryParams());
    }

    /**
     * Test repeated withQuery() calls are merged, later keys winning.
     *
     * @return void
     */
    #[Test]
    public function getQueryParamsMergesWithQueryCalls(): void
    {
        $client = HttpClient::make()
            ->withQuery(['page' => 1, 'sort' => 'name'])
            ->withQuery(['page' => 2, 'tags' => ['a', 'b']]);

        $this->assertSame(['page' => 2, 'sort' => 'name', 'tags' => ['a', 'b']], $client->getQueryParams());
    }

    /**
     * Test get($uri, $data) exposes $data as query params to middleware.
     *
     * @return void
     */
    #[Test]
    public function getQueryParamsSeesGetDataInMiddleware(): void
    {
        $seen = null;

        $this->offlineClient()
            ->withMiddleware(function (HttpClient $request, Closure $next) use (&$seen): Response {
                $seen = $request->getQueryParams();
                return $next();
            })
            ->get('https://api.example.com/users', ['status' => 'active']);

        $this->assertSame(['status' => 'active'], $seen);
    }

    /**
     * Test params written into the resource URL are not reported.
     *
     * @return void
     */
    #[Test]
    public function getQueryParamsExcludesParamsInResourceUrl(): void
    {
        $client = HttpClient::make()->resource('/users?status=active');

        $this->assertSame([], $client->getQueryParams());
        $this->assertSame('/users?status=active', $client->getEndpoint());
    }

    /**
     * Test middleware sees a header added by an outer middleware.
     *
     * @return void
     */
    #[Test]
    public function innerMiddlewareSeesHeaderAddedByOuterMiddleware(): void
    {
        $seen = null;

        $this->offlineClient()
            ->withMiddleware(function (HttpClient $request, Closure $next): Response {
                $request->withHeader('X-Signed', 'yes');
                return $next();
            })
            ->withMiddleware(function (HttpClient $request, Closure $next) use (&$seen): Response {
                $seen = $request->getRequestHeaders();
                return $next();
            })
            ->get('https://api.example.com/users');

        $this->assertSame(['x-signed' => ['yes']], $seen);
    }

    /**
     * Test per-request state is cleared after the request; the bearer token stays.
     *
     * @return void
     */
    #[Test]
    public function stateIsResetAfterRequestExceptBearerToken(): void
    {
        $client = $this->offlineClient()
            ->withBearerToken('tok')
            ->withHeader('X-Trace-Id', 'abc')
            ->withQuery(['page' => 2])
            ->withJson(['a' => 1]);

        $client->post('https://api.example.com/users');

        $this->assertSame(['authorization' => ['Bearer tok']], $client->getRequestHeaders());
        $this->assertSame([], $client->getQueryParams());
        $this->assertNull($client->getRequestBody());
    }
}
