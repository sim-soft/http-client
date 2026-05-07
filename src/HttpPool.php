<?php

namespace Simsoft\HttpClient;

use Closure;
use CurlHandle;
use CurlMultiHandle;
use Exception;
use InvalidArgumentException;
use RuntimeException;
use Simsoft\HttpClient\Testing\FakeHttpClient;
use SplObjectStorage;

/**
 * HttpPool class.
 *
 * A companion class for concurrent HTTP request execution via curl_multi_*.
 * Accepts an array of pre-configured HttpClient instances or closures returning
 * HttpClient instances and executes them concurrently with configurable
 * concurrency limits and per-response callbacks.
 */
class HttpPool
{
    /** @var int Maximum concurrent requests. */
    private int $concurrency;

    /** @var Closure|null Per-response callback: fn(Response, int|string): void */
    private ?Closure $onResponse = null;

    /** @var Closure|null Error callback: fn(Response, int|string): void */
    private ?Closure $onError = null;

    /**
     * Static factory method.
     *
     * @param int $concurrency Maximum concurrent requests (default: 25).
     *
     * @return self
     *
     * @throws InvalidArgumentException When concurrency is less than 1.
     */
    public static function create(int $concurrency = 25): self
    {
        return new self($concurrency);
    }

    /**
     * Execute a pool using a closure-based builder.
     *
     * The closure receives a PoolBuilder instance and should return an array
     * of HttpClient instances (built via $pool->get(), $pool->post(), etc.).
     *
     * @param Closure(PoolBuilder): array<int|string, HttpClient> $callback Builder closure.
     * @param int $concurrency Maximum concurrent requests (default: 25).
     *
     * @return HttpPoolResult
     *
     * @throws InvalidArgumentException When entries are not HttpClient instances.
     * @throws RuntimeException When curl_multi_init() fails.
     * @throws Exception
     */
    public static function run(Closure $callback, int $concurrency = 25): HttpPoolResult
    {
        $builder = new PoolBuilder();
        $requests = $callback($builder);

        return self::create($concurrency)->send($requests);
    }

    /**
     * Create a new HttpPool instance.
     *
     * @param int $concurrency Maximum concurrent requests (default: 25).
     *
     * @throws InvalidArgumentException When concurrency is less than 1.
     */
    public function __construct(int $concurrency = 25)
    {
        $this->concurrency($concurrency);
    }

    /**
     * Set the concurrency limit.
     *
     * @param int $limit Maximum simultaneous requests.
     *
     * @return $this
     *
     * @throws InvalidArgumentException When the limit is less than 1.
     */
    public function concurrency(int $limit): self
    {
        if ($limit < 1) {
            throw new InvalidArgumentException(
                'Concurrency limit must be at least 1, got ' . $limit . '.'
            );
        }

        $this->concurrency = $limit;

        return $this;
    }

    /**
     * Register a per-response callback.
     *
     * The callback is invoked with the Response object and its key
     * as each request completes.
     *
     * @param Closure $callback Callback receiving (Response, int|string): void.
     *
     * @return $this
     */
    public function onResponse(Closure $callback): self
    {
        $this->onResponse = $callback;

        return $this;
    }

    /**
     * Register an error callback.
     *
     * The callback is invoked for each request that fails with a network
     * error or HTTP error status.
     *
     * @param Closure $callback Callback receiving (Response, int|string): void.
     *
     * @return $this
     */
    public function onError(Closure $callback): self
    {
        $this->onError = $callback;

        return $this;
    }

