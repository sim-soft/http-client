<?php

namespace Simsoft\HttpClient;

use Closure;
use CurlHandle;
use Exception;
use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Simsoft\HttpClient\Exceptions\NetworkException;
use Simsoft\HttpClient\Exceptions\RequestException;
use Simsoft\HttpClient\Traits\AttachmentTrait;
use Simsoft\HttpClient\Traits\CurlOptionsTrait;
use Simsoft\HttpClient\Traits\DebugTrait;
use Simsoft\HttpClient\Traits\DeprecatedTrait;
use Simsoft\HttpClient\Traits\Macroable;
use Simsoft\HttpClient\Traits\PrepareHandleTrait;
use Simsoft\HttpClient\Traits\RequestBodyTrait;
use Simsoft\HttpClient\Traits\RetryTrait;
use Simsoft\HttpClient\Traits\SinkTrait;
use Throwable;

/**
 * Request class.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Trait methods are counted toward the class total.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Coupling is inherent to PSR-18 compliance and trait composition.
 */
class HttpClient implements ClientInterface
{
    use Macroable, DeprecatedTrait, DebugTrait, CurlOptionsTrait, PrepareHandleTrait, RetryTrait, RequestBodyTrait, AttachmentTrait, SinkTrait;

    /** @var bool Determine to throw an exception on error. */
    protected bool $throwOnError = false;

    /** @var string User-agent. */
    protected string $userAgent = 'SimsoftHttpClient/2.0';

    /** @var string Target base URL. */
    protected string $baseUrl = '';

    /** @var string Resource URL. */
    protected string $pendingUrl = '';

    /** @var string  */
    protected string $method = 'GET';

    /** @var array<string, mixed> Headers. */
    protected array $headers = [];

    /** @var array<array-key, mixed>|null Cached formatted headers for cURL. */
    protected ?array $formattedHeaders = null;

    /** @var array<string|int, mixed> Query params. */
    protected array $queryParams = [];

    /** @var string The response class to be used. */
    protected string $responseClass = Response::class;

    /** @var array<array-key, Closure(self, Closure): Response> Middleware stack (in reverse order)> */
    protected array $middleware = [];

    /** @var LoggerInterface|null Logger instance. */
    protected ?LoggerInterface $logger = null;

    const TYPE_JSON = 'json';
    const TYPE_FORM = 'form';
    const TYPE_MULTIPART = 'multipart';
    const TYPE_RAW = 'raw';

    /**
     * Factory method.
     *
     * @return self
     */
    public static function make(): self
    {
        return new self();
    }

    /**
     * Flush temporary request data and resources.
     *
     * @return void
     */
    private function flush(): void
    {
        $this->pendingUrl = '';
        $this->method = 'GET';
        $this->contentType = null;
        $this->queryParams = [];
        $this->headers = [];
        $this->hasAttachments = false;
        $this->formattedHeaders = null;

        if ($this->postFields instanceof StreamInterface && $this->postFieldsOwned) {
            $this->postFields->close();
        }

        if (!$this->postFields instanceof StreamInterface && is_resource($this->postFields)) {
            fclose($this->postFields);
        }

        $this->postFields = null;
        $this->postFieldsOwned = false;

        // Close and clear temporary files created for this request
        foreach ($this->tmpFiles as $tmp) {
            if (is_resource($tmp)) {
                fclose($tmp);
            }
        }
        $this->tmpFiles = [];

        if ($this->sinkOwned && is_resource($this->sink)) {
            fclose($this->sink);
            $this->sink = null;
        }
        $this->sinkOwned = false;
        $this->sinkPath = null;
        $this->resetDebug();
    }

    /**
     * Set request base Url.
     *
     * @param string $baseUrl
     * @return $this
     */
    public function withBaseUrl(string $baseUrl): self
    {
        $this->baseUrl = $baseUrl;
        return $this;
    }

    /**
     * Resource URL.
     *
     * This method is used to set the resource URL.
     *
     * @param string $url
     * @return $this
     */
    public function resource(string $url): self
    {
        $this->pendingUrl = $url;
        return $this;
    }

    /**
     * Get endpoint URL.
     *
     * @return string
     */
    public function getEndpoint(): string
    {
        return $this->baseUrl . $this->pendingUrl;
    }

    /**
     * Alias to resource().
     *
     * @param string $url
     * @return $this
     *
     * @SuppressWarnings(PHPMD.ShortMethodName)
     */
    public function to(string $url): self
    {
        return $this->resource($url);
    }

