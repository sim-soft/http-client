<?php

namespace Simsoft\HttpClient\Traits;

use CURLFile;
use Exception;
use InvalidArgumentException;
use RuntimeException;

/**
 * AttachmentTrait — manages file attachment handling for HTTP requests.
 *
 * Provides methods for attaching files (CURLFile, resources, file paths, raw strings)
 * to multipart form requests, including normalization and temporary file management.
 */
trait AttachmentTrait
{
    /** @var bool Determine to attach files. */
    protected bool $hasAttachments = false;

    /** @var resource[] Temporary files resource. For attachment. */
    protected array $tmpFiles = [];

    /**
     * Attach file/ files.
     *
     * Auto set Content-type: multipart/form-data
     *
     * @param string $name Attribute name.
     * @param CURLFile|CURLFile[]|string|string[]|resource|resource[] $file
     * @param string|null $filename
     * @param string|null $mimeType
     * @return $this
     * @throws Exception
     */
    public function attach(string $name, mixed $file, ?string $filename = null, ?string $mimeType = null): self
    {
        if (!is_array($this->postFields)) {
            $this->postFields = [];
        }

        $this->hasAttachments = true;

        if (is_array($file)) {
            $name = rtrim($name, '[]') . '[]';
            foreach ($file as $attachment) {
                $this->postFields[$name][] = $this->normalizeAttachment($attachment, $filename, $mimeType);
            }

            return $this;
        }

        $this->postFields[$name] = $this->normalizeAttachment($file, $filename, $mimeType);
        return $this;
    }

    /**
     * Normalize attached file.
     *
     * Dispatches to type-specific helpers based on the input type.
     *
     * @param mixed $file
     * @param string|null $filename
     * @param string|null $mimeType
     * @return string|CURLFile
     * @throws Exception
     */
    protected function normalizeAttachment(mixed $file, ?string $filename = null, ?string $mimeType = null): string|CURLFile
    {
        if ($file instanceof CURLFile) {
            return $file;
        }

        if (is_resource($file)) {
            return $this->normalizeResourceAttachment($file, $filename, $mimeType);
        }

        if (is_string($file) && is_file($file)) {
            return $this->normalizeFilePathAttachment($file, $filename, $mimeType);
        }

        if (is_string($file)) {
            return $this->normalizeRawStringAttachment($file, $filename, $mimeType);
        }

        throw new InvalidArgumentException('Unsupported file type for attachment.');
    }

    /**
     * Normalize a resource attachment into a CURLFile.
     *
     * Handles open file resources by either referencing the underlying file path
     * directly, or copying the stream content to a temporary file.
     *
     * @param resource $file The resource to normalize.
     * @param string|null $filename Optional posted filename.
     * @param string|null $mimeType Optional MIME type.
     * @return CURLFile
     * @throws RuntimeException If a valid temp file cannot be created.
     */
    protected function normalizeResourceAttachment($file, ?string $filename, ?string $mimeType): CURLFile
    {
        /** @var array<string, mixed> $meta */
        $meta = stream_get_meta_data($file);
        $path = $meta['uri'] ?? null;

        if ($path && is_file($path)) {
            return new CURLFile(
                $path,
                mime_type: $mimeType,
                posted_filename: $filename ?? basename($path)
            );
        }

        if (isset($meta['seekable']) && $meta['seekable']) {
            rewind($file);
        }

        $tmp = $this->createTempFile();
        stream_copy_to_stream($file, $tmp);

        /** @var array<string, mixed> $tmpMeta */
        $tmpMeta = stream_get_meta_data($tmp);
        $tmpPath = $tmpMeta['uri'] ?? null;

        if (!$tmpPath || !is_file($tmpPath)) {
            throw new RuntimeException('Failed to create valid temp file for attachment.');
        }

        $this->tmpFiles[] = $tmp;

        return new CURLFile(
            $tmpPath,
            $mimeType,
            $filename ?? 'upload'
        );
    }

    /**
     * Normalize a file path string attachment into a CURLFile.
     *
     * Validates that the file path is readable before creating the CURLFile.
     *
     * @param string $file The file path to normalize.
     * @param string|null $filename Optional posted filename.
     * @param string|null $mimeType Optional MIME type.
     * @return CURLFile
     * @throws InvalidArgumentException If the file path is not readable.
     */
    protected function normalizeFilePathAttachment(string $file, ?string $filename, ?string $mimeType): CURLFile
    {
        if (!is_readable($file)) {
            throw new InvalidArgumentException("File path exists but is not readable: $file");
        }

        return new CURLFile(
            $file,
            $mimeType,
            $filename ?? basename($file)
        );
    }

    /**
     * Normalize a raw string attachment into a CURLFile.
     *
     * Writes the raw string content to a temporary file and returns a CURLFile
     * referencing that temporary file.
     *
     * @param string $file The raw string content to normalize.
     * @param string|null $filename Optional posted filename.
     * @param string|null $mimeType Optional MIME type.
     * @return CURLFile
     * @throws RuntimeException If a valid temp file cannot be created.
     */
    protected function normalizeRawStringAttachment(string $file, ?string $filename, ?string $mimeType): CURLFile
    {
        $tmp = $this->createTempFile();
        fwrite($tmp, $file);

        /** @var array{uri: string} $meta */
        $meta = stream_get_meta_data($tmp);
        $this->tmpFiles[] = $tmp;

        return new CURLFile(
            $meta['uri'],
            $mimeType ?? 'application/octet-stream',
            $filename ?? 'upload_' . uniqid()
        );
    }

    /**
     * Create a temporary file.
     *
     * @return resource
     */
    private function createTempFile()
    {
        $tmp = tmpfile();
        if (!$tmp) {
            $tmpDir = sys_get_temp_dir();
            $reason = !is_writable($tmpDir)
                ? "Temporary directory is not writable: $tmpDir"
                : "Unknown system error creating temp file.";
            throw new RuntimeException("Unable to create temporary file. $reason");
        }
        return $tmp;
    }
}
