<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use ReflectionProperty;
use Simsoft\HttpClient\Clients\Responses\SimpleOAuth2Response;
use Simsoft\HttpClient\HttpClient;
use Simsoft\HttpClient\Response;

/**
 * HttpClientTest class.
 *
 * Tests the HttpClient fluent API, URL composition, content types,
 * headers, middleware, retry logic, and response class validation.
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessivePublicCount)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class HttpClientTest extends TestCase
{
    /**
     * Helper to read a protected/private property via reflection.
     *
     * @param object $object The object to inspect.
     * @param string $propertyName The property name.
     * @return mixed
     */
    private function getProperty(object $object, string $propertyName): mixed
    {
        $reflection = new ReflectionProperty($object, $propertyName);

        return $reflection->getValue($object);
    }

    /**
     * Test make() returns a new HttpClient instance.
     *
     * @return void
     */
    #[Test]
    public function makeReturnsNewHttpClientInstance(): void
    {
        $client = HttpClient::make();

        $this->assertInstanceOf(HttpClient::class, $client);
    }

    /**
     * Test withBaseUrl() and resource() compose endpoint via getEndpoint().
     *
     * @return void
     */
    #[Test]
    public function withBaseUrlAndResourceComposeEndpoint(): void
    {
        $client = HttpClient::make()
            ->withBaseUrl('https://api.example.com')
            ->resource('/users');

        $this->assertSame('https://api.example.com/users', $client->getEndpoint());
    }

    /**
     * Test withMethod() stores GET correctly.
     *
     * @return void
     */
    #[Test]
    public function withMethodStoresGet(): void
    {
        $client = HttpClient::make()->withMethod('GET');

        $this->assertSame('GET', $this->getProperty($client, 'method'));
    }

    /**
     * Test withMethod() stores POST correctly.
     *
     * @return void
     */
    #[Test]
    public function withMethodStoresPost(): void
    {
        $client = HttpClient::make()->withMethod('POST');

        $this->assertSame('POST', $this->getProperty($client, 'method'));
    }

    /**
     * Test withMethod() stores PUT correctly.
     *
     * @return void
     */
    #[Test]
    public function withMethodStoresPut(): void
    {
        $client = HttpClient::make()->withMethod('PUT');

        $this->assertSame('PUT', $this->getProperty($client, 'method'));
    }

    /**
     * Test withMethod() stores PATCH correctly.
     *
     * @return void
     */
    #[Test]
    public function withMethodStoresPatch(): void
    {
        $client = HttpClient::make()->withMethod('PATCH');

        $this->assertSame('PATCH', $this->getProperty($client, 'method'));
    }

    /**
     * Test withMethod() stores DELETE correctly.
     *
     * @return void
     */
    #[Test]
    public function withMethodStoresDelete(): void
    {
        $client = HttpClient::make()->withMethod('DELETE');

        $this->assertSame('DELETE', $this->getProperty($client, 'method'));
    }

    /**
     * Test withMethod() uppercases the method string.
     *
     * @return void
     */
    #[Test]
    public function withMethodUppercasesInput(): void
    {
        $client = HttpClient::make()->withMethod('post');

        $this->assertSame('POST', $this->getProperty($client, 'method'));
    }

    /**
     * Test withHeaders() accumulates headers correctly.
     *
     * @return void
     */
    #[Test]
    public function withHeadersAccumulatesHeaders(): void
    {
        $client = HttpClient::make()
            ->withHeaders([
                'Accept' => 'application/json',
                'X-Custom' => 'value1',
            ]);

        /** @var array<string, array<int, string>> $headers */
        $headers = $this->getProperty($client, 'headers');

        $this->assertSame(['application/json'], $headers['Accept']);
        $this->assertSame(['value1'], $headers['X-Custom']);
    }

    /**
     * Test withHeader() merges values without duplicates.
     *
     * @return void
     */
    #[Test]
    public function withHeaderMergesWithoutDuplicates(): void
    {
        $client = HttpClient::make()
            ->withHeader('Accept', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withHeader('Accept', 'text/html');

        /** @var array<string, array<int, string>> $headers */
        $headers = $this->getProperty($client, 'headers');

        $this->assertSame(['application/json', 'text/html'], $headers['Accept']);
    }

    /**
     * Test withBearerToken() sets authorization header with Bearer prefix.
     *
     * @return void
     */
    #[Test]
    public function withBearerTokenSetsAuthorizationHeader(): void
    {
        $client = HttpClient::make()->withBearerToken('my-token-123');

        /** @var array<string, array<int, string>> $headers */
        $headers = $this->getProperty($client, 'headers');

        $this->assertSame(['Bearer my-token-123'], $headers['authorization']);
    }

    /**
     * Test withQuery() merges query parameters.
     *
     * @return void
     */
    #[Test]
    public function withQueryMergesParameters(): void
    {
        $client = HttpClient::make()
            ->withQuery(['page' => 1])
            ->withQuery(['limit' => 10]);

        /** @var array<string|int, mixed> $queryParams */
        $queryParams = $this->getProperty($client, 'queryParams');

        $this->assertSame(1, $queryParams['page']);
        $this->assertSame(10, $queryParams['limit']);
    }

    /**
     * Test withJson() encodes data and sets application/json content type.
     *
     * @return void
     */
    #[Test]
    public function withJsonEncodesDataAndSetsContentType(): void
    {
        $client = HttpClient::make()->withJson(['name' => 'John']);

        /** @var string|null $contentType */
        $contentType = $this->getProperty($client, 'contentType');
        /** @var string $postFields */
        $postFields = $this->getProperty($client, 'postFields');

        $this->assertSame('application/json', $contentType);
        $this->assertSame('{"name":"John"}', $postFields);
    }

    /**
     * Test withJson() with non-encodable data throws InvalidArgumentException.
     *
     * @return void
     */
    #[Test]
    public function withJsonThrowsOnNonEncodableData(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $resource = fopen('php://memory', 'r');
        if ($resource === false) {
            $this->fail('Failed to open php://memory');
        }

        try {
            /** @var array<string, mixed> $data */
            $data = ['bad' => $resource];
            HttpClient::make()->withJson($data);
        } finally {
            fclose($resource);
        }
    }

    /**
     * Test withForm() sets application/x-www-form-urlencoded and URL-encodes data.
     *
     * @return void
     */
    #[Test]
    public function withFormSetsFormContentTypeAndEncodesData(): void
    {
        $client = HttpClient::make()->withForm(['username' => 'john', 'pass' => 'secret']);

        /** @var string|null $contentType */
        $contentType = $this->getProperty($client, 'contentType');
        /** @var string $postFields */
        $postFields = $this->getProperty($client, 'postFields');

        $this->assertSame('application/x-www-form-urlencoded', $contentType);
        $this->assertSame('username=john&pass=secret', $postFields);
    }

    /**
     * Test withGraphQL() encodes query and variables as JSON.
     *
     * @return void
     */
    #[Test]
    public function withGraphQlEncodesQueryAndVariables(): void
    {
        $query = '{ users { id name } }';
        $variables = ['limit' => 10];

        $client = HttpClient::make()->withGraphQL($query, $variables);

        /** @var string|null $contentType */
        $contentType = $this->getProperty($client, 'contentType');
        /** @var string $postFields */
        $postFields = $this->getProperty($client, 'postFields');

        $this->assertSame('application/json', $contentType);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($postFields, true);
        $this->assertSame($query, $decoded['query']);
        $this->assertSame($variables, $decoded['variables']);
    }

    /**
     * Test asJson() sets content type to TYPE_JSON constant.
     *
     * @return void
     */
    #[Test]
    public function asJsonSetsCorrectContentType(): void
    {
        $client = HttpClient::make()->asJson();

        $this->assertSame('json', $this->getProperty($client, 'contentType'));
    }

    /**
     * Test asForm() sets content type to TYPE_FORM constant.
     *
     * @return void
     */
    #[Test]
    public function asFormSetsCorrectContentType(): void
    {
        $client = HttpClient::make()->asForm();

        $this->assertSame('form', $this->getProperty($client, 'contentType'));
    }

    /**
     * Test asMultipart() sets content type to TYPE_MULTIPART constant.
     *
     * @return void
     */
    #[Test]
    public function asMultipartSetsCorrectContentType(): void
    {
        $client = HttpClient::make()->asMultipart();

        $this->assertSame('multipart', $this->getProperty($client, 'contentType'));
    }

    /**
     * Test asRaw() sets content type to TYPE_RAW constant.
     *
     * @return void
     */
    #[Test]
    public function asRawSetsCorrectContentType(): void
    {
        $client = HttpClient::make()->asRaw();

        $this->assertSame('raw', $this->getProperty($client, 'contentType'));
    }

    /**
     * Test withMiddleware() registers closures in the middleware array.
     *
     * @return void
     */
    #[Test]
    public function withMiddlewareRegistersClosures(): void
    {
        $middlewareOne = function (HttpClient $client, Closure $next): Response {
            return $next($client);
        };
        $middlewareTwo = function (HttpClient $client, Closure $next): Response {
            return $next($client);
        };

        $client = HttpClient::make()
            ->withMiddleware($middlewareOne)
            ->withMiddleware($middlewareTwo);

        /** @var array<array-key, Closure> $middleware */
        $middleware = $this->getProperty($client, 'middleware');

        $this->assertCount(2, $middleware);
    }

    /**
     * Test withMiddleware() with named middleware prevents duplicates.
     *
     * @return void
     */
    #[Test]
    public function withMiddlewareNamedPreventsDuplicates(): void
    {
        $middlewareOne = function (HttpClient $client, Closure $next): Response {
            return $next($client);
        };
        $middlewareTwo = function (HttpClient $client, Closure $next): Response {
            return $next($client);
        };

        $client = HttpClient::make()
            ->withMiddleware($middlewareOne, 'auth')
            ->withMiddleware($middlewareTwo, 'auth');

        /** @var array<array-key, Closure> $middleware */
        $middleware = $this->getProperty($client, 'middleware');

        $this->assertCount(1, $middleware);
        $this->assertArrayHasKey('auth', $middleware);
    }

    /**
     * Test withResponseClass() accepts valid Response subclass.
     *
     * @return void
     */
    #[Test]
    public function withResponseClassAcceptsValidSubclass(): void
    {
        $client = HttpClient::make()->withResponseClass(SimpleOAuth2Response::class);

        /** @var string $responseClass */
        $responseClass = $this->getProperty($client, 'responseClass');

        $this->assertSame(SimpleOAuth2Response::class, $responseClass);
    }

    /**
     * Test withResponseClass() accepts the base Response class itself.
     *
     * @return void
     */
    #[Test]
    public function withResponseClassAcceptsBaseResponseClass(): void
    {
        $client = HttpClient::make()->withResponseClass(Response::class);

        /** @var string $responseClass */
        $responseClass = $this->getProperty($client, 'responseClass');

        $this->assertSame(Response::class, $responseClass);
    }

    /**
     * Test withResponseClass() rejects invalid class that does not extend Response.
     *
     * @return void
     */
    #[Test]
    public function withResponseClassRejectsInvalidClass(): void
    {
        $this->expectException(InvalidArgumentException::class);

        HttpClient::make()->withResponseClass(\stdClass::class);
    }

    /**
     * Test withResponseClass() with non-existent class throws InvalidArgumentException.
     *
     * @return void
     */
    #[Test]
    public function withResponseClassThrowsOnNonExistentClass(): void
    {
        $this->expectException(InvalidArgumentException::class);

        HttpClient::make()->withResponseClass('NonExistent\\FakeClass');
    }

    /**
     * Test retry() stores count and delay.
     *
     * @return void
     */
    #[Test]
    public function retryStoresCountAndDelay(): void
    {
        $client = HttpClient::make()->retry(3, 500);

        $this->assertSame(3, $this->getProperty($client, 'retry'));
        $this->assertSame(500, $this->getProperty($client, 'retryAfter'));
    }

    /**
     * Test retry() throws InvalidArgumentException for times less than 1.
     *
     * @return void
     */
    #[Test]
    public function retryThrowsForTimesLessThanOne(): void
    {
        $this->expectException(InvalidArgumentException::class);

        HttpClient::make()->retry(0);
    }

    /**
     * Test shouldRetry() returns false for non-seekable stream bodies.
     *
     * @return void
     */
    #[Test]
    public function shouldRetryReturnsFalseForNonSeekableStream(): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('isSeekable')->willReturn(false);

        $client = HttpClient::make()->retry(3);

        $postFieldsRef = new ReflectionProperty($client, 'postFields');
        $postFieldsRef->setValue($client, $stream);

        $response = new Response(['http_code' => 500], '', '', null, 0, '');

        $this->assertFalse($client->shouldRetry($response));
    }

    /**
     * Test getMethod() returns the current HTTP method.
     *
     * @return void
     */
    #[Test]
    public function getMethodReturnsCurrentHttpMethod(): void
    {
        $client = HttpClient::make();

        $this->assertSame('GET', $client->getMethod());

        $client->withMethod('POST');
        $this->assertSame('POST', $client->getMethod());

        $client->withMethod('put');
        $this->assertSame('PUT', $client->getMethod());

        $client->withMethod('DELETE');
        $this->assertSame('DELETE', $client->getMethod());
    }
}
