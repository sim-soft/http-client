<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests\Traits;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
}
