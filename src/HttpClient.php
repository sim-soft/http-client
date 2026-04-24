<?php

namespace Simsoft\HttpClient;

use Closure;
use CURLFile;
use CurlHandle;
use Exception;
use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Simsoft\HttpClient\Exceptions\NetworkException;
use Simsoft\HttpClient\Exceptions\RequestException;
use Simsoft\HttpClient\Traits\DeprecatedTrait;
use Simsoft\HttpClient\Traits\Macroable;
use Throwable;

/**
 * Request class.
 */
class HttpClient implements ClientInterface
{
    use Macroable, DeprecatedTrait;

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

    /** @var mixed Post fields. */
    protected mixed $postFields = null;

    /** @var mixed|null Download destination. */
    protected mixed $sink = null; // string|resource|null

    /** @var string|null Download the destination path. */
    protected ?string $sinkPath = null;

    /** @var bool Determine the client owns the sink. */
    protected bool $sinkOwned = false;

    /** @var bool Determine if the client owns the postFields stream. */
    protected bool $postFieldsOwned = false;

    /** @var bool Determine to attach files. */
    protected bool $hasAttachments = false;

    /** @var resource[] Temporary files resource. For attachment. */
    protected array $tmpFiles = [];

    /** @var bool Enable debug mode. */
    protected bool $debugDump = false;

    /** @var bool Dumps and exits after prepareHandle(). */
    protected bool $debugDie = false;

    /** @var int Execution Timeout. (In seconds) */
    protected int $timeout = 30;

    /** @var int Establish Connection timeout. (In seconds) */
    protected int $connectionTimeout = 5;

    /** @var int DNS Cache Timeout. (In seconds) */
    protected int $dnsTimeout = 60; // Force DNS re-resolution every 60 seconds

    /** @var bool Determine to CURLOPT_RETURNTRANSFER. Default: true */
    protected bool $returnTransfer = true;

    /** @var int Total retry if the request failed. */
    protected int $retry = 0;

    /** @var int|null Retry after milliseconds. */
    protected ?int $retryAfter = null;

    /** @var Closure|null Custom retry decision callback */
    protected ?Closure $retryCallback = null;

    /** @var string|null Default content type. */
    protected ?string $contentType = null;

    /** @var int Buffer size (In bytes). Default: 8192 bytes. */
    protected int $bufferSize = 8192;

    /** @var string The response class to be used. */
    protected string $responseClass = Response::class;

    /** @var array<array-key, Closure(self, Closure): Response> Middleware stack (in reverse order)> */
    protected array $middleware = [];

    /** @var LoggerInterface|null Logger instance. */
    protected ?LoggerInterface $logger = null;

    /** @var CurlHandle|null */
    protected ?CurlHandle $curlHandle = null;

    /** @var array<int, mixed>  */
    protected array $options = [
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
    ];

