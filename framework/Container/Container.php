<?php

declare(strict_types=1);

namespace Framework\Container;

use Framework\Container\Attributes\{Inject, Config};
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionUnionType;
use ReflectionParameter;
use Closure;

/**
 * Enhanced PSR-11 Container with Attribute-based auto-registration
 * 
 * Modern dependency injection container supporting PHP 8.4 features:
 * - Attribute-based service registration
 * - Union type resolution
 * - Configuration injection
 * - Service tagging and discovery
 * - Automatic interface binding
 */
final class Container implements ContainerInterface
{
    private array $services = [];
    private array $singletons = [];
    private array $resolved = [];
    private array $tagged = [];
    private array $config = [];
    private ?ServiceDiscovery $discovery = null;

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->discovery = new ServiceDiscovery($this);
        
        // Register container itself
        $this->instance(ContainerInterface::class, $this);
        $this->instance(self::class, $this);
    }

    /**
     * Auto-discover and register services from given directories
     * 
     * @param array<string> $directories
     */
    public function autodiscover(array $directories): void
    {
        $this->discovery->autodiscover($directories);
    }

    /**
     * Register a service with optional factory closure
     */
    public function bind(string $id, mixed $concrete = null, bool $singleton = false): void
    {
        $this->services[$id] = $concrete ?? $id;
        
        if ($singleton) {
            $this->singletons[$id] = true;
        }
    }

    /**
     * Register a singleton service
     */
    public function singleton(string $id, mixed $concrete = null): void
    {
        $this->bind($id, $concrete, true);
    }

    /**
     * Register an existing instance as singleton
     */
    public function instance(string $id, object $instance): void
    {
        $this->resolved[$id] = $instance;
        $this->singletons[$id] = true;
    }

    /**
     * Tag a service for grouped retrieval
     */
    public function tag(string $serviceId, string $tag): void
    {
        $this->tagged[$tag][] = $serviceId;
    }

    /**
     * Get services by tag
     * 
     * @return array<object>
     */
    public function tagged(string $tag): array
    {
        $services = [];
        
        if (isset($this->tagged[$tag])) {
            foreach ($this->tagged[$tag] as $serviceId) {
                $services[] = $this->get($serviceId);
            }
        }
        
        return $services;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $id): mixed
    {
        // Return already resolved singleton
        if (isset($this->resolved[$id])) {
            return $this->resolved[$id];
        }

        // Check if service is registered
        if (!$this->has($id)) {
            throw ContainerNotFoundException::serviceNotFound($id);
        }

        $concrete = $this->services[$id];
        $instance = $this->resolve($concrete);

        // Store singleton instances
        if (isset($this->singletons[$id])) {
            $this->resolved[$id] = $instance;
        }

        return $instance;
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $id): bool
    {
        return isset($this->services[$id]) || 
               isset($this->resolved[$id]) || 
               class_exists($id);
    }

    /**
     * Resolve concrete implementation
     */
    private function resolve(mixed $concrete): mixed
    {
        if ($concrete instanceof Closure) {
            return $concrete($this);
        }

        if (is_object($concrete)) {
            return $concrete;
        }

        if (is_string($concrete)) {
            return $this->build($concrete);
        }

        throw ContainerException::cannotResolve(
            gettype($concrete), 
            'Unsupported concrete type'
        );
    }

    /**
     * Build class instance using reflection and dependency injection
     */
    private function build(string $className): object
    {
        try {
            $reflection = new ReflectionClass($className);
        } catch (\ReflectionException $e) {
            throw ContainerException::cannotResolve($className, 'Class does not exist');
        }

        if (!$reflection->isInstantiable()) {
            throw ContainerException::invalidService($className, 'Class is not instantiable');
        }

        $constructor = $reflection->getConstructor();

        // No constructor parameters - simple instantiation
        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            return new $className;
        }

        // Resolve constructor dependencies
        $dependencies = $this->resolveDependencies($constructor->getParameters());

        return $reflection->newInstanceArgs($dependencies);
    }

    /**
     * Resolve method/constructor dependencies with attribute support
     * 
     * @param array<ReflectionParameter> $parameters
     * @return array<mixed>
     */
    private function resolveDependencies(array $parameters): array
    {
        $dependencies = [];

        foreach ($parameters as $parameter) {
            $dependency = $this->resolveParameter($parameter);
            $dependencies[] = $dependency;
        }

        return $dependencies;
    }

    /**
     * Resolve single parameter with attribute support
     */
    private function resolveParameter(ReflectionParameter $parameter): mixed
    {
        // Check for Inject attribute
        $injectAttrs = $parameter->getAttributes(Inject::class);
        if (!empty($injectAttrs)) {
            return $this->resolveInjectAttribute($parameter, $injectAttrs[0]->newInstance());
        }

        // Check for Config attribute
        $configAttrs = $parameter->getAttributes(Config::class);
        if (!empty($configAttrs)) {
            return $this->resolveConfigAttribute($configAttrs[0]->newInstance());
        }

        // Fall back to type-based resolution
        $type = $parameter->getType();
        
        if ($type === null) {
            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }
            
            throw ContainerException::cannotResolve(
                $parameter->getName(),
                'No type hint or attributes provided'
            );
        }

        return $this->resolveParameterType($parameter, $type);
    }

    /**
     * Resolve parameter with Inject attribute
     */
    private function resolveInjectAttribute(ReflectionParameter $parameter, Inject $inject): mixed
    {
        try {
            if ($inject->id !== null) {
                return $this->get($inject->id);
            }
            
            if ($inject->tag !== null) {
                $services = $this->tagged($inject->tag);
                if (empty($services)) {
                    throw ContainerNotFoundException::tagNotFound($inject->tag);
                }
                return $services[0];
            }
            
            // Fall back to parameter type
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                return $this->get($type->getName());
            }
            
        } catch (ContainerNotFoundException $e) {
            if ($inject->optional) {
                return null;
            }
            throw $e;
        }

        throw ContainerException::cannotResolve(
            $parameter->getName(),
            'Cannot resolve Inject attribute'
        );
    }

    /**
     * Resolve parameter with Config attribute
     */
    private function resolveConfigAttribute(Config $config): mixed
    {
        $value = $this->getConfigValue($config->key);
        return $value ?? $config->default;
    }

    /**
     * Get configuration value using dot notation
     */
    private function getConfigValue(string $key): mixed
    {
        $keys = explode('.', $key);
        $value = $this->config;
        
        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }
        
        return $value;
    }

    /**
     * Resolve parameter based on type (supports Union Types)
     */
    private function resolveParameterType(ReflectionParameter $parameter, mixed $type): mixed
    {
        if ($type instanceof ReflectionUnionType) {
            return $this->resolveUnionType($parameter, $type);
        }

        if ($type instanceof ReflectionNamedType) {
            return $this->resolveNamedType($parameter, $type);
        }

        throw ContainerException::cannotResolve(
            $parameter->getName(),
            'Unsupported parameter type'
        );
    }

    /**
     * Resolve Union Type parameter (try each type until successful)
     */
    private function resolveUnionType(ReflectionParameter $parameter, ReflectionUnionType $unionType): mixed
    {
        foreach ($unionType->getTypes() as $type) {
            if (!$type instanceof ReflectionNamedType) {
                continue;
            }

            try {
                return $this->resolveNamedType($parameter, $type);
            } catch (ContainerException) {
                continue;
            }
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        throw ContainerException::cannotResolve(
            $parameter->getName(),
            'Cannot resolve union type'
        );
    }

    /**
     * Resolve Named Type parameter
     */
    private function resolveNamedType(ReflectionParameter $parameter, ReflectionNamedType $type): mixed
    {
        $typeName = $type->getName();

        // Handle built-in types
        if ($type->isBuiltin()) {
            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }
            
            throw ContainerException::cannotResolve(
                $parameter->getName(),
                "Cannot auto-resolve built-in type '{$typeName}'"
            );
        }

        // Try to resolve from container
        try {
            return $this->get($typeName);
        } catch (ContainerNotFoundException) {
            if ($type->allowsNull() && $parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }
            
            if ($type->allowsNull()) {
                return null;
            }
            
            throw ContainerException::cannotResolve(
                $parameter->getName(),
                "Cannot resolve dependency '{$typeName}'"
            );
        }
    }
}