    /**
     * Execute all requests concurrently.
     *
     * Resolves closures to HttpClient instances, validates all entries,
     * then dispatches to the appropriate execution strategy based on
     * whether clients are FakeHttpClient (direct execution) or real
     * HttpClient (curl_multi concurrent execution).
     *
     * @param array<int|string, HttpClient|Closure> $requests Array of HttpClient instances or closures returning HttpClient.
     *
     * @return HttpPoolResult
     *
     * @throws InvalidArgumentException When entries are not HttpClient or Closure returning HttpClient.
     * @throws RuntimeException When curl_multi_init() fails.
     * @throws Exception
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function send(array $requests): HttpPoolResult
    {
        if ($requests === []) {
            return new HttpPoolResult([]);
        }

        $clients = $this->resolveClients($requests);
        $this->validateClients($clients);

        if ($this->allFakeClients($clients)) {
            return $this->executeFakeClients($clients);
        }

        return $this->executeWithCurlMulti($clients);
    }

    /**
     * Resolve closures in the requests array to HttpClient instances.
     *
     * @param array<int|string, HttpClient|Closure> $requests The raw requests array.
     *
     * @return array<int|string, HttpClient|mixed> Resolved array with closures replaced by their return values.
     */
    private function resolveClients(array $requests): array
    {
        return array_map(function ($entry) {
            return $entry instanceof Closure ? $entry() : $entry;
        }, $requests);
    }

    /**
     * Validate that all entries are HttpClient instances.
     *
     * @param array<int|string, mixed> $clients The resolved clients array.
     *
     * @return void
     *
     * @throws InvalidArgumentException When an entry is not an HttpClient instance.
     */
    private function validateClients(array $clients): void
    {
        foreach ($clients as $index => $client) {
            if (!$client instanceof HttpClient) {
                throw new InvalidArgumentException(
                    "Entry at index $index must be an HttpClient instance or a Closure returning HttpClient."
                );
            }
        }
    }

    /**
     * Check if all clients are FakeHttpClient instances.
     *
     * @param array<int|string, HttpClient> $clients The resolved clients.
     *
     * @return bool True if all clients are FakeHttpClient instances.
     */
    private function allFakeClients(array $clients): bool
    {
        foreach ($clients as $client) {
            if (!$client instanceof FakeHttpClient) {
                return false;
            }
        }

        return true;
    }

    /**
     * Execute FakeHttpClient instances directly without curl_multi.
     *
     * Calls request() on each FakeHttpClient in a sliding window pattern
     * to simulate concurrency behavior while using the fake response mechanism.
     *
     * @param array<int|string, FakeHttpClient> $clients The fake clients to execute.
     *
     * @return HttpPoolResult The pool result with all responses.
     * @throws Exception
     */
    private function executeFakeClients(array $clients): HttpPoolResult
    {
        $responses = [];

        foreach ($clients as $index => $client) {
            $response = $client->request();

            $responses[$index] = $response;

            $this->invokeOnResponse($response, $index);
            $this->invokeOnError($response, $index);
        }

        return new HttpPoolResult($responses);
    }

    /**
     * Execute requests concurrently using curl_multi_* functions.
     *
     * Implements a sliding window approach: adds handles up to the concurrency
     * limit, polls for completion, replaces completed handles with pending ones,
     * and continues until all requests are processed.
     *
     * @param array<int|string, HttpClient> $clients The clients to execute concurrently.
     *
     * @return HttpPoolResult The pool result with all responses.
     *
     * @throws RuntimeException When curl_multi_init() fails.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function executeWithCurlMulti(array $clients): HttpPoolResult
    {
        $multiHandle = curl_multi_init();
        curl_multi_setopt($multiHandle, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX);

        /** @var array<int|string, Response> $responses */
        $responses = [];

        /** @var array<int|string, string> $headerBuffers */
        $headerBuffers = [];

        /** @var SplObjectStorage<CurlHandle, int|string> $handleToIndex */
        $handleToIndex = new SplObjectStorage();

        $pendingIndices = array_keys($clients);
        $activeCount = 0;

        // Fill the initial window
        while ($activeCount < $this->concurrency && $pendingIndices !== []) {
            $index = array_shift($pendingIndices);
            $this->addHandleToMulti($multiHandle, $clients[$index], $index, $headerBuffers, $handleToIndex);
            $activeCount++;
        }

