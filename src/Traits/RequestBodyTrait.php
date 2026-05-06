<?php

namespace Simsoft\HttpClient\Traits;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;

/**
 * RequestBodyTrait — manages request body preparation and content type handling.
 *
 * Provides methods for setting request bodies in various formats (JSON, form-encoded,
 * multipart, raw, GraphQL) and for configuring the default content type shorthand.
 */
trait RequestBodyTrait
{
    /** @var mixed Post fields. */
    protected mixed $postFields = null;

    /** @var bool Determine if the client owns the postFields stream. */
    protected bool $postFieldsOwned = false;

    /** @var string|null Default content type. */
    protected ?string $contentType = null;

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
     * Set the content type to application/json.
     *
     * @return $this
     */
    public function asJson(): self
    {
        $this->contentType = static::TYPE_JSON;
        return $this;
    }

    /**
     * Set the content type to application/x-www-form-urlencoded.
     *
     * @return $this
     */
    public function asForm(): self
    {
        $this->contentType = static::TYPE_FORM;
        return $this;
    }

    /**
     * Set the content type to multipart/form-data.
     *
     * @return $this
     */
    public function asMultipart(): self
    {
        $this->contentType = static::TYPE_MULTIPART;
        return $this;
    }

    /**
     * Set the content type to text/plain.
     *
     * @return $this
     */
    public function asRaw(): self
    {
        $this->contentType = static::TYPE_RAW;
        return $this;
    }
}
