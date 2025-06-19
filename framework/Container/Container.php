<?php

declare(strict_types=1);

namespace Framework\Container;

use Framework\Container\Attributes\{Config, Inject};
use Framework\Http\RequestSanitizer;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use stdClass;
use WeakMap;

/**
 * High-Performance Framework Container für PHP 8.4
 *
 * Optimiert für Performance, Security und Developer Experience.
 * Nutzt moderne PHP 8.4 Features wie Property Hooks und Asymmetric Visibility.
 */
final class Container implements ContainerInterface
{
    // Property Hooks für computed properties
    public int $serviceCount {
        get => array_sum(array_map('count', $this->registry['services']));
    }

    public bool $isCompiled {
        get => $this->compiled;
    }

    public array $registeredServices {
        get => array_keys($this->registry['services']);
    }

    // Konsolidierte Registry für bessere Memory Efficiency
    public readonly array $config;

    // Performance Optimierungen
    private array $registry = [
        'services' => [],      // Service definitions
        'instances' => [],     // Resolved singletons
        'meta' => [],         // Metadata (singleton, tags, etc.)
        'lazy' => [],         // Lazy service configurations
        'building' => [],     // Circular dependency tracking
        'contextual' => [],   // Contextual bindings
    ];
    private WeakMap $reflectionCache;

    // Security & Config
    private bool $compiled = false;

    public function __construct(
        array $config = []
    )
    {
        $this->config = $config;
        $this->reflectionCache = new WeakMap();

        // Self-registration
        $this->instance(self::class, $this);
        $this->instance(Container::class, $this);
        $this->instance(ContainerInterface::class, $this);
    }

    /**
     * Register existing instance
     */
    public function instance(string $id, object $instance): static
    {
        if (!RequestSanitizer::isSecureClassName($id)) {
            throw ContainerException::invalidService($id, 'Invalid service ID format');
        }

        $this->registry['instances'][$id] = $instance;
        $this->registry['meta'][$id] = [
            'singleton' => true,
            'tags' => []
        ];

        return $this;
    }

    /**
     * Register singleton
     */
    public function singleton(string $id, mixed $concrete = null): static
    {
        return $this->bind($id, $concrete, true);
    }

    /**
     * Register service binding
     */
    public function bind(string $id, mixed $concrete = null, bool $singleton = false): static
    {
        if (!RequestSanitizer::isSecureClassName($id)) {
            throw ContainerException::invalidService($id, 'Invalid service ID format');
        }

        $this->registry['services'][$id] = $concrete ?? $id;
        $this->registry['meta'][$id] = [
            'singleton' => $singleton,
            'tags' => []
        ];

        return $this;
    }

    /**
     * Register lazy service
     */
    public function lazy(string $id, callable $factory, bool $singleton = true): static
    {
        if (!RequestSanitizer::isSecureClassName($id)) {
            throw ContainerException::invalidService($id, 'Invalid service ID format');
        }

        $this->registry['lazy'][$id] = [
            'factory' => $factory,
            'singleton' => $singleton,
            'proxy' => null
        ];

        $this->registry['meta'][$id] = [
            'singleton' => $singleton,
            'tags' => []
        ];

        return $this;
    }

    /**
     * Tag service für discovery
     */
    public function tag(string $id, string $tag): static
    {
        if (!RequestSanitizer::isSecureClassName($tag)) {
            throw ContainerException::invalidService($tag, 'Invalid tag format');
        }

        if (!isset($this->registry['meta'][$id])) {
            throw ContainerNotFoundException::serviceNotFound($id);
        }

        $this->registry['meta'][$id]['tags'][] = $tag;
        return $this;
    }

    /**
     * Contextual binding builder
     */
    public function when(string $context): ContextualBindingBuilder
    {
        return new ContextualBindingBuilder($this, $context);
    }

    /**
     * Internal method für contextual bindings
     */
    public function addContextualBinding(string $context, string $abstract, mixed $concrete): void
    {
        $this->registry['contextual'][$context][$abstract] = $concrete;
    }

