<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests\Traits;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Simsoft\HttpClient\HttpClient;

/**
 * DeprecatedTraitTest class
 *
 * Tests for the DeprecatedTrait: verifies that each deprecated method
 * triggers an E_USER_DEPRECATED notice before delegating to its replacement.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class DeprecatedTraitTest extends TestCase
{
    /** @var HttpClient Client instance using the DeprecatedTrait. */
    private HttpClient $client;

    /**
     * Set up a fresh HttpClient instance for each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->client = HttpClient::make();
    }

    /**
     * Test that query() triggers an E_USER_DEPRECATED notice.
     *
     * @return void
     */
    #[Test]
    public function queryTriggersDeprecationNotice(): void
    {
        $deprecationTriggered = false;
        set_error_handler(function (int $errno, string $errstr) use (&$deprecationTriggered): bool {
            if ($errno === E_USER_DEPRECATED) {
                $deprecationTriggered = true;
            }
            return true;
        }, E_USER_DEPRECATED);

        try {
            $this->client->query(['page' => '1']);
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($deprecationTriggered);
    }

    /**
     * Test that formData() triggers an E_USER_DEPRECATED notice.
     *
     * @return void
     */
    #[Test]
    public function formDataTriggersDeprecationNotice(): void
    {
        $deprecationTriggered = false;
        set_error_handler(function (int $errno, string $errstr) use (&$deprecationTriggered): bool {
            if ($errno === E_USER_DEPRECATED) {
                $deprecationTriggered = true;
            }
            return true;
        }, E_USER_DEPRECATED);

        try {
            $this->client->formData(['name' => 'test']);
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($deprecationTriggered);
    }

    /**
     * Test that raw() triggers an E_USER_DEPRECATED notice.
     *
     * @return void
     */
    #[Test]
    public function rawTriggersDeprecationNotice(): void
    {
        $deprecationTriggered = false;
        set_error_handler(function (int $errno, string $errstr) use (&$deprecationTriggered): bool {
            if ($errno === E_USER_DEPRECATED) {
                $deprecationTriggered = true;
            }
            return true;
        }, E_USER_DEPRECATED);

        try {
            $this->client->raw('hello');
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($deprecationTriggered);
    }

    /**
     * Test that json() triggers an E_USER_DEPRECATED notice.
     *
     * @return void
     */
    #[Test]
    public function jsonTriggersDeprecationNotice(): void
    {
        $deprecationTriggered = false;
        set_error_handler(function (int $errno, string $errstr) use (&$deprecationTriggered): bool {
            if ($errno === E_USER_DEPRECATED) {
                $deprecationTriggered = true;
            }
            return true;
        }, E_USER_DEPRECATED);

        try {
            $this->client->json(['key' => 'value']);
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($deprecationTriggered);
    }

    /**
     * Test that graphQL() triggers an E_USER_DEPRECATED notice.
     *
     * @return void
     */
    #[Test]
    public function graphQlTriggersDeprecationNotice(): void
    {
        $deprecationTriggered = false;
        set_error_handler(function (int $errno, string $errstr) use (&$deprecationTriggered): bool {
            if ($errno === E_USER_DEPRECATED) {
                $deprecationTriggered = true;
            }
            return true;
        }, E_USER_DEPRECATED);

        try {
            $this->client->graphQL('{ users { id } }');
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($deprecationTriggered);
    }

    /**
     * Test that formData() sends multipart, as its own deprecation notice says.
     *
     * It forwarded to withForm() instead, sending url-encoded fields — a silent
     * change of request for callers who had not yet migrated.
     *
     * @return void
     */
    #[Test]
    public function formDataSendsMultipartNotUrlEncoded(): void
    {
        $data = ['name' => 'Alice', 'role' => 'admin'];

        @$this->client->formData($data);

        $expected = HttpClient::make()->withMultipart($data);

        $this->assertSame(
            $this->postFieldsOf($expected),
            $this->postFieldsOf($this->client)
        );
    }

    /**
     * Test that formData() sets the POST method, as withMultipart() does.
     *
     * @return void
     */
    #[Test]
    public function formDataSetsPostMethod(): void
    {
        @$this->client->formData(['name' => 'Alice']);

        $this->assertSame('POST', $this->client->getMethod());
    }

    /**
     * Test that formData() keeps the fields as an array for cURL to encode.
     *
     * A url-encoded body would arrive here as a string, which is exactly how
     * the two content types were being confused.
     *
     * @return void
     */
    #[Test]
    public function formDataKeepsFieldsAsAnArray(): void
    {
        @$this->client->formData(['name' => 'Alice']);

        $this->assertSame(['name' => 'Alice'], $this->postFieldsOf($this->client));
    }

    /**
     * Test that formData() and withForm() no longer produce the same body.
     *
     * @return void
     */
    #[Test]
    public function formDataDiffersFromWithForm(): void
    {
        $data = ['name' => 'Alice'];

        @$this->client->formData($data);

        $formClient = HttpClient::make()->withForm($data);

        $this->assertNotSame(
            $this->postFieldsOf($formClient),
            $this->postFieldsOf($this->client)
        );
    }

    /**
     * Read the prepared cURL POST fields of a client.
     *
     * @param HttpClient $client The client to inspect.
     * @return mixed The value cURL would be given for CURLOPT_POSTFIELDS.
     */
    private function postFieldsOf(HttpClient $client): mixed
    {
        $prepare = new ReflectionMethod($client, 'preparePostFields');
        $prepare->invoke($client);

        $options = new ReflectionProperty($client, 'options');

        /** @var array<int, mixed> $values */
        $values = $options->getValue($client);

        return $values[CURLOPT_POSTFIELDS] ?? null;
    }
}
