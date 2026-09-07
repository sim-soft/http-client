<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests\Testing;

use InvalidArgumentException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\HttpClient\Response;
use Simsoft\HttpClient\Testing\FakeHttpClient;
use Simsoft\HttpClient\Testing\UnexpectedRequestException;

/**
 * FakeHttpClientTest class.
 *
 * Unit tests for FakeHttpClient covering static factory creation, URL pattern
 * matching, response configuration, request recording, assertion methods,
 * and response sequencing behavior.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.TooManyMethods")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class FakeHttpClientTest extends TestCase
{
    // ── Static factory tests ─────────────────────────────────────────

    /**
     * Test fake() static factory creates a FakeHttpClient instance.
     */
    #[Test]
    public function fakeStaticFactoryCreatesInstance(): void
    {
        $client = FakeHttpClient::fake();

        $this->assertInstanceOf(FakeHttpClient::class, $client);
    }

    /**
     * Test fake() static factory with responses creates configured instance.
     */
    #[Test]
    public function fakeStaticFactoryWithResponsesCreatesConfiguredInstance(): void
    {
        $client = FakeHttpClient::fake([
            'https://example.com/users' => 200,
        ]);

        $this->assertInstanceOf(FakeHttpClient::class, $client);

        $response = $client->get('https://example.com/users');
        $this->assertSame(200, $response->getStatusCode());
    }

    // ── Exact URL matching tests ─────────────────────────────────────

    /**
     * Test exact URL matching returns configured response.
     */
    #[Test]
    public function exactUrlMatchingReturnsConfiguredResponse(): void
    {
        $client = FakeHttpClient::fake([
            'https://api.example.com/users' => 200,
        ]);

        $response = $client->get('https://api.example.com/users');

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Test unmatched URL throws UnexpectedRequestException.
     */
    #[Test]
    public function unmatchedUrlThrowsUnexpectedRequestException(): void
    {
        $client = FakeHttpClient::fake([
            'https://api.example.com/users' => 200,
        ]);

        $this->expectException(UnexpectedRequestException::class);

        $client->get('https://api.example.com/posts');
    }

    // ── Wildcard pattern matching tests ──────────────────────────────

    /**
     * Test wildcard pattern matching with star segment.
     */
    #[Test]
    public function wildcardPatternMatchingWithStarSegment(): void
    {
        $client = FakeHttpClient::fake([
            'https://api.example.com/users/*' => 200,
        ]);

        $response = $client->get('https://api.example.com/users/123');

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Test wildcard pattern matches nested paths.
     */
    #[Test]
    public function wildcardPatternMatchesNestedPaths(): void
    {
        $client = FakeHttpClient::fake([
            'https://api.example.com/*/posts' => 201,
        ]);

        $response = $client->get('https://api.example.com/users/posts');

        $this->assertSame(201, $response->getStatusCode());
    }

    // ── Method + URL matching tests ──────────────────────────────────

    /**
     * Test method plus URL matching returns response for correct method.
     */
    #[Test]
    public function methodPlusUrlMatchingReturnsResponseForCorrectMethod(): void
    {
        $client = FakeHttpClient::fake([
            'GET https://api.example.com/users' => 200,
            'POST https://api.example.com/users' => 201,
        ]);

        $getResponse = $client->get('https://api.example.com/users');
        $postResponse = $client->post('https://api.example.com/users');

        $this->assertSame(200, $getResponse->getStatusCode());
        $this->assertSame(201, $postResponse->getStatusCode());
    }

    /**
     * Test method plus URL matching rejects wrong method.
     */
    #[Test]
    public function methodPlusUrlMatchingRejectsWrongMethod(): void
    {
        $client = FakeHttpClient::fake([
            'POST https://api.example.com/users' => 201,
        ]);

        $this->expectException(UnexpectedRequestException::class);

        $client->get('https://api.example.com/users');
    }

    // ── Callable matcher tests ───────────────────────────────────────

    /**
     * Test callable matcher receives method and URL.
     */
    #[Test]
    public function callableMatcherReceivesMethodAndUrl(): void
    {
        $client = FakeHttpClient::fake();
        $client->addFake(
            function (string $method, string $url): bool {
                return $method === 'GET' && str_contains($url, '/users');
            },
            200
        );

        $response = $client->get('https://api.example.com/users');

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Test callable matcher that returns false does not match.
     */
    #[Test]
    public function callableMatcherThatReturnsFalseDoesNotMatch(): void
    {
        $client = FakeHttpClient::fake();
        $client->addFake(
            function (string $method, string $url): bool {
                return $method !== '' && str_contains($url, '/admin');
            },
            403
        );

        $this->expectException(UnexpectedRequestException::class);

        $client->get('https://api.example.com/users');
    }

    // ── assertNothingSent tests ──────────────────────────────────────

    /**
     * Test assertNothingSent passes on fresh instance.
     */
    #[Test]
    public function assertNothingSentPassesOnFreshInstance(): void
    {
        $client = FakeHttpClient::fake();

        // assertNothingSent() throws AssertionFailedError rather than going
        // through PHPUnit's counter, so reaching the next line is the pass
        // condition. Declared, rather than padded with assertTrue(true), which
        // asserts nothing about the code under test.
        $this->expectNotToPerformAssertions();

        $client->assertNothingSent();
    }

    /**
     * Test assertNothingSent fails after request is made.
     */
    #[Test]
    public function assertNothingSentFailsAfterRequest(): void
    {
        $client = FakeHttpClient::fake([
            'https://api.example.com/users' => 200,
        ]);

        $client->get('https://api.example.com/users');

        $this->expectException(AssertionFailedError::class);

        $client->assertNothingSent();
    }

    // ── PHPUnit assertion exception tests ────────────────────────────

    /**
     * Test assertSent throws AssertionFailedError when request was not made.
     */
    #[Test]
    public function assertSentThrowsWhenRequestWasNotMade(): void
    {
        $client = FakeHttpClient::fake();

        $this->expectException(AssertionFailedError::class);

        $client->assertSent('GET', 'https://api.example.com/users');
    }

    /**
     * Test assertNotSent throws AssertionFailedError when request was made.
     */
    #[Test]
    public function assertNotSentThrowsWhenRequestWasMade(): void
    {
        $client = FakeHttpClient::fake([
            'https://api.example.com/users' => 200,
        ]);

        $client->get('https://api.example.com/users');

        $this->expectException(AssertionFailedError::class);

        $client->assertNotSent('GET', 'https://api.example.com/users');
    }

    /**
     * Test assertSentCount throws AssertionFailedError with wrong count.
     */
    #[Test]
    public function assertSentCountThrowsWithWrongCount(): void
    {
        $client = FakeHttpClient::fake([
            'https://api.example.com/users' => 200,
        ]);

        $client->get('https://api.example.com/users');

        $this->expectException(AssertionFailedError::class);

        $client->assertSentCount(5);
    }

    /**
     * Test assertSent passes when request was made.
     */
    #[Test]
    public function assertSentPassesWhenRequestWasMade(): void
    {
        $client = FakeHttpClient::fake([
            'https://api.example.com/users' => 200,
        ]);

        $client->get('https://api.example.com/users');

        // assertSent() throws on failure; reaching the end is the pass.
        $this->expectNotToPerformAssertions();

        $client->assertSent('GET', 'https://api.example.com/users');
    }

    /**
     * Test assertSentCount passes with correct count.
     */
    #[Test]
    public function assertSentCountPassesWithCorrectCount(): void
    {
        $client = FakeHttpClient::fake([
            'https://api.example.com/users' => 200,
        ]);

        $client->get('https://api.example.com/users');
        $client->get('https://api.example.com/users');

        // assertSentCount() throws on failure; reaching the end is the pass.
        $this->expectNotToPerformAssertions();

        $client->assertSentCount(2);
    }

    // ── Response from array config tests ─────────────────────────────

    /**
     * Test response from array config with status, headers, and body.
     */
    #[Test]
    public function responseFromArrayConfigWithStatusHeadersAndBody(): void
    {
        $client = FakeHttpClient::fake([
            'https://api.example.com/users' => [
                'status' => 201,
                'headers' => ['X-Custom' => 'value'],
                'body' => '{"id": 1}',
            ],
        ]);

        $response = $client->post('https://api.example.com/users');

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('{"id": 1}', $response->body());
        $this->assertTrue($response->hasHeader('X-Custom'));
    }

    /**
     * Test response from array config uses defaults for missing keys.
     */
    #[Test]
    public function responseFromArrayConfigUsesDefaultsForMissingKeys(): void
    {
        $client = FakeHttpClient::fake([
            'https://api.example.com/users' => [
                'body' => 'hello',
            ],
        ]);

        $response = $client->get('https://api.example.com/users');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('hello', $response->body());
    }

    // ── Response from integer tests ──────────────────────────────────

    /**
     * Test response from integer creates response with that status code.
     */
    #[Test]
    public function responseFromIntegerCreatesResponseWithStatusCode(): void
    {
        $client = FakeHttpClient::fake([
            'https://api.example.com/users' => 404,
        ]);

        $response = $client->get('https://api.example.com/users');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('', $response->body());
    }

    /**
     * Test response from integer with server error status.
     */
    #[Test]
    public function responseFromIntegerWithServerErrorStatus(): void
    {
        $client = FakeHttpClient::fake([
            'https://api.example.com/health' => 500,
        ]);

        $response = $client->get('https://api.example.com/health');

        $this->assertSame(500, $response->getStatusCode());
        $this->assertTrue($response->failed());
    }

    // ── Mixing sequenced and single responses tests ──────────────────

    /**
     * Test mixing sequenced and single responses for different patterns.
     */
    #[Test]
    public function mixingSequencedAndSingleResponsesForDifferentPatterns(): void
    {
        $client = FakeHttpClient::fake([
            'https://api.example.com/health' => 200,
        ]);

        $client->sequence('https://api.example.com/users', [201, 200]);

        $usersFirst = $client->get('https://api.example.com/users');
        $usersSecond = $client->get('https://api.example.com/users');
        $health = $client->get('https://api.example.com/health');

        $this->assertSame(201, $usersFirst->getStatusCode());
        $this->assertSame(200, $usersSecond->getStatusCode());
        $this->assertSame(200, $health->getStatusCode());
    }

    // ── Response sequencing repeats last response tests ──────────────

    /**
     * Test response sequencing repeats last response after exhaustion.
     */
    #[Test]
    public function responseSequencingRepeatsLastResponseAfterExhaustion(): void
    {
        $client = FakeHttpClient::fake();
        $client->sequence('https://api.example.com/users', [201, 200]);

        $first = $client->get('https://api.example.com/users');
        $second = $client->get('https://api.example.com/users');
        $third = $client->get('https://api.example.com/users');
        $fourth = $client->get('https://api.example.com/users');

        $this->assertSame(201, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame(200, $third->getStatusCode());
        $this->assertSame(200, $fourth->getStatusCode());
    }

    /**
     * Test response sequencing with array config responses.
     */
    #[Test]
    public function responseSequencingWithArrayConfigResponses(): void
    {
        $client = FakeHttpClient::fake();
        $client->sequence('https://api.example.com/users', [
            ['status' => 500, 'body' => 'error'],
            ['status' => 200, 'body' => 'success'],
        ]);

        $first = $client->get('https://api.example.com/users');
        $second = $client->get('https://api.example.com/users');

        $this->assertSame(500, $first->getStatusCode());
        $this->assertSame('error', $first->body());
        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame('success', $second->body());
    }

    // ── Request recording tests ──────────────────────────────────────

    /**
     * Test getRecordedRequests returns all recorded requests.
     */
    #[Test]
    public function getRecordedRequestsReturnsAllRecordedRequests(): void
    {
        $client = FakeHttpClient::fake([
            'https://api.example.com/users' => 200,
            'https://api.example.com/posts' => 200,
        ]);

        $client->get('https://api.example.com/users');
        $client->post('https://api.example.com/posts');

        $recorded = $client->getRecordedRequests();

        $this->assertCount(2, $recorded);
        $this->assertSame('GET', $recorded[0]->method);
        $this->assertSame('https://api.example.com/users', $recorded[0]->url);
        $this->assertSame('POST', $recorded[1]->method);
        $this->assertSame('https://api.example.com/posts', $recorded[1]->url);
    }

    // ── Response object passthrough test ─────────────────────────────

    /**
     * Test Response object is used directly when provided.
     */
    #[Test]
    public function responseObjectIsUsedDirectlyWhenProvided(): void
    {
        $expected = new Response(curlInfo: ['http_code' => 202], body: 'accepted');

        $client = FakeHttpClient::fake();
        $client->addFake('https://api.example.com/jobs', $expected);

        $response = $client->post('https://api.example.com/jobs');

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('accepted', $response->body());
    }

    // ── Sink tests ───────────────────────────────────────────────────

    /**
     * Test the response body is written to a sink given as a path.
     *
     * The real client hands the body to cURL, which writes it to the sink; the
     * fake never reaches cURL, so a test asserting on a downloaded file found
     * it empty.
     */
    #[Test]
    public function sinkReceivesTheResponseBody(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fake_sink');
        $this->assertNotFalse($path);

        try {
            $client = FakeHttpClient::fake([
                'GET https://api.example.com/report.csv' => [
                    'status' => 200,
                    'body' => "id,name\n1,Alice\n",
                ],
            ]);

            $client->sink($path)->get('https://api.example.com/report.csv');

            $this->assertSame("id,name\n1,Alice\n", file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    /**
     * Test the response body is written to a sink given as a resource.
     */
    #[Test]
    public function sinkResourceReceivesTheResponseBody(): void
    {
        $resource = fopen('php://temp', 'w+');
        $this->assertNotFalse($resource);

        try {
            $client = FakeHttpClient::fake([
                'GET https://api.example.com/data' => ['status' => 200, 'body' => 'payload'],
            ]);

            $client->sink($resource)->get('https://api.example.com/data');

            rewind($resource);
            $this->assertSame('payload', stream_get_contents($resource));
        } finally {
            fclose($resource);
        }
    }

    /**
     * Test a sink holds only the final response when a retry occurred.
     *
     * The real client truncates the sink between attempts, so the file must not
     * accumulate the bodies of failed attempts.
     */
    #[Test]
    public function sinkHoldsOnlyTheFinalResponseAfterARetry(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fake_sink');
        $this->assertNotFalse($path);

        try {
            $client = FakeHttpClient::fake();
            $client->sequence('GET https://api.example.com/flaky', [
                ['status' => 503, 'body' => 'unavailable'],
                ['status' => 200, 'body' => 'ok'],
            ]);

            $response = $client->retry(1)->sink($path)->get('https://api.example.com/flaky');

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('ok', file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    /**
     * Test an empty response body leaves the sink empty rather than untouched.
     */
    #[Test]
    public function sinkIsTruncatedForAnEmptyResponseBody(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fake_sink');
        $this->assertNotFalse($path);

        try {
            file_put_contents($path, 'stale contents');

            $client = FakeHttpClient::fake([
                'GET https://api.example.com/empty' => ['status' => 204, 'body' => ''],
            ]);

            $client->sink($path)->get('https://api.example.com/empty');

            $this->assertSame('', file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    /**
     * Test a request without a sink is unaffected.
     */
    #[Test]
    public function requestWithoutASinkStillReturnsTheBody(): void
    {
        $client = FakeHttpClient::fake([
            'GET https://api.example.com/plain' => ['status' => 200, 'body' => 'body text'],
        ]);

        $response = $client->get('https://api.example.com/plain');

        $this->assertSame('body text', $response->body());
    }

    // ── Empty sequence tests ─────────────────────────────────────────

    /**
     * Test sequence() rejects an empty response list.
     *
     * The route previously matched and then died inside nextResponse() with an
     * undefined-index warning and a TypeError, pointing at the library rather
     * than at the empty call.
     */
    #[Test]
    public function sequenceRejectsAnEmptyResponseList(): void
    {
        $client = FakeHttpClient::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A fake route requires at least one response.');

        $client->sequence('GET https://api.example.com/nothing', []);
    }

    /**
     * Test a route rejected for being empty is not registered.
     *
     * A half-registered route would match later requests and fail again.
     */
    #[Test]
    public function anEmptySequenceLeavesNoRouteBehind(): void
    {
        $client = FakeHttpClient::fake();

        try {
            $client->sequence('GET https://api.example.com/nothing', []);
            $this->fail('Expected InvalidArgumentException for an empty sequence.');
        } catch (InvalidArgumentException) {
            // Expected: the sequence carried no responses.
        }

        $this->expectException(UnexpectedRequestException::class);

        $client->get('https://api.example.com/nothing');
    }

    /**
     * Test a single-response sequence is still accepted.
     */
    #[Test]
    public function sequenceAcceptsASingleResponse(): void
    {
        $client = FakeHttpClient::fake();
        $client->sequence('GET https://api.example.com/one', [200]);

        $this->assertSame(200, $client->get('https://api.example.com/one')->getStatusCode());
    }
}
