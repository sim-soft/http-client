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
 * concurrency limits, per-response callbacks, retries, and rate limiting.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class HttpPool
{
    /** @var int Maximum concurrent requests. */
    private int $concurrency;

    /** @var int Per-request timeout in seconds (0 = no timeout). */
    private int $timeout = 0;

    /** @var int Number of retry attempts for failed requests. */
    private int $retries = 0;

    /** @var int Delay between retry attempts in milliseconds. */
    private int $retryDelayMs = 0;

    /** @var int Delay between requests in milliseconds (rate limiting). */
    private int $delayMs = 0;

    /** @var Closure|null Per-response callback: fn(Response, int|string): void */
    private ?Closure $onResponse = null;

    /** @var Closure|null Error callback: fn(Response, int|string): void */
    private ?Closure $onError = null;

    /** @var Closure|null Progress callback: fn(int $completed, int $total): void */
    private ?Closure $onProgress = null;

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
     * Set the per-request timeout in seconds.
     *
     * Each request in the pool will be aborted if it exceeds this duration.
     * Set to 0 to disable (default).
     *
     * @param int $seconds Timeout in seconds.
     *
     * @return $this
     *
     * @throws InvalidArgumentException When timeout is negative.
     */
    public function timeout(int $seconds): self
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException(
                'Timeout must be non-negative, got ' . $seconds . '.'
            );
        }

        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Set the number of retry attempts for failed requests.
     *
     * Failed requests (network errors or HTTP 5xx) will be retried up to
     * this many times before being marked as failed in the result.
     *
     * @param int $attempts Number of retry attempts (0 = no retries).
     * @param int $after Delay in milliseconds between retry attempts (default: 0).
     *
     * @return $this
     *
     * @throws InvalidArgumentException When attempts or delay is negative.
     */
    public function retries(int $attempts, int $after = 0): self
    {
        if ($attempts < 0) {
            throw new InvalidArgumentException(
                'Retries must be non-negative, got ' . $attempts . '.'
            );
        }

        if ($after < 0) {
            throw new InvalidArgumentException(
                'Retry delay must be non-negative, got ' . $after . '.'
            );
        }

        $this->retries = $attempts;
        $this->retryDelayMs = $after;

        return $this;
    }

    /**
     * Set a delay between requests for rate limiting.
     *
     * Adds a pause (in milliseconds) after each request completes before
     * the next one starts. Useful for respecting API rate limits.
     *
     * @param int $milliseconds Delay in milliseconds between requests.
     *
     * @return $this
     *
     * @throws InvalidArgumentException When delay is negative.
     */
    public function delay(int $milliseconds): self
    {
        if ($milliseconds < 0) {
            throw new InvalidArgumentException(
                'Delay must be non-negative, got ' . $milliseconds . '.'
            );
        }

        $this->delayMs = $milliseconds;

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
     * Register a progress callback.
     *
     * The callback is invoked after each request completes with the current
     * count of completed requests and the total number of requests.
     *
     * @param Closure $callback Callback receiving (int $completed, int $total): void.
     *
     * @return $this
     */
    public function onProgress(Closure $callback): self
    {
        $this->onProgress = $callback;

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
     * Calls request() on each FakeHttpClient to simulate concurrency behavior
     * while using the fake response mechanism. Supports retries and progress.
     *
     * @param array<int|string, FakeHttpClient> $clients The fake clients to execute.
     *
     * @return HttpPoolResult The pool result with all responses.
     * @throws Exception
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function executeFakeClients(array $clients): HttpPoolResult
    {
        $responses = [];
        $total = count($clients);
        $completed = 0;

        foreach ($clients as $index => $client) {
            $response = $this->executeWithRetries($client);

            $responses[$index] = $response;

            $this->invokeOnResponse($response, $index);
            $this->invokeOnError($response, $index);

            $completed++;
            $this->invokeOnProgress($completed, $total);

            $this->applyDelay();
        }

        return new HttpPoolResult($responses);
    }

    /**
     * Execute a single FakeHttpClient with retry support.
     *
     * @param FakeHttpClient $client The fake client to execute.
     *
     * @return Response The final response after retries.
     * @throws Exception
     */
    private function executeWithRetries(FakeHttpClient $client): Response
    {
        $maxAttempts = $this->retries + 1;
        $response = $client->request();

        for ($attempt = 1; $attempt < $maxAttempts && $this->shouldRetryResponse($response); $attempt++) {
            $this->applyRetryDelay();
            $response = $client->request();
        }

        return $response;
    }

    /**
     * Determine if a response should be retried.
     *
     * Retries on network errors (errno > 0) or server errors (5xx).
     *
     * @param Response $response The response to evaluate.
     *
     * @return bool True if the request should be retried.
     */
    private function shouldRetryResponse(Response $response): bool
    {
        if ($response->getErrno() > 0) {
            return true;
        }

        return $response->isServerError();
    }

    /**
     * Execute requests concurrently using curl_multi_* functions.
     *
     * Implements a sliding window approach: adds handles up to the concurrency
     * limit, polls for completion, replaces completed handles with pending ones,
     * and continues until all requests are processed. Supports per-request
     * timeout, retries, rate limiting, and progress tracking.
     *
     * @param array<int|string, HttpClient> $clients The clients to execute concurrently.
     *
     * @return HttpPoolResult The pool result with all responses.
     *
     * @throws RuntimeException When curl_multi_init() fails.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
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

        /** @var array<int|string, int> $retryCounts */
        $retryCounts = [];

        $total = count($clients);
        $completed = 0;
        $pendingIndices = array_keys($clients);
        $activeCount = 0;

        // Fill the initial window
        while ($activeCount < $this->concurrency && $pendingIndices !== []) {
            $index = array_shift($pendingIndices);
            $this->addHandleToMulti($multiHandle, $clients[$index], $index, $headerBuffers, $handleToIndex);
            $retryCounts[$index] = 0;
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

                $response = $this->buildResponseFromHandle($handle, $index, $headerBuffers, $clients);

                curl_multi_remove_handle($multiHandle, $handle);
                curl_close($handle);
                $handleToIndex->detach($handle);
                $activeCount--;

                // Retry logic
                if ($this->shouldRetryResponse($response) && $retryCounts[$index] < $this->retries) {
                    $retryCounts[$index]++;
                    $this->applyRetryDelay();
                    $headerBuffers[$index] = '';
                    $this->addHandleToMulti(
                        $multiHandle,
                        $clients[$index],
                        $index,
                        $headerBuffers,
                        $handleToIndex
                    );
                    $activeCount++;
                    continue;
                }

                $responses[$index] = $response;

                $this->invokeOnResponse($response, $index);
                $this->invokeOnError($response, $index);

                $completed++;
                $this->invokeOnProgress($completed, $total);

                $this->applyDelay();

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
                    $retryCounts[$nextIndex] = 0;
                    $activeCount++;
                }
            }

            // Wait for activity if handles are still running
            if ($running > 0) {
                curl_multi_select($multiHandle, 1.0);
            } elseif ($activeCount > 0) {
                // Handles were just added (e.g., retry) — re-exec immediately
                curl_multi_exec($multiHandle, $running);
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
     * capture and timeout, and adds it to the multi handle for concurrent execution.
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
        CurlMultiHandle $multiHandle,
        HttpClient      $client,
        int|string      $index,
        array           &$headerBuffers,
        SplObjectStorage $handleToIndex
    ): void
    {
        $handle = $client->buildHandle();

        if ($this->timeout > 0) {
            curl_setopt($handle, CURLOPT_TIMEOUT, $this->timeout);
        }

        $headerBuffers[$index] = '';
        curl_setopt($handle, CURLOPT_HEADERFUNCTION, static function ($curlHandle, $header) use ($index, &$headerBuffers) {
            unset($curlHandle); // required by cURL callback signature
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
     * @param array<int|string, HttpClient> $clients The original clients array.
     *
     * @return Response The constructed response.
     */
    private function buildResponseFromHandle(
        CurlHandle $handle,
        int|string $index,
        array $headerBuffers,
        array $clients = [],
    ): Response
    {
        $curlInfo = curl_getinfo($handle);
        $body = (string)curl_multi_getcontent($handle);
        $curlError = curl_error($handle);
        $curlErrno = curl_errno($handle);

        $error = $curlError ?: ($curlInfo['http_code'] >= 400 ? 'HTTP Error' : '');
        $rawHeaders = $headerBuffers[$index] ?? '';

        $sinkPath = null;
        if (isset($clients[$index])) {
            $sinkPath = $clients[$index]->getPoolSinkPath();
        }

        return new Response(
            curlInfo: $curlInfo,
            body: $body,
            message: $error,
            sinkPath: $sinkPath,
            errno: $curlErrno,
            rawHeaders: $rawHeaders,
        );
    }

    /**
     * Apply rate limiting delay between requests.
     *
     * @return void
     */
    private function applyDelay(): void
    {
        if ($this->delayMs <= 0) {
            return;
        }

        usleep($this->delayMs * 1000);
    }

    /**
     * Apply delay between retry attempts.
     *
     * @return void
     */
    private function applyRetryDelay(): void
    {
        if ($this->retryDelayMs <= 0) {
            return;
        }

        usleep($this->retryDelayMs * 1000);
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

    /**
     * Invoke the onProgress callback if configured.
     *
     * @param int $completed Number of completed requests.
     * @param int $total Total number of requests.
     *
     * @return void
     */
    private function invokeOnProgress(int $completed, int $total): void
    {
        if ($this->onProgress === null) {
            return;
        }

        ($this->onProgress)($completed, $total);
    }
}