    /**
     * Forget service
     */
    public function forget(string $id): static
    {
        unset(
            $this->registry['services'][$id],
            $this->registry['instances'][$id],
            $this->registry['meta'][$id],
            $this->registry['lazy'][$id]
        );

        return $this;
    }

    /**
     * Clear all services
     */
    public function flush(): static
    {
        $this->registry = [
            'services' => [],
            'instances' => [],
            'meta' => [],
            'lazy' => [],
            'building' => [],
            'contextual' => []
        ];

        $this->reflectionCache = new WeakMap();
        $this->compiled = false;

        // Self-registration
        $this->instance(self::class, $this);
        $this->instance(Container::class, $this);
        $this->instance(ContainerInterface::class, $this);

        return $this;
    }

    /**
     * Compile container für maximale Performance
     */
    public function compile(): static
    {
        $this->compiled = true;
        return $this;
    }

    /**
     * Memory cleanup für Lazy Objects
     */
    public function gc(): int
    {
        $cleaned = 0;

        // Cleanup lazy proxies ohne Referenzen
        foreach ($this->registry['lazy'] as $id => $config) {
            if (isset($config['proxy']) && !isset($this->registry['instances'][$id])) {
                $this->registry['lazy'][$id]['proxy'] = null;
                $cleaned++;
            }
        }

        return $cleaned;
    }

    public function get(string $id): mixed
    {
        return $this->resolve($id);
    }

    /**
     * Resolve service - Hauptmethode mit optimierter Performance
     */
    public function resolve(string $id, ?string $context = null): mixed
    {
        if (!RequestSanitizer::isSecureClassName($id)) {
            throw ContainerException::invalidService($id, 'Invalid service ID format');
        }

        // Fast path: Bereits aufgelöste Singletons
        if (isset($this->registry['instances'][$id])) {
            return $this->registry['instances'][$id];
        }

        // Contextual binding check
        if ($context !== null && isset($this->registry['contextual'][$context][$id])) {
            return $this->resolveContextual($context, $id);
        }

        // Lazy services
        if (isset($this->registry['lazy'][$id])) {
            return $this->resolveLazy($id);
        }

        // Standard resolution
        if (!$this->isRegistered($id)) {
            // ✅ Auto-Wiring: Versuche Klasse direkt zu instanziieren
            if (class_exists($id)) {
                return $this->buildClass($id);
            }

            throw ContainerNotFoundException::serviceNotFound(
                $id,
                array_keys($this->registry['services'])
            );
        }

        return $this->resolveStandard($id);
    }

    /**
     * Resolve contextual binding
     */
    private function resolveContextual(string $context, string $id): mixed
    {
        $concrete = $this->registry['contextual'][$context][$id];
        return $this->build($concrete);
    }

    /**
     * Build instance mit optimierter Reflection
     */
    private function build(mixed $concrete): mixed
    {
        return match (true) {
            is_callable($concrete) => $concrete($this),
            is_string($concrete) && class_exists($concrete) => $this->buildClass($concrete),
            is_object($concrete) => $concrete,
            default => $concrete
        };
    }

    /**
     * ✅ GEÄNDERT: Nutzt RequestSanitizer statt SecurityValidator
     */
    private function buildClass(string $className): object
    {
        if (!RequestSanitizer::isSecureClassName($className)) {
            throw ContainerException::securityViolation($className, 'Unsafe class name');
        }

        $reflection = $this->getCachedReflection($className);

        if (!$reflection->isInstantiable()) {
            throw ContainerException::cannotResolve(
                $className,
                'Class is not instantiable'
            );
        }

        // ✅ VEREINFACHT: Basis-Sicherheitscheck
        if ($this->hasSecurityRisks($reflection)) {
            throw ContainerException::securityViolation($className, 'Class has security risks');
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return new $className();
        }

        $parameters = $this->resolveParameters($constructor, $className);
        return $reflection->newInstanceArgs($parameters);
    }

