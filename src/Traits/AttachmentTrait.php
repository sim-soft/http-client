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
            $this->attachMany($name, $file, $filename, $mimeType);
            return $this;
        }

        $this->postFields[$name] = $this->normalizeAttachment($file, $filename, $mimeType);
        return $this;
    }

    /**
     * Attach several files under one field name.
     *
     * Each file gets an explicitly indexed name — `files[0]`, `files[1]` — rather
     * than a bare `files[]`. cURL sends a multipart field name verbatim and does
     * not expand `[]` into successive indices the way a browser does, so a shared
     * `files[]` key would need one array entry per file and the index has to be
     * written out. Indices continue from any files already attached under the
     * same name, so repeated calls append instead of overwriting.
     *
     * @param string $name Attribute name, with or without a trailing `[]`.
     * @param array<array-key, mixed> $files
     * @param string|null $filename
     * @param string|null $mimeType
     * @return void
     * @throws Exception
     */
    protected function attachMany(string $name, array $files, ?string $filename, ?string $mimeType): void
    {
        $base = str_ends_with($name, '[]') ? substr($name, 0, -2) : $name;
        $index = 0;

        foreach ($files as $attachment) {
            while (isset($this->postFields["{$base}[$index]"])) {
                ++$index;
            }

            $this->postFields["{$base}[$index]"] = $this->normalizeAttachment($attachment, $filename, $mimeType);
            ++$index;
        }
    }

    /**
     * Normalize an attached file.
     *
     * Dispatches to type-specific helpers based on the input type.
     *
     * @param mixed $file
     * @param string|null $filename
     * @param string|null $mimeType
     * @return string|CURLFile
     * @throws Exception
     */
    protected function normalizeAttachment(
        mixed $file,
        ?string $filename = null,
        ?string $mimeType = null
    ): string|CURLFile {
        if ($file instanceof CURLFile) {
            return $this->normalizeCurlFileAttachment($file, $filename);
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
     * Normalize a caller-supplied CURLFile.
     *
     * A CURLFile constructed without a posted filename reports an empty one, and
     * cURL then falls back to the full local path — so `new CURLFile('/srv/app/
     * storage/invoices/2026-03.pdf')` puts that path in the part header for the
     * server to log. The basename is substituted so only the file's own name is
     * sent, matching what the other input types already do. An explicit filename
     * is applied on a copy, leaving the caller's object untouched.
     *
     * @param CURLFile $file The caller-supplied file.
     * @param string|null $filename Optional posted filename.
     * @return CURLFile
     */
    protected function normalizeCurlFileAttachment(CURLFile $file, ?string $filename): CURLFile
    {
        $posted = $filename ?? ($file->getPostFilename() ?: basename($file->getFilename()));

        if ($posted === $file->getPostFilename()) {
            return $file;
        }

        $copy = clone $file;
        $copy->setPostFilename($posted);

        return $copy;
    }

    /**
     * Normalize a resource attachment into a CURLFile.
     *
     * Handles open file resources by either referencing the underlying file path
     * directly or copying the stream content to a temporary file.
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

        // stream_get_meta_data() does not guarantee uri for every stream type,
        // and CURLFile needs a real path. A tmpfile() stream always has one, so
        // this reports the same failure createTempFile() would rather than
        // handing cURL an empty filename.
        $meta = stream_get_meta_data($tmp);
        if (!isset($meta['uri'])) {
            throw new RuntimeException('Unable to determine the path of the temporary file for an attachment.');
        }

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
