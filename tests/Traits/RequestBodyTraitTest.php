<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests\Traits;

use CURLFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use ReflectionProperty;
use Simsoft\HttpClient\HttpClient;
use Simsoft\HttpClient\Streams\StringStream;

/**
 * RequestBodyTraitTest class
 *
 * Tests for the RequestBodyTrait methods: withRaw(), withBody(), withBodyStream(),
 * withMultipart(), and stream ownership management.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
class RequestBodyTraitTest extends TestCase
{
    /**
     * Read a protected property value via reflection.
     *
     * @param HttpClient $client The client instance.
     * @param string $property The property name.
     * @return mixed
     */
    private function getProperty(HttpClient $client, string $property): mixed
    {
        $reflection = new ReflectionProperty($client, $property);

        return $reflection->getValue($client);
    }

    /**
     * Test withRaw() sets string body and text/plain content type by default.
     *
     * @return void
     */
    #[Test]
    public function withRawSetsStringBodyAndDefaultContentType(): void
    {
        $client = HttpClient::make()->withRaw('hello world');

        $this->assertSame('hello world', $this->getProperty($client, 'postFields'));
        $this->assertSame('text/plain', $this->getProperty($client, 'contentType'));
    }

    /**
     * Test withRaw() accepts a custom content type.
     *
     * @return void
     */
    #[Test]
    public function withRawAcceptsCustomContentType(): void
    {
        $client = HttpClient::make()->withRaw('<xml>data</xml>', 'application/xml');

        $this->assertSame('<xml>data</xml>', $this->getProperty($client, 'postFields'));
        $this->assertSame('application/xml', $this->getProperty($client, 'contentType'));
    }

    /**
     * Test withBody() sets string data and content type.
     *
     * @return void
     */
    #[Test]
    public function withBodySetsStringDataAndContentType(): void
    {
        $client = HttpClient::make()->withBody('raw data', 'text/csv');

        $this->assertSame('raw data', $this->getProperty($client, 'postFields'));
        $this->assertSame('text/csv', $this->getProperty($client, 'contentType'));
    }

    /**
     * Test withBody() accepts a StreamInterface.
     *
     * @return void
     */
    #[Test]
    public function withBodyAcceptsStreamInterface(): void
    {
        $stream = new StringStream('stream content');
        $client = HttpClient::make()->withBody($stream, 'application/octet-stream');

        $this->assertSame($stream, $this->getProperty($client, 'postFields'));
        $this->assertSame('application/octet-stream', $this->getProperty($client, 'contentType'));
        $this->assertFalse($this->getProperty($client, 'postFieldsOwned'));
    }

    /**
     * Test withBody() does not set ownership flag.
     *
     * @return void
     */
    #[Test]
    public function withBodyDoesNotSetOwnership(): void
    {
        $stream = new StringStream('data');
        $client = HttpClient::make()->withBody($stream);

        $this->assertFalse($this->getProperty($client, 'postFieldsOwned'));
    }

    /**
     * Test withBody() without type does not change existing content type.
     *
     * @return void
     */
    #[Test]
    public function withBodyWithoutTypePreservesNull(): void
    {
        $client = HttpClient::make()->withBody('data');

        $this->assertNull($this->getProperty($client, 'contentType'));
    }

    /**
     * Test withBodyStream() sets stream and ownership flag.
     *
     * @return void
     */
    #[Test]
    public function withBodyStreamSetsStreamAndOwnership(): void
    {
        $stream = new StringStream('owned stream');
        $client = HttpClient::make()->withBodyStream($stream);

        $this->assertSame($stream, $this->getProperty($client, 'postFields'));
        $this->assertTrue($this->getProperty($client, 'postFieldsOwned'));
        $this->assertSame('application/octet-stream', $this->getProperty($client, 'contentType'));
    }

    /**
     * Test withBodyStream() accepts a custom content type.
     *
     * @return void
     */
    #[Test]
    public function withBodyStreamAcceptsCustomContentType(): void
    {
        $stream = new StringStream('pdf content');
        $client = HttpClient::make()->withBodyStream($stream, 'application/pdf');

        $this->assertSame('application/pdf', $this->getProperty($client, 'contentType'));
    }

    /**
     * Test withBodyStream() closes previously owned stream.
     *
     * @return void
     */
    #[Test]
    public function withBodyStreamClosesPreviouslyOwnedStream(): void
    {
        $firstStream = $this->createMock(StreamInterface::class);
        $firstStream->expects($this->once())->method('close');

        $client = HttpClient::make()->withBodyStream($firstStream);

        $secondStream = new StringStream('new');
        $client->withBodyStream($secondStream);
    }

    /**
     * Test withBody() closes previously owned stream.
     *
     * @return void
     */
    #[Test]
    public function withBodyClosesPreviouslyOwnedStream(): void
    {
        $ownedStream = $this->createMock(StreamInterface::class);
        $ownedStream->expects($this->once())->method('close');

        $client = HttpClient::make()->withBodyStream($ownedStream);

        $client->withBody('replacing with string', 'text/plain');
    }

    /**
     * Test withMultipart() sets array data and POST method.
     *
     * @return void
     */
    #[Test]
    public function withMultipartSetsArrayDataAndPostMethod(): void
    {
        $client = HttpClient::make()->withMultipart(['name' => 'Alice', 'age' => '30']);

        $postFields = $this->getProperty($client, 'postFields');
        $method = $this->getProperty($client, 'method');

        $this->assertIsArray($postFields);
        $this->assertSame('Alice', $postFields['name']);
        $this->assertSame('30', $postFields['age']);
        $this->assertSame('POST', $method);
        $this->assertSame('multipart', $this->getProperty($client, 'contentType'));
    }

    /**
     * Test withMultipart() merges with existing array data.
     *
     * @return void
     */
    #[Test]
    public function withMultipartMergesWithExistingData(): void
    {
        $client = HttpClient::make()
            ->withMultipart(['name' => 'Alice'])
            ->withMultipart(['email' => 'alice@example.com']);

        $postFields = $this->getProperty($client, 'postFields');

        $this->assertSame('Alice', $postFields['name']);
        $this->assertSame('alice@example.com', $postFields['email']);
    }

    /**
     * Test withMultipart() keeps the multipart content type when merging.
     *
     * @return void
     */
    #[Test]
    public function withMultipartKeepsMultipartContentTypeOnMerge(): void
    {
        $client = HttpClient::make()
            ->withMultipart(['first' => 'value'])
            ->withMultipart(['second' => 'value']);

        $this->assertSame('multipart', $this->getProperty($client, 'contentType'));
    }

    /**
     * Test getRequestBody() returns null when no body is set.
     *
     * @return void
     */
    #[Test]
    public function getRequestBodyIsNullByDefault(): void
    {
        $this->assertNull(HttpClient::make()->getRequestBody());
    }

    /**
     * Test getRequestBody() returns the encoded JSON string.
     *
     * @return void
     */
    #[Test]
    public function getRequestBodyReturnsJsonString(): void
    {
        $client = HttpClient::make()->withJson(['name' => 'Alice', 'tags' => ['a']]);

        $this->assertSame('{"name":"Alice","tags":["a"]}', $client->getRequestBody());
    }

    /**
     * Test getRequestBody() returns the URL-encoded form string.
     *
     * @return void
     */
    #[Test]
    public function getRequestBodyReturnsFormString(): void
    {
        $client = HttpClient::make()->withForm(['email' => 'a@b.com', 'remember' => '1']);

        $this->assertSame('email=a%40b.com&remember=1', $client->getRequestBody());
    }

    /**
     * Test getRequestBody() returns the raw string.
     *
     * @return void
     */
    #[Test]
    public function getRequestBodyReturnsRawString(): void
    {
        $this->assertSame('<a/>', HttpClient::make()->withRaw('<a/>', 'application/xml')->getRequestBody());
    }

    /**
     * Test getRequestBody() returns the GraphQL payload.
     *
     * @return void
     */
    #[Test]
    public function getRequestBodyReturnsGraphQlPayload(): void
    {
        $client = HttpClient::make()->withGraphQL('query { users }', ['limit' => 5]);

        $this->assertSame('{"query":"query { users }","variables":{"limit":5}}', $client->getRequestBody());
    }

    /**
     * Test getRequestBody() returns merged multipart data as an array.
     *
     * @return void
     */
    #[Test]
    public function getRequestBodyReturnsMultipartArray(): void
    {
        $client = HttpClient::make()
            ->withMultipart(['name' => 'Alice'])
            ->withMultipart(['age' => '30']);

        $this->assertSame(['name' => 'Alice', 'age' => '30'], $client->getRequestBody());
    }

    /**
     * Test getRequestBody() exposes attachments as CURLFile entries.
     *
     * @return void
     */
    #[Test]
    public function getRequestBodyIncludesAttachments(): void
    {
        $client = HttpClient::make()
            ->withMultipart(['title' => 'Report'])
            ->attach('file', 'file contents', 'report.txt');

        $body = $client->getRequestBody();

        $this->assertIsArray($body);
        $this->assertSame('Report', $body['title']);
        $this->assertInstanceOf(CURLFile::class, $body['file']);
        $this->assertSame('report.txt', $body['file']->getPostFilename());
    }

    /**
     * Test getRequestBody() returns the same stream instance that was set.
     *
     * @return void
     */
    #[Test]
    public function getRequestBodyReturnsSameStream(): void
    {
        $stream = new StringStream('payload');

        $this->assertSame($stream, HttpClient::make()->withBody($stream)->getRequestBody());
        $this->assertSame($stream, HttpClient::make()->withBodyStream($stream)->getRequestBody());
    }

    /**
     * Test the last body set wins.
     *
     * @return void
     */
    #[Test]
    public function getRequestBodyReturnsLastBodySet(): void
    {
        $client = HttpClient::make()->withJson(['a' => 1])->withRaw('final');

        $this->assertSame('final', $client->getRequestBody());
    }
}
