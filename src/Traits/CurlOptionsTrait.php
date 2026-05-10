<?php

namespace Simsoft\HttpClient\Traits;

use CurlHandle;
use InvalidArgumentException;
use RuntimeException;

/**
 * CurlOptionsTrait — manages cURL handle lifecycle and option preparation.
 *
 * @phpmd:SuppressWarnings(StaticAccess)
 */
trait CurlOptionsTrait
{
    /** @var CurlHandle|null Reusable cURL handle. */
    protected ?CurlHandle $curlHandle = null;

    /**
     * Reset the cURL handle on clone to prevent shared handle corruption.
     *
     * @return void
     */
    public function __clone(): void
    {
        $this->curlHandle = null;
    }

    /** @var int Buffer size in bytes. Default: 8192. */
    protected int $bufferSize = 8192;

    /** @var int DNS cache timeout in seconds. Force DNS re-resolution every 60 seconds by default. */
    protected int $dnsTimeout = 60;

    /** @var int Execution timeout in seconds. */
    protected int $timeout = 30;

    /** @var int Connection timeout in seconds. */
    protected int $connectionTimeout = 5;

    /** @var bool Whether to return the transfer as a string. */
    protected bool $returnTransfer = true;

    /** @var array<int, mixed> Default cURL options. */
    protected array $options = [
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
    ];

    /**
     * Set buffer size (In bytes). Default: 8192 bytes.
     *
     * PHP default is 16,000 bytes = 16KB
     * Suggest set 128 thousand bytes = 128KB for large file download
     *
     * @param int $size
     * @return $this
     */
    public function withBufferSize(int $size): self
    {
        $this->bufferSize = $size;
        return $this;
    }

    /**
     * Set DNS cache timeout in seconds.
     *
     * @param int $seconds
     * @return $this
     */
    public function withDNSTimeout(int $seconds): self
    {
        $this->dnsTimeout = $seconds;
        return $this;
    }

    /**
     * Set the connection timeout in seconds.
     *
     * @param int $timeout Connection timeout in seconds. Default: 0 seconds.
     * @return $this
     */
    public function connectionTimeout(int $timeout): self
    {
        if ($timeout < 0) {
            throw new InvalidArgumentException('Connection timeout must be >= 0');
        }
        $this->connectionTimeout = $timeout;
        return $this;
    }

    /**
     * Set the execution timeout in seconds.
     *
     * @param int $timeout Timeout in seconds. 0 seconds means no timeout.
     * @return $this
     */
    public function timeout(int $timeout): self
    {
        if ($timeout < 0) {
            throw new InvalidArgumentException('Timeout must be >= 0');
        }
        $this->timeout = $timeout;
        return $this;
    }

    /**
     * Enable verbose cURL output.
     *
     * @return $this
     */
    public function verbose(): self
    {
        $this->options[CURLOPT_VERBOSE] = true;
        return $this;
    }

    /**
     * Disable TLS certificate verification.
     *
     * @return $this
     */
    public function withoutVerifying(): self
    {
        $this->options[CURLOPT_SSL_VERIFYPEER] = false;
        $this->options[CURLOPT_SSL_VERIFYHOST] = 0;
        return $this;
    }

    /**
     * Disable return transfer (output directly).
     *
     * @return $this
     */
    public function withoutReturnTransfer(): self
    {
        $this->returnTransfer = false;
        return $this;
    }

    /**
     * Set arbitrary cURL options.
     *
     * @param array<int, mixed> $options
     * @return $this
     */
    public function withOptions(array $options): self
    {
        foreach ($options as $option => $value) {
            $this->options[$option] = $value;
        }
        return $this;
    }

    /**
     * Initialize or reset the cURL handle.
     *
     * @return CurlHandle
     */
    protected function initCurlHandle(): CurlHandle
    {
        if ($this->curlHandle !== null) {
            curl_reset($this->curlHandle);
            return $this->curlHandle;
        }

        $this->curlHandle = curl_init();
        if ($this->curlHandle === false) {
            throw new RuntimeException('Failed to initialize cURL handle.');
        }

        return $this->curlHandle;
    }

    /**
     * Apply global cURL settings that never change between requests.
     *
     * @param CurlHandle $handle
     * @return void
     */
    protected function applyCurlSettings(CurlHandle $handle): void
    {
        curl_setopt($handle, CURLOPT_BUFFERSIZE, $this->bufferSize);
        curl_setopt($handle, CURLOPT_ENCODING, ''); // Disable automatic encoding. Allow Gzip/Brotli compression
        curl_setopt($handle, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0); // Enable HTTP/2 as it allows multiplexing
        curl_setopt($handle, CURLOPT_DNS_CACHE_TIMEOUT, $this->dnsTimeout);
        curl_setopt($handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($handle, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS); // PHP 8.1 Security Fix. deprecated. Use CURLOPT_REDIR_PROTOCOLS_STR

        //CURLOPT_REDIR_PROTOCOLS_STR => 'http,https', // Available PHP 8.3+
        //181 => 'http,https', // Use constant value represent CURLOPT_REDIR_PROTOCOLS_STR for PHP <8.3
        curl_setopt(
            $handle,
            defined('CURLOPT_REDIR_PROTOCOLS_STR') ? CURLOPT_REDIR_PROTOCOLS_STR : 181,
            'http,https'
        );
    }

    /**
     * Apply transfer options (return transfer, timeouts).
     *
     * @param CurlHandle $handle
     * @return void
     */
    protected function applyTransferOptions(CurlHandle $handle): void
    {
        $this->options[CURLOPT_CONNECTTIMEOUT] = $this->connectionTimeout;
        $this->options[CURLOPT_TIMEOUT] = $this->timeout;
        $this->options[CURLOPT_FAILONERROR] = false;
        $this->options[CURLOPT_NOSIGNAL] = 1;

        if (!isset($this->options[CURLOPT_FILE])
            && !isset($this->options[CURLOPT_WRITEFUNCTION])
            && $this->returnTransfer
        ) {
            $this->options[CURLOPT_RETURNTRANSFER] = true;
        }

        curl_setopt_array($handle, $this->options);
    }

    /**
     * Reset cURL options to defaults (called by flush).
     *
     * @return void
     */
    protected function resetCurlOptions(): void
    {
        $this->returnTransfer = true;
        $this->options = [
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
        ];
    }
}
