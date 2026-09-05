<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests\Clients;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\HttpClient\Clients\Helpers\FileStorage;
use Simsoft\HttpClient\Clients\TokenData;

/**
 * FileStorageTest class
 *
 * Tests for the FileStorage class: CRUD operations, directory creation,
 * SHA-256 key hashing, and allowed_classes restriction on unserialize.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class FileStorageTest extends TestCase
{
    private string $testDir;

    private FileStorage $storage;

    /**
     * Set up a temporary directory for each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'file_storage_test_' . uniqid();
        $this->storage = new FileStorage($this->testDir);
    }

    /**
     * Clean up the temporary directory after each test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $files = glob($this->testDir . DIRECTORY_SEPARATOR . '*');
        if ($files !== false) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        if (is_dir($this->testDir)) {
            rmdir($this->testDir);
        }
    }

    /**
     * Test that the constructor creates the directory if it does not exist.
     *
     * @return void
     */
    #[Test]
    public function constructorCreatesDirectory(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fs_test_create_' . uniqid();
        $this->assertDirectoryDoesNotExist($dir);

        new FileStorage($dir);

        $this->assertDirectoryExists($dir);
        rmdir($dir);
    }

    /**
     * Test that has() returns false for a non-existent key.
     *
     * @return void
     */
    #[Test]
    public function hasReturnsFalseForMissingKey(): void
    {
        $this->assertFalse($this->storage->has('nonexistent'));
    }

    /**
     * Test that has() returns true after a value is stored.
     *
     * @return void
     */
    #[Test]
    public function hasReturnsTrueAfterSet(): void
    {
        $this->storage->set('my-key', 'my-value');

        $this->assertTrue($this->storage->has('my-key'));
    }

    /**
     * Test that get() returns null for a non-existent key.
     *
     * @return void
     */
    #[Test]
    public function getReturnsNullForMissingKey(): void
    {
        $this->assertNull($this->storage->get('nonexistent'));
    }

    /**
     * Test that get() returns the stored value.
     *
     * @return void
     */
    #[Test]
    public function getReturnsStoredValue(): void
    {
        $token = new TokenData(
            accessToken: 'abc123',
            expiresAt: time() + 3600,
            refreshToken: 'refresh_xyz',
            tokenType: 'Bearer',
            scope: 'read write',
        );

        $this->storage->set('client-id', $token);

        $retrieved = $this->storage->get('client-id');

        $this->assertInstanceOf(TokenData::class, $retrieved);
        $this->assertSame('abc123', $retrieved->accessToken);
        $this->assertSame('refresh_xyz', $retrieved->refreshToken);
        $this->assertSame('Bearer', $retrieved->tokenType);
        $this->assertSame('read write', $retrieved->scope);
    }

    /**
     * Test that set() overwrites an existing value.
     *
     * @return void
     */
    #[Test]
    public function setOverwritesExistingValue(): void
    {
        $this->storage->set('key', new TokenData('first', time() + 100));
        $this->storage->set('key', new TokenData('second', time() + 200));

        $retrieved = $this->storage->get('key');

        $this->assertInstanceOf(TokenData::class, $retrieved);
        $this->assertSame('second', $retrieved->accessToken);
    }

    /**
     * Test that remove() deletes the stored value.
     *
     * @return void
     */
    #[Test]
    public function removeDeletesStoredValue(): void
    {
        $this->storage->set('key', new TokenData('token', time() + 100));
        $this->assertTrue($this->storage->has('key'));

        $this->storage->remove('key');

        $this->assertFalse($this->storage->has('key'));
        $this->assertNull($this->storage->get('key'));
    }

    /**
     * Test that remove() does not throw for a non-existent key.
     *
     * @return void
     */
    #[Test]
    public function removeDoesNotThrowForMissingKey(): void
    {
        $this->storage->remove('nonexistent');

        $this->assertFalse($this->storage->has('nonexistent'));
    }

    /**
     * Test that file names use SHA-256 hashing.
     *
     * @return void
     */
    #[Test]
    public function filePathUsesSha256Hash(): void
    {
        $this->storage->set('test-key', new TokenData('token', time() + 100));

        $expectedFile = $this->testDir . DIRECTORY_SEPARATOR . hash('sha256', 'test-key') . '.token';

        $this->assertFileExists($expectedFile);
    }

    /**
     * Test that unserialize restricts to TokenData class only.
     *
     * @return void
     */
    #[Test]
    public function unserializeRestrictsAllowedClasses(): void
    {
        // Manually write a serialized stdClass to the storage file
        $key = 'injected';
        $filePath = $this->testDir . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.token';
        file_put_contents($filePath, serialize(new \stdClass()));

        $result = $this->storage->get($key);

        // stdClass should be blocked by allowed_classes restriction
        // PHP returns __PHP_Incomplete_Class instead
        $this->assertNotInstanceOf(\stdClass::class, $result);
    }

    /**
     * Test that default directory uses sys_get_temp_dir when no path is provided.
     *
     * @return void
     */
    #[Test]
    public function defaultDirectoryUsesSystemTempDir(): void
    {
        $storage = new FileStorage();
        $expectedDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'oauth_tokens';

        $this->assertDirectoryExists($expectedDir);
    }

    /**
     * Test that token files are not readable by other users on the host.
     *
     * Token files hold live access tokens; on a shared host the default
     * storage location is a world-writable temp directory.
     *
     * @return void
     */
    #[Test]
    public function tokenFilesAreNotReadableByOtherUsers(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX permissions are not enforced on Windows.');
        }

        $this->storage->set('account', new TokenData(accessToken: 'secret', tokenType: 'Bearer'));

        $files = glob($this->testDir . DIRECTORY_SEPARATOR . '*.token');
        $this->assertIsArray($files);
        $this->assertCount(1, $files);

        $this->assertSame(0600, fileperms($files[0]) & 0777, 'Token file must be owner-only.');
        $this->assertSame(0700, fileperms($this->testDir) & 0777, 'Token directory must be owner-only.');
    }

    /**
     * Test that an existing permissive directory is tightened on construction.
     *
     * A directory left behind by another process keeps the mode it was created
     * with, so the constructor must not simply trust it.
     *
     * @return void
     */
    #[Test]
    public function existingPermissiveDirectoryIsTightened(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX permissions are not enforced on Windows.');
        }

        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'file_storage_loose_' . uniqid();
        mkdir($dir, 0777, true);
        chmod($dir, 0777);

        new FileStorage($dir);

        $mode = fileperms($dir) & 0777;
        rmdir($dir);

        $this->assertSame(0700, $mode, 'An existing world-readable directory must be tightened.');
    }

    /**
     * Test that overwriting an existing token file keeps it owner-only.
     *
     * @return void
     */
    #[Test]
    public function rewritingATokenKeepsRestrictivePermissions(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX permissions are not enforced on Windows.');
        }

        $this->storage->set('account', new TokenData(accessToken: 'first', tokenType: 'Bearer'));

        $files = glob($this->testDir . DIRECTORY_SEPARATOR . '*.token');
        $this->assertIsArray($files);
        chmod($files[0], 0644);

        $this->storage->set('account', new TokenData(accessToken: 'second', tokenType: 'Bearer'));

        $this->assertSame(0600, fileperms($files[0]) & 0777);

        /** @var TokenData $token */
        $token = $this->storage->get('account');
        $this->assertSame('second', $token->accessToken);
    }
}
