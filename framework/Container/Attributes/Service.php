<?php

declare(strict_types=1);

namespace Framework\Container\Attributes;

use Attribute;

/**
 * Mark a class as a service to be auto-registered in the container mit PHP 8.4 Features
 *
 * This attribute enables automatic service discovery and registration,
 * eliminating the need for manual service provider configurations.
 *
 * @example
 * #[Service(id: 'my.service', singleton: true, tags: ['cache', 'storage'], lazy: true)]
 * class RedisCache implements CacheInterface {}
 *
 * #[Service(condition: 'app.env === "production"', scope: 'request')]
 * class ProductionLogger implements LoggerInterface {}
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Service
{
    /**
     * @param string|null $id Custom service ID, defaults to class name
     * @param bool $singleton Whether to register as singleton (default: true)
     * @param array<string> $tags Tags for service discovery and grouping
     * @param int $priority Priority for ordered resolution (higher = first)
     * @param bool $lazy Whether to register as lazy service
     * @param string|null $condition Condition for conditional registration
     * @param string $scope Service scope: 'singleton', 'transient', 'request', 'session'
     * @param array<string> $interfaces Specific interfaces to bind to
     */
    public function __construct(
        public ?string $id = null,
        public bool $singleton = true,
        public array $tags = [],
        public int $priority = 0,
        public bool $lazy = false,
        public ?string $condition = null,
        public string $scope = 'singleton',
        public array $interfaces = []
    ) {
        // Validation mit PHP 8.4 match
        match ($this->scope) {
            'singleton', 'transient', 'request', 'session' => null,
            default => throw new \InvalidArgumentException("Invalid scope: {$this->scope}")
        };

        // Validate service ID if provided
        if ($this->id !== null) {
            match (true) {
                empty($this->id) =>
                throw new \InvalidArgumentException('Service ID cannot be empty'),
                str_contains($this->id, '..') =>
                throw new \InvalidArgumentException('Service ID cannot contain ".."'),
                !preg_match('/^[a-zA-Z_\\\\][a-zA-Z0-9_\\\\.]*$/', $this->id) =>
                throw new \InvalidArgumentException('Invalid service ID format'),
                default => null
            };
        }

        // Validate tags
        foreach ($this->tags as $tag) {
            if (!is_string($tag) || empty($tag) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_\.]*$/', $tag)) {
                throw new \InvalidArgumentException("Invalid tag format: {$tag}");
            }
        }

        // Validate interfaces
        foreach ($this->interfaces as $interface) {
            if (!is_string($interface) || empty($interface)) {
                throw new \InvalidArgumentException("Invalid interface specification: {$interface}");
            }
        }
    }

    /**
     * Check if service should be registered based on condition
     */
    public function shouldRegister(array $config = []): bool
    {
        if ($this->condition === null) {
            return true;
        }

        return $this->evaluateCondition($this->condition, $config);
    }

    /**
     * Evaluate condition string
     */
    private function evaluateCondition(string $condition, array $config): bool
    {
        // Simple condition evaluation - in production sollte ein echter Parser verwendet werden
        return match (true) {
            str_contains($condition, 'app.env === "production"') =>
                ($config['app']['env'] ?? '') === 'production',
            str_contains($condition, 'app.env === "development"') =>
                ($config['app']['env'] ?? '') === 'development',
            str_contains($condition, 'app.debug === true') =>
                ($config['app']['debug'] ?? false) === true,
            str_contains($condition, 'app.debug === false') =>
                ($config['app']['debug'] ?? false) === false,
            default => true // Fallback für unbekannte Bedingungen
        };
    }

    /**
     * Get effective singleton setting based on scope
     */
    public function isSingleton(): bool
    {
        return match ($this->scope) {
            'singleton' => true,
            'transient' => false,
            'request', 'session' => $this->singleton,
            default => $this->singleton
        };
    }

    /**
     * Get service registration options
     */
    public function getRegistrationOptions(): array
    {
        return [
            'singleton' => $this->isSingleton(),
            'lazy' => $this->lazy,
            'tags' => $this->tags,
            'priority' => $this->priority,
            'scope' => $this->scope,
            'interfaces' => $this->interfaces
        ];
    }

    /**
     * Get unique service identifier
     */
    public function getServiceId(string $className): string
    {
        return $this->id ?? $className;
    }
}