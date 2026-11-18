<?php

declare(strict_types=1);

namespace App\Core;

use Closure;
use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;

/**
 * Minimal constructor-injection container (Phase 6).
 *
 * Deliberately small: bindings, singletons, instances and reflection-based
 * autowiring. There is no service locator API for views and no annotation
 * magic — a class either has a resolvable constructor or the container throws
 * with the exact parameter it could not build.
 *
 *     $container->instance(Config::class, $config);
 *     $container->singleton(Database::class, fn (Container $c) => new Database($c->get(Config::class)->section('database')));
 *     $container->get(ScheduleService::class);   // autowired
 */
final class Container
{
    /**
     * Bindings keyed by id. A Closure receives the container; a string is a
     * class name to build (the default when nothing is bound).
     *
     * @var array<string, Closure|string>
     */
    private array $bindings = [];

    /**
     * Shared flags. get() caches constructed objects only when this is true.
     *
     * @var array<string, bool>
     */
    private array $shared = [];

    /**
     * Already-built shared instances.
     *
     * @var array<string, object>
     */
    private array $instances = [];

    /**
     * Classes currently being built, used to detect circular dependencies
     * instead of recursing until the stack dies.
     *
     * @var array<string, true>
     */
    private array $building = [];

    /**
     * Bind an id to a factory or class name.
     *
     * @param Closure|class-string $concrete
     */
    public function bind(string $id, Closure|string $concrete, bool $shared = true): void
    {
        $this->bindings[$id] = $concrete;
        $this->shared[$id] = $shared;
        unset($this->instances[$id]);
    }

    /**
     * Bind a single shared instance factory (Database, Config, Security, …).
     *
     * @param Closure|class-string $concrete
     */
    public function singleton(string $id, Closure|string $concrete): void
    {
        $this->bind($id, $concrete, true);
    }

    /**
     * Register an already-constructed object (the request, the config tree).
     */
    public function instance(string $id, object $instance): void
    {
        $this->instances[$id] = $instance;
        $this->shared[$id] = true;
    }

    /**
     * Whether the container can resolve the id: bound, instantiated or an
     * existing class.
     */
    public function has(string $id): bool
    {
        return isset($this->instances[$id])
            || isset($this->bindings[$id])
            || class_exists($id);
    }

    /**
     * Resolve an id, honouring the shared flag.
     *
     * @template T of object
     * @param class-string<T>|string $id
     * @return T|mixed
     */
    public function get(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        $object = $this->resolve($id);

        if (($this->shared[$id] ?? false) === true) {
            $this->instances[$id] = $object;
        }

        return $object;
    }

    /**
     * Build a fresh object every time — used for controllers, which must not
     * carry state (and the previous request) between dispatches.
     */
    public function make(string $id): mixed
    {
        return $this->resolve($id);
    }

    /**
     * @return object
     */
    private function resolve(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        $concrete = $this->bindings[$id] ?? $id;

        if ($concrete instanceof Closure) {
            $result = $concrete($this);

            if (!is_object($result)) {
                throw new RuntimeException(sprintf('The binding for "%s" must return an object, %s returned.', $id, get_debug_type($result)));
            }

            return $result;
        }

        return $this->build($concrete);
    }

    /**
     * Instantiate a class, resolving its constructor by type hint.
     *
     * @param class-string $class
     */
    private function build(string $class): object
    {
        if (!class_exists($class)) {
            throw new RuntimeException(sprintf('Class "%s" does not exist and no binding is registered for it.', $class));
        }

        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new RuntimeException(sprintf('Class "%s" cannot be instantiated (abstract or non-public constructor).', $class));
        }

        if (isset($this->building[$class])) {
            throw new RuntimeException(sprintf('Circular dependency detected while building "%s".', $class));
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $this->building[$class] = true;

        try {
            return $reflection->newInstanceArgs($this->arguments($constructor));
        } finally {
            unset($this->building[$class]);
        }
    }

    /**
     * Resolve each constructor parameter:
     *   1. a class type hint → get() that class (shared singletons included);
     *   2. a built-in type with a default → the default;
     *   3. a nullable built-in without a default → null;
     *   4. anything else is a wiring error and is reported by name.
     *
     * @return array<int, mixed>
     */
    private function arguments(ReflectionFunctionAbstract $function): array
    {
        $arguments = [];

        foreach ($function->getParameters() as $parameter) {
            $arguments[] = $this->argument($parameter, $function);
        }

        return $arguments;
    }

    private function argument(ReflectionParameter $parameter, ReflectionFunctionAbstract $function): mixed
    {
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            /** @var class-string $dependency */
            $dependency = $type->getName();

            return $this->get($dependency);
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($type instanceof ReflectionNamedType && $type->allowsNull()) {
            return null;
        }

        throw new RuntimeException(sprintf(
            'Cannot resolve parameter $%s of %s::%s(): type "%s" has no binding and no default value.',
            $parameter->getName(),
            $function instanceof \ReflectionMethod ? (string) $function->class : 'closure',
            $function->getName(),
            $type instanceof ReflectionNamedType ? $type->getName() : 'unknown'
        ));
    }
}
