<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests\Traits;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\HttpClient\Traits\Sandbox;

/**
 * SandboxHost class
 *
 * Concrete host class using the Sandbox trait for testing.
 */
class SandboxHost
{
    use Sandbox;

    /**
     * Constructor.
     *
     * Sets production and sandbox endpoints.
     */
    public function __construct()
    {
        $this->endpoint = 'https://api.example.com';
        $this->sandboxEndpoint = 'https://sandbox.example.com';
    }
}

/**
 * SandboxTraitTest class
 *
 * Tests for the Sandbox trait: production endpoint default,
 * sandbox mode switching, and URI appending.
 */
class SandboxTraitTest extends TestCase
{
    /** @var SandboxHost Host object using the Sandbox trait. */
    private SandboxHost $host;

    /**
     * Set up a fresh host instance before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->host = new SandboxHost();
    }

    /**
     * Test that getEndpoint() returns the production endpoint by default.
     *
     * @return void
     */
    #[Test]
    public function getEndpointReturnsProductionEndpointByDefault(): void
    {
        $result = $this->host->getEndpoint();

        $this->assertSame('https://api.example.com', $result);
    }

    /**
     * Test that sandbox() switches getEndpoint() to the sandbox endpoint.
     *
     * @return void
     */
    #[Test]
    public function sandboxSwitchesToSandboxEndpoint(): void
    {
        $returned = $this->host->sandbox();

        $this->assertSame($this->host, $returned);
        $this->assertSame('https://sandbox.example.com', $this->host->getEndpoint());
    }

    /**
     * Test that getEndpoint() appends the URI parameter to the active endpoint.
     *
     * @return void
     */
    #[Test]
    public function getEndpointAppendsUriParameter(): void
    {
        $result = $this->host->getEndpoint('/v1/users');

        $this->assertSame('https://api.example.com/v1/users', $result);

        $this->host->sandbox();
        $sandboxResult = $this->host->getEndpoint('/v1/users');

        $this->assertSame('https://sandbox.example.com/v1/users', $sandboxResult);
    }
}
