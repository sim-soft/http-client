<?php

namespace Simsoft\HttpClient;

use Simsoft\HttpClient\Interfaces\StorageInterface;

/**
 * SessionStorage class.
 */
class SessionStorage implements StorageInterface
{
    /**
     * Storage name
     *
     * @param string $name
     */
    public function __construct(protected string $name)
    {
        if (!in_array(session_status(), [PHP_SESSION_ACTIVE, PHP_SESSION_NONE])) {
            session_start();
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
    public function set(string $key, $value): void
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
