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
 * @method string plainValue()
 * @method string protectedValue()
 * @method string joinValues(string $one, string $two)
 * @method string readSecret()
 * @method string readLabel()
 * @method string fromFactory()
 * @method string fromPlain()
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
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
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

    /**
     * Test that a public mixin method not returning a Closure is callable.
     *
     * Registering such a method as an [$object, 'method'] pair left __call()
     * trying to rebind a method closure to an unrelated class, which PHP
     * refuses: the bind emitted a warning, returned null, and the call died
     * with a TypeError.
     *
     * @return void
     */
    #[Test]
    public function mixinRegistersPublicMethodNotReturningClosure(): void
    {
        $mixin = new class () {
            /**
             * A public method returning a plain value.
             *
             * @return string
             */
            public function plainValue(): string
            {
                return 'plain from mixin';
            }
        };

        MacroableHost::mixin($mixin);

        $this->assertSame('plain from mixin', $this->host->plainValue());
    }

    /**
     * Test that a protected mixin method not returning a Closure is callable.
     *
     * A protected method could not even be invoked through a callable pair,
     * being out of scope outside the mixin itself.
     *
     * @return void
     */
    #[Test]
    public function mixinRegistersProtectedMethodNotReturningClosure(): void
    {
        $mixin = new class () {
            /**
             * A protected method returning a plain value.
             *
             * @return string
             */
            protected function protectedValue(): string
            {
                return 'protected from mixin';
            }
        };

        MacroableHost::mixin($mixin);

        $this->assertSame('protected from mixin', $this->host->protectedValue());
    }

    /**
     * Test that a forwarded mixin method receives the arguments it was called with.
     *
     * @return void
     */
    #[Test]
    public function forwardedMixinMethodReceivesArguments(): void
    {
        $mixin = new class () {
            /**
             * A method taking arguments and returning a plain value.
             *
             * @param string $one First argument.
             * @param string $two Second argument.
             * @return string
             */
            public function joinValues(string $one, string $two): string
            {
                return $one . '-' . $two;
            }
        };

        MacroableHost::mixin($mixin);

        $this->assertSame('a-b', $this->host->joinValues('a', 'b'));
    }

    /**
     * Test that a forwarded mixin method keeps the mixin as its receiver.
     *
     * The wrapper must not rebind the method: its own $this, and therefore the
     * mixin's private state, has to stay intact.
     *
     * @return void
     */
    #[Test]
    public function forwardedMixinMethodKeepsMixinAsReceiver(): void
    {
        $mixin = new class () {
            /** @var string Private state only the mixin can read. */
            private string $secret = 'mixin-state';

            /**
             * A method reading the mixin's own private state.
             *
             * @return string
             */
            public function readSecret(): string
            {
                return $this->secret;
            }
        };

        MacroableHost::mixin($mixin);

        $this->assertSame('mixin-state', $this->host->readSecret());
    }

    /**
     * Test that a mixin returning a Closure still binds to the host object.
     *
     * The factory pattern is the documented way to reach the host, and the
     * forwarding wrapper must not have disturbed it.
     *
     * @return void
     */
    #[Test]
    public function closureReturningMixinMethodStillBindsToHost(): void
    {
        $this->host->label = 'host-label';

        $mixin = new class () {
            /**
             * A factory method whose closure reads the host's property.
             *
             * @return Closure
             */
            public function readLabel(): Closure
            {
                return function (): string {
                    /** @var MacroableHost $this */
                    return $this->label;
                };
            }
        };

        MacroableHost::mixin($mixin);

        $this->assertSame('host-label', $this->host->readLabel());
    }

    /**
     * Test that a mixin mixing both method styles registers each correctly.
     *
     * @return void
     */
    #[Test]
    public function mixinRegistersBothClosureAndPlainMethods(): void
    {
        $mixin = new class () {
            /**
             * A factory method.
             *
             * @return Closure
             */
            public function fromFactory(): Closure
            {
                return function (): string {
                    return 'factory';
                };
            }

            /**
             * A plain method.
             *
             * @return string
             */
            public function fromPlain(): string
            {
                return 'plain';
            }
        };

        MacroableHost::mixin($mixin);

        $this->assertSame('factory', $this->host->fromFactory());
        $this->assertSame('plain', $this->host->fromPlain());
    }
}
