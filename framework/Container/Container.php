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
 * - Enhanced security and circular dependency detection
 */
final class Container implements ContainerInterface
{
    private array $services = [];
    private array $singletons = [];
    private array $resolved = [];
    private array $tagged = [];
    private array $building = [];
    private array $_config = [];
    private ?ServiceDiscovery $discovery = null;
    private readonly array $allowedPaths;

    // PHP 8.4 Property Hooks für sichere Config-Verwaltung
    public array $config {
        get => $this->_config;
        set(array $value) {
            $this->_config = $this->validateConfig($value);
        }
    }

    public function __construct(array $config = [], array $allowedPaths = [])
    {
        $this->config = $config;
        $this->allowedPaths = $allowedPaths ?: [getcwd()];
        $this->discovery = new ServiceDiscovery($this);
        
        // Register container itself
        $this->instance(ContainerInterface::class, $this);
        $this->instance(self::class, $this);
    }

    /**
     * Validiert und normalisiert Konfigurationsdaten
     */
    private function validateConfig(array $config): array
    {
        return $this->sanitizeConfigArray($config);
    }

    /**
     * Rekursive Sanitization von Config-Arrays
     */
    private function sanitizeConfigArray(array $config): array
    {
        $sanitized = [];
        
        foreach ($config as $key => $value) {
            if (!is_string($key) || $key === '' || str_contains($key, '..')) {
                continue;
            }
            
            $sanitized[$key] = match (true) {
                is_array($value) => $this->sanitizeConfigArray($value),
                is_string($value) => $this->sanitizeConfigValue($value),
                default => $value
            };
        }
        
        return $sanitized;
    }

    /**
     * Sanitization einzelner Config-Werte
     */
    private function sanitizeConfigValue(string $value): string
    {
        // Entfernt potentiell gefährliche Sequenzen
        return str_replace(['../', '..\\', '<script', '</script'], '', $value);
    }

    /**
     * Auto-discover und register services from given directories
     * 
     * @param array<string> $directories
     */
    public function autodiscover(array $directories): void
    {
        $secureDirectories = array_filter($directories, [$this, 'isAllowedPath']);
        $this->discovery->autodiscover($secureDirectories);
    }

