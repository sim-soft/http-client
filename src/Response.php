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
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessivePublicCount)
 * The HTTP status helper methods (ok(), notFound(), etc.) are intentional
 * convenience aliases — suppressing these metrics is appropriate here.
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
        protected string  $body = '',
        protected string  $message = '',
        protected ?string $sinkPath = null,
        protected int     $errno = 0,
        protected string  $rawHeaders = '',
    )
    {
        $this->statusCode = $curlInfo['http_code'] ?? 0;
        $this->setHeaders($rawHeaders);
    }

    /**
     * Parse raw HTTP headers, keeping only the final response block.
     *
     * @param string $rawHeaders
     * @return void
     */
    protected function setHeaders(string $rawHeaders): void
    {
        $this->headers = [];

        $blocks = preg_split('/\r?\n\r?\n/', trim($rawHeaders));
        $lastBlock = $blocks === false ? '' : (end($blocks) ?: '');
        if ($lastBlock === '') {
            return;
        }

        $lines = explode("\n", str_replace("\r", "", $lastBlock));
        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            if ($index === 0 && str_starts_with($line, 'HTTP/')) {
                $this->parseStatusLine($line);
                continue;
            }

            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $this->headers[trim($key)][] = trim($value);
            }
        }

        $this->headers = array_change_key_case($this->headers);
    }

    /**
     * Parse the HTTP status line to extract the protocol version and reason phrase.
     *
     * @param string $line
     * @return void
     */
    private function parseStatusLine(string $line): void
    {
        $parts = explode(' ', $line, 3);

        if (isset($parts[0])) {
            $this->protocolVersion = str_replace('HTTP/', '', $parts[0]);
        }

        if (isset($parts[2]) && ($this->message === '' || $this->statusCode > 0)) {
            $this->message = trim($parts[2]);
        }
    }

    /**
     * Get all response headers.
     *
     * @return array<string, mixed>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Determine if the response status is 2xx.
     *
     * @return bool
     */
    public function successful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * Determine if the response status is 4xx or 5xx, or a network error.
     *
     * @return bool
     */
    public function failed(): bool
    {
        return $this->statusCode >= 400 || $this->isNetworkError();
    }

    /**
     * Determine if the response status is 4xx.
     *
     * @return bool
     */
    public function isClientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }

    /**
     * Determine if the response status is 5xx.
     *
     * @return bool
     */
    public function isServerError(): bool
    {
        return $this->statusCode >= 500;
    }

    /**
     * Determine if there was a network-level error (non-zero cURL errno).
     *
     * @return bool
     */
    public function isNetworkError(): bool
    {
        return $this->errno !== 0;
    }

    /**
     * Determine if the error is a retryable network error.
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
     * Alias for failed().
     *
     * @return bool
     */
    public function hasError(): bool
    {
        return $this->failed();
    }

    /**
     * Determine if the response is a 3xx redirect.
     *
     * @return bool
     */
    public function isRedirect(): bool
    {
        return $this->statusCode >= 300 && $this->statusCode < 400;
    }

    /**
     * Determine if the response status is exactly 200.
     *
     * @return bool
     *
     * @SuppressWarnings(PHPMD.ShortMethodName)
     */
    public function ok(): bool
    {
        return $this->statusCode === 200;
    }

    /** @return bool */
    public function created(): bool
    {
        return $this->statusCode === 201;
    }

    /** @return bool */
    public function accepted(): bool
    {
        return $this->statusCode === 202;
    }

    /** @return bool */
    public function noContent(): bool
    {
        return $this->statusCode === 204;
    }

    /** @return bool */
    public function movedPermanently(): bool
    {
        return $this->statusCode === 301;
    }

    /** @return bool */
    public function found(): bool
    {
        return $this->statusCode === 302;
    }

    /** @return bool */
    public function notModified(): bool
    {
        return $this->statusCode === 304;
    }

    /** @return bool */
    public function badRequest(): bool
    {
        return $this->statusCode === 400;
    }

    /** @return bool */
    public function unauthorized(): bool
    {
        return $this->statusCode === 401;
    }

    /** @return bool */
    public function forbidden(): bool
    {
        return $this->statusCode === 403;
    }

    /** @return bool */
    public function notFound(): bool
    {
        return $this->statusCode === 404;
    }

    /** @return bool */
    public function methodNotAllowed(): bool
    {
        return $this->statusCode === 405;
    }

    /** @return bool */
    public function conflict(): bool
    {
        return $this->statusCode === 409;
    }

    /** @return bool */
    public function unprocessableEntity(): bool
    {
        return $this->statusCode === 422;
    }

    /** @return bool */
    public function tooManyRequests(): bool
    {
        return $this->statusCode === 429;
    }

    /** @return bool */
    public function internalServerError(): bool
    {
        return $this->statusCode === 500;
    }

    /**
     * Get the response reason phrase or cURL error message.
     *
     * @return string|null
     */
    public function getMessage(): ?string
    {
        return $this->message;
    }

    /**
     * Determine if the response body is JSON.
     * First checks Content-Type header, then peeks at the first byte.
     *
     * @return bool
     */
    public function isJson(): bool
    {
        if ($this->isJson !== null) {
            return $this->isJson;
        }

        if (str_contains($this->getHeaderLine('Content-Type'), 'json')) {
            return $this->isJson = true;
        }

        return $this->isJson = $this->sniffJsonFromBody();
    }

    /**
     * Peek at the first non-whitespace byte to detect JSON.
     *
     * @return bool
     */
    private function sniffJsonFromBody(): bool
    {
        $stream = $this->getBody();

        if (!$stream->isReadable() || !$stream->isSeekable()) {
            return false;
        }

        $initialPosition = $stream->tell();
        $stream->rewind();
        $preview = $stream->read(16);
        $stream->seek($initialPosition);
        $firstChar = trim($preview)[0] ?? '';

        return $firstChar === '{' || $firstChar === '[';
    }

    /**
     * Decode the response body as JSON and return an associative array.
     *
     * @return mixed
     */
    public function json(): mixed
    {
        return $this->decodeJson(true);
    }

    /**
     * Decode the response body as JSON and return the stdClass object.
     *
     * @return object|null
     */
    public function object(): ?object
    {
        $data = $this->decodeJson(false);
        return is_object($data) ? $data : null;
    }

    /**
     * Internal JSON decoder shared by json() and object().
     *
     * Replaces the BooleanArgumentFlag violation on the old json($associative) method.
     *
     * @param bool $associative
     * @return mixed
     */
    private function decodeJson(bool $associative): mixed
    {
        $body = trim($this->getRaw());
        if (!$this->isJson() || $body === '') {
            return null;
        }

        $decoded = json_decode($body, $associative);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('JSON decode error: ' . json_last_error_msg());
        }

        return $decoded;
    }

    /**
     * Get data in an array.
     *
     * @return array<string, mixed>
     * @deprecated Use data() instead.
     */
    public function getAttributes(): array
    {
        return $this->toArray();
    }

    /**
     * Get the response body decoded as an associative array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = $this->json();
        return is_array($data) ? $data : [];
    }

    /**
     * Get the raw response body string.
     *
     * @return string
     */
    public function getRaw(): string
    {
        return (string)$this->getBody();
    }

    /**
     * Alias for getRaw().
     *
     * @return string
     */
    public function body(): string
    {
        return $this->getRaw();
    }

    /**
     * Get the cURL error number.
     *
     * @return int
     */
    public function getErrno(): int
    {
        return $this->errno;
    }

    /**
     * @inheritDoc
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Get the total request time in seconds.
     *
     * @return float
     */
    public function getTotalTime(): float
    {
        return isset($this->curlInfo['total_time'])
            ? (float)$this->curlInfo['total_time']
            : 0.0;
    }

    /**
     * Get the file path where the response body was saved (sink).
     *
     * @return string|null
     */
    public function getSinkPath(): ?string
    {
        return $this->sinkPath;
    }

    /**
     * Get a response attribute by the dot-notation key.
     *
     * @param string $key
     * @param mixed|null $default
     * @return mixed
     * @deprecated Use data($key, $default) instead.
     */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->data($key, $default);
    }

    /**
     * Get a value from the decoded JSON body using dot notation.
     * Returns the full decoded array when $key is null.
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

        return $this->getRecursive($this->attributes, explode('.', $key), $default);
    }

    /**
     * Recursively resolve a dot-notation path through nested arrays.
     * Supports the * wildcard to collect values across all items.
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
            return $this->collectWildcard($array, $segments, $default);
        }

        if (!isset($array[$segment])) {
            return $default;
        }

        $value = $array[$segment];

        if (count($segments) === 0) {
            return $value;
        }

        if (!is_array($value)) {
            return $default;
        }

        return $this->getRecursive($value, $segments, $default);
    }

    /**
     * Collect values from all items in an array using the remaining segments.
     *
     * @param array<string|int, mixed> $array
     * @param string[] $segments
     * @param mixed $default
     * @return array<int, mixed>
     */
    private function collectWildcard(array $array, array $segments, mixed $default): array
    {
        $result = [];

        foreach ($array as $item) {
            if (!is_array($item)) {
                continue;
            }
            $value = $this->getRecursive($item, $segments, $default);
            if (is_array($value)) {
                $result = array_merge($result, $value);
                continue;
            }
            $result[] = $value;
        }

        return $result;
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
        $existing = is_array($clone->headers[$name] ?? [])
            ? ($clone->headers[$name] ?? [])
            : [$clone->headers[$name]];
        $clone->headers[$name] = array_merge($existing, (array)$value);
        return $clone;
    }

    /**
     * @inheritDoc
     */
    public function withoutHeader(string $name): MessageInterface
    {
        if (!$this->hasHeader($name)) {
            return $this;
        }

        $clone = clone $this;
        unset($clone->headers[strtolower($name)]);
        return $clone;
    }

    /**
     * Get the full response body as a string.
     * Falls back to the sink file when the body is empty.
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
        if ($this->stream !== null) {
            return $this->stream;
        }

        $this->stream = ($this->sinkPath !== null && is_file($this->sinkPath))
            ? new FileStream($this->sinkPath)
            : new StringStream($this->body);

        return $this->stream;
    }

    /**
     * @inheritDoc
     */
    public function withBody(StreamInterface $body): MessageInterface
    {
        $clone = clone $this;
        $clone->body = (string)$body;
        $clone->stream = null;
        $clone->attributes = null;
        $clone->isJson = null;
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
