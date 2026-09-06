<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use Simsoft\HttpClient\HttpClient;
use Simsoft\HttpClient\HttpPool;

/**
 * Concurrent transfers against a real HTTP server.
 *
 * HttpPool drives curl_multi, and several defects fixed in 2.3.0 lived there:
 * a hang when a handle was submitted twice, results returned out of order, and
 * sinks truncated when state was released too early. None of those are
 * observable without executing the transfers, which the unit suite never does.
 *
 * The PHP built-in server is single-process unless PHP_CLI_SERVER_WORKERS is
 * set, which is POSIX-only, so these tests assert on correctness — every
 * request completing, keyed to the right response — rather than on wall-clock
 * overlap, which would fail on Windows for reasons unrelated to the library.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class HttpPoolTransferTest extends IntegrationTestCase
{
    /**
     * Every request in a batch completes and is keyed by its index.
     *
     * @return void
     */
    #[Test]
    public function everyRequestInABatchCompletes(): void
    {
        $requests = [];
        for ($index = 0; $index < 5; $index++) {
            $requests[] = HttpClient::make()
                ->withQuery(['index' => (string)$index])
                ->to($this->serverUrl('/echo'));
        }

        $results = (new HttpPool())->send($requests);

        $this->assertCount(5, $results);

        foreach ($results->getResponses() as $key => $response) {
            $this->assertTrue($response->successful());
            $this->assertSame((string)$key, $response->json()['query']['index']);
        }
    }

    /**
     * Responses are keyed by the caller's keys, not by completion order.
     *
     * A batch mixing slow and fast endpoints completes out of submission
     * order; the association between key and response must survive that.
     *
     * @return void
     */
    #[Test]
    public function responsesKeepTheirKeysWhenCompletionIsOutOfOrder(): void
    {
        $requests = [
            'slow' => HttpClient::make()->to($this->serverUrl('/slow?ms=400')),
            'fast' => HttpClient::make()->to($this->serverUrl('/echo?tag=fast')),
            'medium' => HttpClient::make()->to($this->serverUrl('/slow?ms=150')),
        ];

        $results = (new HttpPool())->send($requests);

        $this->assertTrue($results->getResponse('slow')->json()['slept']);
        $this->assertSame('fast', $results->getResponse('fast')->json()['query']['tag']);
        $this->assertTrue($results->getResponse('medium')->json()['slept']);
    }

    /**
     * A batch larger than the concurrency limit drains completely.
     *
     * The window refills as transfers finish; a defect there stalls the pool
     * or drops the overflow.
     *
     * @return void
     */
    #[Test]
    public function aBatchLargerThanTheWindowDrains(): void
    {
        $requests = [];
        for ($index = 0; $index < 12; $index++) {
            $requests[] = HttpClient::make()
                ->withQuery(['index' => (string)$index])
                ->to($this->serverUrl('/echo'));
        }

        $results = (new HttpPool())->concurrency(3)->send($requests);

        $this->assertCount(12, $results);
        $this->assertCount(12, $results->getSuccessful());
        $this->assertCount(0, $results->getFailed());
    }

    /**
     * A failing request does not prevent the rest of the batch from completing.
     *
     * @return void
     */
    #[Test]
    public function oneFailureDoesNotSinkTheBatch(): void
    {
        $requests = [
            'ok' => HttpClient::make()->to($this->serverUrl('/echo')),
            'missing' => HttpClient::make()->to($this->serverUrl('/status/404')),
            'broken' => HttpClient::make()->to($this->serverUrl('/status/500')),
            'refused' => HttpClient::make()->connectionTimeout(2)->to('http://127.0.0.1:1/x'),
        ];

        $results = (new HttpPool())->send($requests);

        $this->assertCount(4, $results);
        $this->assertTrue($results->getResponse('ok')->successful());
        $this->assertTrue($results->getResponse('missing')->notFound());
        $this->assertTrue($results->getResponse('broken')->isServerError());
        $this->assertTrue($results->getResponse('refused')->isNetworkError());
    }

    /**
     * The response callback fires once per successful transfer.
     *
     * @return void
     */
    #[Test]
    public function theResponseCallbackFiresPerTransfer(): void
    {
        $seen = [];

        $requests = [];
        for ($index = 0; $index < 4; $index++) {
            $requests[] = HttpClient::make()->to($this->serverUrl('/echo'));
        }

        (new HttpPool())
            ->onResponse(function ($response) use (&$seen): void {
                $seen[] = $response->getStatusCode();
            })
            ->send($requests);

        $this->assertCount(4, $seen);
        $this->assertSame([200, 200, 200, 200], $seen);
    }

    /**
     * The error callback fires for a transfer that never reaches a server.
     *
     * @return void
     */
    #[Test]
    public function theErrorCallbackFiresForANetworkFailure(): void
    {
        $errors = 0;

        (new HttpPool())
            ->onError(function () use (&$errors): void {
                $errors++;
            })
            ->send([
                HttpClient::make()->connectionTimeout(2)->to('http://127.0.0.1:1/a'),
                HttpClient::make()->to($this->serverUrl('/echo')),
            ]);

        $this->assertSame(1, $errors);
    }

    /**
     * Each pooled request writes its own body to its own sink.
     *
     * Sink ownership and per-request state release were both defect sites; a
     * regression shows up as an empty, truncated or cross-written file.
     *
     * @return void
     */
    #[Test]
    public function pooledSinksReceiveTheirOwnBodies(): void
    {
        $paths = [];
        $requests = [];

        foreach ([256, 1024, 4096] as $index => $size) {
            $path = tempnam(sys_get_temp_dir(), 'pool-sink-');
            $paths[$index] = $path;

            $requests[$index] = HttpClient::make()
                ->sink($path)
                ->to($this->serverUrl('/download/' . $size));
        }

        (new HttpPool())->send($requests);

        $this->assertSame(256, filesize($paths[0]));
        $this->assertSame(1024, filesize($paths[1]));
        $this->assertSame(4096, filesize($paths[2]));

        foreach ($paths as $path) {
            $this->assertSame(str_repeat('a', (int)filesize($path)), file_get_contents($path));
            unlink($path);
        }
    }

    /**
     * A pooled retry recovers without reusing an already-attached handle.
     *
     * Attaching one handle to a curl_multi twice is what previously hung the
     * pool, and a retry is the path that does it.
     *
     * @return void
     */
    #[Test]
    public function aPooledRetryRecovers(): void
    {
        $token = 'pool-retry-' . bin2hex(random_bytes(6));

        $results = (new HttpPool())
            ->retries(4)
            ->send([
                HttpClient::make()->to($this->serverUrl('/flaky?failures=2&token=' . $token)),
            ]);

        $response = $results->getResponse(0);

        $this->assertTrue($response->successful());
        $this->assertSame(3, $response->json()['attempt']);
    }

    /**
     * A client submitted twice in one batch does not deadlock the pool.
     *
     * @return void
     */
    #[Test]
    public function theSameClientTwiceDoesNotDeadlock(): void
    {
        $client = HttpClient::make()->to($this->serverUrl('/echo'));

        $results = (new HttpPool())->send([$client, $client]);

        $this->assertCount(2, $results);
        $this->assertTrue($results->getResponse(0)->successful());
        $this->assertTrue($results->getResponse(1)->successful());
    }

    /**
     * Verbs survive a pool round trip rather than arriving as POST.
     *
     * @return void
     */
    #[Test]
    public function pooledVerbsReachTheServerUnchanged(): void
    {
        $base = $this->serverBase();

        $results = (new HttpPool())->send([
            'put' => HttpClient::make()->withJson(['a' => 1])->withMethod('PUT')->to($base . '/echo'),
            'patch' => HttpClient::make()->withJson(['a' => 1])->withMethod('PATCH')->to($base . '/echo'),
            'delete' => HttpClient::make()->withMethod('DELETE')->to($base . '/echo'),
        ]);

        $this->assertSame('PUT', $results->getResponse('put')->json()['method']);
        $this->assertSame('PATCH', $results->getResponse('patch')->json()['method']);
        $this->assertSame('DELETE', $results->getResponse('delete')->json()['method']);
    }
}
