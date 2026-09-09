<?php

namespace Simsoft\HttpClient;

use Closure;
use CurlHandle;
use Exception;
use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
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
 * @phpstan-consistent-constructor make() instantiates the called class, so a
 * subclass must keep the constructor signature it inherits.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") Trait methods are counted toward the class total.
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") Coupling is inherent to PSR-18 compliance and trait composition.
 */
class HttpClient implements ClientInterface
{
    use Macroable;
    use DeprecatedTrait;
    use DebugTrait;
    use CurlOptionsTrait;
    use PrepareHandleTrait;
    use RetryTrait;
    use RequestBodyTrait;
    use AttachmentTrait;
    use SinkTrait;

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

    /** @var array<string, mixed> Headers. Cleared after every request. */
    protected array $headers = [];

    /**
     * Connection-scoped headers that survive flush() and apply to every
     * request made through this client, e.g. the bearer token.
     *
     * @var array<string, mixed>
     */
    protected array $persistentHeaders = [];

    /** @var array<array-key, mixed>|null Cached formatted headers for cURL. */
    protected ?array $formattedHeaders = null;

    /** @var array<string|int, mixed> Query params. */
    protected array $queryParams = [];

    /** @var string The response class to be used. */
    protected string $responseClass = Response::class;

    /**
     * Middleware stack (in reverse order).
     *
     * Typed as returning mixed rather than Response because withMiddleware()
     * accepts a bare Closure from the caller: nothing enforces the return type
     * until the pipeline checks it at runtime. Promising Response here would
     * make that check look redundant when it is the only thing performing it.
     *
     * @var array<array-key, Closure(self, Closure): mixed>
     */
    protected array $middleware = [];

    /** @var LoggerInterface|null Logger instance. */
    protected ?LoggerInterface $logger = null;

    public const TYPE_JSON = 'json';
    public const TYPE_FORM = 'form';
    public const TYPE_MULTIPART = 'multipart';
    public const TYPE_RAW = 'raw';

    /**
     * Create a new client.
     *
     * Declared so make() has a signature it can rely on when instantiating the
     * called class. A subclass adding required constructor parameters would
     * break that factory, which is what @phpstan-consistent-constructor pins.
     */
    public function __construct()
    {
    }

    /**
     * Factory method.
     *
     * Late static binding keeps the factory usable by subclasses: new self()
     * returned an HttpClient even when called as MyClient::make(), so any
     * behaviour the subclass added was silently dropped.
     *
     * @return static
     */
    public static function make(): static
    {
        return new static();
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
        }
        $this->sink = null;
        $this->sinkOwned = false;
        $this->sinkPath = null;
        $this->resetDebug();
        $this->resetRequestOptions();
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
     * The base URL and the resource path are joined by exactly one slash.
     * Plain concatenation produced "https://api.test//users" for a base with a
     * trailing slash and "https://api.testusers" for a resource without a
     * leading one — the first is a different path to most routers, the second
     * a different host.
     *
     * An absolute resource URL is returned as given, so a client configured
     * with a base URL can still address another host directly.
     *
     * @return string
     */
    public function getEndpoint(): string
    {
        if ($this->baseUrl === '' || $this->isAbsoluteUrl($this->pendingUrl)) {
            return $this->pendingUrl === '' ? $this->baseUrl : $this->pendingUrl;
        }

        if ($this->pendingUrl === '') {
            return $this->baseUrl;
        }

        return rtrim($this->baseUrl, '/') . '/' . ltrim($this->pendingUrl, '/');
    }

