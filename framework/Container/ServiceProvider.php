<?php

declare(strict_types=1);

namespace Framework\Container;

/**
 * Enhanced Service Provider für organizing service registrations mit PHP 8.4 Features
 *
 * Vereinfachte Service Provider Klasse mit Prioritäten, Conditional Loading,
 * Validierung und verbesserter Fehlerbehandlung.
 *
 * @example
 * class DatabaseServiceProvider extends ServiceProvider
 * {
 *     protected int $priority = 100;
 *     protected array $requiredConfig = ['database.host', 'database.user'];
 *
 *     public function shouldLoad(): bool
 *     {
 *         return $this->container->config['database']['enabled'] ?? false;
 *     }
 *
 *     public function register(): void
 *     {
 *         $this->singleton('db.connection', fn() => new PDO(...));
 *     }
 *
 *     public function boot(): void
 *     {
 *         $this->runMigrations();
 *     }
 * }
 */
abstract class ServiceProvider
{
    // Property Hooks für computed properties
    public bool $canLoad {
        get => $this->shouldLoad() && $this->validateRequirements();
    }

    public array $dependencies {
        get => [
            'config' => $this->requiredConfig,
            'services' => $this->requiredServices
        ];
    }

    public bool $hasValidRequirements {
        get => $this->validateRequirements();
    }

    protected int $priority = 0;
    protected array $requiredConfig = [];
    protected array $requiredServices = [];
    protected bool $loadOnDemand = false;

    public function __construct(
        protected Container $container
    )
    {
    }

    /**
     * Register services in the container
     *
     * This method is called during the registration phase,
     * before all services are available for resolution.
     */
    abstract public function register(): void;

    /**
     * Boot services after all providers are registered
     *
     * This method is called after all service providers
     * have been registered, making all services available.
     */
    public function boot(): void
    {
        // Default implementation - override in subclasses if needed
    }

    /**
     * Cleanup-Methode für Provider
     */
    public function cleanup(): void
    {
        // Override in subclasses für Cleanup-Logik
    }

    /**
     * Provider-Informationen für Debugging
     */
    public function getProviderInfo(): array
    {
        return [
            'class' => static::class,
            'priority' => $this->getPriority(),
            'deferred' => $this->isDeferred(),
            'provides' => $this->provides(),
            'required_config' => $this->requiredConfig,
            'required_services' => $this->requiredServices,
            'should_load' => $this->shouldLoad(),
            'can_load' => $this->canLoad,
            'has_valid_requirements' => $this->hasValidRequirements
        ];
    }

    /**
     * Get provider priority (höher = früher geladen)
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * Determine if provider should be deferred
     */
    public function isDeferred(): bool
    {
        return $this->loadOnDemand;
    }

    /**
     * Get services provided by this provider
     *
     * Used for deferred loading to determine which
     * services trigger provider registration.
     *
     * @return array<string>
     */
    public function provides(): array
    {
        return [];
    }

    /**
     * Prüft ob Provider geladen werden soll mit PHP 8.4 match
     */
    public function shouldLoad(): bool
    {
        return match (true) {
            $this->loadOnDemand => false, // Wird später bei Bedarf geladen
            default => true
        };
    }

    /**
     * Check if all dependencies are available
     */
    public function checkDependencies(): array
    {
        $missing = [];

        foreach ($this->requiredServices as $serviceId) {
            if (!$this->container->isRegistered($serviceId)) {
                $missing[] = $serviceId;
            }
        }

        return $missing;
    }

    /**
     * Magic method for debugging
     */
    public function __debugInfo(): array
    {
        return [
            'class' => static::class,
            'priority' => $this->priority,
            'can_load' => $this->canLoad,
            'is_deferred' => $this->isDeferred(),
            'required_config_count' => count($this->requiredConfig),
            'required_services_count' => count($this->requiredServices),
            'validation_errors' => $this->validate()
        ];
    }

