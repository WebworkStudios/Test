<?php

declare(strict_types=1);

namespace Framework\Container\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Mark a class as a service to be auto-registered in the container mit PHP 8.4 Features
 *
 * Vereinfachte Service-Attribut-Klasse mit optimierter Validierung
 * und verbesserter Developer Experience.
 *
 * @example
 * #[Service(id: 'my.service', singleton: true, tags: ['cache', 'storage'], lazy: true)]
 * class RedisCache implements CacheInterface {}
 *
 * #[Service(condition: 'production', scope: 'request')]
 * class ProductionLogger implements LoggerInterface {}
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Service
{
    // Property Hooks für computed properties
    public bool $isSingleton {
        get => match ($this->scope) {
            'singleton' => true,
            'transient' => false,
            'request', 'session' => $this->singleton,
            default => $this->singleton
        };
    }

    public bool $hasCondition {
        get => $this->condition !== null;
    }

    public bool $hasInterfaces {
        get => !empty($this->interfaces);
    }

    public int $tagCount {
        get => count($this->tags);
    }

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
        public bool    $singleton = true,
        public array   $tags = [],
        public int     $priority = 0,
        public bool    $lazy = false,
        public ?string $condition = null,
        public string  $scope = 'singleton',
        public array   $interfaces = []
    )
    {
        $this->validateConstruction();
    }

    /**
     * Validate all constructor parameters
     */
    private function validateConstruction(): void
    {
        $this->validateScope();
        $this->validateId();
        $this->validateTags();
        $this->validateInterfaces();
        $this->validatePriority();
    }

    /**
     * Validate scope parameter
     */
    private function validateScope(): void
    {
        match ($this->scope) {
            'singleton', 'transient', 'request', 'session' => null,
            default => throw new InvalidArgumentException("Invalid scope: {$this->scope}")
        };
    }

    /**
     * Validate service ID if provided
     */
    private function validateId(): void
    {
        if ($this->id === null) {
            return;
        }

        match (true) {
            empty($this->id) =>
            throw new InvalidArgumentException('Service ID cannot be empty string'),
            strlen($this->id) > 255 =>
            throw new InvalidArgumentException('Service ID too long (max 255 characters)'),
            str_contains($this->id, '..') =>
            throw new InvalidArgumentException('Service ID cannot contain ".."'),
            !preg_match('/^[a-zA-Z_\\\\][a-zA-Z0-9_\\\\.]*$/', $this->id) =>
            throw new InvalidArgumentException('Invalid service ID format'),
            default => null
        };
    }

    /**
     * Validate tags array
     */
    private function validateTags(): void
    {
        if (count($this->tags) > 10) {
            throw new InvalidArgumentException('Too many tags (max 10)');
        }

        foreach ($this->tags as $tag) {
            match (true) {
                !is_string($tag) =>
                throw new InvalidArgumentException('Tags must be strings'),
                empty($tag) =>
                throw new InvalidArgumentException('Tag cannot be empty'),
                strlen($tag) > 100 =>
                throw new InvalidArgumentException('Tag too long (max 100 characters)'),
                !preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $tag) =>
                throw new InvalidArgumentException("Invalid tag format: {$tag}"),
                default => null
            };
        }
    }

    /**
     * Validate interfaces array
     */
    private function validateInterfaces(): void
    {
        if (count($this->interfaces) > 10) {
            throw new InvalidArgumentException('Too many interfaces (max 10)');
        }

        foreach ($this->interfaces as $interface) {
            match (true) {
                !is_string($interface) =>
                throw new InvalidArgumentException('Interfaces must be strings'),
                empty($interface) =>
                throw new InvalidArgumentException('Interface cannot be empty'),
                strlen($interface) > 255 =>
                throw new InvalidArgumentException('Interface name too long'),
                !preg_match('/^[a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*$/', $interface) =>
                throw new InvalidArgumentException("Invalid interface format: {$interface}"),
                default => null
            };
        }
    }

    /**
     * Validate priority value
     */
    private function validatePriority(): void
    {
        match (true) {
            $this->priority < 0 =>
            throw new InvalidArgumentException('Priority cannot be negative'),
            $this->priority > 1000 =>
            throw new InvalidArgumentException('Priority too high (max 1000)'),
            default => null
        };
    }

    /**
     * Create from array (for cache/serialization)
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? null,
            singleton: $data['singleton'] ?? true,
            tags: $data['tags'] ?? [],
            priority: $data['priority'] ?? 0,
            lazy: $data['lazy'] ?? false,
            condition: $data['condition'] ?? null,
            scope: $data['scope'] ?? 'singleton',
            interfaces: $data['interfaces'] ?? []
        );
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
     * Simplified condition evaluation mit PHP 8.4 match
     */
    private function evaluateCondition(string $condition, array $config): bool
    {
        return match ($condition) {
            'production' => ($config['app']['env'] ?? '') === 'production',
            'development' => ($config['app']['env'] ?? '') === 'development',
            'testing' => ($config['app']['env'] ?? '') === 'testing',
            'debug' => ($config['app']['debug'] ?? false) === true,
            default => $this->hasConfigValue($condition, $config)
        };
    }

    /**
     * Check if config value exists and is truthy
     */
    private function hasConfigValue(string $key, array $config): bool
    {
        $keys = explode('.', $key);
        $value = $config;

        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return false;
            }
            $value = $value[$segment];
        }

        return !empty($value);
    }

    /**
     * Get service registration options
     */
    public function getRegistrationOptions(): array
    {
        return [
            'singleton' => $this->isSingleton,
            'lazy' => $this->lazy,
            'tags' => $this->tags,
            'priority' => $this->priority,
            'scope' => $this->scope,
            'interfaces' => $this->interfaces,
            'condition' => $this->condition
        ];
    }

    /**
     * Get unique service identifier
     */
    public function getServiceId(string $className): string
    {
        return $this->id ?? $className;
    }

    /**
     * Check if service is deprecated
     */
    public function isDeprecated(): bool
    {
        return in_array('deprecated', $this->tags, true);
    }

    /**
     * Get service description from tags
     */
    public function getDescription(): ?string
    {
        foreach ($this->tags as $tag) {
            if (str_starts_with($tag, 'description:')) {
                return substr($tag, 12); // Remove 'description:' prefix
            }
        }
        return null;
    }

    /**
     * Check if service has specific tag
     */
    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags, true);
    }

    /**
     * Get service category from tags
     */
    public function getCategory(): ?string
    {
        foreach ($this->tags as $tag) {
            if (str_starts_with($tag, 'category:')) {
                return substr($tag, 9); // Remove 'category:' prefix
            }
        }
        return null;
    }

    /**
     * Convert to array for serialization
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'singleton' => $this->singleton,
            'tags' => $this->tags,
            'priority' => $this->priority,
            'lazy' => $this->lazy,
            'condition' => $this->condition,
            'scope' => $this->scope,
            'interfaces' => $this->interfaces,
            'is_singleton' => $this->isSingleton,
            'has_condition' => $this->hasCondition,
            'tag_count' => $this->tagCount
        ];
    }

    /**
     * Clone service with different options
     */
    public function withId(?string $id): self
    {
        return new self(
            $id,
            $this->singleton,
            $this->tags,
            $this->priority,
            $this->lazy,
            $this->condition,
            $this->scope,
            $this->interfaces
        );
    }

    /**
     * Clone service with additional tags
     */
    public function withTags(array $additionalTags): self
    {
        return new self(
            $this->id,
            $this->singleton,
            array_unique([...$this->tags, ...$additionalTags]),
            $this->priority,
            $this->lazy,
            $this->condition,
            $this->scope,
            $this->interfaces
        );
    }

    /**
     * Clone service with different scope
     */
    public function withScope(string $scope): self
    {
        return new self(
            $this->id,
            $this->singleton,
            $this->tags,
            $this->priority,
            $this->lazy,
            $this->condition,
            $scope,
            $this->interfaces
        );
    }

    /**
     * Clone service as lazy
     */
    public function asLazy(): self
    {
        return new self(
            $this->id,
            $this->singleton,
            $this->tags,
            $this->priority,
            true, // lazy = true
            $this->condition,
            $this->scope,
            $this->interfaces
        );
    }

    /**
     * Get validation errors
     */
    public function getValidationErrors(): array
    {
        $errors = [];

        try {
            $this->validateScope();
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }

        try {
            $this->validateId();
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }

        try {
            $this->validateTags();
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }

        try {
            $this->validateInterfaces();
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }

        try {
            $this->validatePriority();
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }

        return $errors;
    }

    /**
     * Magic method for debugging
     */
    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'scope' => $this->scope,
            'is_singleton' => $this->isSingleton,
            'is_lazy' => $this->lazy,
            'tag_count' => $this->tagCount,
            'has_condition' => $this->hasCondition,
            'has_interfaces' => $this->hasInterfaces,
            'priority' => $this->priority,
            'is_valid' => $this->isValid()
        ];
    }

    /**
     * Check if service configuration is valid
     */
    public function isValid(): bool
    {
        try {
            $this->validateConstruction();
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * String representation
     */
    public function __toString(): string
    {
        $parts = [];

        if ($this->id) {
            $parts[] = "id:{$this->id}";
        }

        $parts[] = "scope:{$this->scope}";

        if ($this->lazy) {
            $parts[] = "lazy";
        }

        if (!empty($this->tags)) {
            $parts[] = "tags:" . implode(',', $this->tags);
        }

        if ($this->condition) {
            $parts[] = "condition:{$this->condition}";
        }

        return 'Service(' . implode(' ', $parts) . ')';
    }
}