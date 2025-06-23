<?php

namespace Simsoft\HttpClient;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;

/**
 * Request class.
 */
class HttpClient
{
    /** @var string Target endpoint. */
    protected string $baseUri;

    /** @var string  */
    protected string $method = 'GET';

    /** @var string[] Headers. */
    protected array $headers = [];

    /** @var string[]|int[] Query params. */
    protected array $queryParams = [];

    /** @var mixed Request body. */
    protected mixed $body = null;

    /** @var int Timeout. */
    protected int $timeout = 0;

    /** @var int Connection timeout. */
    protected int $connectionTimeout = 0;

    /** @var int Total retry if request failed. */
    protected int $retry = 0;

    /** @var string Default content type. */
    protected string $contentType = 'application/json';

    /** @var string  */
    protected string $responseClass = Response::class;

    /** @var array<int, mixed>  */
    protected array $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
    ];

    /**
     * Set request endpoint.
     *
     * @param string $endpoint
     * @return $this
     */
    public function withBaseUri(string $endpoint): self
    {
        $this->baseUri = $endpoint;
        return $this;
    }

    /**
     * Set request method.
     *
     * @param string $method
     * @return $this
     */
    public function withMethod(string $method): self
    {
        $this->method = strtoupper($method);
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
            $this->headers[$name] = $value;
        }
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
        $this->options = array_merge($this->options, $options);
        return $this;
    }

    /**
     * Set bearer token.
     *
     * @param string $token
     * @return $this
     */
    public function bearerToken(string $token): self
    {
        $this->headers['Authorization'] = "Bearer $token";
        return $this;
    }

    /**
     * Prepare query params.
     *
     * @param string[]|int[] $params
     * @return $this
     */
    public function query(array $params): self
    {
        $this->queryParams = $params;
        return $this;
    }

    /**
     * Prepare form-data request params.
     *
     * @param string[]|int[] $data
     * @return $this
     */
    public function formData(array $data): self
    {
        $this->body = $data;
        $this->contentType = 'multipart/form-data';
        return $this;
    }

    /**
     * Prepare x-www-form-urlencoded request params.
     *
     * @param string[]|int[] $data
     * @return $this
     */
    public function urlEncoded(array $data): self
    {
        $this->body = http_build_query($data);
        $this->contentType = 'application/x-www-form-urlencoded';
        return $this;
    }

    /**
     * Prepare raw request params.
     *
     * @param string $data
     * @param string $type
     * @return $this
     */
    public function raw(string $data, string $type = 'application/json'): self
    {
        $this->body = $data;
        $this->contentType = $type;
        return $this;
    }

    /**
     * Prepare GraphQL request params.
     *
     * @param string $query
     * @param string[]|int[] $variables
     * @return $this
     */
    public function graphQL(string $query, array $variables = []): self
    {
        $this->body = json_encode([
            'query' => $query,
            'variables' => $variables
        ]);
        $this->contentType = 'application/json';
        return $this;
    }

    /**
     * Set retry. Default: 0.
     *
     * @param int $times
     * @return $this
     */
    public function retry(int $times): static
    {
        $this->retry = $times;
        return $this;
    }

    /**
     * Perform GET request.
     *
     * @param string[]|int[] $params
     * @return Response
     */
    public function get(array $params = []): Response
    {
        if ($params) {
            $this->query($params);
        }

        return $this->request();
    }

    /**
     * Perform POST request.
     *
     * @return Response
     */
    public function post(): Response
    {
        return $this->withMethod('POST')->request();
    }

    /**
     * Perform PATCH request.
     *
     * @return Response
     */
    public function patch(): Response
    {
        return $this->withMethod('PATCH')->request();
    }

    /**
     * Perform DELETE request.
     *
     * @return Response
     */
    public function delete(): Response
    {
        return $this->withMethod('DELETE')->request();
    }

    /**
     * Set custom response class.
     *
     * @param string $class
     * @return $this
     */
    public function withResponseClass(string $class): static
    {
        if (!class_exists($class)) {
            throw new InvalidArgumentException("Response class is not exists: $class");
        }
        $this->responseClass = $class;
        return $this;
    }

    /**
     * Perform send request.
     *
     * @return Response
     */
    public function request(): Response
    {
        $attempts = $this->retry + 1;
        $url = $this->baseUri;
        if (!empty($this->queryParams)) {
            $url .= '?' . http_build_query($this->queryParams);
        }

        do {
            $curl = curl_init($url);

            $this->headers['Content-Type'] = $this->contentType;

            $this->options[CURLOPT_CUSTOMREQUEST] = $this->method;
            $this->options[CURLOPT_HTTPHEADER] = array_map(
                function($key, $value) { return "$key: $value";},
                array_keys($this->headers),
                $this->headers
            );

            if (in_array($this->method, ['POST', 'PATCH', 'PUT', 'DELETE'])) {
                $this->options[CURLOPT_POSTFIELDS] = $this->body;
            }

            if ($this->timeout) {
                $this->options[CURLOPT_TIMEOUT] = $this->timeout;
            }

            if ($this->connectionTimeout) {
                $this->options[CURLOPT_CONNECTTIMEOUT] = $this->connectionTimeout;
            }

            curl_setopt_array($curl, $this->options);

            $response = curl_exec($curl);
            $curlInfo = curl_getinfo($curl);
            $curlError = curl_error($curl);

            curl_close($curl);
            if ($response === false) {
                $attempts--;
                continue;
            }

            /** @var Response $response */
            $response = new $this->responseClass($curlInfo, $response, $curlError);
            return $response;
        } while ($attempts > 0);

        /** @var Response $response */
        $response = new $this->responseClass($curlInfo, false, $curlError);
        return $response;
    }
}