    /**
     * Determine whether a URL carries its own scheme and host.
     *
     * @param string $url The URL to inspect.
     * @return bool
     */
    private function isAbsoluteUrl(string $url): bool
    {
        return preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) === 1;
    }

    /**
     * Get the current HTTP method.
     *
     * @return string The HTTP method (e.g., GET, POST, PUT, PATCH, DELETE).
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Alias to resource().
     *
     * @param string $url
     * @return $this
     *
     * @SuppressWarnings("PHPMD.ShortMethodName")
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
            // 'PUT', 'PATCH', 'DELETE' or another non-standard verb.
            default => $this->options[CURLOPT_CUSTOMREQUEST] = $this->method,
        };
        return $this;
    }

    /**
     * Set headers.
     *
     * @param array<string, string|array<array-key, mixed>> $headers
     * @return $this
     * @throws InvalidArgumentException When a name or value is not a legal header.
     */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->withHeader($name, $value);
        }
        return $this;
    }

    /**
     * Add header.
     *
     * @param string $name
     * @param string|array<array-key, mixed> $value
     * @return self
     * @throws InvalidArgumentException When the name or value is not a legal header.
     */
    public function withHeader(string $name, string|array $value): self
    {
        $this->assertHeaderName($name);

        $current = (array)($this->headers[$name] ?? []);
        $new = array_map(fn($val) => (string)$val, (array)$value);

        foreach ($new as $val) {
            $this->assertHeaderValue($name, $val);
        }

        $this->headers[$name] = array_values(array_unique(array_merge($current, $new)));
        $this->formattedHeaders = null;
        return $this;
    }

    /**
     * Assert that a header name is a legal RFC 7230 token.
     *
     * A name containing CR or LF would terminate the header line early and let
     * a caller-supplied value forge additional headers on the wire, so anything
     * outside the token grammar is rejected outright.
     *
     * @param string $name The header name to validate.
     * @return void
     * @throws InvalidArgumentException When the name is empty or not a token.
     */
    protected function assertHeaderName(string $name): void
    {
        if (preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name) === 1) {
            return;
        }

        throw new InvalidArgumentException(
            sprintf('Invalid header name: %s', var_export($name, true))
        );
    }

    /**
     * Assert that a header value contains no line breaks or NUL bytes.
     *
     * @param string $name The header name, for the error message.
     * @param string $value The header value to validate.
     * @return void
     * @throws InvalidArgumentException When the value contains CR, LF or NUL.
     */
    protected function assertHeaderValue(string $name, string $value): void
    {
        if (strpbrk($value, "\r\n\0") === false) {
            return;
        }

        throw new InvalidArgumentException(
            sprintf('Invalid value for header "%s": line breaks and NUL bytes are not allowed.', $name)
        );
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
     * The token is connection-scoped: it survives flush() and is applied to
     * every subsequent request made through this client, so a client may be
     * configured once and reused. Use withoutBearerToken() to remove it.
     *
     * @param string $token
     * @return $this
     * @throws InvalidArgumentException When the token contains line breaks or NUL bytes.
     */
    public function withBearerToken(string $token): self
    {
        $this->assertHeaderValue('Authorization', $token);

        $this->persistentHeaders['authorization'] = ["Bearer $token"]; // Force single value
        $this->formattedHeaders = null;
        return $this;
    }

    /**
     * Remove the connection-scoped bearer token from this client.
     *
     * @return $this
     */
    public function withoutBearerToken(): self
    {
        unset($this->persistentHeaders['authorization']);
        $this->formattedHeaders = null;
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
     * The PSR-7 request is authoritative for this send alone: it supplies the
     * target URL, method, headers and body. Connection-scoped state that has
     * to be overwritten to do that — the base URL — is restored afterwards, so
     * a client may be driven through the fluent API and PSR-18 interchangeably
     * in either order.
     *
     * A connection-scoped credential set with withBearerToken() is withheld
     * when the request targets an origin other than the configured base URL,
     * mirroring the way cURL drops Authorization across a cross-host redirect.
     * A client with no base URL has no origin to conflict with, so its token
     * is applied as usual.
     *
     * @param RequestInterface $request
     * @return ResponseInterface
     * @throws NetworkExceptionInterface
     * @throws RequestExceptionInterface
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $previousBaseUrl = $this->baseUrl;
        $previousPersistent = $this->persistentHeaders;
        $previousThrowOnError = $this->throwOnError;
        $this->throwOnError = false;

        $this->adoptPsrRequest($request);

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
            $this->baseUrl = $previousBaseUrl;
            $this->persistentHeaders = $previousPersistent;
            $this->formattedHeaders = null;
        }
    }

    /**
     * Translate a PSR-7 request into this client's request-scoped state.
     *
     * @param RequestInterface $request The request to adopt.
     * @return void
     * @throws InvalidArgumentException When a header name or value is illegal.
     */
    private function adoptPsrRequest(RequestInterface $request): void
    {
        $uri = $request->getUri();

        if ($this->isForeignOrigin($uri)) {
            $this->persistentHeaders = [];
            $this->formattedHeaders = null;
        }

        $this->withBaseUrl($uri->getScheme() . '://' . $uri->getAuthority());
        $this->resource($uri->getPath());

        if ($uri->getQuery() !== '') {
            $queryParams = [];
            parse_str($uri->getQuery(), $queryParams);
            $queryParams === [] || $this->withQuery($queryParams);
        }

        foreach ($request->getHeaders() as $name => $values) {
            $this->withHeader($name, $values);
        }

        $body = $request->getBody();
        if ($body->getSize() !== 0) {
            $this->withBody($body, $request->getHeaderLine('Content-Type') ?: null);
        }
    }

    /**
     * Determine whether a PSR-7 target points at an origin other than the one
     * this client is configured for.
     *
     * Comparison is on scheme and authority only, normalised for case, since
     * that is the boundary a credential must not cross. A client with no base
     * URL, or one whose base URL has no authority, is treated as having no
     * origin of its own and therefore no conflict.
     *
     * @param UriInterface $uri The target of the PSR-7 request.
     * @return bool True when the target origin differs from the base URL's.
     */
    private function isForeignOrigin(UriInterface $uri): bool
    {
        if ($this->persistentHeaders === [] || $this->baseUrl === '') {
            return false;
        }

        $parts = parse_url($this->baseUrl);
        if ($parts === false || !isset($parts['host'])) {
            return false;
        }

        $host = $parts['host'];
        if (isset($parts['port'])) {
            $host .= ':' . $parts['port'];
        }

        return strcasecmp($parts['scheme'] ?? '', $uri->getScheme()) !== 0
            || strcasecmp($host, $uri->getAuthority()) !== 0;
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
                        $data instanceof StreamInterface => $this->withBody($data),
                        is_string($data) => $this->withBody($data, 'text/plain'),
                        is_array($data) => strtoupper($method) === 'GET'
                            ? $this->withQuery($data)
                            : $this->withMultipart($data),
                        default => throw new InvalidArgumentException(
                            'Unsupported data type: ' . get_debug_type($data)
                        ),
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
     * Values are re-validated here as a last line of defence: not every header
     * reaches this point through withHeader(), for example a content type
     * supplied to withBody(). Nothing containing CR or LF may reach cURL.
     *
     * @param array<string, mixed> $headers
     * @return array<array-key, mixed>
     * @throws InvalidArgumentException When a name or value is not a legal header.
     */
    protected function buildFormattedHeaders(array $headers): array
    {
        $formatted = [];
        foreach ($headers as $key => $values) {
            $this->assertHeaderName($key);
            $normalized = ucwords(strtolower($key), '-');
            $values = (array)$values;

            foreach ($values as $value) {
                $this->assertHeaderValue($key, (string)$value);
            }

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
     * Build a prepared cURL handle for external execution (used by HttpPool).
     *
     * This method prepares the handle with all configured options but does NOT
     * execute it. The caller is responsible for execution and cleanup.
     *
     * Each call returns a handle of its own. A cURL handle may be attached to
     * a curl_multi only once, so the instance's cached handle — which exists to
     * keep the connection alive between sequential request() calls — cannot be
     * handed out here: a client submitted twice in one batch, or resubmitted
     * for a retry, would otherwise be rejected with CURLM_ADDED_ALREADY and
     * that transfer would never run.
     *
     * @return CurlHandle The prepared handle ready for curl_multi_add_handle().
     */
    public function buildHandle(): CurlHandle
    {
        $requestId = uniqid('httpclient_req_', true);
        return $this->prepareDedicatedHandle($requestId);
    }

    /**
     * Get the sink path for pool response construction.
     *
     * @return string|null The sink file path, or null if no sink is configured.
     */
    public function getPoolSinkPath(): ?string
    {
        return $this->sinkPath;
    }

    /**
     * Settle and discard the state of a request executed by an external driver.
     *
     * request() ends by flushing per-request state; a client handed to HttpPool
     * never reaches that point, because the pool executes the handle itself.
     * Without this call the client keeps the URL, method, query and body of the
     * pooled request, and the next call through the fluent API inherits them.
     *
     * @return void
     */
    public function releaseRequest(): void
    {
        $this->flushSink();
        $this->flush();
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
     */
    private function setupHeaderCapture(CurlHandle $curl, string &$rawHeaders): void
    {
        curl_setopt($curl, CURLOPT_HEADERFUNCTION, static function ($curlHandle, $header) use (&$rawHeaders) {
            unset($curlHandle); // required by cURL callback signature
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
     * Produces the same field names as http_build_query(), so a payload sent as
     * multipart arrives on the server in the same shape as one sent form-encoded.
     *
     * @param array<array-key, mixed> $data
     * @param string|null $prefix
     * @return array<string, mixed>
     */
    protected function flattenMultipartData(array $data, ?string $prefix = null): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            // A "0" prefix is a falsy string, so an emptiness test here would drop
            // the parent name and collapse the first branch of a list into the root.
            $name = $prefix === null ? (string)$key : "{$prefix}[$key]";

            if (is_array($value)) {
                // Union, not array_merge: merge renumbers integer-like keys, which
                // are exactly the names a list produces.
                $result += $this->flattenMultipartData($value, $name);
                continue;
            }

            $result[$name] = $this->normalizeMultipartValue($value);
        }

        return array_filter($result, static fn(mixed $value): bool => $value !== null);
    }

    /**
     * Convert a scalar multipart value to the string cURL will send.
     *
     * cURL stringifies a bool by casting, so false becomes "" — an empty field
     * rather than the "0" the same payload produces when form-encoded. Booleans
     * are converted here so both encodings agree, and null is returned as-is for
     * the caller to drop, matching http_build_query() omitting a null entirely.
     *
     * @param mixed $value
     * @return mixed
     */
    private function normalizeMultipartValue(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return $value;
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
        // Dropping the reference is what frees the handle. curl_close() has
        // been a no-op since PHP 8.0, when cURL moved from resources to
        // CurlHandle objects, and is deprecated in 8.5.
        $this->curlHandle = null;

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
