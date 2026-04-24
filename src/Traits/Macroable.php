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
     * @param bool $replace Whether to replace the existing macro method. Default: true.
     * @return void
     * @throws ReflectionException
     */
    public static function mixin(object $mixin, bool $replace = true): void
    {
        foreach ((new ReflectionClass($mixin))->getMethods(
            ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED
        ) as $method) {
            if ($replace || !array_key_exists($method->getName(), static::$macros)) {

                $returnType = $method->getReturnType();
                $isClosure = $returnType instanceof ReflectionNamedType
                    && $returnType->getName() === 'Closure';

                static::macro(
                    $method->getName(),
                    $isClosure
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
            if (!$macro instanceof Closure) {
                $macro = Closure::fromCallable($macro);
            }

            return call_user_func_array(
                Closure::bind($macro, $this, get_called_class()),
                $arguments
            );

        }

        throw new BadMethodCallException("Method $name does not exist.");
    }
}
