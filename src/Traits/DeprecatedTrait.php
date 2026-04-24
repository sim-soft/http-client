<?php

namespace Simsoft\HttpClient\Traits;

trait DeprecatedTrait
{
    /**
     * Prepare query params.
     *
     * @param array<string, mixed> $params
     * @return $this
     * @deprecated Use withQuery(array) instead.
     */
    public function query(array $params): self
    {
        trigger_error('query() is deprecated, use withQuery()', E_USER_DEPRECATED);
        return $this->withQuery($params);
    }

    /**
     * Prepare form-data request params.
     **
     * @param array<string, mixed> $data
     * @return $this
     * @deprecated Use withMultipart(array) instead.
     */
    public function formData(array $data): self
    {
        trigger_error('formData() is deprecated, use withMultipart()', E_USER_DEPRECATED);
        return $this->withForm($data);
    }

    /**
     * Prepare raw request params.
     *
     * For JSON/XML string, always set the type manually.
     *
     * @param string $data
     * @param string $type Content type. Default: application/json
     * @return $this
     * @deprecated Use withRaw(string) instead.
     */
    public function raw(string $data, string $type = 'text/plain'): self
    {
        trigger_error('raw() is deprecated, use withRaw()', E_USER_DEPRECATED);
        return $this->withBody($data, $type);
    }

    /**
     * Prepare array data to JSON body.
     *
     * @param array<string, mixed> $data
     * @return $this
     * @deprecated Use withJson(array) instead.
     */
    public function json(array $data): self
    {
        trigger_error('json() is deprecated, use withJson()', E_USER_DEPRECATED);
        return $this->withJson($data);
    }

    /**
     * Prepare GraphQL request params.
     *
     * @param string $query
     * @param array<string, mixed> $variables
     * @return $this
     * @deprecated Use withGraphQL(string, array) instead.
     */
    public function graphQL(string $query, array $variables = []): self
    {
        trigger_error('graphQL() is deprecated, use withGraphQL()', E_USER_DEPRECATED);
        return $this->withGraphQL($query, $variables);
    }
}
