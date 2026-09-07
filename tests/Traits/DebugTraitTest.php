<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests\Traits;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Simsoft\HttpClient\HttpClient;

/**
 * DebugTraitTest class
 *
 * Tests for the DebugTrait: dump(), dd(), flag management, and resetDebug().
 */
class DebugTraitTest extends TestCase
{
    /**
     * Read a protected property value via reflection.
     *
     * @param HttpClient $client The client instance.
     * @param string $property The property name.
     * @return mixed
     */
    private function getProperty(HttpClient $client, string $property): mixed
    {
        $reflection = new ReflectionProperty($client, $property);

        return $reflection->getValue($client);
    }

    /**
     * Test that dump() sets debugDump to true without setting debugDie.
     *
     * @return void
     */
    #[Test]
    public function dumpSetsDebugDumpFlag(): void
    {
        $client = HttpClient::make()->dump();

        $this->assertTrue($this->getProperty($client, 'debugDump'));
        $this->assertFalse($this->getProperty($client, 'debugDie'));
    }

    /**
     * Test that dd() sets both debugDump and debugDie to true.
     *
     * @return void
     */
    #[Test]
    public function ddSetsBothFlags(): void
    {
        $client = HttpClient::make()->dd();

        $this->assertTrue($this->getProperty($client, 'debugDump'));
        $this->assertTrue($this->getProperty($client, 'debugDie'));
    }

    /**
     * Test that dump() returns the client instance for chaining.
     *
     * @return void
     */
    #[Test]
    public function dumpReturnsSelfForChaining(): void
    {
        $client = HttpClient::make();
        $result = $client->dump();

        $this->assertSame($client, $result);
    }

    /**
     * Test that dd() returns the client instance for chaining.
     *
     * @return void
     */
    #[Test]
    public function ddReturnsSelfForChaining(): void
    {
        $client = HttpClient::make();
        $result = $client->dd();

        $this->assertSame($client, $result);
    }

    /**
     * Test that dump() outputs request state when debugDump is true.
     *
     * @return void
     */
    #[Test]
    public function dumpOutputsRequestState(): void
    {
        $client = HttpClient::make()
            ->withBaseUrl('https://api.example.com')
            ->resource('/users')
            ->withQuery(['page' => 1])
            ->dump();

        // Use FakeHttpClient to avoid real network call but test dump output
        // Instead, test the output buffer directly via debugDump()
        $reflection = new \ReflectionMethod($client, 'debugDump');

        ob_start();
        $reflection->invoke($client);
        $output = (string)ob_get_clean();

        $this->assertStringContainsString('base_url', $output);
        $this->assertStringContainsString('https://api.example.com', $output);
        $this->assertStringContainsString('/users', $output);
    }

    /**
     * Test that debugDump() does nothing when debugDump flag is false.
     *
     * @return void
     */
    #[Test]
    public function debugDumpDoesNothingWhenFlagIsFalse(): void
    {
        $client = HttpClient::make()->withBaseUrl('https://api.example.com');

        $reflection = new \ReflectionMethod($client, 'debugDump');

        ob_start();
        $reflection->invoke($client);
        $output = (string)ob_get_clean();

        $this->assertSame('', $output);
    }

    /**
     * Test that resetDebug() clears both flags.
     *
     * @return void
     */
    #[Test]
    public function resetDebugClearsBothFlags(): void
    {
        $client = HttpClient::make()->dd();

        $this->assertTrue($this->getProperty($client, 'debugDump'));
        $this->assertTrue($this->getProperty($client, 'debugDie'));

        $reflection = new \ReflectionMethod($client, 'resetDebug');
        $reflection->invoke($client);

        $this->assertFalse($this->getProperty($client, 'debugDump'));
        $this->assertFalse($this->getProperty($client, 'debugDie'));
    }
}