    /**
     * Validate provider configuration
     */
    public function validate(): array
    {
        $errors = [];

        // Check required config
        foreach ($this->requiredConfig as $configKey) {
            if (!$this->hasConfig($configKey)) {
                $errors[] = "Missing required config: {$configKey}";
            }
        }

        // Check required services
        foreach ($this->requiredServices as $serviceId) {
            if (!$this->container->isRegistered($serviceId)) {
                $errors[] = "Missing required service: {$serviceId}";
            }
        }

        return $errors;
    }

    /**
     * Validiert erforderliche Konfiguration und Services
     */
    protected function validateRequirements(): bool
    {
        // Prüfe erforderliche Konfiguration
        foreach ($this->requiredConfig as $configKey) {
            if (!$this->hasConfig($configKey)) {
                return false;
            }
        }

        // Prüfe erforderliche Services
        foreach ($this->requiredServices as $serviceId) {
            if (!$this->container->isRegistered($serviceId)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Prüft ob Konfigurationswert existiert
     */
    protected function hasConfig(string $key): bool
    {
        return $this->getConfig($key) !== null;
    }

    /**
     * Sichere Konfigurationswert-Abfrage mit erweiterten Features
     */
    protected function getConfig(string $key, mixed $default = null): mixed
    {
        // Sicherheitsprüfungen
        if (!is_string($key) || $key === '' || str_contains($key, '..')) {
            return $default;
        }

        $keys = array_filter(explode('.', $key), 'strlen');
        if (empty($keys)) {
            return $default;
        }

        $value = $this->container->config;
        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Environment-aware configuration mit PHP 8.4 Features
     */
    protected function getEnvConfig(string $key, string $envKey, mixed $default = null): mixed
    {
        return match (true) {
            isset($_ENV[$envKey]) => $this->parseEnvValue($_ENV[$envKey]),
            isset($_SERVER[$envKey]) => $this->parseEnvValue($_SERVER[$envKey]),
            default => $this->getConfig($key, $default)
        };
    }

    /**
     * Parse environment values mit type conversion
     */
    private function parseEnvValue(string $value): mixed
    {
        return match (strtolower($value)) {
            'true', 'yes', '1', 'on' => true,
            'false', 'no', '0', 'off', '' => false,
            'null' => null,
            default => is_numeric($value) ?
                (str_contains($value, '.') ? (float)$value : (int)$value) :
                $value
        };
    }

    /**
     * Sichere Instance-Registrierung
     */
    protected function instance(string $id, object $instance): void
    {
        try {
            $this->container->instance($id, $instance);
        } catch (ContainerException $e) {
            throw ContainerException::configurationError(
                $id,
                "Failed to register instance in " . static::class . ": " . $e->getMessage(),
                ['provider' => static::class]
            );
        }
    }

    /**
     * Conditional Service Registration mit vereinfachten Bedingungen
     */
    protected function bindIf(string $condition, string $id, mixed $concrete = null, bool $singleton = false): void
    {
        if ($this->evaluateCondition($condition)) {
            $this->bind($id, $concrete, $singleton);
        }
    }

    /**
     * Vereinfachte Bedingungsauswertung mit PHP 8.4 match
     */
    protected function evaluateCondition(string $condition): bool
    {
        return match ($condition) {
            'debug' => $this->getConfig('app.debug', false),
            'production' => $this->getConfig('app.env') === 'production',
            'development' => $this->getConfig('app.env') === 'development',
            'testing' => $this->getConfig('app.env') === 'testing',
            default => $this->hasConfig($condition)
        };
    }

    /**
     * Sichere Service-Registrierung mit Validierung
     */
    protected function bind(string $id, mixed $concrete = null, bool $singleton = false): void
    {
        try {
            $this->container->bind($id, $concrete, $singleton);
        } catch (ContainerException $e) {
            throw ContainerException::configurationError(
                $id,
                "Failed to bind service in " . static::class . ": " . $e->getMessage(),
                ['provider' => static::class]
            );
        }
    }

    /**
     * Helper für Factory-Closures mit Error Handling
     */
    protected function factory(callable $factory): \Closure
    {
        return function (Container $container) use ($factory): mixed {
            try {
                return $factory($container);
            } catch (\Throwable $e) {
                throw ContainerException::cannotResolve(
                    'factory_service',
                    "Factory in " . static::class . " failed: " . $e->getMessage(),
                    ['provider' => static::class]
                );
            }
        };
    }

    /**
     * Register multiple tagged services
     */
    protected function registerTaggedServices(string $tag, array $services): void
    {
        foreach ($services as $id => $concrete) {
            if (!is_string($id)) continue;

            try {
                $this->bind($id, $concrete);
                $this->tag($id, $tag);
            } catch (\Throwable $e) {
                error_log("Failed to register tagged service '{$id}' with tag '{$tag}': " . $e->getMessage());
            }
        }
    }

    /**
     * Service-Tagging mit Validierung
     */
    protected function tag(string $serviceId, string $tag): void
    {
        try {
            $this->container->tag($serviceId, $tag);
        } catch (ContainerException $e) {
            throw ContainerException::configurationError(
                $serviceId,
                "Failed to tag service in " . static::class . ": " . $e->getMessage(),
                ['provider' => static::class, 'tag' => $tag]
            );
        }
    }

    /**
     * Contextual binding helper
     */
    protected function when(string $context): ContextualBindingBuilder
    {
        return $this->container->when($context);
    }

    /**
     * Register services with common configuration
     */
    protected function registerGroup(array $services, array $commonOptions = []): void
    {
        foreach ($services as $id => $concrete) {
            if (!is_string($id)) continue;

            $options = match (true) {
                is_array($concrete) => array_merge($commonOptions, $concrete),
                default => array_merge($commonOptions, ['concrete' => $concrete])
            };

            $this->registerService($id, $options['concrete'] ?? $id, $options);
        }
    }

    /**
     * Erweiterte Service-Registrierung mit Optionen
     */
    protected function registerService(string $id, mixed $concrete, array $options = []): void
    {
        $singleton = $options['singleton'] ?? true;
        $tags = $options['tags'] ?? [];
        $alias = $options['alias'] ?? null;
        $lazy = $options['lazy'] ?? false;

        // Service registrieren
        match ($lazy) {
            true => $this->lazy($id, $concrete, $singleton),
            false => $this->bind($id, $concrete, $singleton)
        };

        // Tags hinzufügen
        foreach ($tags as $tag) {
            if (is_string($tag) && $tag !== '') {
                $this->tag($id, $tag);
            }
        }

        // Alias registrieren
        if ($alias && is_string($alias)) {
            $this->bind($alias, $id, $singleton);
        }
    }

    /**
     * Lazy service registration mit PHP 8.4 Features
     */
    protected function lazy(string $id, callable $factory, bool $singleton = true): void
    {
        try {
            $this->container->lazy($id, $factory, $singleton);
        } catch (ContainerException $e) {
            throw ContainerException::configurationError(
                $id,
                "Failed to register lazy service in " . static::class . ": " . $e->getMessage(),
                ['provider' => static::class]
            );
        }
    }

    /**
     * Register conditional services based on environment
     */
    protected function registerConditional(array $conditions): void
    {
        foreach ($conditions as $condition => $services) {
            if ($this->evaluateCondition($condition)) {
                match (true) {
                    is_array($services) => $this->registerBatch($services),
                    is_callable($services) => $services($this),
                    default => null
                };
            }
        }
    }

    /**
     * Batch registration with error handling
     */
    protected function registerBatch(array $definitions): void
    {
        foreach ($definitions as $id => $definition) {
            if (!is_string($id)) {
                continue;
            }

            try {
                match (true) {
                    is_array($definition) => $this->registerService($id, $definition['concrete'] ?? $id, $definition),
                    is_callable($definition) => $this->singleton($id, $definition),
                    default => $this->bind($id, $definition)
                };
            } catch (\Throwable $e) {
                error_log("Failed to register service '{$id}' in " . static::class . ": " . $e->getMessage());
            }
        }
    }

    /**
     * Sichere Singleton-Registrierung
     */
    protected function singleton(string $id, mixed $concrete = null): void
    {
        $this->bind($id, $concrete, true);
    }

    /**
     * Register aliases for services
     */
    protected function registerAliases(array $aliases): void
    {
        foreach ($aliases as $alias => $target) {
            if (is_string($alias) && is_string($target)) {
                $this->bind($alias, $target);
            }
        }
    }
}