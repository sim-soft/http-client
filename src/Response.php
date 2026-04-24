<?php

namespace Simsoft\HttpClient;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Simsoft\HttpClient\Streams\FileStream;
use Simsoft\HttpClient\Streams\StringStream;

/**
 * Response class.
 */
class Response implements ResponseInterface
{
    /** @var string Default protocol version. */
    protected string $protocolVersion = '1.1';

    /** @var int Status code. */
    protected int $statusCode = 0;

    /** @var array<string, mixed> Headers */
    protected array $headers = [];

    /** @var array<string|int, mixed>|null JSON content. */
    protected ?array $attributes = null;

    /** @var StreamInterface|null Stream */
    protected ?StreamInterface $stream = null;

    /** @var bool|null Determine if the content is JSON. */
    protected ?bool $isJson = null;

    /**
     * Constructor.
     *
     * @param array<string, mixed>|false $curlInfo CURL info.
     * @param string $body Raw response body.
     * @param string $message Response message.
     * @param string|null $sinkPath Download the destination path.
     * @param int $errno Error code.
     * @param string $rawHeaders Raw headers.
     */
    final public function __construct(
        protected array|false $curlInfo = false,
        protected string      $body = '',
        protected string      $message = '',
        protected ?string     $sinkPath = null,
        protected int         $errno = 0,
        protected string      $rawHeaders = '',
    )
    {
        $this->statusCode = $curlInfo['http_code'] ?? 0;
        $this->setHeaders($rawHeaders);
    }

