<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests\Traits;

use CURLFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QuickCheck\Generator as Gen;
use QuickCheck\PHPUnit\PropertyConstraint;
use QuickCheck\Property;
use ReflectionMethod;
use Simsoft\HttpClient\HttpClient;

/**
 * AttachmentTraitPropertyTest class
 *
 * Property-based tests for the AttachmentTrait normalization logic.
 * Validates that attachment normalization preserves file content across
 * all input types (file path, resource, raw string).
 *
 * Feature: phpmd-compliance-fixes, Property 1: Attachment normalization preserves file content
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class AttachmentTraitPropertyTest extends TestCase
{
    /** @var string[] Temporary files to clean up after each test. */
    private array $tempFiles = [];

    /**
     * Clean up temporary files after each test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tempFiles = [];
    }

    /**
     * Property 1: Attachment normalization preserves file content.
     *
     * For any generated string content, normalizing via file path, resource,
     * and raw string input types all produce a CURLFile whose referenced file
     * contains identical bytes to the original content.
     *
     * **Validates: Requirements 7.3**
     *
     * @return void
     */
    #[Test]
    public function attachmentNormalizationPreservesFileContent(): void
    {
        $property = Property::forAll(
            [Gen::asciiStrings()],
            function (string $content): bool {
                return $this->verifyFilePathNormalization($content)
                    && $this->verifyResourceNormalization($content)
                    && $this->verifyRawStringNormalization($content);
            }
        );

        $this->assertThat(
            $property,
            PropertyConstraint::check(100)
        );
    }

    /**
     * Verify that normalizing a file path attachment preserves content.
     *
     * @param string $content The original content to verify.
     * @return bool True if the normalized CURLFile contains identical bytes.
     */
    private function verifyFilePathNormalization(string $content): bool
    {
        $tempFile = $this->createTempFileWithContent($content);
        $client = HttpClient::make();

        $result = $this->invokeNormalizeAttachment($client, $tempFile);

        if (!$result instanceof CURLFile) {
            return false;
        }

        $normalizedContent = file_get_contents($result->getFilename());

        return $normalizedContent === $content;
    }

    /**
     * Verify that normalizing a resource attachment preserves content.
     *
     * @param string $content The original content to verify.
     * @return bool True if the normalized CURLFile contains identical bytes.
     */
    private function verifyResourceNormalization(string $content): bool
    {
        $tempFile = $this->createTempFileWithContent($content);
        $resource = fopen($tempFile, 'rb');

        if (!$resource) {
            return false;
        }

        $client = HttpClient::make();
        $result = $this->invokeNormalizeAttachment($client, $resource);
        fclose($resource);

        if (!$result instanceof CURLFile) {
            return false;
        }

        $normalizedContent = file_get_contents($result->getFilename());

        return $normalizedContent === $content;
    }

    /**
     * Verify that normalizing a raw string attachment preserves content.
     *
     * @param string $content The original content to verify.
     * @return bool True if the normalized CURLFile contains identical bytes.
     */
    private function verifyRawStringNormalization(string $content): bool
    {
        $client = HttpClient::make();
        $result = $this->invokeNormalizeAttachment($client, $content);

        if (!$result instanceof CURLFile) {
            return false;
        }

        $normalizedContent = file_get_contents($result->getFilename());

        return $normalizedContent === $content;
    }

    /**
     * Invoke the protected normalizeAttachment method via reflection.
     *
     * @param HttpClient $client The client instance.
     * @param mixed $file The file input to normalize.
     * @return string|CURLFile The normalized attachment.
     */
    private function invokeNormalizeAttachment(HttpClient $client, mixed $file): string|CURLFile
    {
        $method = new ReflectionMethod($client, 'normalizeAttachment');

        return $method->invoke($client, $file, null, null);
    }

    /**
     * Create a temporary file with the given content.
     *
     * @param string $content The content to write.
     * @return string The path to the temporary file.
     */
    private function createTempFileWithContent(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'qc_attach_');

        if ($path === false) {
            throw new \RuntimeException('Failed to create temp file for test.');
        }

        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }
}
