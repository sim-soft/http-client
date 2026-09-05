<?php

namespace Simsoft\HttpClient\Traits;

use BadMethodCallException;
use Closure;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Macroable trait.
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 * Closure::fromCallable() and Closure::bind() are intentional PHP built-ins,
 * not application-layer static coupling.
 */
trait Macroable
{
    /** @var Closure[]|callable[] Extended methods. */
    protected static array $macros = [];

    /**
     * Register a macro closure.
     *
     * @param string $name Method name.
     * @param Closure|callable $closure
     * @return void
     */
    public static function macro(string $name, Closure|callable $closure): void
    {
        static::$macros[$name] = $closure;
    }

    /**
     * Register all public/protected methods of a mixin object as macros.
     *
     * Methods whose return type is `Closure` are invoked, and their return
     * value is registered (factory pattern). All other methods are registered
     * as bound callables.
     *
     * @param object $mixin The mixin object.
     * @param bool $replace Whether to replace existing macros with the same name.
     * @return void
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     * The $replace flag controls additive vs. overwrite behavior and is
     * intentional API design — there is no meaningful way to split this
     * into two separate methods without duplicating all the reflection logic.
     * @throws ReflectionException
     */
    public static function mixin(object $mixin, bool $replace = true): void
    {
        $methods = (new ReflectionClass($mixin))->getMethods(
            ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED
        );

        foreach ($methods as $method) {
            if (!$replace && array_key_exists($method->getName(), static::$macros)) {
                continue;
            }

            static::macro(
                $method->getName(),
                self::resolveMixinMethod($mixin, $method)
            );
        }
    }

    /**
     * Resolve a mixin method to either its returned Closure or a forwarding Closure.
     *
     * Methods that do not produce a Closure are wrapped rather than registered
     * as an [$object, 'method'] pair. A pair is not usable here: __call() has to
     * rebind whatever it stores to the host instance, and a method closure
     * cannot be rebound across an unrelated class — PHP emits "Cannot bind
     * method ... to object of class", Closure::bind() returns null, and the
     * call dies with a TypeError. A protected method never even reaches that
     * point, since the pair is not callable outside the mixin's scope.
     *
     * The wrapper keeps the mixin as the receiver, so its own $this, private
     * state and visibility all work as written.
     *
     * @param object $mixin
     * @param ReflectionMethod $method
     * @return Closure
     * @throws ReflectionException
     */
    private static function resolveMixinMethod(object $mixin, ReflectionMethod $method): Closure
    {
        $returnType = $method->getReturnType();
        $returnsClosure = $returnType instanceof ReflectionNamedType
            && $returnType->getName() === 'Closure';

        if ($returnsClosure) {
            /** @var Closure $factory */
            $factory = $method->invoke($mixin);

            return $factory;
        }

        return function (mixed ...$arguments) use ($mixin, $method): mixed {
            return $method->invoke($mixin, ...$arguments);
        };
    }

    /**
     * Dispatch a macro call, binding $this so macros have full access to the
     * host class's properties and methods.
     *
     * @param string $name Method name.
     * @param array<int, mixed> $arguments
     * @return mixed
     */
    public function __call(string $name, array $arguments): mixed
    {
        if (!array_key_exists($name, static::$macros)) {
            throw new BadMethodCallException("Method $name does not exist.");
        }

        $macro = static::$macros[$name];

        if (!$macro instanceof Closure) {
            $macro = Closure::fromCallable($macro);
        }

        return call_user_func_array(
            Closure::bind($macro, $this, get_called_class()),
            $arguments
        );
    }
}
