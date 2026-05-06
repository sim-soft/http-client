<?php

namespace Simsoft\HttpClient\Traits;

use InvalidArgumentException;

/**
 * SinkTrait — manages download/sink functionality for HTTP requests.
 *
 * Provides methods for file-based and stream-based download modes,
 * including destination validation and the internal write handler.
 */
trait SinkTrait
{
    /** @var mixed|null Download destination. */
    protected mixed $sink = null; // string|resource|null

    /** @var string|null Download the destination path. */
    protected ?string $sinkPath = null;

    /** @var bool Determine the client owns the sink. */
    protected bool $sinkOwned = false;

    /**
     * Download file using file-based mode (CURLOPT_FILE).
     *
     * Sets up the sink destination for direct file writing via cURL's
     * built-in file output mechanism.
     *
     * @param mixed $destination A file path (string) or an open resource.
     * @return $this
     * @throws InvalidArgumentException If the destination is invalid.
     */
    public function sink(mixed $destination): self
    {
        unset($this->options[CURLOPT_WRITEFUNCTION], $this->options[CURLOPT_FILE]);

        $this->prepareSinkDestination($destination);

        $this->options[CURLOPT_RETURNTRANSFER] = false;
        $this->options[CURLOPT_FILE] = $this->sink;

        return $this;
    }

    /**
     * Download file using stream-based mode (CURLOPT_WRITEFUNCTION).
     *
     * Sets up the sink destination for chunk-based writing via cURL's
     * write function callback.
     *
     * @param mixed $destination A file path (string) or an open resource.
     * @return $this
     * @throws InvalidArgumentException If the destination is invalid.
     */
    public function sinkStream(mixed $destination): self
    {
        unset($this->options[CURLOPT_WRITEFUNCTION], $this->options[CURLOPT_FILE]);

        $this->prepareSinkDestination($destination);

        $this->options[CURLOPT_RETURNTRANSFER] = false;
        $this->options[CURLOPT_WRITEFUNCTION] = [$this, 'writeToSink'];

        return $this;
    }

    /**
     * Validate and prepare the sink destination.
     *
     * Accepts either an open resource or a string file path. If a string path
     * is provided, the file is opened for writing and ownership is tracked.
     *
     * @param mixed $destination A file path (string) or an open resource.
     * @return void
     * @throws InvalidArgumentException If the destination cannot be used as a sink.
     */
    protected function prepareSinkDestination(mixed $destination): void
    {
        if (is_resource($destination)) {
            $this->sink = $destination;
            $this->sinkPath = null;

            $meta = stream_get_meta_data($this->sink);
            if ($meta['seekable']) {
                rewind($this->sink);
            }

            return;
        }

        if (is_string($destination)) {
            $handle = fopen($destination, 'w');
            $handle || throw new InvalidArgumentException("Unable to open file: $destination");
            $this->sinkOwned = true;
            $this->sink = $handle;
            $this->sinkPath = $destination;

            return;
        }

        throw new InvalidArgumentException('Sink must be file path or resource');
    }

    /**
     * Internal write handler to align cURL and Stream buffers.
     *
     * @param mixed $curlHandle The cURL handle (required by cURL API, unused).
     * @param string $data The data chunk to write.
     * @return int The number of bytes written.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function writeToSink(mixed $curlHandle, string $data): int
    {
        $written = fwrite($this->sink, $data);

        return $written === false ? 0 : $written;
    }
}
