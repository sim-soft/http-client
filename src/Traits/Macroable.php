<?php

namespace Simsoft\HttpClient\Traits;

use BadMethodCallException;
use Closure;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

/**
 * Macroable trait.
 */
trait Macroable
{
    /** @var Closure[]|callable[] Extended methods */
    protected static array $macros = [];

    /**
     * Add macro closure.
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
     * Add macro closure by mixin.
     *
     * @param object $mixin Mixin object.
     * @param bool $replace Whether to replace existing macro method. Default: true.
     * @return void
     * @throws ReflectionException
     */
    public static function mixin(object $mixin, bool $replace = true): void
    {
        foreach ((new ReflectionClass($mixin))->getMethods(
            ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED
        ) as $method) {
            if ($replace || !array_key_exists($method->getName(), static::$macros)) {
                static::macro(
                    $method->getName(),
                    $method->getReturnType()?->getName() === 'Closure' // @phpstan-ignore method.notFound
                        ? $method->invoke($mixin)
                        : [$mixin, $method->getName()]
                );
            }
        }
    }

    /**
     * Magic call macro closure.
     *
     * @param string $name Method name.
     * @param array<int, mixed> $arguments
     * @return $this
     */
    public function __call(string $name, array $arguments)
    {
        if (array_key_exists($name, static::$macros)) {
            $macro = static::$macros[$name];
            if ($macro instanceof Closure) {
                return call_user_func_array(
                    Closure::bind($macro, $this, get_called_class()), // @phpstan-ignore argument.type
                    $arguments
                );
            }

            return call_user_func_array($macro, $arguments); // @phpstan-ignore argument.type
        }

        throw new BadMethodCallException("Method $name does not exist.");
    }
}
