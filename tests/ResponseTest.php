<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QuickCheck\Generator;
use QuickCheck\PHPUnit\PropertyConstraint;
use QuickCheck\Property;
use Simsoft\HttpClient\Response;
use Simsoft\HttpClient\Streams\StringStream;
use stdClass;

/**
 * ResponseTest class.
 *
 * Tests for Response construction, status helpers, JSON decoding,
 * dot-notation data access, PSR-7 header immutability, and stream handling.
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessivePublicCount)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ResponseTest extends TestCase
{
    /** @var array<string, mixed> Loaded responses fixture data. */
    private array $responsesFixture;

    /** @var array<string, mixed> Loaded user fixture data. */
    private array $userFixture;

    /**
     * Load JSON fixture files before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $responsesJson = file_get_contents(__DIR__ . '/fixtures/responses.json');
        $this->responsesFixture = json_decode((string)$responsesJson, true);

        $userJson = file_get_contents(__DIR__ . '/fixtures/user.json');
        $this->userFixture = json_decode((string)$userJson, true);
    }

    // ── Status helper tests ──────────────────────────────────────────

    /**
     * Test ok() returns true for status 200.
     */
    #[Test]
    public function okReturnsTrueForStatus200(): void
    {
        $response = new Response(
            curlInfo: ['http_code' => 200],
            body: '{"message":"OK"}',
            rawHeaders: "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n",
        );

        $this->assertTrue($response->ok());
        $this->assertFalse($response->created());
        $this->assertFalse($response->notFound());
        $this->assertFalse($response->internalServerError());
    }

    /**
     * Test created() returns true for status 201.
     */
    #[Test]
    public function createdReturnsTrueForStatus201(): void
    {
        $response = new Response(curlInfo: ['http_code' => 201]);

        $this->assertTrue($response->created());
        $this->assertFalse($response->ok());
    }

    /**
     * Test notFound() returns true for status 404.
     */
    #[Test]
    public function notFoundReturnsTrueForStatus404(): void
    {
        $scenario = $this->responsesFixture['not_found'];
        $response = new Response(
            curlInfo: ['http_code' => $scenario['status']],
            body: $scenario['body'],
            rawHeaders: $scenario['headers'],
        );

        $this->assertTrue($response->notFound());
        $this->assertFalse($response->ok());
    }

    /**
     * Test internalServerError() returns true for status 500.
     */
    #[Test]
    public function internalServerErrorReturnsTrueForStatus500(): void
    {
        $scenario = $this->responsesFixture['server_error'];
        $response = new Response(
            curlInfo: ['http_code' => $scenario['status']],
            body: $scenario['body'],
            rawHeaders: $scenario['headers'],
        );

        $this->assertTrue($response->internalServerError());
        $this->assertFalse($response->ok());
    }

    /**
     * Test successful() returns true for 2xx status codes.
     */
    #[Test]
    public function successfulReturnsTrueFor2xxStatusCodes(): void
    {
        foreach ([200, 201, 204, 299] as $code) {
            $response = new Response(curlInfo: ['http_code' => $code]);
            $this->assertTrue($response->successful(), "Expected successful() for status {$code}");
        }
    }

    /**
     * Test successful() returns false for non-2xx status codes.
     */
    #[Test]
    public function successfulReturnsFalseForNon2xxStatusCodes(): void
    {
        foreach ([100, 301, 400, 404, 500] as $code) {
            $response = new Response(curlInfo: ['http_code' => $code]);
            $this->assertFalse($response->successful(), "Expected not successful() for status {$code}");
        }
    }

    /**
     * Test failed() returns true for 4xx and 5xx status codes.
     */
    #[Test]
    public function failedReturnsTrueFor4xxAnd5xxStatusCodes(): void
    {
        foreach ([400, 404, 422, 500, 503] as $code) {
            $response = new Response(curlInfo: ['http_code' => $code]);
            $this->assertTrue($response->failed(), "Expected failed() for status {$code}");
        }
    }

    /**
     * Test failed() returns false for 2xx and 3xx status codes.
     */
    #[Test]
    public function failedReturnsFalseFor2xxAnd3xxStatusCodes(): void
    {
        foreach ([200, 201, 204, 301, 302] as $code) {
            $response = new Response(curlInfo: ['http_code' => $code]);
            $this->assertFalse($response->failed(), "Expected not failed() for status {$code}");
        }
    }

    /**
     * Test isClientError() returns true for 4xx status codes.
     */
    #[Test]
    public function isClientErrorReturnsTrueFor4xxStatusCodes(): void
    {
        foreach ([400, 401, 403, 404, 422, 429, 499] as $code) {
            $response = new Response(curlInfo: ['http_code' => $code]);
            $this->assertTrue($response->isClientError(), "Expected isClientError() for status {$code}");
        }
    }

    /**
     * Test isClientError() returns false for non-4xx status codes.
     */
    #[Test]
    public function isClientErrorReturnsFalseForNon4xxStatusCodes(): void
    {
        foreach ([200, 301, 500] as $code) {
            $response = new Response(curlInfo: ['http_code' => $code]);
            $this->assertFalse($response->isClientError(), "Expected not isClientError() for status {$code}");
        }
    }

    /**
     * Test isServerError() returns true for 5xx status codes.
     */
    #[Test]
    public function isServerErrorReturnsTrueFor5xxStatusCodes(): void
    {
        foreach ([500, 502, 503, 599] as $code) {
            $response = new Response(curlInfo: ['http_code' => $code]);
            $this->assertTrue($response->isServerError(), "Expected isServerError() for status {$code}");
        }
    }

    /**
     * Test isServerError() returns false for non-5xx status codes.
     */
    #[Test]
    public function isServerErrorReturnsFalseForNon5xxStatusCodes(): void
    {
        foreach ([200, 404, 301] as $code) {
            $response = new Response(curlInfo: ['http_code' => $code]);
            $this->assertFalse($response->isServerError(), "Expected not isServerError() for status {$code}");
        }
    }

    /**
     * Test isRedirect() returns true for 3xx status codes.
     */
    #[Test]
    public function isRedirectReturnsTrueFor3xxStatusCodes(): void
    {
        foreach ([300, 301, 302, 304, 399] as $code) {
            $response = new Response(curlInfo: ['http_code' => $code]);
            $this->assertTrue($response->isRedirect(), "Expected isRedirect() for status {$code}");
        }
    }

    /**
     * Test isRedirect() returns false for non-3xx status codes.
     */
    #[Test]
    public function isRedirectReturnsFalseForNon3xxStatusCodes(): void
    {
        foreach ([200, 404, 500] as $code) {
            $response = new Response(curlInfo: ['http_code' => $code]);
            $this->assertFalse($response->isRedirect(), "Expected not isRedirect() for status {$code}");
        }
    }

    // ── JSON decoding tests ──────────────────────────────────────────

    /**
     * Test json() returns associative array for valid JSON body.
     */
    #[Test]
    public function jsonReturnsAssociativeArrayForValidJsonBody(): void
    {
        $scenario = $this->responsesFixture['success'];
        $response = new Response(
            curlInfo: ['http_code' => $scenario['status']],
            body: $scenario['body'],
            rawHeaders: $scenario['headers'],
        );

        $result = $response->json();
        $this->assertIsArray($result);
        $this->assertSame('OK', $result['message']);
    }

    /**
     * Test object() returns stdClass for valid JSON body.
     */
    #[Test]
    public function objectReturnsStdClassForValidJsonBody(): void
    {
        $scenario = $this->responsesFixture['success'];
        $response = new Response(
            curlInfo: ['http_code' => $scenario['status']],
            body: $scenario['body'],
            rawHeaders: $scenario['headers'],
        );

        $result = $response->object();
        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertSame('OK', $result->message);
    }

    /**
     * Test json() returns null for empty body.
     */
    #[Test]
    public function jsonReturnsNullForEmptyBody(): void
    {
        $scenario = $this->responsesFixture['empty_body'];
        $response = new Response(
            curlInfo: ['http_code' => $scenario['status']],
            body: $scenario['body'],
            rawHeaders: $scenario['headers'],
        );

        $this->assertNull($response->json());
    }

    /**
     * Test json() returns null for non-JSON body without JSON content type.
     */
    #[Test]
    public function jsonReturnsNullForNonJsonBodyWithoutJsonContentType(): void
    {
        $response = new Response(
            curlInfo: ['http_code' => 200],
            body: 'plain text body',
            rawHeaders: "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\n",
        );

        $this->assertNull($response->json());
    }

    /**
     * Test RuntimeException is thrown for invalid JSON body when Content-Type is JSON.
     */
    #[Test]
    public function jsonThrowsRuntimeExceptionForInvalidJsonWithJsonContentType(): void
    {
        $response = new Response(
            curlInfo: ['http_code' => 200],
            body: '{invalid json',
            rawHeaders: "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n",
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JSON decode error');
        $response->json();
    }

    /**
     * Test object() returns null for non-JSON body.
     */
    #[Test]
    public function objectReturnsNullForNonJsonBody(): void
    {
        $response = new Response(
            curlInfo: ['http_code' => 200],
            body: 'not json',
            rawHeaders: "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\n",
        );

        $this->assertNull($response->object());
    }

    // ── Dot-notation data access tests ───────────────────────────────

    /**
     * Test data() with null key returns full decoded array.
     */
    #[Test]
    public function dataWithNullKeyReturnsFullDecodedArray(): void
    {
        $body = json_encode($this->userFixture);
        $response = new Response(
            curlInfo: ['http_code' => 200],
            body: (string)$body,
            rawHeaders: "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n",
        );

        $result = $response->data();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
    }

    /**
     * Test data() with dot-notation resolves nested values.
     */
    #[Test]
    public function dataWithDotNotationResolvesNestedValues(): void
    {
        $body = json_encode($this->userFixture);
        $response = new Response(
            curlInfo: ['http_code' => 200],
            body: (string)$body,
            rawHeaders: "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n",
        );

        $this->assertSame('Alice Tan', $response->data('data.0.name'));
        $this->assertSame(28, $response->data('data.0.profile.age'));
        $this->assertSame('Kuala Lumpur', $response->data('data.0.profile.address.0.city'));
        $this->assertSame('+60123456789', $response->data('data.0.profile.contact.phone'));
    }

    /**
     * Test data() returns default for non-existent key.
     */
    #[Test]
    public function dataReturnsDefaultForNonExistentKey(): void
    {
        $response = new Response(
            curlInfo: ['http_code' => 200],
            body: '{"name":"test"}',
            rawHeaders: "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n",
        );

        $this->assertNull($response->data('nonexistent'));
        $this->assertSame('fallback', $response->data('nonexistent', 'fallback'));
    }

    /**
     * Test data() with wildcard segments collects values from all array items.
     */
    #[Test]
    public function dataWithWildcardCollectsValuesFromAllItems(): void
    {
        $body = json_encode($this->userFixture);
        $response = new Response(
            curlInfo: ['http_code' => 200],
            body: (string)$body,
            rawHeaders: "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n",
        );

        $names = $response->data('data.*.name');
        $this->assertIsArray($names);
        $this->assertCount(10, $names);
        $this->assertSame('Alice Tan', $names[0]);
        $this->assertSame('Benjamin Lee', $names[1]);

        $emails = $response->data('data.*.email');
        $this->assertIsArray($emails);
        $this->assertCount(10, $emails);
        $this->assertSame('alice.tan@example.com', $emails[0]);
    }

    /**
     * Test data() with nested wildcard resolves deep values.
     */
    #[Test]
    public function dataWithNestedWildcardResolvesDeepValues(): void
    {
        $body = json_encode($this->userFixture);
        $response = new Response(
            curlInfo: ['http_code' => 200],
            body: (string)$body,
            rawHeaders: "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n",
        );

        $phones = $response->data('data.*.profile.contact.phone');
        $this->assertIsArray($phones);
        $this->assertCount(10, $phones);
        $this->assertSame('+60123456789', $phones[0]);
    }

    // ── PSR-7 header tests ───────────────────────────────────────────

    /**
     * Test getHeader() returns array of values for existing header.
     */
    #[Test]
    public function getHeaderReturnsArrayOfValuesForExistingHeader(): void
    {
        $response = new Response(
            curlInfo: ['http_code' => 200],
            rawHeaders: "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n",
        );

        $this->assertSame(['application/json'], $response->getHeader('Content-Type'));
    }

    /**
     * Test getHeader() returns empty array for non-existent header.
     */
    #[Test]
    public function getHeaderReturnsEmptyArrayForNonExistentHeader(): void
    {
        $response = new Response(
            curlInfo: ['http_code' => 200],
            rawHeaders: "HTTP/1.1 200 OK\r\n",
        );

        $this->assertSame([], $response->getHeader('X-Missing'));
    }

    /**
     * Test getHeaderLine() returns comma-separated values.
     */
    #[Test]
    public function getHeaderLineReturnsCommaSeparatedValues(): void
    {
        $response = new Response(
            curlInfo: ['http_code' => 200],
            rawHeaders: "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n",
        );

        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    /**
     * Test getHeaderLine() returns empty string for non-existent header.
     */
    #[Test]
    public function getHeaderLineReturnsEmptyStringForNonExistentHeader(): void
    {
        $response = new Response(
            curlInfo: ['http_code' => 200],
            rawHeaders: "HTTP/1.1 200 OK\r\n",
        );

        $this->assertSame('', $response->getHeaderLine('X-Missing'));
    }

    /**
     * Test hasHeader() returns true for existing header (case-insensitive).
     */
    #[Test]
    public function hasHeaderReturnsTrueForExistingHeader(): void
    {
        $response = new Response(
            curlInfo: ['http_code' => 200],
            rawHeaders: "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n",
        );

        $this->assertTrue($response->hasHeader('content-type'));
        $this->assertTrue($response->hasHeader('Content-Type'));
    }

    /**
     * Test hasHeader() returns false for non-existent header.
     */
    #[Test]
    public function hasHeaderReturnsFalseForNonExistentHeader(): void
    {
        $response = new Response(
            curlInfo: ['http_code' => 200],
            rawHeaders: "HTTP/1.1 200 OK\r\n",
        );

        $this->assertFalse($response->hasHeader('X-Missing'));
    }

    /**
     * Test withHeader() returns new instance with updated header.
     */
    #[Test]
    public function withHeaderReturnsNewInstanceWithUpdatedHeader(): void
    {
        $response = new Response(
            curlInfo: ['http_code' => 200],
            rawHeaders: "HTTP/1.1 200 OK\r\n",
        );

        $newResponse = $response->withHeader('X-Custom', 'value');

        $this->assertNotSame($response, $newResponse);
        $this->assertSame(['value'], $newResponse->getHeader('X-Custom'));
        $this->assertFalse($response->hasHeader('X-Custom'));
    }

    /**
     * Test withoutHeader() returns new instance without the header.
     */
    #[Test]
    public function withoutHeaderReturnsNewInstanceWithoutHeader(): void
    {
        $response = new Response(
            curlInfo: ['http_code' => 200],
            rawHeaders: "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n",
        );

        $newResponse = $response->withoutHeader('Content-Type');

        $this->assertNotSame($response, $newResponse);
        $this->assertFalse($newResponse->hasHeader('Content-Type'));
        $this->assertTrue($response->hasHeader('Content-Type'));
    }

    /**
     * Test withoutHeader() returns same instance when header does not exist.
     */
    #[Test]
    public function withoutHeaderReturnsSameInstanceWhenHeaderMissing(): void
    {
        $response = new Response(
            curlInfo: ['http_code' => 200],
            rawHeaders: "HTTP/1.1 200 OK\r\n",
        );

        $sameResponse = $response->withoutHeader('X-Missing');

        $this->assertSame($response, $sameResponse);
    }

    // ── Network error tests ──────────────────────────────────────────

    /**
     * Test non-zero errno makes isNetworkError() and failed() return true.
     */
    #[Test]
    public function nonZeroErrnoMakesIsNetworkErrorAndFailedReturnTrue(): void
    {
        $response = new Response(
            curlInfo: ['http_code' => 0],
            errno: 7,
        );

        $this->assertTrue($response->isNetworkError());
        $this->assertTrue($response->failed());
    }

    /**
     * Test zero errno makes isNetworkError() return false.
     */
    #[Test]
    public function zeroErrnoMakesIsNetworkErrorReturnFalse(): void
    {
        $response = new Response(
            curlInfo: ['http_code' => 200],
            errno: 0,
        );

        $this->assertFalse($response->isNetworkError());
    }

    // ── PSR-7 immutability tests ─────────────────────────────────────

    /**
     * Test withStatus() returns new instance with updated status code.
     */
    #[Test]
    public function withStatusReturnsNewInstanceWithUpdatedStatusCode(): void
    {
        $response = new Response(curlInfo: ['http_code' => 200]);

        $newResponse = $response->withStatus(404, 'Not Found');

        $this->assertNotSame($response, $newResponse);
        $this->assertSame(404, $newResponse->getStatusCode());
        $this->assertSame('Not Found', $newResponse->getReasonPhrase());
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Test withProtocolVersion() returns new instance with updated version.
     */
    #[Test]
    public function withProtocolVersionReturnsNewInstanceWithUpdatedVersion(): void
    {
        $response = new Response(curlInfo: ['http_code' => 200]);

        $newResponse = $response->withProtocolVersion('2.0');

        $this->assertNotSame($response, $newResponse);
        $this->assertSame('2.0', $newResponse->getProtocolVersion());
        $this->assertSame('1.1', $response->getProtocolVersion());
    }

    // ── Stream / body tests ──────────────────────────────────────────

    /**
     * Test getBody() returns StringStream for in-memory bodies.
     */
    #[Test]
    public function getBodyReturnsStringStreamForInMemoryBodies(): void
    {
        $response = new Response(
            curlInfo: ['http_code' => 200],
            body: 'hello world',
        );

        $body = $response->getBody();
        $this->assertInstanceOf(StringStream::class, $body);
        $this->assertSame('hello world', (string)$body);
    }

    // ── Raw header parsing tests ─────────────────────────────────────

    /**
     * Test raw headers with multiple response blocks parses only final block.
     */
    #[Test]
    public function rawHeadersWithMultipleBlocksParsesOnlyFinalBlock(): void
    {
        $scenario = $this->responsesFixture['redirect_chain'];
        $response = new Response(
            curlInfo: ['http_code' => $scenario['status']],
            body: $scenario['body'],
            rawHeaders: $scenario['headers'],
        );

        $this->assertTrue($response->hasHeader('Content-Type'));
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertFalse($response->hasHeader('Location'));
    }

    /**
     * Test protocol version is parsed from the final header block.
     */
    #[Test]
    public function protocolVersionIsParsedFromFinalHeaderBlock(): void
    {
        $response = new Response(
            curlInfo: ['http_code' => 200],
            rawHeaders: "HTTP/1.0 301 Moved\r\nLocation: /new\r\n\r\nHTTP/1.1 200 OK\r\nContent-Type: text/html\r\n",
        );

        $this->assertSame('1.1', $response->getProtocolVersion());
    }

    /**
     * Test getStatusCode() returns the code from curlInfo.
     */
    #[Test]
    public function getStatusCodeReturnsCodeFromCurlInfo(): void
    {
        $response = new Response(curlInfo: ['http_code' => 418]);

        $this->assertSame(418, $response->getStatusCode());
    }

    /**
     * Test default status code is 0 when curlInfo is false.
     */
    #[Test]
    public function defaultStatusCodeIsZeroWhenCurlInfoIsFalse(): void
    {
        $response = new Response();

        $this->assertSame(0, $response->getStatusCode());
    }

    /**
     * Test data() returns empty array for empty body.
     */
    #[Test]
    public function dataReturnsEmptyArrayForEmptyBody(): void
    {
        $response = new Response(
            curlInfo: ['http_code' => 204],
            body: '',
        );

        $this->assertSame([], $response->data());
    }

    /**
     * Test getRaw() returns the raw body string.
     */
    #[Test]
    public function getRawReturnsRawBodyString(): void
    {
        $response = new Response(
            curlInfo: ['http_code' => 200],
            body: 'raw body content',
        );

        $this->assertSame('raw body content', $response->getRaw());
    }

    /**
     * Test getReasonPhrase() returns the message.
     */
    #[Test]
    public function getReasonPhraseReturnsMessage(): void
    {
        $response = new Response(
            curlInfo: ['http_code' => 200],
            rawHeaders: "HTTP/1.1 200 OK\r\n",
        );

        $this->assertSame('OK', $response->getReasonPhrase());
    }

    // ── Property-based tests ─────────────────────────────────────────

    /**
     * Property test: for any status code 100–599, exactly the matching helper methods return true.
     *
     * Feature: unit-tests-and-code-quality, Property 5: Response status code maps to correct helpers
     *
     * Validates: Requirements 3.1, 3.7
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    #[Test]
    public function statusCodeMapsToCorrectHelpersProperty(): void
    {
        $property = Property::forAll(
            [Generator::choose(100, 599)],
            function (int $code): bool {
                $response = new Response(curlInfo: ['http_code' => $code]);

                $exactMatches = [
                    'ok' => $code === 200,
                    'created' => $code === 201,
                    'accepted' => $code === 202,
                    'noContent' => $code === 204,
                    'movedPermanently' => $code === 301,
                    'found' => $code === 302,
                    'notModified' => $code === 304,
                    'badRequest' => $code === 400,
                    'unauthorized' => $code === 401,
                    'forbidden' => $code === 403,
                    'notFound' => $code === 404,
                    'methodNotAllowed' => $code === 405,
                    'conflict' => $code === 409,
                    'unprocessableEntity' => $code === 422,
                    'tooManyRequests' => $code === 429,
                    'internalServerError' => $code === 500,
                ];

                foreach ($exactMatches as $method => $expected) {
                    if ($response->$method() !== $expected) {
                        return false;
                    }
                }

                $rangeMatches = [
                    'successful' => $code >= 200 && $code < 300,
                    'isClientError' => $code >= 400 && $code < 500,
                    'isServerError' => $code >= 500,
                    'isRedirect' => $code >= 300 && $code < 400,
                    'failed' => $code >= 400,
                ];

                foreach ($rangeMatches as $method => $expected) {
                    if ($response->$method() !== $expected) {
                        return false;
                    }
                }

                return true;
            },
        );

        $this->assertThat($property, PropertyConstraint::check(100));
    }
}
