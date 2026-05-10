<?php

namespace Simsoft\HttpClient;

/**
 * PoolBuilder class.
 *
 * A request factory for HttpPool that mirrors HttpClient's HTTP method API
 * but returns configured HttpClient instances instead of executing requests.
 * Used with HttpPool::run() for a clean, closure-based pool definition.
 */
class PoolBuilder
{
    /** @var string Base URL applied to all requests built by this builder. */
    private string $baseUrl = '';

    /** @var array<string, mixed> Headers applied to all requests. */
    private array $headers = [];

    /** @var string|null Bearer token applied to all requests. */
    private ?string $bearerToken = null;

    /** @var bool Whether to use JSON content type for POST/PUT/PATCH requests. */
    private bool $jsonMode = false;

    /**
     * Set the base URL for all requests created by this builder.
     *
     * @param string $baseUrl The base URL prefix.
     *
     * @return $this
     */
    public function withBaseUrl(string $baseUrl): self
    {
        $this->baseUrl = $baseUrl;

        return $this;
    }

    /**
     * Set headers for all requests created by this builder.
     *
     * @param array<string, string> $headers Headers to apply.
     *
     * @return $this
     */
    public function withHeaders(array $headers): self
    {
        $this->headers = array_merge($this->headers, $headers);

        return $this;
    }

    /**
     * Set a bearer token for all requests created by this builder.
     *
     * @param string $token The bearer token.
     *
     * @return $this
     */
    public function withBearerToken(string $token): self
    {
        $this->bearerToken = $token;

        return $this;
    }

    /**
     * Set JSON mode for all POST/PUT/PATCH requests created by this builder.
     *
     * When enabled, request bodies are sent as JSON instead of multipart form data.
     *
     * @return $this
     */
    public function asJson(): self
    {
        $this->jsonMode = true;

        return $this;
    }

    /**
     * Create a GET request.
     *
     * @param string $uri The request URL or path.
     * @param array<string, mixed> $data Query parameters.
     *
     * @return HttpClient A configured HttpClient instance (not executed).
     */
    public function get(string $uri = '', array $data = []): HttpClient
    {
        $client = $this->buildClient($uri);

        if ($data !== []) {
            $client->withQuery($data);
        }

        return $client;
    }

    /**
     * Create a POST request.
     *
     * @param string $uri The request URL or path.
     * @param mixed $data The request body.
     *
     * @return HttpClient A configured HttpClient instance (not executed).
     */
    public function post(string $uri = '', mixed $data = null): HttpClient
    {
        $client = $this->buildClient($uri)->withMethod('POST');

        return $this->applyBody($client, $data);
    }

    /**
     * Create a PUT request.
     *
     * @param string $uri The request URL or path.
     * @param mixed $data The request body.
     *
     * @return HttpClient A configured HttpClient instance (not executed).
     */
    public function put(string $uri = '', mixed $data = null): HttpClient
    {
        $client = $this->buildClient($uri)->withMethod('PUT');

        return $this->applyBody($client, $data);
    }

    /**
     * Create a PATCH request.
     *
     * @param string $uri The request URL or path.
     * @param mixed $data The request body.
     *
     * @return HttpClient A configured HttpClient instance (not executed).
     */
    public function patch(string $uri = '', mixed $data = null): HttpClient
    {
        $client = $this->buildClient($uri)->withMethod('PATCH');

        return $this->applyBody($client, $data);
    }

    /**
     * Create a DELETE request.
     *
     * @param string $uri The request URL or path.
     * @param mixed $data The request body.
     *
     * @return HttpClient A configured HttpClient instance (not executed).
     */
    public function delete(string $uri = '', mixed $data = null): HttpClient
    {
        $client = $this->buildClient($uri)->withMethod('DELETE');

        return $this->applyBody($client, $data);
    }

    /**
     * Apply the request body using the appropriate content type.
     *
     * @param HttpClient $client The client to configure.
     * @param mixed $data The request body data.
     *
     * @return HttpClient The configured client.
     */
    private function applyBody(HttpClient $client, mixed $data): HttpClient
    {
        if ($data === null) {
            return $client;
        }

        if ($this->jsonMode) {
            $client->withJson($data);
            return $client;
        }

        $client->withMultipart($data);

        return $client;
    }

    /**
     * Build a base HttpClient with shared configuration applied.
     *
     * @param string $uri The request URL or path.
     *
     * @return HttpClient A configured HttpClient instance.
     */
    private function buildClient(string $uri): HttpClient
    {
        $client = HttpClient::make();

        if ($this->baseUrl !== '') {
            $client->withBaseUrl($this->baseUrl);
        }

        if ($this->headers !== []) {
            $client->withHeaders($this->headers);
        }

        if ($this->bearerToken !== null) {
            $client->withBearerToken($this->bearerToken);
        }

        $client->resource($uri);

        return $client;
    }
}
