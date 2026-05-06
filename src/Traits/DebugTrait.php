<?php

namespace Simsoft\HttpClient\Traits;

/**
 * DebugTrait — request dumping and debugging helpers.
 */
trait DebugTrait
{
    /** @var bool Enable debug dump on next request. */
    protected bool $debugDump = false;

    /** @var bool Exit after debug dump (dd mode). */
    protected bool $debugDie = false;

    /**
     * Set debug flags and return $this so the chain continues.
     * The dump fires inside getCoreHandler() after prepareHandle(),
     * ensuring the full state (URL, headers, cURL options) is visible.
     * Execution then exits.
     *
     * @SuppressWarnings(PHPMD.ShortMethodName)
     *
     * @return static
     */
    public function dd(): static
    {
        $this->debugDump = true;
        $this->debugDie = true;
        return $this;
    }

    /**
     * Enable debug dump without stopping execution.
     * The dump fires after prepareHandle(), so the full state is visible,
     * then the request proceeds normally.
     *
     * @return $this
     */
    public function dump(): static
    {
        $this->debugDump = true;
        return $this;
    }

    /**
     * Output the current request state as a var_dump and optionally exit.
     *
     * @SuppressWarnings(PHPMD.ExitExpression)
     *
     * @return void
     */
    protected function debugDump(): void
    {
        if ($this->debugDump === false) {
            return;
        }

        $url = $this->buildDebugUrl();

        echo "<pre>";
        var_dump([
            'base_url' => $url,
            'resource_url' => $this->pendingUrl ?: null,
            'method' => $this->method,
            'curl_headers' => $this->headers,
            'curl_options' => $this->options,
            'query_params' => $this->queryParams,
            'post_fields' => $this->postFields,
            'has_attachments' => $this->hasAttachments,
            'sink_path' => $this->sinkPath,
        ]);
        echo "</pre>";

        if ($this->debugDie) {
            exit;
        }
    }

    /**
     * Build the debug URL from the available state.
     *
     * @return string
     */
    private function buildDebugUrl(): string
    {
        if (isset($this->options[CURLOPT_URL])) {
            return $this->options[CURLOPT_URL];
        }

        $url = $this->getEndpoint();
        if (!empty($this->queryParams)) {
            //$separator = str_contains($url, '?') ? '&' : '?';
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($this->queryParams);
        }

        return $url;
    }

    /**
     * Reset debug flags (called by flush).
     *
     * @return void
     */
    protected function resetDebug(): void
    {
        $this->debugDump = false;
        $this->debugDie = false;
    }
}
