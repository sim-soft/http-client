<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests\Clients;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\HttpClient\Clients\Helpers\SessionStorage;

/**
 * SessionStorageTest class
 *
 * Tests for SessionStorage CRUD operations using a simulated $_SESSION superglobal.
 */
#[RunTestsInSeparateProcesses]
class SessionStorageTest extends TestCase
{
    /** @var string Namespace key for session storage. */
    private string $namespace = 'test_storage';

    /** @var SessionStorage Storage instance under test. */
    private SessionStorage $storage;

    /**
     * Set up a fresh session and storage instance before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $_SESSION = [];
        $this->storage = new SessionStorage($this->namespace);
    }

    /**
     * Clean up session after each test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /**
     * Test that set() stores a value retrievable by get() with the same key.
     *
     * @return void
     */
    #[Test]
    public function setStoresValueRetrievableByGet(): void
    {
        $this->storage->set('token', 'abc123');

        $this->assertSame('abc123', $this->storage->get('token'));
    }

    /**
     * Test that has() returns true for existing keys and false for non-existing keys.
     *
     * @return void
     */
    #[Test]
    public function hasReturnsTrueForExistingAndFalseForNonExisting(): void
    {
        $this->assertFalse($this->storage->has('missing'));

        $this->storage->set('present', 'value');

        $this->assertTrue($this->storage->has('present'));
        $this->assertFalse($this->storage->has('missing'));
    }

    /**
     * Test that remove() deletes the key so has() returns false afterward.
     *
     * @return void
     */
    #[Test]
    public function removeDeletesKeySoHasReturnsFalse(): void
    {
        $this->storage->set('temp', 'data');
        $this->assertTrue($this->storage->has('temp'));

        $this->storage->remove('temp');

        $this->assertFalse($this->storage->has('temp'));
        $this->assertNull($this->storage->get('temp'));
    }

    /**
     * Test that get() returns null for non-existing keys.
     *
     * @return void
     */
    #[Test]
    public function getReturnsNullForNonExistingKeys(): void
    {
        $this->assertNull($this->storage->get('nonexistent'));
    }
}
