<?php

namespace Simsoft\HttpClient\Traits;

/**
 * Sandbox trait.
 */
trait Sandbox
{
    /** @var string|null Production endpoint */
    protected ?string $endpoint;

    /** @var string|null Sandbox endpoint. */
    protected ?string $sandboxEndpoint;

    /** @var bool Determine is sandbox mode. */
    protected bool $sandboxMode = false;

    /**
     * Enable sandbox mode.
     *
     * @return static
     */
    public function sandbox(): static
    {
        $this->sandboxMode = true;
        return $this;
    }

    /**
     * Get endpoint.
     *
     * @param string|null $uri
     * @return string
     */
    public function getEndpoint(?string $uri = null): string
    {
        return ($this->sandboxMode ? $this->sandboxEndpoint :  $this->endpoint) . $uri;
    }
}
