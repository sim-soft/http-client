<?php

namespace Simsoft\HttpClient\Clients\Helpers;

use Simsoft\HttpClient\Clients\TokenData;
use Simsoft\HttpClient\Interfaces\StorageInterface;
use Throwable;

/**
 * FileStorage class.
 *
 * File-based token storage that persists serialized data to the filesystem.
 * Works in all contexts: web, CLI, queues, workers, and long-running processes.
 *
 * Each key is stored as a separate file in the configured directory, using
 * PHP's serialize/unserialize for data persistence.
 *
 * The storage directory defaults to the system temp directory under an
 * `oauth_tokens` subdirectory. You can provide a custom path via the
 * constructor.
 *
 * Files hold live access tokens, so they are created 0600 and the directory
 * 0700. On a shared host the default location is a world-writable temp
 * directory; prefer passing a path owned by the application user.
 */
class FileStorage implements StorageInterface
{
    /** @var int Permissions for the storage directory: owner only. */
    private const DIR_MODE = 0700;

    /** @var int Permissions for token files: owner read/write only. */
    private const FILE_MODE = 0600;

    /** @var string The directory where token files are stored. */
    private string $directory;

    /**
     * Constructor.
     *
     * @param string|null $directory Custom directory path for token files.
     *                               Defaults to sys_get_temp_dir()/oauth_tokens.
     */
    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'oauth_tokens';

        if (!is_dir($this->directory)) {
            mkdir($this->directory, self::DIR_MODE, true);
            return;
        }

        // An existing directory keeps whatever mode it was created with, which
        // for a shared temp directory may be world-readable.
        $this->restrictPermissions($this->directory, self::DIR_MODE);
    }

    /**
     * @inheritDoc
     */
    public function has(string $key): bool
    {
        return is_file($this->filePath($key));
    }

    /**
     * @inheritDoc
     */
    public function set(string $key, mixed $value): void
    {
        $path = $this->filePath($key);

        // Restrict the file before the token is written to it, so the secret is
        // never briefly readable under the default umask.
        if (!is_file($path)) {
            touch($path);
        }

        $this->restrictPermissions($path, self::FILE_MODE);

        file_put_contents($path, serialize($value), LOCK_EX);
    }

    /**
     * Tighten permissions on a token file or its directory.
     *
     * chmod is a no-op on Windows and may fail where the process does not own
     * the path; both are tolerated, since the alternative is refusing to store
     * a token that the caller has already obtained.
     *
     * The stat cache is cleared before the mode is read. On PHP 8.2 chmod()
     * does not invalidate that cache — it only began doing so in 8.3 — so a
     * path whose mode changed earlier in the same process reports its old
     * mode, and the early return below would skip the chmod that is the whole
     * point of this method.
     *
     * @param string $path The file or directory to restrict.
     * @param int $mode The octal permission mode to apply.
     * @return void
     */
    private function restrictPermissions(string $path, int $mode): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return;
        }

        clearstatcache(true, $path);

        if ((fileperms($path) & 0777) === $mode) {
            return;
        }

        @chmod($path, $mode);
    }

    /**
     * @inheritDoc
     */
    public function get(string $key): mixed
    {
        $path = $this->filePath($key);

        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false || $contents === '') {
            return null;
        }

        // A truncated or hand-edited file is a normal condition for a cache in
        // a shared temp directory, not a programming error, so it is reported
        // as an absent value rather than as a PHP warning the caller cannot
        // act on. The @ covers the default handler; the catch covers frameworks
        // that promote warnings to ErrorException, where an uncaught throw
        // would leave the client unable to replace the very file that broke it.
        // serialize(false) is the one legitimate value indistinguishable from a
        // failure, and is not a TokenData.
        try {
            $value = @unserialize($contents, ['allowed_classes' => [TokenData::class]]);
        } catch (Throwable) {
            return null;
        }

        return $value === false ? null : $value;
    }

    /**
     * @inheritDoc
     */
    public function remove(string $key): void
    {
        $path = $this->filePath($key);

        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * Build the full file path for a given storage key.
     *
     * Uses SHA-256 hash of the key to produce a safe, fixed-length filename.
     *
     * @param string $key The storage key.
     * @return string The full file path.
     */
    private function filePath(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.token';
    }
}