    /**
     * Set headers.
     *
     * @param string $rawHeaders
     * @return void
     */
    protected function setHeaders(string $rawHeaders): void
    {
        $this->headers = [];

        // Split into blocks by blank lines, take only the last (final response)
        $blocks = preg_split('/\r?\n\r?\n/', trim($rawHeaders));
        $lastBlock = $blocks === false ? '' : (end($blocks) ?: '');
        if ($lastBlock === '') {
            return;
        }

        $lines = explode("\n", str_replace("\r", "", $lastBlock));
        foreach ($lines as $index => $line) {
            if (trim($line) === '') continue;

            // The first line contains the protocol, status code, and message
            if ($index === 0 && str_starts_with($line, 'HTTP/')) {
                $parts = explode(' ', $line, 3);

                // Sync the Protocol Version
                if (isset($parts[0])) {
                    $this->protocolVersion = str_replace('HTTP/', '', $parts[0]);
                }

                // FIX: Sync the Reason Phrase (Message) if not already set or if it's a cURL error
                if (isset($parts[2]) && ($this->message === '' || $this->statusCode > 0)) {
                    $this->message = trim($parts[2]);
                }
                continue;
            }

            // Standard header parsing logic...
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $this->headers[trim($key)][] = trim($value);
            }
        }
        $this->headers = array_change_key_case($this->headers);
    }

    /**
     * Get headers.
     *
     * @return array<string, mixed>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Determine the status code is ok.
     *
     * @return bool
     */
    public function successful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * Determine if the status code is >= 400.
     *
     * @return bool
     */
    public function failed(): bool
    {
        return $this->statusCode >= 400 || $this->isNetworkError();
    }

    /**
     * Determine is client error.
     *
     * @return bool
     */
    public function isClientError(): bool
    {
        return $this->getStatusCode() >= 400 && $this->getStatusCode() < 500;
    }

    /**
     * Determine is server error.
     *
     * @return bool
     */
    public function isServerError(): bool
    {
        return $this->getStatusCode() >= 500;
    }

    /**
     * Determine is network error.
     *
     * @return bool
     */
    public function isNetworkError(): bool
    {
        return $this->errno !== 0;
    }

    /**
     * Determine is retryable network error.
     *
     * @return bool
     */
    public function isRetryableNetworkError(): bool
    {
        return in_array($this->errno, [
            CURLE_OPERATION_TIMEOUTED,
            CURLE_COULDNT_CONNECT,
            CURLE_COULDNT_RESOLVE_HOST,
            CURLE_RECV_ERROR,
            CURLE_SEND_ERROR,
            CURLE_PARTIAL_FILE,
            CURLE_GOT_NOTHING,
            CURLE_SSL_CONNECT_ERROR,
        ]);
    }

    /**
     * Determine the response has an error.
     *
     * @return bool
     */
    public function hasError(): bool
    {
        return $this->failed();
    }

    /**
     * Determine the response is a redirect.
     * @return bool
     */
    public function isRedirect(): bool
    {
        return $this->getStatusCode() >= 300 && $this->getStatusCode() < 400;
    }

    /**
     * Determine the response is ok.
     *
     * @return bool
     */
    public function ok(): bool
    {
        return $this->getStatusCode() === 200;
    }

    /**
     * 201 Created
     * @return bool
     */
    public function created(): bool
    {
        return $this->getStatusCode() === 201;
    }

    /**
     * 202 Accepted
     * @return bool
     */
    public function accepted(): bool
    {
        return $this->getStatusCode() === 202;
    }

    /**
     * 203 Non-Authoritative Information
     * @return bool
     */
    public function nonAuthoritativeInformation(): bool
    {
        return $this->getStatusCode() === 203;
    }

    /**
     * 204 No Content
     * @return bool
     */
    public function noContent(): bool
    {
        return $this->getStatusCode() === 204;
    }

    /**
     * 205 Reset Content
     * @return bool
     */
    public function resetContent(): bool
    {
        return $this->getStatusCode() === 205;
    }

    /**
     * 301 Moved Permanently
     * @return bool
     */
    public function movedPermanently(): bool
    {
        return $this->getStatusCode() === 301;
    }

    /**
     * 302 Found
     * @return bool
     */
    public function found(): bool
    {
        return $this->getStatusCode() === 302;
    }

    /**
     * 304 Not modified
     * @return bool
     */
    public function notModified(): bool
    {
        return $this->getStatusCode() === 304;
    }

    /**
     * 400 Bad Request
     * @return bool
     */
    public function badRequest(): bool
    {
        return $this->getStatusCode() === 400;
    }

    /**
     * 401 Unauthorized
     * @return bool
     */
    public function unauthorized(): bool
    {
        return $this->getStatusCode() === 401;
    }

    /**
     * 402 Payment Required
     * @return bool
     */
    public function paymentRequired(): bool
    {
        return $this->getStatusCode() === 402;
    }

    /**
     * 403 Forbidden
     * @return bool
     */
    public function forbidden(): bool
    {
        return $this->getStatusCode() === 403;
    }

    /**
     * 404 Not Found
     * @return bool
     */
    public function notFound(): bool
    {
        return $this->getStatusCode() === 404;
    }

    /**
     * 405 Method Not Allowed
     * @return bool
     */
    public function methodNotAllowed(): bool
    {
        return $this->getStatusCode() === 405;
    }

    /**
     * 406 Not Acceptable
     * @return bool
     */
    public function notAcceptable(): bool
    {
        return $this->getStatusCode() === 406;
    }

    /**
     * 407 Proxy Authentication Required
     * @return bool
     */
    public function proxyAuthenticationRequired(): bool
    {
        return $this->getStatusCode() === 407;
    }

    /**
     * 408 Request Timeout
     * @return bool
     */
    public function requestTimeout(): bool
    {
        return $this->getStatusCode() === 408;
    }

    /**
     * 409 Conflict
     * @return bool
     */
    public function conflict(): bool
    {
        return $this->getStatusCode() === 409;
    }

    /**
     * 410 Gone
     * @return bool
     */
    public function gone(): bool
    {
        return $this->getStatusCode() === 410;
    }

    /**
     * 411 Length Required
     * @return bool
     */
    public function lengthRequired(): bool
    {
        return $this->getStatusCode() === 411;
    }

    /**
     * 422 Unprocessable Entity
     * @return bool
     */
    public function unprocessableEntity(): bool
    {
        return $this->getStatusCode() === 422;
    }

    /**
     * 429 Too Many Requests
     * @return bool
     */
    public function tooManyRequests(): bool
    {
        return $this->getStatusCode() === 429;
    }

    /**
     * 500 Internal Server Error
     * @return bool
     */
    public function internalServerError(): bool
    {
        return $this->getStatusCode() === 500;
    }

    /**
     * Get a response message.
     *
     * @return string|null
     */
    public function getMessage(): ?string
    {
       return $this->message;
    }

    /**
     * Determine is current content-type is JSON.
     *
     * @return bool
     */
    public function isJson(): bool
    {
        if ($this->isJson === null) {
            if (str_contains($this->getHeaderLine('Content-Type'), 'json')) {
                return $this->isJson = true;
            }

            $stream = $this->getBody();
            if (!$stream->isReadable() || !$stream->isSeekable()) {
                return $this->isJson = false;
            }

            $initialPosition = $stream->tell();
            $stream->rewind();
            $preview = $stream->read(16);
            $stream->seek($initialPosition);
            $firstChar = trim($preview)[0] ?? '';
            $this->isJson = $firstChar === '{' || $firstChar === '[';
        }

        return $this->isJson;
    }

    /**
     * Decode JSON.
     *
     * @param bool $associative
     * @return mixed
     */
    public function json(bool $associative = true): mixed
    {
        $body = trim($this->getRaw());
        if (!$this->isJson() || $body === '') {
            return null;
        }

        $decoded = json_decode($body, $associative);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                'JSON decode error: ' . json_last_error_msg()
            );
        }
        return $decoded;
    }

    /**
     * Get data in an array.
     *
     * @return array<string, mixed>
     * @deprecated Replaced with data()
     */
    public function getAttributes(): array
    {
        return $this->toArray();
    }

    /**
     * Get data in an array
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = $this->json();
        return is_array($data) ? $data : [];
    }

    /**
     * Convert to object.
     *
     * @return object|null
     */
    public function object(): ?object
    {
        $data = $this->json(false);
        return is_object($data) ? $data : null;
    }

    /**
     * Get raw body.
     *
     * @return string
     */
    public function getRaw(): string
    {
        return (string)$this->getBody();
    }

    /**
     * Get raw
     *
     * @return string
     * @see getRaw()
     */
    public function body(): string
    {
        return $this->getRaw();
    }

    /**
     * Get error code.
     *
     * @return int
     */
    public function getErrno(): int
    {
        return $this->errno;
    }

    /**
     * Get status code.
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Get total time.
     *
     * @return float
     */
    public function getTotalTime(): float
    {
        return isset($this->curlInfo['total_time']) ? (float) $this->curlInfo['total_time']: 0;
    }

    /**
     * Get sink path.
     *
     * @return string|null
     */
    public function getSinkPath(): ?string
    {
        return $this->sinkPath;
    }

    /**
     * Get a response attribute by key.
     *
     * @param string $key
     * @param mixed|null $default
     * @return mixed
     * @deprecated Replaced with data($key, $default = null)
     */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->data($key, $default);
    }

    /**
     * Get a response attribute by key.
     *
     * @param string|null $key
     * @param mixed|null $default
     * @return mixed
     */
    public function data(?string $key = null, mixed $default = null): mixed
    {
        if ($this->attributes === null) {
            $this->attributes = $this->toArray();
        }

        if ($this->attributes === []) {
            return $key === null ? [] : $default;
        }

        if ($key === null) {
            return $this->attributes;
        }

        $segments = explode('.', $key);
        return $this->getRecursive($this->attributes, $segments, $default);
    }

    /**
     * Get value from an array recursively.
     *
     * @param array<string|int, mixed> $array
     * @param string[] $segments
     * @param mixed $default
     * @return mixed
     */
    protected function getRecursive(array $array, array $segments, mixed $default = null): mixed
    {
        $segment = array_shift($segments);

        if ($segment === '*') {

            $result = [];

            foreach ($array as $item) {
                if (is_array($item)) {
                    $value = $this->getRecursive($item, $segments, $default);

                    if (is_array($value)) {
                        $result = array_merge($result, $value);
                    } else {
                        $result[] = $value;
                    }
                }
            }

            return $result;
        }

        if (isset($array[$segment])) {
            $value = $array[$segment];
        } else {
            return $default;
        }

        if (count($segments) === 0) {
            return $value;
        }

        if (!is_array($value)) {
            return $default;
        }

        return $this->getRecursive($value, $segments, $default);
    }

    /**
     * @inheritDoc
     */
    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    /**
     * @inheritDoc
     */
    public function withProtocolVersion(string $version): MessageInterface
    {
        $clone = clone $this;
        $clone->protocolVersion = $version;
        return $clone;
    }

    /**
     * @inheritDoc
     */
    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    /**
     * @inheritDoc
     */
    public function getHeader(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    /**
     * @inheritDoc
     */
    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    /**
     * @inheritDoc
     */
    public function withHeader(string $name, mixed $value): MessageInterface
    {
        $clone = clone $this;
        $clone->headers[strtolower($name)] = is_array($value) ? $value : [$value];
        return $clone;
    }

    /**
     * @inheritDoc
     */
    public function withAddedHeader(string $name, mixed $value): MessageInterface
    {
        $clone = clone $this;
        $name = strtolower($name);
        $existing = $clone->headers[$name] ?? [];
        $existing = is_array($existing) ? $existing : [$existing];
        $value = is_array($value) ? $value : [$value];

        $clone->headers[$name] = array_merge($existing, $value);

        return $clone;
    }

    /**
     * @inheritDoc
     */
    public function withoutHeader(string $name): MessageInterface
    {
        if ($this->hasHeader($name)) {
            $clone = clone $this;
            unset($clone->headers[strtolower($name)]);
            return $clone;
        }
        return $this;
    }

    /**
     * Get contents.
     *
     * @return string
     */
    public function getContents(): string
    {
        if ($this->body !== '') {
            return $this->body;
        }

        if ($this->sinkPath && is_file($this->sinkPath)) {
            return file_get_contents($this->sinkPath) ?: '';
        }

        return '';
    }

    /**
     * @inheritDoc
     */
    public function getBody(): StreamInterface
    {
        if ($this->stream === null) {
            if ($this->sinkPath !== null && is_file($this->sinkPath)) {
                $this->stream = new FileStream($this->sinkPath);
            } else {
                $this->stream = new StringStream($this->body);
            }
        }
        return $this->stream;
    }

    /**
     * @inheritDoc
     */
    public function withBody(StreamInterface $body): MessageInterface
    {
        $clone = clone $this;
        $clone->body = (string)$body;
        return $clone;
    }

    /**
     * @inheritDoc
     */
    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        $clone = clone $this;
        $clone->statusCode = $code;
        $clone->message = $reasonPhrase;
        return $clone;
    }

    /**
     * @inheritDoc
     */
    public function getReasonPhrase(): string
    {
        return $this->message;
    }
}
