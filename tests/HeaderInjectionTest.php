<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Simsoft\HttpClient\HttpClient;
use Simsoft\HttpClient\Streams\StringStream;

/**
 * HeaderInjectionTest class
 *
 * Regression tests for CRLF header injection.
 *
 * A CR or LF reaching cURL terminates the header line early, so any
 * caller-supplied value forwarded into a header could forge additional
 * headers on the wire. Every path that can produce a header must reject them.
 */
class HeaderInjectionTest extends TestCase
{
    /**
     * Header values that must never reach cURL.
     *
     * @return array<string, array<int, string>>
     */
    public static function malformedValues(): array
    {
        return [
            'crlf injection' => ["acme\r\nX-Admin: true"],
            'lf injection' => ["acme\nX-Admin: true"],
            'cr injection' => ["acme\rX-Admin: true"],
            'nul byte' => ["acme\0truncated"],
            'trailing newline' => ["acme\n"],
        ];
    }

    /**
     * Header names that must never reach cURL.
     *
     * @return array<string, array<int, string>>
     */
    public static function malformedNames(): array
    {
        return [
            'crlf injection' => ["X-Tenant\r\nX-Admin: true"],
            'lf injection' => ["X-Tenant\nX-Admin: true"],
            'embedded colon' => ['X-Tenant: evil'],
            'embedded space' => ['X Tenant'],
            'empty name' => [''],
        ];
    }

    /**
     * Test that withHeader() rejects line breaks in the value.
     *
     * @param string $value The malformed header value.
     * @return void
     */
    #[Test]
    #[DataProvider('malformedValues')]
    public function withHeaderRejectsMalformedValues(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        HttpClient::make()->withHeader('X-Tenant', $value);
    }

    /**
     * Test that withHeader() rejects illegal header names.
     *
     * @param string $name The malformed header name.
     * @return void
     */
    #[Test]
    #[DataProvider('malformedNames')]
    public function withHeaderRejectsMalformedNames(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        HttpClient::make()->withHeader($name, 'value');
    }

    /**
     * Test that withHeaders() rejects a malformed entry.
     *
     * @return void
     */
    #[Test]
    public function withHeadersRejectsMalformedValues(): void
    {
        $this->expectException(InvalidArgumentException::class);

        HttpClient::make()->withHeaders([
            'X-Safe' => 'fine',
            'X-Tenant' => "acme\r\nX-Admin: true",
        ]);
    }

    /**
     * Test that an array of values is validated element by element.
     *
     * @return void
     */
    #[Test]
    public function withHeaderValidatesEveryValueInAnArray(): void
    {
        $this->expectException(InvalidArgumentException::class);

        HttpClient::make()->withHeader('Accept', ['application/json', "text/html\r\nX-Admin: true"]);
    }

    /**
     * Test that withBearerToken() rejects a token containing line breaks.
     *
     * @return void
     */
    #[Test]
    public function withBearerTokenRejectsMalformedTokens(): void
    {
        $this->expectException(InvalidArgumentException::class);

        HttpClient::make()->withBearerToken("token\r\nX-Admin: true");
    }

    /**
     * Test that a content type supplied to withBody() cannot inject a header.
     *
     * This path does not go through withHeader(), so it relies on the check in
     * buildFormattedHeaders().
     *
     * @return void
     */
    #[Test]
    public function contentTypeCannotInjectAHeader(): void
    {
        $client = HttpClient::make()->withBaseUrl('https://api.example.com');
        $client->withBody(new StringStream('payload'), "text/plain\r\nX-Admin: true");

        $this->expectException(InvalidArgumentException::class);

        $prepare = new ReflectionMethod(HttpClient::class, 'prepareHandle');
        $prepare->invoke($client, 'test_request_id');
    }

    /**
     * Test that legitimate header values are still accepted unchanged.
     *
     * Guards against over-eager validation: colons, spaces, commas, equals
     * signs and base64 padding are all legal inside a header value.
     *
     * @return void
     */
    #[Test]
    public function legitimateHeadersArePreserved(): void
    {
        $client = HttpClient::make()->withBaseUrl('https://api.example.com');
        $client->withHeaders([
            'Authorization' => 'Basic dXNlcjpwYXNz==',
            'Accept' => 'text/html, application/json;q=0.9',
            'If-Modified-Since' => 'Wed, 21 Oct 2015 07:28:00 GMT',
            'X-Correlation-Id' => 'abc-123_456.789~x',
        ]);

        $prepare = new ReflectionMethod(HttpClient::class, 'prepareHandle');
        $prepare->invoke($client, 'test_request_id');

        $formatted = new ReflectionProperty(HttpClient::class, 'formattedHeaders');

        /** @var array<int, string> $headers */
        $headers = $formatted->getValue($client);

        $this->assertContains('Authorization: Basic dXNlcjpwYXNz==', $headers);
        $this->assertContains('Accept: text/html, application/json;q=0.9', $headers);
        $this->assertContains('If-Modified-Since: Wed, 21 Oct 2015 07:28:00 GMT', $headers);
        $this->assertContains('X-Correlation-Id: abc-123_456.789~x', $headers);
    }
}
