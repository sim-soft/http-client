<?php

namespace Simsoft\HttpClient;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Response class.
 */
class Response implements ResponseInterface
{
    /** @var int Status code. */
    protected int $statusCode;

    /** @var string|bool Raw response body. */
    protected string|bool $body;

    /** @var array<string, mixed>|null Headers */
    protected ?array $headers = null;

    /** @var array<string|int, mixed>|null JSON content. */
    protected ?array $attributes = null;

    /** @var string Message. */
    protected string $message;

    /** @var array<string, mixed>|false CURL info */
    protected array|false $curlInfo;

    /**
     * Constructor.
     *
     * @param array<string, mixed>|false $curlInfo
     * @param string|bool $body
     * @param string $message
     */
    public function __construct(array|false $curlInfo, string|bool $body, string $message = '')
    {
        $this->curlInfo = $curlInfo;
        $this->body = $body;
        if (isset($curlInfo['http_code'])) {
            $this->withStatus($curlInfo['http_code'], $message);
        }
    }

    /**
     * Determine the response is ok.
     *
     * @return bool
     */
    public function ok(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * Determine the response has error.
     *
     * @return bool
     */
    public function hasError(): bool
    {
        return !$this->ok();
    }

    /**
     * Get response message.
     *
     * @return string|null
     */
    public function getMessage(): ?string
    {
       return $this->message;
    }

    /**
     * Get data.
     *
     * @param string|null $key
     * @param mixed|null $default
     * @return mixed
     */
    public function getAttributes(?string $key = null, mixed $default = null): mixed
    {
        if ($this->attributes === null) {
            if($this->body
                && is_string($this->body)
                && isset($this->curlInfo['header_size'])
                && is_int($this->curlInfo['header_size'])
                && ($content = substr($this->body, $this->curlInfo['header_size']))
            ){
                $this->attributes = $this->parseJson($content);
            } else {
                $this->attributes = [];
            }
        }
        return $key ? $this->getAttribute($key, $default) : $this->attributes;
    }

    /**
     * Parse JSON string.
     *
     * @param string $string
     * @return array<int, mixed>
     */
    protected function parseJson(string $string): array
    {
        $content = json_decode($string, true);
        return json_last_error() === JSON_ERROR_NONE ? $content :[];
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
     * Get headers.
     *
     * @return array<string, mixed>
     */
    public function getHeaders(): array
    {
        if ($this->headers === null) {
            $this->headers = [];
            if ($this->body
                && is_string($this->body)
                && isset($this->curlInfo['header_size'])
                && is_int($this->curlInfo['header_size'])
            ) {
                $rawHeaders = trim(substr($this->body, 0, $this->curlInfo['header_size']));
                foreach (explode("\r\n", $rawHeaders) as $line) {
                    if (strpos($line, ':') !== false) {
                        [$key, $value] = explode(':', $line, 2);
                        $this->headers[trim($key)] = trim($value);
                    }
                }
            }
        }
        return $this->headers;
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
     * Get response attribute by key.
     *
     * @param string $key
     * @param mixed|null $default
     * @return mixed
     */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        if ($key === '') {
            return $default;
        }

        if ($this->attributes === null) {
            $this->getAttributes();
        }

        if ($this->attributes === null) {
            return $default;
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
                $value = $this->getRecursive($item, $segments, $default);

                if (is_array($value)) {
                    $result = array_merge($result, $value);
                } else {
                    $result[] = $value;
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

        return $this->getRecursive($value, $segments, $default);
    }

    /**
     * @inheritDoc
     */
    public function getProtocolVersion(): string
    {
        if (isset($this->curlInfo['http_version'])) {
            switch ($this->curlInfo['http_version']) {
                case CURL_HTTP_VERSION_NONE: return 'HTTP/NONE';
                case CURL_HTTP_VERSION_1_0: return 'HTTP/1.0';
                case CURL_HTTP_VERSION_1_1: return 'HTTP/1.1';
                case CURL_HTTP_VERSION_2: return 'HTTP/2';
                case CURL_HTTP_VERSION_2TLS: return 'HTTP/2TLS';
                case CURL_HTTP_VERSION_2_0: return 'HTTP/2.0';
                case CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE: return 'HTTP/2 PRIOR KNOWLEDGE';
                case CURL_HTTP_VERSION_3: return 'HTTP/3';
                case CURL_HTTP_VERSION_3ONLY: return 'HTTP/3 ONLY';
            }
        }
        return 'Unknown';
    }

    /**
     * @inheritDoc
     */
    public function withProtocolVersion(string $version): MessageInterface
    {
        if ($this->curlInfo !== false) {
            $this->curlInfo['http_version'] = $version;
        }
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function hasHeader(string $name): bool
    {
        if ($this->headers === null) {  $this->getHeaders(); }
        return isset($this->headers[$name]);
    }

    /**
     * @inheritDoc
     */
    public function getHeader(string $name): array
    {
        return explode(',', $this->getHeaderLine($name));
    }

    /**
     * @inheritDoc
     */
    public function getHeaderLine(string $name): string
    {
        return $this->hasHeader($name) && isset($this->headers[$name]) ? $this->headers[$name] : '';
    }

    /**
     * @inheritDoc
     */
    public function withHeader(string $name, mixed $value): MessageInterface
    {
        if ($this->hasHeader($name)) {
            $this->withAddedHeader($name, $value);
        } else {
            $this->headers[$name] = $value;
        }
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function withAddedHeader(string $name, mixed $value): MessageInterface
    {
        if ($this->hasHeader($name) && $value && is_string($value) && isset($this->headers[$name])) {
            $this->headers[$name] .= ",$value";
        }
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function withoutHeader(string $name): MessageInterface
    {
        if ($this->hasHeader($name)) {
            unset($this->headers[$name]);
        }
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getBody(): StreamInterface
    {
        // TODO: Implement getBody() method.
    }

    /**
     * @inheritDoc
     */
    public function withBody(StreamInterface $body): MessageInterface
    {
        // TODO: Implement withBody() method.
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        $this->statusCode = $code;
        $this->message = $reasonPhrase;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getReasonPhrase(): string
    {
        return $this->message;
    }
}
