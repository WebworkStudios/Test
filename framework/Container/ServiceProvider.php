<?php

declare(strict_types=1);

namespace Framework\Container;

/**
 * Enhanced Service Provider für organizing service registrations
 * 
 * Erweiterte Service Provider Klasse mit Prioritäten, Conditional Loading,
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
abstract readonly class ServiceProvider
{
    protected int $priority = 0;
    protected array $requiredConfig = [];
    protected array $requiredServices = [];
    protected bool $loadOnDemand = false;

    public function __construct(
        protected Container $container
    ) {}

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
     * Prüft ob Provider geladen werden soll
     */
    public function shouldLoad(): bool
    {
        return $this->validateRequirements();
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
            if (!$this->container->has($serviceId)) {
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
     * Sichere Konfigurationswert-Abfrage
     */
    protected function getConfig(string $key, mixed $default = null): mixed
    {
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
     * Determine if provider should be deferred
     * 
     * Deferred providers are only registered when their
     * services are actually requested.
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
     * Get provider priority (höher = früher geladen)
     */
    public function getPriority(): int
    {
        return $this->priority;
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
     * Sichere Singleton-Registrierung
     */
    protected function singleton(string $id, mixed $concrete = null): void
    {
        $this->bind($id, $concrete, true);
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
     * Erweiterte Service-Registrierung mit Optionen
     */
    protected function registerService(string $id, mixed $concrete, array $options = []): void
    {
        $singleton = $options['singleton'] ?? true;
        $tags = $options['tags'] ?? [];
        $alias = $options['alias'] ?? null;

        // Hauptservice registrieren
        $this->bind($id, $concrete, $singleton);

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
     * Conditional Service Registration
     */
    protected function bindIf(string $condition, string $id, mixed $concrete = null, bool $singleton = false): void
    {
        if ($this->evaluateCondition($condition)) {
            $this->bind($id, $concrete, $singleton);
        }
    }

    /**
     * Evaluiert Bedingungen für Conditional Loading
     */
    protected function evaluateCondition(string $condition): bool
    {
        return match ($condition) {
            'debug' => $this->getConfig('app.debug', false),
            'production' => $this->getConfig('app.env') === 'production',
            'testing' => $this->getConfig('app.env') === 'testing', 
            default => $this->hasConfig($condition)
        };
    }

    /**
     * Helper für Factory-Closures mit Error Handling
     */
    protected function factory(callable $factory): \Closure
    {
        return function(Container $container) use ($factory): mixed {
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
     * Bulk-Registrierung von Services
     * 
     * @param array<string, mixed> $services
     */
    protected function registerServices(array $services): void
    {
        foreach ($services as $id => $definition) {
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
                // Protokolliere Fehler, aber stoppe nicht die gesamte Registrierung
                error_log("Failed to register service '{$id}' in " . static::class . ": " . $e->getMessage());
            }
        }
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
            'should_load' => $this->shouldLoad()
        ];
    }
}