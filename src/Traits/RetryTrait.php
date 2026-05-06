<?php

namespace Simsoft\HttpClient\Traits;

use Closure;
use Exception;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use Simsoft\HttpClient\Response;

/**
 * RetryTrait — retry logic and wait strategy.
 */
trait RetryTrait
{
    /** @var int Total retry count. 0 = no retries. */
    protected int $retry = 0;

    /** @var int|null Retry delay in milliseconds. */
    protected ?int $retryAfter = null;

    /** @var Closure|null Custom retry condition callback. */
    protected ?Closure $retryCallback = null;

    /**
     * Set the number of retries.
     *
     * @param int $times Number of retries (minimum 1).
     * @param int|null $after Delay between retries in milliseconds.
     * @return $this
     * @throws InvalidArgumentException|Exception
     */
    public function retry(int $times, ?int $after = null): static
    {
        if ($times < 1) {
            throw new InvalidArgumentException('The number of retries must be at least 1.');
        }
        $this->retry = $times;
        if (is_int($after) && $after < 0) {
            throw new InvalidArgumentException('Retry delay must be >= 0 milliseconds.');
        }
        $this->retryAfter = $after;
        return $this;
    }

    /**
     * Set a custom retry condition.
     *
     * @param Closure(Response, string, int): bool $callback
     * @return $this
     */
    public function retryWhen(Closure $callback): static
    {
        $this->retryCallback = $callback;
        return $this;
    }

    /**
     * Determine whether the request should be retried.
     *
     * @param Response $response
     * @param int $attempt Current 1-based attempt number.
     * @return bool
     */
    public function shouldRetry(Response $response, int $attempt = 1): bool
    {
        // Non-seekable streams cannot be retried reliably
        if ($this->postFields instanceof StreamInterface && !$this->postFields->isSeekable()) {
            return false;
        }

        if ($this->retryCallback !== null) {
            return (bool)($this->retryCallback)($response, $this->method, $attempt);
        }

        if ($response->isRetryableNetworkError()) {
            return true;
        }

        if ($response->isServerError()) {
            return in_array($this->method, ['GET', 'HEAD', 'OPTIONS']);
        }

        return false;
    }

    /**
     * Wait the configured delay before the next retry.
     *
     * @return void
     */
    protected function wait(): void
    {
        if ($this->retryAfter === null || $this->retryAfter <= 0) {
            return;
        }
        usleep($this->retryAfter * 1000);
    }

    /**
     * Reset retry state (called by flush).
     *
     * @return void
     */
    protected function resetRetry(): void
    {
        $this->retry = 0;
        $this->retryAfter = null;
        $this->retryCallback = null;
    }
}
