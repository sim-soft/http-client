<?php

namespace Simsoft\HttpClient\Traits;

use CurlHandle;
use Psr\Http\Message\StreamInterface;

/**
 * PrepareHandleTrait — splits prepareHandle() into focused private methods,
 * reducing CyclomaticComplexity and NPathComplexity on the main class.
 */
trait PrepareHandleTrait
{
    /**
     * Prepare the cURL handle for the next request.
     *
     * @param string $requestId Unique ID for this request.
     * @return CurlHandle
     */
    private function prepareHandle(string $requestId): CurlHandle
    {
        $this->prepareUrl();
        $this->prepareMethodOptions();
        $this->prepareHeaders($requestId);
        $this->preparePostFields();
        $this->prepareDownloadOptions();

        $handle = $this->initCurlHandle();
        $this->applyTransferOptions($handle);

        curl_setopt($handle, CURLOPT_HTTPHEADER, $this->buildFinalHeaders());
        $this->applyCurlSettings($handle);

        return $handle;
    }

    /**
     * Build the full URL with query string and set CURLOPT_URL.
     *
     * @return void
     */
    private function prepareUrl(): void
    {
        $url = $this->getEndpoint();
        if (!empty($this->queryParams)) {
            $separator = str_contains($url, '?') ? '&' : '?';
            $url .= $separator . http_build_query($this->queryParams);
        }
        $this->options[CURLOPT_URL] = $url;
    }

    /**
     * Remove conflicting cURL method options for GET requests.
     *
     * @return void
     */
    private function prepareMethodOptions(): void
    {
        if ($this->method === 'GET') {
            unset($this->options[CURLOPT_POSTFIELDS], $this->options[CURLOPT_POST]);
        }
    }

    /**
     * Build and cache the static formatted headers, including content-type.
     *
     * @param string $requestId
     * @return void
     */
    private function prepareHeaders(string $requestId): void
    {
        if ($this->formattedHeaders !== null) {
            return;
        }

        $headers = array_change_key_case($this->headers);
        $headers['x-request-id'] ??= [$requestId];
        $headers['user-agent'] ??= [$this->userAgent];

        if ($this->hasAttachments) {
            unset($headers['content-type']);
        }

        if (!$this->hasAttachments && $this->contentType && !isset($headers['content-type'])) {
            $headers['content-type'] = [$this->resolveContentTypeHeader()];
        }

        $this->formattedHeaders = $this->buildFormattedHeaders($headers);
    }

    /**
     * Resolve the content-type header string from the current contentType constant.
     *
     * @return string
     */
    private function resolveContentTypeHeader(): string
    {
        $map = [
            static::TYPE_JSON => 'application/json',
            static::TYPE_FORM => 'application/x-www-form-urlencoded',
            static::TYPE_MULTIPART => 'multipart/form-data',
            static::TYPE_RAW => 'text/plain',
        ];
        return $map[$this->contentType] ?? (string)$this->contentType;
    }

    /**
     * Merge cached static headers with dynamic per-request headers (e.g. content-length).
     *
     * @return array<array-key, mixed>
     */
    private function buildFinalHeaders(): array
    {
        $dynamic = $this->buildDynamicHeaders();
        if ($dynamic !== []) {
            return array_merge($this->formattedHeaders ?? [], $this->buildFormattedHeaders($dynamic));
        }
        return $this->formattedHeaders ?? [];
    }

    /**
     * Build dynamic per-request headers such as Content-Length.
     *
     * @return array<string, mixed>
     */
    private function buildDynamicHeaders(): array
    {
        $headers = [];

        if ($this->postFields === null) {
            if (in_array($this->method, ['POST', 'PUT', 'PATCH'])) {
                $headers['content-length'] = ['0'];
            }
            return $headers;
        }

        if ($this->postFields instanceof StreamInterface) {
            $size = $this->postFields->getSize();
            if ($size !== null) {
                $headers['content-length'] = [(string)$size];
            }
            return $headers;
        }

        $fields = $this->options[CURLOPT_POSTFIELDS] ?? null;
        if (is_string($fields)) {
            $headers['content-length'] = [(string)strlen($fields)];
        }

        return $headers;
    }

    /**
     * Configure cURL options for the request body (postFields).
     *
     * @return void
     */
    private function preparePostFields(): void
    {
        if ($this->postFields === null) {
            return;
        }

        if ($this->postFields instanceof StreamInterface) {
            $this->prepareStreamPostFields($this->postFields);
            return;
        }

        $this->prepareArrayOrStringPostFields();
    }

    /**
     * Configure cURL for a PSR-7 StreamInterface body.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @param StreamInterface $stream
     * @return void
     */
    private function prepareStreamPostFields(StreamInterface $stream): void
    {
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $size = $stream->getSize();
        if ($size !== null) {
            $this->options[CURLOPT_INFILESIZE] = $size;
        }

        if ($this->method === 'PUT') {
            $this->options[CURLOPT_UPLOAD] = true;
        }

        if ($this->method !== 'PUT') {
            $this->options[CURLOPT_POST] = true;
            unset($this->options[CURLOPT_UPLOAD]);
        }

        $this->options[CURLOPT_READFUNCTION] = static function ($ch, $fd, int $length) use ($stream): string {
            return $stream->eof() ? '' : $stream->read($length);
        };

        unset($this->options[CURLOPT_POSTFIELDS]);
    }

    /**
     * Configure cURL for a standard array or string post body.
     *
     * @return void
     */
    private function prepareArrayOrStringPostFields(): void
    {
        unset(
            $this->options[CURLOPT_UPLOAD],
            $this->options[CURLOPT_INFILESIZE],
            $this->options[CURLOPT_READFUNCTION]
        );

        $fields = $this->postFields;
        if (is_array($fields)) {
            $fields = ($this->hasAttachments || $this->contentType === static::TYPE_MULTIPART)
                ? $this->flattenMultipartData($fields)
                : http_build_query($fields);
        }

        $this->options[CURLOPT_POSTFIELDS] = $fields;
    }

    /**
     * Configure cURL download/resume options from the sink resource.
     *
     * @return void
     */
    private function prepareDownloadOptions(): void
    {
        if (!is_resource($this->sink)) {
            return;
        }

        unset($this->options[CURLOPT_RESUME_FROM]);

        $downloadedSize = ftell($this->sink);
        if ($downloadedSize > 0) {
            $this->options[CURLOPT_RESUME_FROM] = $downloadedSize;
            return;
        }

        $meta = stream_get_meta_data($this->sink);
        if ($meta['seekable']) {
            ftruncate($this->sink, 0);
            rewind($this->sink);
        }
    }
}
