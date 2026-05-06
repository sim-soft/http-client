<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests\Traits;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QuickCheck\Generator as Gen;
use QuickCheck\PHPUnit\PropertyConstraint;
use QuickCheck\Property;
use ReflectionMethod;
use ReflectionProperty;
use Simsoft\HttpClient\HttpClient;

/**
 * PrepareHandlePropertyTest class
 *
 * Property-based tests for the PrepareHandleTrait cURL configuration logic.
 * Validates that request preparation produces correct cURL configuration
 * for all valid request configurations.
 *
 * Feature: phpmd-compliance-fixes, Property 2: Request preparation produces correct cURL configuration
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class PrepareHandlePropertyTest extends TestCase
{
    /**
     * Property 2: Request preparation produces correct cURL configuration.
     *
     * For any valid request configuration (HTTP method, non-empty URL, arbitrary
     * headers, body types), the decomposed prepareHandle() SHALL produce a cURL
     * handle where CURLOPT_URL contains the correct URL, method option matches,
     * headers include user-agent and x-request-id, and body options are set correctly.
     *
     * **Validates: Requirements 10.4, 11.2**
     *
     * @return void
     */
    #[Test]
    public function requestPreparationProducesCorrectCurlConfiguration(): void
    {
        $methodGen = Gen::elements('GET', 'POST', 'PUT', 'PATCH', 'DELETE');
        $urlGen = Gen::asciiStrings()->notEmpty();
        $headerKeyGen = Gen::elements(
            'accept',
            'authorization',
            'cache-control',
            'content-language',
            'x-custom-header',
            'x-api-key'
        );
        $headerValGen = Gen::asciiStrings()->notEmpty();
        $bodyTypeGen = Gen::elements('none', 'string', 'array');

        $property = Property::forAll(
            [
                $methodGen,
                $urlGen,
                $headerKeyGen,
                $headerValGen,
                $bodyTypeGen,
            ],
            function (
                string $method,
                string $url,
                string $headerKey,
                string $headerVal,
                string $bodyType
            ): bool {
                return $this->verifyRequestConfiguration(
                    $method,
                    $url,
                    $headerKey,
                    $headerVal,
                    $bodyType
                );
            }
        );

        $this->assertThat(
            $property,
            PropertyConstraint::check(100)
        );
    }

    /**
     * Verify that a request configuration produces correct cURL options.
     *
     * @param string $method HTTP method.
     * @param string $url Request URL path.
     * @param string $headerKey Custom header name.
     * @param string $headerVal Custom header value.
     * @param string $bodyType Body type: none, string, or array.
     * @return bool True if all assertions pass.
     */
    private function verifyRequestConfiguration(
        string $method,
        string $url,
        string $headerKey,
        string $headerVal,
        string $bodyType
    ): bool
    {
        $client = HttpClient::make();
        $baseUrl = 'https://example.com';

        $client->withBaseUrl($baseUrl);
        $client->resource($url);
        $client->withMethod($method);
        $client->withHeader($headerKey, $headerVal);

        $this->applyBody($client, $bodyType, $method);

        $requestId = uniqid('test_req_', true);
        $handle = $this->invokePrepareHandle($client, $requestId);

        $options = $this->getOptionsFromClient($client);

        return $this->verifyUrl($options, $baseUrl, $url)
            && $this->verifyMethod($options, $method)
            && $this->verifyHeaders($client, $headerKey, $headerVal, $requestId)
            && $this->verifyBody($options, $bodyType, $method);
    }

    /**
     * Apply a body to the client based on the body type.
     *
     * @param HttpClient $client The client instance.
     * @param string $bodyType The body type to apply.
     * @param string $method The HTTP method.
     * @return void
     */
    private function applyBody(HttpClient $client, string $bodyType, string $method): void
    {
        if ($bodyType === 'none') {
            return;
        }

        if ($bodyType === 'string') {
            $client->withBody('test-body-content', 'text/plain');
            return;
        }

        if ($bodyType === 'array') {
            $client->withForm(['key' => 'value', 'foo' => 'bar']);
        }
    }

    /**
     * Invoke the protected prepareHandle method via reflection.
     *
     * @param HttpClient $client The client instance.
     * @param string $requestId The request ID.
     * @return \CurlHandle The prepared cURL handle.
     */
    private function invokePrepareHandle(HttpClient $client, string $requestId): \CurlHandle
    {
        $method = new ReflectionMethod($client, 'prepareHandle');

        return $method->invoke($client, $requestId);
    }

    /**
     * Get the options array from the client via reflection.
     *
     * @param HttpClient $client The client instance.
     * @return array<int, mixed> The cURL options array.
     */
    private function getOptionsFromClient(HttpClient $client): array
    {
        $prop = new ReflectionProperty($client, 'options');

        return $prop->getValue($client);
    }

    /**
     * Get the formatted headers from the client via reflection.
     *
     * @param HttpClient $client The client instance.
     * @return array<array-key, mixed>|null The formatted headers array.
     */
    private function getFormattedHeaders(HttpClient $client): ?array
    {
        $prop = new ReflectionProperty($client, 'formattedHeaders');

        return $prop->getValue($client);
    }

    /**
     * Verify that CURLOPT_URL contains the correct URL.
     *
     * @param array<int, mixed> $options The cURL options.
     * @param string $baseUrl The base URL.
     * @param string $url The pending URL path.
     * @return bool True if the URL is correct.
     */
    private function verifyUrl(array $options, string $baseUrl, string $url): bool
    {
        if (!isset($options[CURLOPT_URL])) {
            return false;
        }

        $expectedUrl = $baseUrl . $url;

        return str_starts_with($options[CURLOPT_URL], $expectedUrl);
    }

    /**
     * Verify that the HTTP method option is set correctly.
     *
     * @param array<int, mixed> $options The cURL options.
     * @param string $method The expected HTTP method.
     * @return bool True if the method option is correct.
     */
    private function verifyMethod(array $options, string $method): bool
    {
        if ($method === 'GET') {
            // GET should not have CURLOPT_POST or CURLOPT_CUSTOMREQUEST set
            return !isset($options[CURLOPT_POST]) && !isset($options[CURLOPT_CUSTOMREQUEST]);
        }

        if ($method === 'POST') {
            // POST uses CURLOPT_POST = true
            return isset($options[CURLOPT_POST]) && $options[CURLOPT_POST] === true;
        }

        // PUT, PATCH, DELETE use CURLOPT_CUSTOMREQUEST
        return isset($options[CURLOPT_CUSTOMREQUEST]) && $options[CURLOPT_CUSTOMREQUEST] === $method;
    }

    /**
     * Verify that headers include user-agent, x-request-id, and the custom header.
     *
     * @param HttpClient $client The client instance.
     * @param string $headerKey The custom header key.
     * @param string $headerVal The custom header value.
     * @param string $requestId The expected request ID.
     * @return bool True if all required headers are present.
     */
    private function verifyHeaders(
        HttpClient $client,
        string     $headerKey,
        string     $headerVal,
        string     $requestId
    ): bool
    {
        $formattedHeaders = $this->getFormattedHeaders($client);

        if ($formattedHeaders === null) {
            return false;
        }

        $headerString = implode("\n", $formattedHeaders);
        $lowerHeaders = strtolower($headerString);

        $hasUserAgent = str_contains($lowerHeaders, 'user-agent:');
        $hasRequestId = str_contains($lowerHeaders, 'x-request-id:');
        $hasCustomHeader = str_contains($lowerHeaders, strtolower($headerKey) . ':');

        return $hasUserAgent && $hasRequestId && $hasCustomHeader;
    }

    /**
     * Verify that body options are set correctly for the body type.
     *
     * @param array<int, mixed> $options The cURL options.
     * @param string $bodyType The body type.
     * @param string $method The HTTP method.
     * @return bool True if body options are correct.
     */
    private function verifyBody(array $options, string $bodyType, string $method): bool
    {
        if ($bodyType === 'none') {
            // No body: CURLOPT_POSTFIELDS should not be set for GET
            if ($method === 'GET') {
                return !isset($options[CURLOPT_POSTFIELDS]);
            }
            // For other methods without body, postfields may or may not be set
            return true;
        }

        if ($bodyType === 'string') {
            // String body: CURLOPT_POSTFIELDS should be the string
            return isset($options[CURLOPT_POSTFIELDS])
                && is_string($options[CURLOPT_POSTFIELDS]);
        }

        if ($bodyType === 'array') {
            // Form body: CURLOPT_POSTFIELDS should be a URL-encoded string
            return isset($options[CURLOPT_POSTFIELDS])
                && is_string($options[CURLOPT_POSTFIELDS]);
        }

        return true;
    }
}