    const type_json = 'json';
    const type_form = 'form';
    const type_multipart = 'multipart';
    const type_raw = 'raw';

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
        } elseif (is_resource($this->postFields)) {
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
        $this->debugDump = false;
        $this->debugDie = false;
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
        $new = array_map(fn($v) => (string)$v, (array)$value);
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
     * Set CURL option.
     *
     * @param array<int, mixed> $options
     * @return $this
     */
    public function withOptions(array $options): self
    {
        foreach ($options as $option => $value) {
            $this->options[$option] = $value;
        }
        return $this;
    }

    /**
     * Without verifying TLS certificates.
     *
     * @return $this
     */
    public function withoutVerifying(): self
    {
        $this->options[CURLOPT_SSL_VERIFYPEER] = false;
        $this->options[CURLOPT_SSL_VERIFYHOST] = 0;
        return $this;
    }

    /**
     * Set buffer size (In bytes). Default: 8192 bytes.
     *
     * PHP default is 16,000 bytes = 16KB
     * Suggest set 128 thousand bytes = 128KB for large file download
     *
     * @param int $size In bytes
     * @return $this
     */
    public function withBufferSize(int $size): self
    {
        $this->bufferSize = $size;
        return $this;
    }

    /**
     * Without return transfer.
     *
     * @return $this
     */
    public function withoutReturnTransfer(): self
    {
        $this->returnTransfer = false;
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
     * Prepare form-data request params.
     *
     * Auto-set Content-Type: multipart/form-data
     *
     * NOTE: May attach CURLFile in the form.
     * $this->form([
     *  'document_type' => 'Finance',
     *  'file' => new CURLFile(filepath),
     * ])
     * ;
     *
     * @param array<string, mixed> $data
     * @return $this
     */
    public function withMultipart(array $data): self
    {
        if (is_array($this->postFields)) {
            $this->postFields = array_merge((array)$this->postFields, $data);
            return $this->withMethod('POST')->asMultipart();
        }

        $this->contentType = null;
        $this->postFields = $data;
        return $this->withMethod('POST');
    }

    /**
     * Prepare x-www-form-urlencoded request params.
     *
     * Auto-set Content-Type: application/x-www-form-urlencoded
     *
     * @param array<string, mixed> $data
     * @return $this
     */
    public function withForm(array $data): self
    {
        return $this->withBody(http_build_query($data), 'application/x-www-form-urlencoded');
    }

    /**
     * Prepare raw request params.
     *
     * For JSON/XML string, always set the type manually.
     *
     * @param string $data
     * @param string $type Content type. Default: application/json
     * @return $this
     */
    public function withRaw(string $data, string $type = 'text/plain'): self
    {
        return $this->withBody($data, $type);
    }

    /**
     * Prepare raw request params.
     *
     * For JSON/XML string, always set the type manually.
     *
     * @param string|StreamInterface $data
     * @param string|null $type Content type.
     * @return $this
     */
    public function withBody(string|StreamInterface $data, ?string $type = null): self
    {
        // Close a previously owned stream before replacing
        if ($this->postFields instanceof StreamInterface && $this->postFieldsOwned) {
            $this->postFields->close();
        }

        $this->postFields = $data;
        $this->postFieldsOwned = false;
        if ($type) {
            $this->contentType = $type;
        }
        return $this;
    }

    /**
     * Set a stream as the request body. The client takes ownership and will close it.
     *
     * Use this when you have an internally created stream.
     * For user-provided streams, use withBody() instead.
     *
     * @param StreamInterface $stream
     * @param string $type Content type. Default: application/octet-stream
     * @return $this
     */
    public function withBodyStream(StreamInterface $stream, string $type = 'application/octet-stream'): self
    {
        // Close a previously owned stream before replacing
        if ($this->postFields instanceof StreamInterface && $this->postFieldsOwned) {
            $this->postFields->close();
        }
        $this->postFields = $stream;
        $this->postFieldsOwned = true;
        $this->contentType = $type;
        return $this;
    }

    /**
     * Prepare array data to JSON body.
     *
     * @param array<string, mixed> $data
     * @return $this
     */
    public function withJson(array $data): self
    {
        $json = json_encode($data);
        if ($json === false) {
            throw new InvalidArgumentException('JSON encoding failed: ' . json_last_error_msg());
        }
        return $this->withBody($json, 'application/json');
    }

    /**
     * Prepare GraphQL request params.
     *
     * @param string $query
     * @param array<string, mixed> $variables
     * @return $this
     */
    public function withGraphQL(string $query, array $variables = []): self
    {
        $json = json_encode([
            'query' => $query,
            'variables' => $variables
        ]);

        if ($json === false) {
            throw new InvalidArgumentException('JSON encoding failed: ' . json_last_error_msg());
        }
        return $this->withBody($json, 'application/json');
    }

    /**
     * Attach file/ files.
     *
     * Auto set Content-type: multipart/form-data
     *
     * @param string $name Attribute name.
     * @param CURLFile|CURLFile[]|string|string[]|resource|resource[] $file
     * @return $this
     * @throws Exception
     */
    public function attach(string $name, mixed $file, ?string $filename = null, ?string $mimeType = null): self
    {
        if (!is_array($this->postFields)) {
            $this->postFields = [];
        }

        $this->hasAttachments = true;

        if (is_array($file)) {
            $name = rtrim($name, '[]') . '[]';
            foreach ($file as $attachment) {
                $this->postFields[$name][] = $this->normalizeAttachment($attachment, $filename, $mimeType);
            }

            return $this;
        }

        $this->postFields[$name] = $this->normalizeAttachment($file, $filename, $mimeType);
        return $this;
    }

    /**
     * Normalize attached file.
     *
     * @param mixed $file
     * @param string|null $filename
     * @param string|null $mimeType
     * @return string|CURLFile
     * @throws Exception
     */
    protected function normalizeAttachment(mixed $file, ?string $filename = null, ?string $mimeType = null): string|CURLFile
    {
        // 1. Already CURLFile
        if ($file instanceof CURLFile) {
            return $file;
        }

        // 2. Resource (fopen)
        if (is_resource($file)) {
            /** @var array<string, mixed> $meta */
            $meta = stream_get_meta_data($file);
            $path = $meta['uri'] ?? null;

            if ($path && is_file($path)) {
                return new CURLFile(
                    $path,
                    mime_type: $mimeType,
                    posted_filename: $filename ?? basename($path)
                );
            }

            if (isset($meta['seekable']) && $meta['seekable']) {
                rewind($file);
            }
            $tmp = $this->createTempFile();
            stream_copy_to_stream($file, $tmp);
            /** @var array<string, mixed> $tmpMeta */
            $tmpMeta = stream_get_meta_data($tmp);
            $tmpPath = $tmpMeta['uri'] ?? null;
            if (!$tmpPath || !is_file($tmpPath)) {
                throw new RuntimeException('Failed to create valid temp file for attachment.');
            }

            $this->tmpFiles[] = $tmp;
            return new CURLFile(
                $tmpPath,
                $mimeType,
                $filename ?? 'upload'
            );
        }

        // 3. File path string
        if (is_string($file) && is_file($file)) {
            if (!is_readable($file)) {
                throw new InvalidArgumentException("File path exists but is not readable: $file");
            }
            return new CURLFile(
                $file,
                $mimeType,
                $filename ?? basename($file)
            );
        }

        // 4. Raw string (file_get_contents)
        if (is_string($file)) {
            $tmp = $this->createTempFile();
            fwrite($tmp, $file);

            $meta = stream_get_meta_data($tmp);
            $this->tmpFiles[] = $tmp;

            return new CURLFile(
                $meta['uri'],
                $mimeType ?? 'application/octet-stream',
                $filename ?? 'upload_' . uniqid()
            );
        }

        throw new InvalidArgumentException('Unsupported file type for attachment.');
    }

    /**
     * Download file.
     *
     * @param mixed $destination
     * @param bool $streamOnly
     * @return $this
     */
    public function sink(mixed $destination, bool $streamOnly = false): self
    {
        unset($this->options[CURLOPT_WRITEFUNCTION], $this->options[CURLOPT_FILE]);

        if (is_resource($destination)) {
            $this->sink = $destination;
            $this->sinkPath = null;

            // FINAL POLISH: Ensure the resource pointer is at the start
            $meta = stream_get_meta_data($this->sink);
            if ($meta['seekable']) {
                rewind($this->sink);
            }
        } elseif (is_string($destination)) {
            $handle = fopen($destination, 'w');
            $handle || throw new InvalidArgumentException("Unable to open file: $destination");
            $this->sinkOwned = true;
            $this->sink = $handle;
            $this->sinkPath = $destination;
        }

        is_resource($this->sink) || throw new InvalidArgumentException('Sink must be file path or resource');

        $this->options[CURLOPT_RETURNTRANSFER] = false;

        if ($streamOnly) {
            $this->options[CURLOPT_WRITEFUNCTION] = [$this, 'writeToSink'];
            return $this;
        }

        $this->options[CURLOPT_FILE] = $this->sink;
        return $this;
    }

    /**
     * Internal write handler to align cURL and Stream buffers.
     */
    protected function writeToSink(mixed $ch, string $data): int
    {
        // Ensure we are writing in chunks that match our defined bufferSize
        $written = fwrite($this->sink, $data);
        return $written === false ? 0 : $written;
    }

    /**
     * Set DNS timeout.
     *
     * @param int $seconds
     * @return $this
     */
    public function withDNSTimeout(int $seconds): self
    {
        $this->dnsTimeout = $seconds;
        return $this;
    }

    /**
     * Set connection timeout.
     *
     * @param int $timeout Connection timeout in seconds. Default: 0 seconds.
     * @return $this
     */
    public function connectionTimeout(int $timeout): self
    {
        if ($timeout < 0) {
            throw new InvalidArgumentException('Connection timeout should be more than 0s');
        }
        $this->connectionTimeout = $timeout;
        return $this;
    }

    /**
     * Set timeout.
     *
     * @param int $timeout Timeout in seconds. 0 seconds means no timeout.
     * @return $this
     */
    public function timeout(int $timeout): self
    {
        if ($timeout < 0) {
            throw new InvalidArgumentException('Timeout should be more than 0s');
        }

        $this->timeout = $timeout;
        return $this;
    }

    /**
     * Enable verbose.
     *
     * @return $this
     */
    public function verbose(): self
    {
        $this->options[CURLOPT_VERBOSE] = true;
        return $this;
    }

    /**
     * Set retry. Default: 0.
     *
     * @param int $times
     * @param int|null $after Retry after the number of milliseconds.
     * @return $this
     * @throws Exception
     */
    public function retry(int $times, ?int $after = null): static
    {
        if ($times < 1) {
            throw new InvalidArgumentException('The number of retries must be at least 1.');
        }

        $this->retry = $times;
        if (is_int($after) && $after < 0) {
            throw new Exception('Retry after milliseconds should be more than 0');
        }
        $this->retryAfter = $after;
        return $this;
    }

    /**
     * Set a custom retry condition.
     *
     * @param Closure(Response, string $method, int $attempt): bool $callback
     * @return $this
     */
    public function retryWhen(Closure $callback): static
    {
        $this->retryCallback = $callback;
        return $this;
    }

    /**
     * Determine should retry based on the response.
     *
     * @param Response $response
     * @param int $attempt Current attempt number (1-based)
     * @return bool
     */
    public function shouldRetry(Response $response, int $attempt = 1): bool
    {
        // If the body is a stream and is not seekable, we cannot retry reliably
        if ($this->postFields instanceof StreamInterface && !$this->postFields->isSeekable()) {
            return false;
        }

        if ($this->retryCallback !== null) {
            return (bool)($this->retryCallback)($response, $this->method, $attempt);
        }

        if ($response->isRetryableNetworkError()) {
            return true;
        }

        if ($response->isServerError()) {
            return in_array($this->method, ['GET', 'HEAD', 'OPTIONS']);
        }

        return false;
    }

    /**
     * Perform wait before the next attempt.
     *
     * @return void
     */
    protected function wait(): void
    {
        if ($this->retryAfter === null || $this->retryAfter <= 0) {
            return;
        }
        usleep($this->retryAfter * 1000);
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
     * Set the content type to application/json.
     *
     * @return $this
     */
    public function asJson(): self
    {
        $this->contentType = static::type_json;
        return $this;
    }

    /**
     * Set the content type to application/x-www-form-urlencoded.
     *
     * @return $this
     */
    public function asForm(): self
    {
        $this->contentType = static::type_form;
        return $this;
    }

    /**
     * Set the content type to multipart/form-data.
     *
     * @return $this
     */
    public function asMultipart(): self
    {
        $this->contentType = static::type_multipart;
        return $this;
    }

    /**
     * Set the content type to text/plain.
     *
     * @return $this
     */
    public function asRaw(): self
    {
        $this->contentType = static::type_raw;
        return $this;
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
     * @throws NetworkExceptionInterface
     * @throws RequestExceptionInterface
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
                    static::type_json => $this->withJson($data),
                    static::type_form => $this->withForm($data),
                    static::type_multipart => $this->withMultipart($data),
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
     * Prepares the cURL handle with all configurations.
     *
     * @param string $requestId Generated request ID.
     * @return CurlHandle
     */
    private function prepareHandle(string $requestId): CurlHandle
    {
        $url = $this->getEndpoint();
        if (!empty($this->queryParams)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($this->queryParams);
        }
        $this->options[CURLOPT_URL] = $url;
        $this->options[CURLOPT_FAILONERROR] = false;
        $this->options[CURLOPT_NOSIGNAL] = 1;

        if ($this->method === 'GET') {
            unset($this->options[CURLOPT_POSTFIELDS], $this->options[CURLOPT_POST]);
        }

        if ($this->formattedHeaders === null) {
            $headers = array_change_key_case($this->headers);
            $headers['x-request-id'] ??= [$requestId];
            $headers['user-agent'] ??= [$this->userAgent];

            if ($this->hasAttachments) {
                unset($headers['content-type']);
            } elseif ($this->contentType && !isset($headers['content-type'])) {
                $map = [
                    static::type_json => 'application/json',
                    static::type_form => 'application/x-www-form-urlencoded',
                    static::type_multipart => 'multipart/form-data',
                    static::type_raw => 'text/plain',
                ];
                $headers['content-type'] = [$map[$this->contentType] ?? $this->contentType];
            }
            $this->formattedHeaders = $this->buildFormattedHeaders($headers);
        }

        $headers = [];
        if ($this->postFields !== null) {
            $fields = $this->postFields;

            // 1. Handle PSR-7 StreamInterface objects (Memory-Efficient)
            if ($fields instanceof StreamInterface) {
                $size = $fields->getSize();
                if ($fields->isSeekable()) {
                    $fields->rewind();
                }

                // Set the size so cURL sends the correct Content-Length header
                if ($size !== null) {
                    $headers['content-length'] = [(string)$size];
                    $this->options[CURLOPT_INFILESIZE] = $size;
                }

                if ($this->method === 'PUT') {
                    $this->options[CURLOPT_UPLOAD] = true;
                } else {
                    $this->options[CURLOPT_POST] = true;
                    unset($this->options[CURLOPT_UPLOAD]);
                }
                $this->options[CURLOPT_READFUNCTION] = function ($ch, $fd, $length) use ($fields) {
                    return $fields->eof() ? '' : $fields->read($length);
                };

                // Ensure we don't treat this as a standard string/array POST
                unset($this->options[CURLOPT_POSTFIELDS]);
            } else {
                unset(
                    $this->options[CURLOPT_UPLOAD],
                    $this->options[CURLOPT_INFILESIZE],
                    $this->options[CURLOPT_READFUNCTION]
                );

                // 2. Handle standard array or string data
                if (is_array($fields)) {
                    if ($this->hasAttachments || $this->contentType === static::type_multipart) {
                        $fields = $this->flattenMultipartData($fields);
                    } else {
                        $fields = http_build_query($fields);
                    }
                }

                $this->options[CURLOPT_POSTFIELDS] = $fields;

                if (is_string($fields)) {
                    $headers['content-length'] = [(string)strlen($fields)];
                }
            }
        } elseif (in_array($this->method, ['POST', 'PUT', 'PATCH'])) {
            // Ensure Content-Length: 0 is sent for empty bodies on write methods
            $headers['content-length'] = ['0'];
        }

        $this->options[CURLOPT_CONNECTTIMEOUT] = $this->connectionTimeout;
        $this->options[CURLOPT_TIMEOUT] = $this->timeout;

        // CRITICAL FIX: Only set return transfer if no custom output method is defined
        if (!isset($this->options[CURLOPT_FILE])
            && !isset($this->options[CURLOPT_WRITEFUNCTION])
            && $this->returnTransfer
        ) {
            $this->options[CURLOPT_RETURNTRANSFER] = true;
        }

        if (is_resource($this->sink)) {
            unset($this->options[CURLOPT_RESUME_FROM]);

            $downloadedSize = ftell($this->sink);
            if ($downloadedSize > 0) {
                $this->options[CURLOPT_RESUME_FROM] = $downloadedSize; // Tell cURL to resume from this byte offset
            } else {
                $meta = stream_get_meta_data($this->sink);
                if ($meta['seekable']) {
                    ftruncate($this->sink, 0);
                    rewind($this->sink);
                }
            }
        }

        if ($this->curlHandle === null) {
            $this->curlHandle = curl_init();
            if ($this->curlHandle === false) {
                throw new RuntimeException('Failed to initialize cURL handle.');
            }
        } else {
            curl_reset($this->curlHandle);
        }

        curl_setopt_array($this->curlHandle, $this->options);
        curl_setopt($this->curlHandle, CURLOPT_HTTPHEADER, $headers
            ? array_merge($this->formattedHeaders, $this->buildFormattedHeaders($headers))
            : $this->formattedHeaders
        );
        curl_setopt($this->curlHandle, CURLOPT_BUFFERSIZE, $this->bufferSize);
        curl_setopt($this->curlHandle, CURLOPT_ENCODING, ''); // Disable automatic encoding. Allow Gzip/Brotli compression
        curl_setopt($this->curlHandle, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0); // Enable HTTP/2 as it allows multiplexing
        curl_setopt($this->curlHandle, CURLOPT_DNS_CACHE_TIMEOUT, $this->dnsTimeout);
        curl_setopt($this->curlHandle, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($this->curlHandle, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS); // PHP 8.1 Security Fix. deprecated. Use CURLOPT_REDIR_PROTOCOLS_STR

        //CURLOPT_REDIR_PROTOCOLS_STR => 'http,https', // Available PHP 8.3+
        //181 => 'http,https', // Use constant value represent CURLOPT_REDIR_PROTOCOLS_STR for PHP <8.3
        curl_setopt($this->curlHandle,
            defined('CURLOPT_REDIR_PROTOCOLS_STR') ? CURLOPT_REDIR_PROTOCOLS_STR : 181,
            'http,https'
        );

        return $this->curlHandle;
    }

    /**
     * Build formatted headers.
     *
     * @param array<string, mixed> $headers
     * @return array<array-key, mixed>
     */
    private function buildFormattedHeaders(array $headers): array
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
            } else {
                $formatted[] = "$normalized: " . implode(', ', $values);
            }
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
                // Ensure seekable streams are rewound for every attempt.
                if ($this->postFields instanceof StreamInterface && $this->postFields->isSeekable()) {
                    $this->postFields->rewind();
                }

                // Reset file pointer if retrying a download. Logic for seeking back to start of a file for retries
                if ($attempts > 1 && is_resource($this->sink) && stream_get_meta_data($this->sink)['seekable']) {
                    rewind($this->sink);
                    ftruncate($this->sink, 0);
                }

                $curl = $this->prepareHandle($requestId);

                if ($this->debugDump) {
                    $this->debugDump();
                    if ($this->debugDie) {
                        exit;
                    }
                }

                // Capture Headers
                $rawHeaders = '';
                curl_setopt($curl, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$rawHeaders) {
                    $rawHeaders .= $header;
                    return strlen($header);
                });

                $curlRaw = curl_exec($curl);
                $curlInfo = curl_getinfo($curl);
                $curlError = curl_error($curl);
                $curlErrorNo = curl_errno($curl);

                /** @var Response $response */
                $response = new $this->responseClass(
                    $curlInfo,
                    ($this->sink === null && is_string($curlRaw)) ? $curlRaw : '',
                    $curlError ?: ($curlInfo['http_code'] >= 400 ? 'HTTP Error' : ''),
                    $this->sinkPath,
                    $curlErrorNo,
                    $rawHeaders
                );

                $this->log($response);

                if ($this->throwOnError && ($response->getErrno() || $response->failed())) {
                    throw new Exception(
                        $response->getErrno()
                            ? $curlError
                            : "HTTP {$response->getStatusCode()}: {$response->getReasonPhrase()}"
                    );
                }

                if (!$this->shouldRetry($response, $attempts) || ++$attempts > $maxAttempts) {
                    return $response;
                }

                $this->wait();
            } while (true);
        };
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
            } else {
                $result[$name] = $value;
            }
        }
        return $result;
    }

    /**
     * Create a temporary file.
     *
     * @return resource
     */
    private function createTempFile()
    {
        $tmp = tmpfile();
        if (!$tmp) {
            $tmpDir = sys_get_temp_dir();
            $reason = !is_writable($tmpDir)
                ? "Temporary directory is not writable: $tmpDir"
                : "Unknown system error creating temp file.";
            throw new RuntimeException("Unable to create temporary file. $reason");
        }
        return $tmp;
    }

    /**
     * Debug request.
     *
     * @return static
     * @throws Throwable
     */
    public function dd(): self
    {
        $this->debugDump = true;
        $this->debugDie = true;
        return $this;
    }

    /**
     * Dump request.
     *
     * @return $this
     */
    public function dump(): static
    {
        $this->debugDump = true;
        return $this;
    }

    /**
     * Outputs a detailed debug dump of the current request properties and halts execution.
     *
     * @return void
     */
    protected function debugDump(): void
    {
        if (isset($this->options[CURLOPT_URL])) {
            $url = $this->options[CURLOPT_URL];
        } else {
            $url = $this->getEndpoint();
            if (!empty($this->queryParams)) {
                $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($this->queryParams);
            }
        }

        echo "<pre>";
        var_dump([
            'base_url' => $url,
            'resource_url' => $this->pendingUrl ?: null,
            'method' => $this->method,
            'curl_headers' => $this->headers,
            'curl_options' => $this->options,
            'query_params' => $this->queryParams,
            'post_fields' => $this->postFields,
            'has_attachments' => $this->hasAttachments,
            'sink_path' => $this->sinkPath,
        ]);
        echo "</pre>";
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
