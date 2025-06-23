<?php

namespace Simsoft\HttpClient\Interfaces;

/**
 * StorageInterface
 */
interface StorageInterface
{
    /**
     * Determine storage has key.
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool;

    /**
     * Set storage value with key.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set(string $key, mixed $value): void;

    /**
     * Get storage value by key.
     *
     * @param string $key
     * @return mixed
     */
    public function get(string $key): mixed;

    /**
     * Remove storage value by key.
     *
     * @param string $key
     * @return void
     */
    public function remove(string $key): void;
}
