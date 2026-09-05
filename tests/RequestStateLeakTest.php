<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Simsoft\HttpClient\HttpClient;
use Simsoft\HttpClient\Streams\StringStream;
use Simsoft\HttpClient\Testing\FakeHttpClient;

/**
 * RequestStateLeakTest class
 *
 * Regression tests for request-scoped cURL option cleanup in flush().
 *
 * A reused client must not carry request-scoped options (sink, upload, body)
 * into the next request, while connection-scoped configuration set by the
 * caller (TLS verification, redirect policy, withOptions()) must survive.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class RequestStateLeakTest extends TestCase
{
    /** @var string|null Temporary sink file path to clean up. */
    private ?string $tempFile = null;

    /**
     * Remove any temporary file created during a test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if ($this->tempFile !== null && is_file($this->tempFile)) {
            unlink($this->tempFile);
        }

        $this->tempFile = null;
    }

    /**
     * Read the cURL options array from a client.
     *
     * @param HttpClient $client The client to inspect.
     * @return array<int, mixed>
     */
    private function getOptions(HttpClient $client): array
    {
        $reflection = new ReflectionProperty(HttpClient::class, 'options');

        /** @var array<int, mixed> $options */
        $options = $reflection->getValue($client);

        return $options;
    }

    /**
     * Invoke the private flush() method on a client.
     *
     * @param HttpClient $client The client to flush.
     * @return void
     */
    private function invokeFlush(HttpClient $client): void
    {
        $method = new ReflectionMethod(HttpClient::class, 'flush');
        $method->invoke($client);
    }

    /**
     * Create a path for a temporary sink file.
     *
     * @return string The temporary file path.
     */
    private function makeTempPath(): string
    {
        $this->tempFile = sys_get_temp_dir() . '/httpclient_leak_' . uniqid() . '.bin';

        return $this->tempFile;
    }

    /**
     * Test that sink() options do not survive a flush.
     *
     * Without cleanup, CURLOPT_FILE would keep pointing at a closed resource
     * and CURLOPT_RETURNTRANSFER would stay false, corrupting the next request.
     *
     * @return void
     */
    #[Test]
    public function sinkOptionsAreClearedAfterFlush(): void
    {
        $client = HttpClient::make();
        $client->sink($this->makeTempPath());

        $this->assertArrayHasKey(CURLOPT_FILE, $this->getOptions($client));

        $this->invokeFlush($client);
        $options = $this->getOptions($client);

        $this->assertArrayNotHasKey(CURLOPT_FILE, $options);
        $this->assertArrayNotHasKey(CURLOPT_RETURNTRANSFER, $options);
    }

    /**
     * Test that sinkStream() options do not survive a flush.
     *
     * @return void
     */
    #[Test]
    public function sinkStreamOptionsAreClearedAfterFlush(): void
    {
        $handle = fopen('php://temp', 'w+');
        $this->assertIsResource($handle);

        $client = HttpClient::make();
        $client->sinkStream($handle);

        $this->assertArrayHasKey(CURLOPT_WRITEFUNCTION, $this->getOptions($client));

        $this->invokeFlush($client);

        $this->assertArrayNotHasKey(CURLOPT_WRITEFUNCTION, $this->getOptions($client));

        fclose($handle);
    }

    /**
     * Test that a non-owned sink resource is detached from the client on flush.
     *
     * A caller-provided resource must not stay attached, otherwise the next
     * request would resume writing into it.
     *
     * @return void
     */
    #[Test]
    public function callerProvidedSinkIsDetachedAfterFlush(): void
    {
        $handle = fopen('php://temp', 'w+');
        $this->assertIsResource($handle);

        $client = HttpClient::make();
        $client->sink($handle);

        $this->invokeFlush($client);

        $sink = new ReflectionProperty(HttpClient::class, 'sink');
        $this->assertNull($sink->getValue($client), 'Caller-provided sink must be detached on flush.');

        fclose($handle);
    }

    /**
     * Test that stream-body upload options do not survive a flush.
     *
     * @return void
     */
    #[Test]
    public function streamUploadOptionsAreClearedAfterFlush(): void
    {
        $client = HttpClient::make()->withBaseUrl('https://api.example.com');
        $client->withBodyStream(new StringStream('payload'), 'application/pdf')->withMethod('PUT');

        $prepare = new ReflectionMethod(HttpClient::class, 'prepareHandle');
        $prepare->invoke($client, 'test_request_id');

        $prepared = $this->getOptions($client);
        $this->assertArrayHasKey(CURLOPT_UPLOAD, $prepared);
        $this->assertArrayHasKey(CURLOPT_READFUNCTION, $prepared);

        $this->invokeFlush($client);
        $options = $this->getOptions($client);

        $this->assertArrayNotHasKey(CURLOPT_UPLOAD, $options);
        $this->assertArrayNotHasKey(CURLOPT_INFILESIZE, $options);
        $this->assertArrayNotHasKey(CURLOPT_READFUNCTION, $options);
        $this->assertArrayNotHasKey(CURLOPT_CUSTOMREQUEST, $options);
        $this->assertArrayNotHasKey(CURLOPT_URL, $options);
    }

    /**
     * Test that connection-scoped configuration survives a flush.
     *
     * Resetting the whole options array would silently re-enable TLS
     * verification after withoutVerifying() — this guards against that.
     *
     * @return void
     */
    #[Test]
    public function connectionConfigurationSurvivesFlush(): void
    {
        $client = HttpClient::make()
            ->withoutVerifying()
            ->withOptions([CURLOPT_MAXREDIRS => 3, CURLOPT_PROXY => 'proxy.local:8080']);

        $this->invokeFlush($client);
        $options = $this->getOptions($client);

        $this->assertFalse($options[CURLOPT_SSL_VERIFYPEER], 'withoutVerifying() must not be undone by flush.');
        $this->assertSame(0, $options[CURLOPT_SSL_VERIFYHOST]);
        $this->assertSame(3, $options[CURLOPT_MAXREDIRS]);
        $this->assertSame('proxy.local:8080', $options[CURLOPT_PROXY]);
        $this->assertTrue($options[CURLOPT_FOLLOWLOCATION]);
    }

    /**
     * Test that a client reused after a download performs a normal request.
     *
     * This is the end-to-end symptom: without cleanup the second request
     * stays in sink mode and returns an unreadable body.
     *
     * @return void
     */
    #[Test]
    public function clientReusedAfterDownloadReturnsReadableBody(): void
    {
        $client = FakeHttpClient::fake([
            'GET *' => ['status' => 200, 'body' => '{"value":42}'],
        ]);

        $client->withBaseUrl('https://api.example.com');
        $client->sink($this->makeTempPath())->get('/archive.zip');

        $response = $client->get('/data');

        $this->assertArrayNotHasKey(CURLOPT_FILE, $this->getOptions($client));
        $this->assertSame(42, $response->data('value'));
    }

    /**
     * Test that retry configuration remains connection-scoped across requests.
     *
     * Retry is configuration like timeout(), not per-request state, so it must
     * survive flush() and apply to every request on the client.
     *
     * @return void
     */
    #[Test]
    public function retryConfigurationSurvivesFlush(): void
    {
        $client = FakeHttpClient::fake(['GET *' => 200]);
        $client->withBaseUrl('https://api.example.com')->retry(3, 1);

        $client->get('/first');

        $retry = new ReflectionProperty(HttpClient::class, 'retry');
        $this->assertSame(3, $retry->getValue($client));
    }
}
