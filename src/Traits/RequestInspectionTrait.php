<?php

namespace Simsoft\HttpClient\Traits;

/**
 * RequestInspectionTrait — read-only access to the pending request's headers
 * and query params, e.g. for logging from middleware.
 */
trait RequestInspectionTrait
{
    /**
     * Get the headers configured for the pending request.
     *
     * Per-request headers are merged over connection-scoped ones (such as the
     * bearer token) and the resolved Content-Type is included. Headers added
     * at send time — X-Request-Id, the default User-Agent and Content-Length —
     * are not part of the result.
     *
     * The result contains credentials such as Authorization; redact them
     * before writing it to a log.
     *
     * @return array<string, mixed> Header values keyed by lower-case name.
     */
    public function getRequestHeaders(): array
    {
        return $this->collectHeaders();
    }

    /**
     * Get the query params configured for the pending request.
     *
     * Params embedded directly in the resource URL are not included; they are
     * already part of getEndpoint().
     *
     * @return array<string|int, mixed>
     */
    public function getQueryParams(): array
    {
        return $this->queryParams;
    }
}