    /**
     * Prüft ob ein Pfad erlaubt ist
     */
    public function isAllowedPath(string $path): bool
    {
        $realPath = realpath($path);
        if ($realPath === false) {
            return false;
        }

        foreach ($this->allowedPaths as $allowedPath) {
            $realAllowedPath = realpath($allowedPath);
            if ($realAllowedPath && str_starts_with($realPath, $realAllowedPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Register a service with optional factory closure
     */
    public function bind(string $id, mixed $concrete = null, bool $singleton = false): void
    {
        if (!$this->isValidServiceId($id)) {
            throw ContainerException::invalidService($id, 'Invalid service ID format');
        }

        $this->services[$id] = $concrete ?? $id;
        
        if ($singleton) {
            $this->singletons[$id] = true;
        }
    }

    /**
     * Validiert Service-IDs gegen Injection-Attacks
     */
    private function isValidServiceId(string $id): bool
    {
        return $id !== '' && 
               !str_contains($id, '..') && 
               !str_contains($id, '/') &&
               !str_contains($id, '\\\\') &&
               preg_match('/^[a-zA-Z_\\\\][a-zA-Z0-9_\\\\.]*$/', $id) === 1;
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
        if (!$this->isValidServiceId($id)) {
            throw ContainerException::invalidService($id, 'Invalid service ID format');
        }

        $this->resolved[$id] = $instance;
        $this->singletons[$id] = true;
    }

    /**
     * Tag a service for grouped retrieval
     */
    public function tag(string $serviceId, string $tag): void
    {
        if (!$this->isValidServiceId($serviceId) || !$this->isValidServiceId($tag)) {
            throw ContainerException::invalidService($serviceId, 'Invalid service ID or tag format');
        }

        $this->tagged[$tag][] = $serviceId;
    }

    /**
     * Get services by tag
     * 
     * @return array<object>
     */
    public function tagged(string $tag): array
    {
        if (!$this->isValidServiceId($tag)) {
            throw ContainerException::invalidService($tag, 'Invalid tag format');
        }

        $services = [];
        
        if (isset($this->tagged[$tag])) {
            foreach ($this->tagged[$tag] as $serviceId) {
                try {
                    $services[] = $this->get($serviceId);
                } catch (ContainerNotFoundException) {
                    // Skip nicht-verfügbare Services
                    continue;
                }
            }
        }
        
        return $services;
    }

    /**
     * Memory Management - Services vergessen
     */
    public function forget(string $id): void
    {
        unset(
            $this->resolved[$id], 
            $this->services[$id], 
            $this->singletons[$id]
        );
    }

    /**
     * Kompletter Container-Reset
     */
    public function flush(): void
    {
        $this->resolved = [];
        $this->building = [];
        // Container-Selbst-Referenzen beibehalten
        $this->instance(ContainerInterface::class, $this);
        $this->instance(self::class, $this);
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $id): mixed
    {
        if (!$this->isValidServiceId($id)) {
            throw ContainerException::invalidService($id, 'Invalid service ID format');
        }

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
               ($this->isValidServiceId($id) && class_exists($id));
    }

    /**
     * Resolve concrete implementation
     */
    private function resolve(mixed $concrete): mixed
    {
        return match (true) {
            $concrete instanceof Closure => $concrete($this),
            is_object($concrete) => $concrete,
            is_string($concrete) && $this->isValidServiceId($concrete) => $this->build($concrete),
            default => throw ContainerException::cannotResolve(
                gettype($concrete), 
                'Unsupported concrete type'
            )
        };
    }

    /**
     * Build class instance mit Circular Dependency Detection
     */
    private function build(string $className): object
    {
        // Circular Dependency Detection
        if (in_array($className, $this->building, true)) {
            throw ContainerException::circularDependency([...$this->building, $className]);
        }

        $this->building[] = $className;

        try {
            $instance = $this->doBuild($className);
            array_pop($this->building);
            return $instance;
        } catch (\Throwable $e) {
            array_pop($this->building);
            throw $e;
        }
    }

    /**
     * Eigentliche Build-Logik
     */
    private function doBuild(string $className): object
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
            try {
                $dependency = $this->resolveParameter($parameter);
                $dependencies[] = $dependency;
            } catch (\Throwable $e) {
                throw ContainerException::cannotResolve(
                    $parameter->getName(),
                    "Parameter resolution failed: {$e->getMessage()}"
                );
            }
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
                if (!$this->isValidServiceId($inject->id)) {
                    throw ContainerException::invalidService($inject->id, 'Invalid injected service ID');
                }
                return $this->get($inject->id);
            }
            
            if ($inject->tag !== null) {
                if (!$this->isValidServiceId($inject->tag)) {
                    throw ContainerException::invalidService($inject->tag, 'Invalid injected tag');
                }
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
     * Get configuration value using dot notation mit Security-Checks
     */
    private function getConfigValue(string $key): mixed
    {
        if (!is_string($key) || $key === '' || str_contains($key, '..') || str_contains($key, '/')) {
            return null;
        }
        
        $keys = array_filter(explode('.', $key), 'strlen');
        if (empty($keys)) {
            return null;
        }
        
        $value = $this->_config;
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
        return match (true) {
            $type instanceof ReflectionUnionType => $this->resolveUnionType($parameter, $type),
            $type instanceof ReflectionNamedType => $this->resolveNamedType($parameter, $type),
            default => throw ContainerException::cannotResolve(
                $parameter->getName(),
                'Unsupported parameter type'
            )
        };
    }

    /**
     * Resolve Union Type parameter mit verbesserter Fehlerbehandlung
     */
    private function resolveUnionType(ReflectionParameter $parameter, ReflectionUnionType $unionType): mixed
    {
        $exceptions = [];

        foreach ($unionType->getTypes() as $type) {
            if (!$type instanceof ReflectionNamedType) {
                continue;
            }

            try {
                return $this->resolveNamedType($parameter, $type);
            } catch (ContainerException $e) {
                $exceptions[] = $e->getMessage();
                continue;
            }
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        throw ContainerException::cannotResolve(
            $parameter->getName(),
            'Union type resolution failed: ' . implode(', ', $exceptions)
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

        // Sicherheitsprüfung für Klassenname
        if (!$this->isValidServiceId($typeName)) {
            throw ContainerException::cannotResolve(
                $parameter->getName(),
                "Invalid class name '{$typeName}'"
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