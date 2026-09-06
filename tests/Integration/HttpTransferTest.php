<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use Simsoft\HttpClient\HttpClient;

/**
 * End-to-end transfers against a real HTTP server.
 *
 * Every assertion here depends on bytes crossing a socket and a response being
 * parsed back. The unit suite verifies that the right cURL options are set;
 * these verify that setting them produces the intended result.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class HttpTransferTest extends IntegrationTestCase
{
    /**
     * A GET returns a parsed body and a 200.
     *
     * @return void
     */
    #[Test]
    public function getReturnsAParsedBody(): void
    {
        $response = HttpClient::make()->get($this->serverUrl('/echo'));

        $this->assertTrue($response->successful());
        $this->assertSame(200, $response->getStatusCode());

        $payload = $response->json();
        $this->assertSame('GET', $payload['method']);
    }

    /**
     * Query parameters reach the server.
     *
     * @return void
     */
    #[Test]
    public function queryParametersArriveIntact(): void
    {
        $response = HttpClient::make()
            ->withQuery(['page' => '2', 'search' => 'a b&c'])
            ->get($this->serverUrl('/echo'));

        $payload = $response->json();

        $this->assertSame('2', $payload['query']['page']);
        $this->assertSame('a b&c', $payload['query']['search']);
    }

    /**
     * A JSON body is sent with the documented content type.
     *
     * @return void
     */
    #[Test]
    public function jsonBodyIsSentAndTyped(): void
    {
        $response = HttpClient::make()
            ->withJson(['name' => 'Ada', 'roles' => ['admin', 'dev']])
            ->post($this->serverUrl('/echo'));

        $payload = $response->json();

        $this->assertSame('POST', $payload['method']);
        $this->assertStringContainsString('application/json', $payload['headers']['Content-Type']);
        $this->assertSame(['name' => 'Ada', 'roles' => ['admin', 'dev']], json_decode($payload['body'], true));
    }

    /**
     * A form body is url-encoded and parsed by the server as fields.
     *
     * @return void
     */
    #[Test]
    public function formBodyIsUrlEncoded(): void
    {
        $response = HttpClient::make()
            ->withForm(['user' => 'ada', 'note' => 'hello world'])
            ->post($this->serverUrl('/upload'));

        $payload = $response->json();

        $this->assertSame('ada', $payload['post']['user']);
        $this->assertSame('hello world', $payload['post']['note']);
    }

    /**
     * Custom headers reach the server with their values intact.
     *
     * @return void
     */
    #[Test]
    public function customHeadersArriveIntact(): void
    {
        $response = HttpClient::make()
            ->withHeader('X-Correlation-Id', 'abc-123')
            ->withHeaders(['X-Tenant' => 'acme'])
            ->get($this->serverUrl('/echo'));

        $headers = $response->json()['headers'];

        $this->assertSame('abc-123', $headers['X-Correlation-Id']);
        $this->assertSame('acme', $headers['X-Tenant']);
    }

    /**
     * A bearer token is sent as an Authorization header.
     *
     * @return void
     */
    #[Test]
    public function bearerTokenReachesTheServer(): void
    {
        $response = HttpClient::make()
            ->withBearerToken('secret-token')
            ->get($this->serverUrl('/auth'));

        $this->assertSame('Bearer secret-token', $response->json()['authorization']);
    }

    /**
     * A bearer token survives across sequential requests on one client.
     *
     * The token is connection-scoped, and a regression here was the subject of
     * an earlier fix; only a real transfer proves the header is still present
     * on the second request.
     *
     * @return void
     */
    #[Test]
    public function bearerTokenSurvivesASecondRequest(): void
    {
        $client = HttpClient::make()->withBearerToken('persistent-token');

        $first = $client->get($this->serverUrl('/auth'));
        $second = $client->get($this->serverUrl('/auth'));

        $this->assertSame('Bearer persistent-token', $first->json()['authorization']);
        $this->assertSame('Bearer persistent-token', $second->json()['authorization']);
    }

    /**
     * PUT, PATCH and DELETE arrive as themselves rather than as POST.
     *
     * @return void
     */
    #[Test]
    public function verbsAreNotDowngradedToPost(): void
    {
        $client = HttpClient::make()->withBaseUrl($this->serverBase());

        $this->assertSame('PUT', $client->withJson(['a' => 1])->put('/echo')->json()['method']);
        $this->assertSame('PATCH', $client->withJson(['a' => 1])->patch('/echo')->json()['method']);
        $this->assertSame('DELETE', $client->delete('/echo')->json()['method']);
    }

    /**
     * Response predicates reflect the status actually returned.
     *
     * @return void
     */
    #[Test]
    public function statusPredicatesMatchTheWire(): void
    {
        $client = HttpClient::make()->withBaseUrl($this->serverBase());

        $this->assertTrue($client->get('/status/404')->notFound());
        $this->assertTrue($client->get('/status/401')->unauthorized());
        $this->assertTrue($client->get('/status/422')->unprocessableEntity());
        $this->assertTrue($client->get('/status/500')->internalServerError());
        $this->assertTrue($client->get('/status/500')->isServerError());
        $this->assertTrue($client->get('/status/404')->isClientError());
    }

    /**
     * A 204 yields an empty body and reports itself as such.
     *
     * @return void
     */
    #[Test]
    public function noContentHasAnEmptyBody(): void
    {
        $response = HttpClient::make()->get($this->serverUrl('/no-content'));

        $this->assertTrue($response->noContent());
        $this->assertSame('', (string)$response->getBody());
    }

    /**
     * Redirects are followed to the final destination by default.
     *
     * @return void
     */
    #[Test]
    public function redirectsAreFollowed(): void
    {
        $response = HttpClient::make()->get($this->serverUrl('/redirect/3'));

        $this->assertTrue($response->successful());
        $this->assertSame('GET', $response->json()['method']);
    }

    /**
     * Response headers are parsed from the final response, not an earlier hop.
     *
     * A redirect chain produces several header blocks on one transfer; only the
     * last belongs to the response the caller receives.
     *
     * @return void
     */
    #[Test]
    public function headersComeFromTheFinalResponse(): void
    {
        $response = HttpClient::make()->get($this->serverUrl('/redirect/2'));

        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('', $response->getHeaderLine('Location'));
    }

    /**
     * A timeout shorter than the server's delay produces a network error.
     *
     * @return void
     */
    #[Test]
    public function aTimeoutIsEnforced(): void
    {
        $response = HttpClient::make()
            ->timeout(1)
            ->get($this->serverUrl('/slow?ms=3000'));

        $this->assertTrue($response->isNetworkError());
        $this->assertTrue($response->failed());
    }

    /**
     * A connection to a closed port reports a network error rather than throwing.
     *
     * @return void
     */
    #[Test]
    public function aRefusedConnectionIsANetworkError(): void
    {
        $response = HttpClient::make()
            ->connectionTimeout(2)
            ->get('http://127.0.0.1:1/nothing');

        $this->assertTrue($response->isNetworkError());
        $this->assertNotSame('', (string)$response->getMessage());
    }

    /**
     * A retried request eventually succeeds and reports the later attempt.
     *
     * @return void
     */
    #[Test]
    public function retriesRecoverFromATransientFailure(): void
    {
        $token = 'retry-' . bin2hex(random_bytes(6));

        $response = HttpClient::make()
            ->retry(4)
            ->get($this->serverUrl('/flaky?failures=2&token=' . $token));

        $this->assertTrue($response->successful());
        $this->assertSame(3, $response->json()['attempt']);
    }

    /**
     * A body sent as a raw string arrives byte-for-byte.
     *
     * @return void
     */
    #[Test]
    public function rawBodyArrivesUnaltered(): void
    {
        $body = '{"not":"parsed"} & <raw> ünïcode';

        $response = HttpClient::make()
            ->withBody($body, 'text/plain')
            ->post($this->serverUrl('/echo'));

        $this->assertSame($body, $response->json()['body']);
    }
}
