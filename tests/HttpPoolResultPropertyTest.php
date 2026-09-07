<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QuickCheck\Generator as Gen;
use QuickCheck\PHPUnit\PropertyConstraint;
use QuickCheck\Property;
use Simsoft\HttpClient\HttpPoolResult;
use Simsoft\HttpClient\Response;

/**
 * HttpPoolResultPropertyTest class.
 *
 * Property-based tests for HttpPoolResult partitioning behavior.
 * Validates that getSuccessful() and getFailed() form a complete partition
 * of all responses in the result set.
 *
 * Feature: http-pool-and-testing, Property 5: Pool Result Partitioning
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class HttpPoolResultPropertyTest extends TestCase
{
    /**
     * Property 5: Pool Result Partitioning.
     *
     * For any HttpPoolResult containing N responses, getSuccessful() and
     * getFailed() SHALL form a complete partition — every response appears
     * in exactly one of the two sets, getSuccessful() contains only responses
     * where successful() is true, getFailed() contains only responses where
     * failed() is true, and their combined count equals N.
     *
     * **Validates: Requirements 4.3, 4.4**
     *
     * @return void
     */
    #[Test]
    public function poolResultPartitioning(): void
    {
        $statusCodes = [200, 201, 204, 400, 401, 403, 404, 500, 502, 503];

        $property = Property::forAll(
            [Gen::choose(1, 50)],
            function (int $count) use ($statusCodes): bool {
                $responses = $this->generateResponses($count, $statusCodes);
                $result = new HttpPoolResult($responses);

                $successful = $result->getSuccessful();
                $failed = $result->getFailed();

                return $this->verifyCombinedCountEqualsTotal($successful, $failed, $count)
                    && $this->verifySuccessfulOnlyContainsSuccessful($successful)
                    && $this->verifyFailedOnlyContainsFailed($failed)
                    && $this->verifyNoOverlap($successful, $failed)
                    && $this->verifyCompletePartition($successful, $failed, $responses);
            }
        );

        $this->assertThat(
            $property,
            PropertyConstraint::check(100)
        );
    }

    /**
     * Generate an array of Response objects with random status codes.
     *
     * @param int $count Number of responses to generate.
     * @param int[] $statusCodes Pool of status codes to choose from.
     * @return array<int, Response> Generated responses indexed sequentially.
     */
    private function generateResponses(int $count, array $statusCodes): array
    {
        $responses = [];

        for ($idx = 0; $idx < $count; $idx++) {
            $code = $statusCodes[array_rand($statusCodes)];
            $responses[$idx] = new Response(
                curlInfo: ['http_code' => $code],
                body: '',
                message: '',
            );
        }

        return $responses;
    }

    /**
     * Verify that the combined count of successful and failed equals total.
     *
     * @param array<int|string, Response> $successful Successful responses.
     * @param array<int|string, Response> $failed Failed responses.
     * @param int $total Expected total count.
     * @return bool True if combined count equals total.
     */
    private function verifyCombinedCountEqualsTotal(array $successful, array $failed, int $total): bool
    {
        return (count($successful) + count($failed)) === $total;
    }

    /**
     * Verify that getSuccessful() contains only responses where successful() is true.
     *
     * @param array<int|string, Response> $successful Successful responses.
     * @return bool True if all responses in the set are successful.
     */
    private function verifySuccessfulOnlyContainsSuccessful(array $successful): bool
    {
        foreach ($successful as $response) {
            if (!$response->successful()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Verify that getFailed() contains only responses where failed() is true.
     *
     * @param array<int|string, Response> $failed Failed responses.
     * @return bool True if all responses in the set are failed.
     */
    private function verifyFailedOnlyContainsFailed(array $failed): bool
    {
        foreach ($failed as $response) {
            if (!$response->failed()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Verify that no response appears in both successful and failed sets.
     *
     * @param array<int|string, Response> $successful Successful responses.
     * @param array<int|string, Response> $failed Failed responses.
     * @return bool True if there is no overlap between the two sets.
     */
    private function verifyNoOverlap(array $successful, array $failed): bool
    {
        $successfulIndices = array_keys($successful);
        $failedIndices = array_keys($failed);
        $overlap = array_intersect($successfulIndices, $failedIndices);

        return count($overlap) === 0;
    }

    /**
     * Verify that every response in the original array appears in exactly one partition.
     *
     * @param array<int|string, Response> $successful Successful responses.
     * @param array<int|string, Response> $failed Failed responses.
     * @param array<int|string, Response> $responses Original responses.
     * @return bool True if every response is accounted for in exactly one set.
     */
    private function verifyCompletePartition(array $successful, array $failed, array $responses): bool
    {
        foreach ($responses as $index => $response) {
            $inSuccessful = array_key_exists($index, $successful);
            $inFailed = array_key_exists($index, $failed);

            if ($inSuccessful === $inFailed) {
                return false;
            }
        }

        return true;
    }
}
