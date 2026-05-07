<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests;

use OutOfBoundsException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Simsoft\HttpClient\HttpPoolResult;
use Simsoft\HttpClient\Response;

/**
 * HttpPoolResultTest class.
 *
 * Unit tests for the HttpPoolResult value object covering empty results,
 * successful/failed partitioning, index access, and count behavior.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class HttpPoolResultTest extends TestCase
{
    /**
     * Create a Response with the given HTTP status code.
     *
     * @param int $statusCode The HTTP status code.
     *
     * @return Response
     */
    private function createResponse(int $statusCode): Response
    {
        return new Response(curlInfo: ['http_code' => $statusCode]);
    }

    // ── Empty result tests ───────────────────────────────────────────

    /**
     * Test empty result returns zero count.
     */
    #[Test]
    public function emptyResultReturnsZeroCount(): void
    {
        $result = new HttpPoolResult([]);

        $this->assertSame(0, $result->count());
    }

    /**
     * Test empty result returns empty responses array.
     */
    #[Test]
    public function emptyResultReturnsEmptyResponsesArray(): void
    {
        $result = new HttpPoolResult([]);

        $this->assertSame([], $result->getResponses());
    }

    /**
     * Test empty result returns empty successful array.
     */
    #[Test]
    public function emptyResultReturnsEmptySuccessfulArray(): void
    {
        $result = new HttpPoolResult([]);

        $this->assertSame([], $result->getSuccessful());
    }

    /**
     * Test empty result returns empty failed array.
     */
    #[Test]
    public function emptyResultReturnsEmptyFailedArray(): void
    {
        $result = new HttpPoolResult([]);

        $this->assertSame([], $result->getFailed());
    }

    // ── All successful tests ─────────────────────────────────────────

    /**
     * Test all successful responses are returned by getSuccessful.
     */
    #[Test]
    public function allSuccessfulResponsesReturnedByGetSuccessful(): void
    {
        $responses = [
            $this->createResponse(200),
            $this->createResponse(201),
            $this->createResponse(204),
        ];

        $result = new HttpPoolResult($responses);

        $this->assertCount(3, $result->getSuccessful());
        $this->assertSame([], $result->getFailed());
    }

    /**
     * Test getSuccessful preserves original indices.
     */
    #[Test]
    public function getSuccessfulPreservesOriginalIndices(): void
    {
        $responses = [
            $this->createResponse(200),
            $this->createResponse(201),
            $this->createResponse(204),
        ];

        $result = new HttpPoolResult($responses);
        $successful = $result->getSuccessful();

        $this->assertArrayHasKey(0, $successful);
        $this->assertArrayHasKey(1, $successful);
        $this->assertArrayHasKey(2, $successful);
    }

    // ── All failed tests ─────────────────────────────────────────────

    /**
     * Test all failed responses are returned by getFailed.
     */
    #[Test]
    public function allFailedResponsesReturnedByGetFailed(): void
    {
        $responses = [
            $this->createResponse(400),
            $this->createResponse(404),
            $this->createResponse(500),
        ];

        $result = new HttpPoolResult($responses);

        $this->assertCount(3, $result->getFailed());
        $this->assertSame([], $result->getSuccessful());
    }

    /**
     * Test getFailed preserves original indices.
     */
    #[Test]
    public function getFailedPreservesOriginalIndices(): void
    {
        $responses = [
            $this->createResponse(400),
            $this->createResponse(404),
            $this->createResponse(500),
        ];

        $result = new HttpPoolResult($responses);
        $failed = $result->getFailed();

        $this->assertArrayHasKey(0, $failed);
        $this->assertArrayHasKey(1, $failed);
        $this->assertArrayHasKey(2, $failed);
    }

    // ── Mixed results partitioning tests ─────────────────────────────

    /**
     * Test mixed results are correctly partitioned into successful and failed.
     */
    #[Test]
    public function mixedResultsCorrectlyPartitioned(): void
    {
        $responses = [
            $this->createResponse(200),
            $this->createResponse(404),
            $this->createResponse(201),
            $this->createResponse(500),
            $this->createResponse(204),
        ];

        $result = new HttpPoolResult($responses);

        $successful = $result->getSuccessful();
        $failed = $result->getFailed();

        $this->assertCount(3, $successful);
        $this->assertCount(2, $failed);
    }

    /**
     * Test mixed results preserve original indices in successful partition.
     */
    #[Test]
    public function mixedResultsPreserveIndicesInSuccessful(): void
    {
        $responses = [
            $this->createResponse(200),
            $this->createResponse(404),
            $this->createResponse(201),
            $this->createResponse(500),
            $this->createResponse(204),
        ];

        $result = new HttpPoolResult($responses);
        $successful = $result->getSuccessful();

        $this->assertArrayHasKey(0, $successful);
        $this->assertArrayHasKey(2, $successful);
        $this->assertArrayHasKey(4, $successful);
        $this->assertArrayNotHasKey(1, $successful);
        $this->assertArrayNotHasKey(3, $successful);
    }

    /**
     * Test mixed results preserve original indices in failed partition.
     */
    #[Test]
    public function mixedResultsPreserveIndicesInFailed(): void
    {
        $responses = [
            $this->createResponse(200),
            $this->createResponse(404),
            $this->createResponse(201),
            $this->createResponse(500),
            $this->createResponse(204),
        ];

        $result = new HttpPoolResult($responses);
        $failed = $result->getFailed();

        $this->assertArrayHasKey(1, $failed);
        $this->assertArrayHasKey(3, $failed);
        $this->assertArrayNotHasKey(0, $failed);
        $this->assertArrayNotHasKey(2, $failed);
        $this->assertArrayNotHasKey(4, $failed);
    }

    /**
     * Test combined successful and failed counts equal total count.
     */
    #[Test]
    public function combinedSuccessfulAndFailedCountsEqualTotal(): void
    {
        $responses = [
            $this->createResponse(200),
            $this->createResponse(404),
            $this->createResponse(201),
            $this->createResponse(500),
            $this->createResponse(204),
        ];

        $result = new HttpPoolResult($responses);

        $totalPartitioned = count($result->getSuccessful()) + count($result->getFailed());
        $this->assertSame($result->count(), $totalPartitioned);
    }

    // ── getResponse() tests ──────────────────────────────────────────

    /**
     * Test getResponse returns correct response at valid index.
     */
    #[Test]
    public function getResponseReturnsCorrectResponseAtValidIndex(): void
    {
        $responses = [
            $this->createResponse(200),
            $this->createResponse(404),
            $this->createResponse(500),
        ];

        $result = new HttpPoolResult($responses);

        $this->assertSame($responses[0], $result->getResponse(0));
        $this->assertSame($responses[1], $result->getResponse(1));
        $this->assertSame($responses[2], $result->getResponse(2));
    }

    /**
     * Test getResponse throws OutOfBoundsException for negative index.
     */
    #[Test]
    public function getResponseThrowsExceptionForNegativeIndex(): void
    {
        $result = new HttpPoolResult([$this->createResponse(200)]);

        $this->expectException(OutOfBoundsException::class);
        $result->getResponse(-1);
    }

    /**
     * Test getResponse throws OutOfBoundsException for index beyond range.
     */
    #[Test]
    public function getResponseThrowsExceptionForIndexBeyondRange(): void
    {
        $result = new HttpPoolResult([$this->createResponse(200)]);

        $this->expectException(OutOfBoundsException::class);
        $result->getResponse(1);
    }

    /**
     * Test getResponse throws OutOfBoundsException on empty result.
     */
    #[Test]
    public function getResponseThrowsExceptionOnEmptyResult(): void
    {
        $result = new HttpPoolResult([]);

        $this->expectException(OutOfBoundsException::class);
        $result->getResponse(0);
    }

    // ── count() tests ────────────────────────────────────────────────

    /**
     * Test count returns correct total for various sizes.
     */
    #[Test]
    public function countReturnsCorrectTotal(): void
    {
        $this->assertSame(0, (new HttpPoolResult([]))->count());
        $this->assertSame(1, (new HttpPoolResult([$this->createResponse(200)]))->count());
        $this->assertSame(3, (new HttpPoolResult([
            $this->createResponse(200),
            $this->createResponse(404),
            $this->createResponse(500),
        ]))->count());
    }

    /**
     * Test count works with PHP count() function via Countable interface.
     */
    #[Test]
    public function countWorksWithPhpCountFunction(): void
    {
        $responses = [
            $this->createResponse(200),
            $this->createResponse(201),
        ];

        $result = new HttpPoolResult($responses);

        $this->assertSame(2, count($result));
    }
}