        // Poll loop
        $running = 0;
        do {
            curl_multi_exec($multiHandle, $running);

            // Process completed handles
            while ($info = curl_multi_info_read($multiHandle)) {
                if ($info['msg'] !== CURLMSG_DONE) {
                    continue;
                }

                /** @var CurlHandle $handle */
                $handle = $info['handle'];
                $index = $handleToIndex[$handle];

                $response = $this->buildResponseFromHandle($handle, $index, $headerBuffers);
                $responses[$index] = $response;

                $this->invokeOnResponse($response, $index);
                $this->invokeOnError($response, $index);

                curl_multi_remove_handle($multiHandle, $handle);
                curl_close($handle);
                $handleToIndex->detach($handle);
                $activeCount--;

                // Add the next pending request to fill the window
                if ($pendingIndices !== []) {
                    $nextIndex = array_shift($pendingIndices);
                    $this->addHandleToMulti(
                        $multiHandle,
                        $clients[$nextIndex],
                        $nextIndex,
                        $headerBuffers,
                        $handleToIndex
                    );
                    $activeCount++;
                }
            }

            // Wait for activity if handles are still running
            if ($running > 0) {
                curl_multi_select($multiHandle, 1.0);
            }
        } while ($running > 0 || $activeCount > 0);

        curl_multi_close($multiHandle);

        ksort($responses);

        return new HttpPoolResult($responses);
    }

    /**
     * Add a client's handle to the multi handle.
     *
     * Builds the curl handle from the client, sets up per-handle header
     * capture, and adds it to the multi handle for concurrent execution.
     *
     * @param CurlMultiHandle $multiHandle The curl multi handle.
     * @param HttpClient $client The client to add.
     * @param int|string $index The original key of this request.
     * @param array<int|string, string> $headerBuffers Reference to a header buffers array.
     * @param SplObjectStorage<CurlHandle, int|string> $handleToIndex Map of a handle to key.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function addHandleToMulti(
        CurlMultiHandle  $multiHandle,
        HttpClient       $client,
        int|string       $index,
        array            &$headerBuffers,
        SplObjectStorage $handleToIndex
    ): void
    {
        $handle = $client->buildHandle();

        $headerBuffers[$index] = '';
        curl_setopt($handle, CURLOPT_HEADERFUNCTION, function ($curlHandle, $header) use ($index, &$headerBuffers) {
            $headerBuffers[$index] .= $header;
            return strlen($header);
        });

        $handleToIndex->attach($handle, $index);
        curl_multi_add_handle($multiHandle, $handle);
    }

    /**
     * Build a Response object from a completed curl handle.
     *
     * Extracts curl info, body, error details, and captured headers
     * to construct a Response object matching the standard HttpClient behavior.
     *
     * @param CurlHandle $handle The completed curl handle.
     * @param int|string $index The original request key.
     * @param array<int|string, string> $headerBuffers The header buffers.
     *
     * @return Response The constructed response.
     */
    private function buildResponseFromHandle(
        CurlHandle $handle,
        int|string $index,
        array      $headerBuffers,
    ): Response
    {
        $curlInfo = curl_getinfo($handle);
        $body = (string)curl_multi_getcontent($handle);
        $curlError = curl_error($handle);
        $curlErrno = curl_errno($handle);

        $error = $curlError ?: ($curlInfo['http_code'] >= 400 ? 'HTTP Error' : '');
        $rawHeaders = $headerBuffers[$index] ?? '';

        return new Response(
            curlInfo: $curlInfo,
            body: $body,
            message: $error,
            errno: $curlErrno,
            rawHeaders: $rawHeaders,
        );
    }

    /**
     * Invoke the onResponse callback if configured.
     *
     * @param Response $response The completed response.
     * @param int|string $index The original request key.
     *
     * @return void
     */
    private function invokeOnResponse(Response $response, int|string $index): void
    {
        if ($this->onResponse === null) {
            return;
        }

        ($this->onResponse)($response, $index);
    }

    /**
     * Invoke the onError callback if the response indicates failure.
     *
     * @param Response $response The completed response.
     * @param int|string $index The original request key.
     *
     * @return void
     */
    private function invokeOnError(Response $response, int|string $index): void
    {
        if ($this->onError === null) {
            return;
        }

        if (!$response->failed()) {
            return;
        }

        ($this->onError)($response, $index);
    }
}