    /**
     * Cached Reflection mit WeakMap
     */
    private function getCachedReflection(string $className): ReflectionClass
    {
        // Suche in WeakMap Cache
        foreach ($this->reflectionCache as $class => $reflection) {
            if ($class->getName() === $className) {
                return $reflection;
            }
        }

        try {
            $reflection = new ReflectionClass($className);
            $this->reflectionCache[$reflection] = $reflection;
            return $reflection;
        } catch (ReflectionException $e) {
            throw ContainerException::cannotResolve($className, 'Class does not exist');
        }
    }

    /**
     * ✅ NEU: Vereinfachte Sicherheitsprüfung
     */
    private function hasSecurityRisks(ReflectionClass $reflection): bool
    {
        // Nur essenzielle Sicherheitschecks
        if ($reflection->isInternal()) {
            return true;
        }

        // Prüfe gefährliche Methoden
        $dangerousMethods = ['eval', 'exec', 'system', 'shell_exec'];
        foreach ($dangerousMethods as $dangerous) {
            if ($reflection->hasMethod($dangerous)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parameter-Auflösung mit Attribut-Support
     */
    private function resolveParameters(ReflectionMethod $constructor, string $context): array
    {
        $parameters = [];

        foreach ($constructor->getParameters() as $parameter) {
            $parameters[] = $this->resolveParameter($parameter, $context);
        }

        return $parameters;
    }

    /**
     * Einzelner Parameter mit Attribut-Unterstützung
     */
    private function resolveParameter(ReflectionParameter $parameter, string $context): mixed
    {
        // Prüfe Inject Attribut
        $injectAttrs = $parameter->getAttributes(Inject::class);
        if (!empty($injectAttrs)) {
            return $this->resolveInjectAttribute($injectAttrs[0], $parameter);
        }

        // Prüfe Config Attribut
        $configAttrs = $parameter->getAttributes(Config::class);
        if (!empty($configAttrs)) {
            return $this->resolveConfigAttribute($configAttrs[0], $parameter);
        }

        // Type-based resolution
        $type = $parameter->getType();
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $typeName = $type->getName();

            // Prüfe contextual binding zuerst
            if (isset($this->registry['contextual'][$context][$typeName])) {
                return $this->resolveContextual($context, $typeName);
            }

            if ($this->isRegistered($typeName)) {
                return $this->resolve($typeName, $context);
            }
        }

        // Default value oder Exception
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        throw ContainerException::cannotResolve(
            $parameter->getName(),
            "Cannot resolve parameter '{$parameter->getName()}' in {$context}"
        );
    }

    /**
     * Resolve Inject Attribut
     */
    private function resolveInjectAttribute(
        ReflectionAttribute $attr,
        ReflectionParameter $parameter
    ): mixed
    {
        $inject = $attr->newInstance();

        if ($inject->id !== null) {
            if ($this->isRegistered($inject->id)) {
                return $this->resolve($inject->id);
            }

            if ($inject->optional) {
                return null;
            }

            throw ContainerNotFoundException::serviceNotFound($inject->id);
        }

        if ($inject->tag !== null) {
            $services = $this->tagged($inject->tag);
            return !empty($services) ? $services[0] : ($inject->optional ? null :
                throw ContainerNotFoundException::tagNotFound($inject->tag));
        }

        throw ContainerException::invalidService(
            $parameter->getName(),
            "Inject attribute requires either 'id' or 'tag'"
        );
    }

    /**
     * Check if service is registered
     */
    public function isRegistered(string $id): bool
    {
        return isset($this->registry['services'][$id]) ||
            isset($this->registry['instances'][$id]) ||
            isset($this->registry['lazy'][$id]);
    }

    /**
     * Get services by tag
     */
    public function tagged(string $tag): array
    {
        $services = [];

        foreach ($this->registry['meta'] as $id => $meta) {
            if (in_array($tag, $meta['tags'], true)) {
                $services[] = $this->resolve($id);
            }
        }

        return $services;
    }

    /**
     * Resolve Config Attribut
     */
    private function resolveConfigAttribute(
        ReflectionAttribute $attr,
        ReflectionParameter $parameter
    ): mixed
    {
        $config = $attr->newInstance();
        return $this->getConfig($config->key, $config->default);
    }

    /**
     * Sichere Konfigurationswert-Abfrage
     */
    public function getConfig(string $key, mixed $default = null): mixed
    {
        if (str_contains($key, '..') || empty($key)) {
            return $default;
        }

        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Lazy Service Resolution mit PHP 8.4 Support
     */
    private function resolveLazy(string $id): object
    {
        $lazyConfig = $this->registry['lazy'][$id];

        // Bereits initialisiertes Lazy Object?
        if (isset($lazyConfig['proxy'])) {
            return $lazyConfig['proxy'];
        }

        $proxy = $this->createLazyProxy($id, $lazyConfig['factory']);

        if ($lazyConfig['singleton']) {
            $this->registry['lazy'][$id]['proxy'] = $proxy;
            $this->registry['instances'][$id] = $proxy;
        }

        return $proxy;
    }

    /**
     * Erstelle Lazy Proxy mit PHP 8.4 Support
     */
    private function createLazyProxy(string $id, callable $factory): object
    {
        $initializer = fn() => $factory($this);

        // Versuche Zielklasse zu ermitteln für typed proxy
        $targetClass = $this->determineTargetClass($factory);

        if ($targetClass && class_exists($targetClass)) {
            $reflection = $this->getCachedReflection($targetClass);
            return $reflection->newLazyProxy($initializer);
        }

        // PHP 8.4 native lazy object für unbekannte Zielklasse
        return (new ReflectionClass(stdClass::class))->newLazyProxy($initializer);
    }

    /**
     * Einfache Zielklassen-Ermittlung
     */
    private function determineTargetClass(callable $factory): ?string
    {
        if (is_string($factory) && class_exists($factory)) {
            return $factory;
        }

        return null; // Für komplexere Fälle verwenden wir Generic Proxy
    }

    /**
     * Optimierte Standard-Auflösung
     */
    private function resolveStandard(string $id): mixed
    {
        // Circular dependency check
        if (isset($this->registry['building'][$id])) {
            $chain = array_keys($this->registry['building']);
            $chain[] = $id;
            throw ContainerException::circularDependency($chain);
        }

        $this->registry['building'][$id] = true;

        try {
            $concrete = $this->registry['services'][$id];
            $instance = $this->build($concrete);

            // Cache singletons
            if ($this->isSingleton($id)) {
                $this->registry['instances'][$id] = $instance;
            }

            return $instance;
        } finally {
            unset($this->registry['building'][$id]);
        }
    }

    private function isSingleton(string $id): bool
    {
        return $this->registry['meta'][$id]['singleton'] ?? false;
    }

    public function has(string $id): bool
    {
        return $this->isRegistered($id);
    }

    /**
     * Debug info
     */
    public function getStats(): array
    {
        return [
            'total_services' => $this->serviceCount,
            'resolved_instances' => count($this->registry['instances']),
            'lazy_services' => count($this->registry['lazy']),
            'contextual_bindings' => array_sum(array_map('count', $this->registry['contextual'])),
            'compiled' => $this->isCompiled,
            'memory_usage' => memory_get_usage(true),
            'registered_services' => $this->registeredServices
        ];
    }

    /**
     * Magic method für debugging
     */
    public function __debugInfo(): array
    {
        return [
            'service_count' => $this->serviceCount,
            'is_compiled' => $this->isCompiled,
            'memory_usage' => memory_get_usage(true),
            'has_request_sanitizer' => true
        ];
    }

    public function __destruct()
    {
        // Cleanup für WeakMaps und Referenzen
        $this->reflectionCache = new WeakMap();
    }
}