<?php

namespace Simsoft\HttpClient\Clients\Helpers;

use RuntimeException;
use Simsoft\HttpClient\Interfaces\StorageInterface;

/**
 * SessionStorage class.
 *
 * PHP session-backed token storage. Suitable for web applications where
 * tokens are scoped to the current user session.
 *
 * IMPORTANT: Objects stored here must be serializable. Do not store
 * Response objects that contain StreamInterface properties — store only
 * plain data transfer objects or serializable value objects.
 *
 * For CLI, queues, or long-running processes, use a database or cache-backed
 * StorageInterface implementation instead.
 */
class SessionStorage implements StorageInterface
{
    /**
     * Constructor.
     *
     * @param string $name Namespace key under $_SESSION for all stored values.
     * @throws RuntimeException When no session is active and one cannot be started.
     */
    public function __construct(protected string $name)
    {
        // PHP_SESSION_NONE = no session started yet → start one
        // PHP_SESSION_ACTIVE = session already running → do nothing
        // PHP_SESSION_DISABLED = sessions disabled → do nothing (has() will always return false)
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        // Starting a session fails when headers are already sent or the save
        // handler is unavailable. Silently continuing would write tokens into
        // a $_SESSION that is never persisted, so the caller is told instead.
        if (!session_start()) {
            throw new RuntimeException(
                'Unable to start a PHP session for token storage. '
                . 'Start the session before constructing SessionStorage, or use a different StorageInterface.'
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function has(string $key): bool
    {
        return isset($_SESSION[$this->name][$key]);
    }

    /**
     * @inheritDoc
     */
    public function set(string $key, mixed $value): void
    {
        $_SESSION[$this->name][$key] = $value;
    }

    /**
     * @inheritDoc
     */
    public function get(string $key): mixed
    {
        return $_SESSION[$this->name][$key] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function remove(string $key): void
    {
        unset($_SESSION[$this->name][$key]);
    }
}