    /**
     * Set request method.
     *
     * @param string $method
     * @return $this
     */
    public function withMethod(string $method): self
    {
        unset($this->options[CURLOPT_POST], $this->options[CURLOPT_CUSTOMREQUEST]);
        $this->method = strtoupper($method);
        match ($this->method) {
            'GET' => null, // the default CURL method is GET, no setting needed.
            'POST' => $this->options[CURLOPT_POST] = true,
            default => $this->options[CURLOPT_CUSTOMREQUEST] = $this->method, //'PUT', 'PATCH', 'DELETE' or another non-standard verb.
        };
        return $this;
    }

    /**
     * Set headers.
     *
     * @param string[] $headers
     * @return $this
     */
    public function withHeaders(array $headers): self
    {
        foreach($headers as $name => $value) {
            $this->withHeader($name, $value);
        }
        return $this;
    }

    /**
     * Add header.
     *
     * @param string $name
     * @param string|array<string, mixed> $value
     * @return self
     */
    public function withHeader(string $name, string|array $value): self
    {
        $current = (array)($this->headers[$name] ?? []);
        $new = array_map(fn($val) => (string)$val, (array)$value);
        $this->headers[$name] = array_values(array_unique(array_merge($current, $new)));
        $this->formattedHeaders = null;
        return $this;
    }

