<?php

namespace Simsoft\HttpClient\Clients\Helpers;

use Simsoft\HttpClient\Clients\TokenData;
use Simsoft\HttpClient\Interfaces\StorageInterface;

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
 */
class FileStorage implements StorageInterface
{
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
            mkdir($this->directory, 0700, true);
        }
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
        file_put_contents($this->filePath($key), serialize($value), LOCK_EX);
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

        if ($contents === false) {
            return null;
        }

        return unserialize($contents, ['allowed_classes' => [TokenData::class]]);
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
