<?php

declare(strict_types=1);

namespace Simsoft\HttpClient\Tests\Traits;

use BadMethodCallException;
use Closure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Simsoft\HttpClient\Traits\Macroable;

/**
 * MacroableHost class
 *
 * Concrete host class using the Macroable trait for testing.
 *
 * @method string greet(string $name)
 * @method string sayHello()
 * @method string sayGoodbye()
 * @method string getLabel()
 * @method void nonExistentMethod()
 */
class MacroableHost
{
    use Macroable;

    /** @var string A public property for $this binding tests. */
    public string $label = 'default';
}

/**
 * MacroableTraitTest class
 *
 * Tests for the Macroable trait: macro registration, mixin functionality,
 * $this binding, and undefined macro handling.
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class MacroableTraitTest extends TestCase
{
    /** @var MacroableHost Host object using the Macroable trait. */
    private MacroableHost $host;

    /**
     * Set up a fresh host instance and clear static macros.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->host = new MacroableHost();
        $this->clearMacros();
    }

    /**
     * Tear down: clear static macros to prevent test pollution.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->clearMacros();
    }

    /**
     * Clear the static $macros array via reflection.
     *
     * @return void
     */
    private function clearMacros(): void
    {
        $reflection = new ReflectionProperty(MacroableHost::class, 'macros');
        $reflection->setValue(null, []);
    }

    /**
     * Test that macro() registers a closure and calling it executes the closure.
     *
     * @return void
     */
    #[Test]
    public function macroRegistersClosureAndExecutesIt(): void
    {
        MacroableHost::macro('greet', function (string $name): string {
            return "Hello, {$name}!";
        });

        $result = $this->host->greet('World');

        $this->assertSame('Hello, World!', $result);
    }

    /**
     * Test that calling a non-existent macro throws BadMethodCallException.
     *
     * @return void
     */
    #[Test]
    public function callingNonExistentMacroThrowsBadMethodCallException(): void
    {
        $this->expectException(BadMethodCallException::class);

        $this->host->nonExistentMethod();
    }

    /**
     * Test that mixin() registers all public and protected methods from a mixin object.
     *
     * @return void
     */
    #[Test]
    public function mixinRegistersPublicAndProtectedMethods(): void
    {
        $mixin = new class () {
            /**
             * A public method returning a Closure (factory pattern).
             *
             * @return Closure
             */
            public function sayHello(): Closure
            {
                return function (): string {
                    return 'hello from mixin';
                };
            }

            /**
             * A protected method returning a Closure.
             *
             * @return Closure
             */
            protected function sayGoodbye(): Closure
            {
                return function (): string {
                    return 'goodbye from mixin';
                };
            }
        };

        MacroableHost::mixin($mixin);

        $helloResult = $this->host->sayHello();
        $this->assertSame('hello from mixin', $helloResult);

        $goodbyeResult = $this->host->sayGoodbye();
        $this->assertSame('goodbye from mixin', $goodbyeResult);
    }

    /**
     * Test that mixin() with replace=false does not overwrite existing macros.
     *
     * @return void
     */
    #[Test]
    public function mixinWithReplaceFalseDoesNotOverwriteExistingMacros(): void
    {
        MacroableHost::macro('sayHello', function (): string {
            return 'original';
        });

        $mixin = new class () {
            /**
             * A method that would conflict with the existing macro.
             *
             * @return Closure
             */
            public function sayHello(): Closure
            {
                return function (): string {
                    return 'from mixin';
                };
            }
        };

        MacroableHost::mixin($mixin, false);

        $result = $this->host->sayHello();
        $this->assertSame('original', $result);
    }

    /**
     * Test that macros have access to the host object via $this binding.
     *
     * @return void
     */
    #[Test]
    public function macrosHaveAccessToHostObjectViaThisBinding(): void
    {
        $this->host->label = 'custom-label';

        MacroableHost::macro('getLabel', function (): string {
            /** @var MacroableHost $this */
            return $this->label;
        });

        $result = $this->host->getLabel();

        $this->assertSame('custom-label', $result);
    }
}