    /**
     * Setup logger.
     *
     * @param LoggerInterface $logger
     * @return $this
     */
    public function withLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * Log event.
     *
     * @param Response $response
     * @return void
     */
    protected function log(Response $response): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->info('HTTP Request', [
            'method' => $this->method,
            'url' => $this->options[CURLOPT_URL] ?? $this->getEndpoint(),
            'status' => $response->getStatusCode(),
            'duration' => $response->getTotalTime(),
            'errno' => $response->getErrno(),
        ]);

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300 || $response->getErrno()) {
            $this->logger->error('HTTP Error', [
                'duration' => $response->getTotalTime(),
                'status' => $response->getStatusCode(),
                'errno' => $response->getErrno(),
                'error' => $response->getReasonPhrase(),
            ]);
        }
    }

    /**
     * Set a custom response class.
     *
     * @param string $class
     * @return $this
     */
    public function withResponseClass(string $class): static
    {
        if (!class_exists($class)) {
            throw new InvalidArgumentException("Response class is not exists: $class");
        }

        if (!is_subclass_of($class, Response::class) && $class !== Response::class) {
            throw new InvalidArgumentException("Class $class must extend " . Response::class);
        }

        $this->responseClass = $class;
        return $this;
    }

    /**
     * Add middleware
     *
     * @param Closure $middleware The middleware closure must return a Response object.
     * @param string|null $name Unique name for the middleware to avoid duplicate registration.
     * @return $this
     */
    public function withMiddleware(Closure $middleware, ?string $name = null): self
    {
        $key = $name ?? spl_object_hash($middleware);
        $this->middleware[$key] = $middleware;
        return $this;
    }

    /**
     * Set bearer token.
     *
     * @param string $token
     * @return $this
     */
    public function withBearerToken(string $token): self
    {
        $this->headers['authorization'] = ["Bearer $token"]; // Force single value
        return $this;
    }

    /**
     * Prepare query params.
     *
     * @param array<string|int, mixed> $params
     * @return $this
     */
    public function withQuery(array $params): self
    {
        $this->queryParams = array_merge($this->queryParams, $params);
        return $this;
    }

    /**
     * Perform GET request.
     *
     * @param string $uri
     * @param array<string, mixed> $data
     * @return Response
     * @throws Exception|Throwable
     */
    public function get(string $uri = '', array $data = []): Response
    {
        return $this->send('GET', $uri, $data);
    }

    /**
     * Perform POST request.
     *
     * @param string $uri
     * @param mixed $data
     * @return Response
     * @throws Exception|Throwable
     */
    public function post(string $uri = '', mixed $data = null): Response
    {
        return $this->send('POST', $uri, $data);
    }

    /**
     * Perform PATCH request.
     *
     * @param string $uri
     * @param mixed $data
     * @return Response
     * @throws Exception|Throwable
     */
    public function patch(string $uri = '', mixed $data = null): Response
    {
        return $this->send('PATCH', $uri, $data);
    }

    /**
     * Perform PUT request.
     *
     * @param string $uri
     * @param mixed $data
     * @return Response
     * @throws Exception|Throwable
     */
    public function put(string $uri = '', mixed $data = null): Response
    {
        return $this->send('PUT', $uri, $data);
    }

    /**
     * Perform DELETE request.
     *
     * @param string $uri
     * @param mixed $data
     * @return Response
     * @throws Exception|Throwable
     */
    public function delete(string $uri = '', mixed $data = null): Response
    {
        return $this->send('DELETE', $uri, $data);
    }

    /**
     * PSR-18: Send a PSR-7 request object.
     *
     * @throws \Psr\Http\Client\NetworkExceptionInterface
     * @throws \Psr\Http\Client\RequestExceptionInterface
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $previousThrowOnError = $this->throwOnError;
        $this->throwOnError = false;

        // Translate PSR-7 request into internal state
        $uri = $request->getUri();

        $base = $uri->getScheme() . '://' . $uri->getAuthority();
        $path = $uri->getPath();

        $this->withBaseUrl($base);
        $this->resource($path);

        if ($uri->getQuery() !== '') {
            $queryParams = [];
            parse_str($uri->getQuery(), $queryParams);
            if ($queryParams !== []) {
                $this->withQuery($queryParams);
            }
        }

        foreach ($request->getHeaders() as $name => $values) {
            $this->withHeader($name, $values);
        }

        $body = $request->getBody();
        if ($body->getSize() !== 0) {
            $contentType = $request->getHeaderLine('Content-Type') ?: null;
            $this->withBody($body, $contentType);
        }

        try {
            return $this
                ->withMethod($request->getMethod())
                ->request();
        } catch (Throwable $throwable) {
            if ($throwable instanceof Exception && str_contains($throwable->getMessage(), 'HTTP')) {
                throw new RequestException($request, $throwable->getMessage(), $throwable->getCode(), $throwable);
            }
            throw new NetworkException($request, $throwable->getMessage(), $throwable->getCode(), $throwable);
        } finally {
            $this->throwOnError = $previousThrowOnError;
        }
    }

    /**
     * Send request.
     *
     * @param string $method
     * @param string $url
     * @param mixed $data
     * @return Response
     * @throws InvalidArgumentException
     * @throws RuntimeException
     * @throws Throwable
     */
    public function send(string $method, string $url, mixed $data = null): Response
    {
        try {
            if ($data) {
                match ($this->contentType) {
                    static::TYPE_JSON => $this->withJson($data),
                    static::TYPE_FORM => $this->withForm($data),
                    static::TYPE_MULTIPART => $this->withMultipart($data),
                    default => match (true) {
                        is_string($data) => $this->withBody($data, 'text/plain'),
                        is_array($data) => strtoupper($method) === 'GET' ? $this->withQuery($data) : $this->withMultipart($data),
                        default => throw new InvalidArgumentException('Unsupported data type: ' . get_debug_type($data)),
                    },
                };
            }

        } catch (Throwable $throwable) {
            $this->flush();
            throw $throwable;
        }

        return $this
            ->withMethod($method)
            ->resource($url)
            ->request();
    }

    /**
     * Build formatted headers.
     *
     * @param array<string, mixed> $headers
     * @return array<array-key, mixed>
     */
    protected function buildFormattedHeaders(array $headers): array
    {
        $formatted = [];
        foreach ($headers as $key => $values) {
            $normalized = ucwords(strtolower($key), '-');
            $values = (array)$values;
            // Set-Cookie is the only header that should stay as multiple lines
            if (strtolower($key) === 'set-cookie') {
                foreach ($values as $value) {
                    $formatted[] = "$normalized: $value";
                }
                continue;
            }
            $formatted[] = "$normalized: " . implode(', ', $values);
        }
        return $formatted;
    }

    /**
     * Perform send request.
     *
     * @return Response
     * @throws Exception
     */
    public function request(): Response
    {
        try {
            $core = $this->getCoreHandler();
            $stack = array_values($this->middleware);

            $pipeline = function (int $index) use (&$pipeline, $stack, $core) {
                if (!isset($stack[$index])) {
                    return $core();
                }

                $response = ($stack[$index])($this, fn(): Response => $pipeline($index + 1));

                if (!$response instanceof Response) {
                    throw new RuntimeException("Middleware Closure must return an instance of " . Response::class);
                }

                return $response;
            };

            return $pipeline(0);
        } finally {
            $this->flush();
        }
    }

    /**
     * Creates a closure that handles the core HTTP request logic, including retries, error handling,
     * and response generation.
     *
     * @return Closure A closure encapsulating the HTTP request execution and response handling.
     */
    protected function getCoreHandler(): Closure
    {
        $requestId = uniqid('httpclient_req_', true);

        return function () use ($requestId): ResponseInterface {

            $maxAttempts = $this->retry + 1;
            $attempts = 1;

            do {
                $this->prepareRetryState($attempts);

                $curl = $this->prepareHandle($requestId);
                $this->debugDump();

                $rawHeaders = '';
                $this->setupHeaderCapture($curl, $rawHeaders);
                $response = $this->executeCurlRequest($curl, $rawHeaders);

                $this->log($response);
                $this->throwIfFailed($response);

                if (!$this->shouldRetry($response, $attempts) || ++$attempts > $maxAttempts) {
                    return $response;
                }

                $this->wait();
            } while (true);
        };
    }

    /**
     * Prepare state for a retry attempt (rewind streams and sink).
     *
     * @param int $attempts Current attempt number.
     * @return void
     */
    private function prepareRetryState(int $attempts): void
    {
        if ($this->postFields instanceof StreamInterface && $this->postFields->isSeekable()) {
            $this->postFields->rewind();
        }

        if ($attempts <= 1) {
            return;
        }

        if (!is_resource($this->sink)) {
            return;
        }

        if (!stream_get_meta_data($this->sink)['seekable']) {
            return;
        }

        rewind($this->sink);
        ftruncate($this->sink, 0);
    }

    /**
     * Set up the cURL header capture callback.
     *
     * @param CurlHandle $curl The cURL handle to configure.
     * @param string $rawHeaders Reference to the string that will accumulate headers.
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function setupHeaderCapture(CurlHandle $curl, string &$rawHeaders): void
    {
        curl_setopt($curl, CURLOPT_HEADERFUNCTION, function ($curlHandle, $header) use (&$rawHeaders) {
            $rawHeaders .= $header;
            return strlen($header);
        });
    }

    /**
     * Execute the cURL request and build the Response object.
     *
     * @param CurlHandle $curl The prepared cURL handle.
     * @param string $rawHeaders The raw headers string (captured by reference via header callback).
     * @return Response The constructed response object.
     */
    private function executeCurlRequest(CurlHandle $curl, string &$rawHeaders): Response
    {
        $curlRaw = curl_exec($curl);
        $curlInfo = curl_getinfo($curl);
        $curlError = curl_error($curl);
        $curlErrorNo = curl_errno($curl);

        $body = ($this->sink === null && is_string($curlRaw)) ? $curlRaw : '';
        $error = $curlError ?: ($curlInfo['http_code'] >= 400 ? 'HTTP Error' : '');

        /** @var Response $response */
        $response = new $this->responseClass(
            $curlInfo,
            $body,
            $error,
            $this->sinkPath,
            $curlErrorNo,
            $rawHeaders
        );

        return $response;
    }

    /**
     * Throw an exception if throwOnError is enabled and the response indicates failure.
     *
     * @param Response $response The response to check.
     * @return void
     * @throws Exception When throwOnError is enabled and the response failed.
     */
    private function throwIfFailed(Response $response): void
    {
        if (!$this->throwOnError) {
            return;
        }

        if (!$response->getErrno() && !$response->failed()) {
            return;
        }

        throw new Exception(
            $response->getErrno()
                ? $response->getReasonPhrase()
                : "HTTP {$response->getStatusCode()}: {$response->getReasonPhrase()}"
        );
    }

    /**
     * Flatten a multi-dimensional array for multipart/form-data.
     *
     * @param array<string, mixed> $data
     * @param string|null $prefix
     * @return array<string, mixed>
     */
    protected function flattenMultipartData(array $data, ?string $prefix = null): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $name = $prefix ? "{$prefix}[$key]" : $key;

            if (is_array($value)) {
                $result = array_merge($result, $this->flattenMultipartData($value, $name));
                continue;
            }
            $result[$name] = $value;
        }
        return $result;
    }

    /**
     * Destructor to release resources.
     *
     * Ensures that temporary files and owned sink resources are properly closed
     * when the object is destroyed.
     *
     * @return void
     */
    public function __destruct()
    {
        if ($this->curlHandle instanceof CurlHandle) {
            curl_close($this->curlHandle);
            $this->curlHandle = null;
        }

        if ($this->postFields instanceof StreamInterface && $this->postFieldsOwned) {
            $this->postFields->close();
        }

        // Ensure resources are released if the object is destroyed mid-config
        foreach ($this->tmpFiles as $tmp) {
            if (is_resource($tmp)) {
                fclose($tmp);
            }
        }

        $this->tmpFiles = [];

        if ($this->sinkOwned && is_resource($this->sink)) {
            fclose($this->sink);
            $this->sink = null;
        }
    }
}
