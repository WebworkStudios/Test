<?php

declare(strict_types=1);

namespace Framework\Container\Attributes;

use Attribute;

/**
 * Mark a method as a factory for creating services mit PHP 8.4 Features
 *
 * Vereinfachte Factory-Attribut-Klasse mit optimierter Validierung
 * und verbesserter Developer Experience.
 *
 * @example
 * #[Factory(creates: DatabaseConnection::class, singleton: true, lazy: true)]
 * public static function createConnection(Container $container): DatabaseConnection
 * {
 *     return new DatabaseConnection($container->resolve('config.database'));
 * }
 *
 * #[Factory(creates: 'logger.file', condition: 'production')]
 * public static function createFileLogger(Container $container): LoggerInterface
 * {
 *     return new FileLogger($container->resolve('config.logging.file.path'));
 * }
 */
#[Attribute(Attribute::TARGET_METHOD)]
final  class Factory
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

    public bool $hasTags {
        get => !empty($this->tags);
    }

    public int $tagCount {
        get => count($this->tags);
    }

    public bool $hasParameters {
        get => !empty($this->parameters);
    }

    /**
     * @param string $creates Class/interface this factory creates
     * @param bool $singleton Whether created service should be singleton
     * @param array<string> $tags Tags to assign to the created service
     * @param int $priority Priority for this factory if multiple exist
     * @param bool $lazy Whether to create lazy proxy
     * @param string|null $condition Condition for conditional factory registration
     * @param string $scope Service scope for the created service
     * @param array<string, mixed> $parameters Default parameters for factory method
     */
    public function __construct(
        public string  $creates,
        public bool    $singleton = true,
        public array   $tags = [],
        public int     $priority = 0,
        public bool    $lazy = false,
        public ?string $condition = null,
        public string  $scope = 'singleton',
        public array   $parameters = []
    )
    {
        $this->validateConstruction();
    }

    /**
     * Validate all constructor parameters
     */
    private function validateConstruction(): void
    {
        $this->validateCreates();
        $this->validateScope();
        $this->validateTags();
        $this->validatePriority();
        $this->validateParameters();
    }

    /**
     * Validate creates parameter
     */
    private function validateCreates(): void
    {
        match (true) {
            empty($this->creates) =>
            throw new \InvalidArgumentException('Factory creates cannot be empty'),
            strlen($this->creates) > 255 =>
            throw new \InvalidArgumentException('Factory creates name too long'),
            str_contains($this->creates, '..') =>
            throw new \InvalidArgumentException('Factory creates cannot contain ".."'),
            !preg_match('/^[a-zA-Z_\\\\][a-zA-Z0-9_\\\\.]*$/', $this->creates) =>
            throw new \InvalidArgumentException('Invalid factory creates format'),
            default => null
        };
    }

    /**
     * Validate scope parameter
     */
    private function validateScope(): void
    {
        match ($this->scope) {
            'singleton', 'transient', 'request', 'session' => null,
            default => throw new \InvalidArgumentException("Invalid scope: {$this->scope}")
        };
    }

    /**
     * Validate tags array
     */
    private function validateTags(): void
    {
        if (count($this->tags) > 10) {
            throw new \InvalidArgumentException('Too many tags (max 10)');
        }

        foreach ($this->tags as $tag) {
            match (true) {
                !is_string($tag) =>
                throw new \InvalidArgumentException('Tags must be strings'),
                empty($tag) =>
                throw new \InvalidArgumentException('Tag cannot be empty'),
                strlen($tag) > 100 =>
                throw new \InvalidArgumentException('Tag too long (max 100 characters)'),
                !preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $tag) =>
                throw new \InvalidArgumentException("Invalid tag format: {$tag}"),
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
            throw new \InvalidArgumentException('Priority cannot be negative'),
            $this->priority > 1000 =>
            throw new \InvalidArgumentException('Priority too high (max 1000)'),
            default => null
        };
    }

    /**
     * Validate parameters array
     */
    private function validateParameters(): void
    {
        if (count($this->parameters) > 20) {
            throw new \InvalidArgumentException('Too many parameters (max 20)');
        }

        foreach ($this->parameters as $name => $value) {
            match (true) {
                !is_string($name) =>
                throw new \InvalidArgumentException('Parameter names must be strings'),
                empty($name) =>
                throw new \InvalidArgumentException('Parameter name cannot be empty'),
                strlen($name) > 100 =>
                throw new \InvalidArgumentException('Parameter name too long'),
                !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) =>
                throw new \InvalidArgumentException("Invalid parameter name: {$name}"),
                default => null
            };
        }
    }

    /**
     * Create from array (for cache/serialization)
     */
    public static function fromArray(array $data): self
    {
        return new self(
            creates: $data['creates'],
            singleton: $data['singleton'] ?? true,
            tags: $data['tags'] ?? [],
            priority: $data['priority'] ?? 0,
            lazy: $data['lazy'] ?? false,
            condition: $data['condition'] ?? null,
            scope: $data['scope'] ?? 'singleton',
            parameters: $data['parameters'] ?? []
        );
    }

    /**
     * Check if factory should be registered based on condition
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
     * Validate that method signature is correct for factory
     */
    public function validateMethod(\ReflectionMethod $method): bool
    {
        // Factory methods müssen static und public sein
        if (!$method->isStatic() || !$method->isPublic()) {
            return false;
        }

        // Prüfe gefährliche Methodennamen
        $dangerousMethods = [
            'eval', 'system', 'exec', 'shell_exec', 'passthru',
            '__destruct', '__wakeup', '__unserialize', '__serialize'
        ];

        $methodName = strtolower($method->getName());
        if (in_array($methodName, $dangerousMethods, true)) {
            return false;
        }

        // Mindestens ein Parameter (Container) sollte vorhanden sein
        if ($method->getNumberOfParameters() < 1) {
            return false;
        }

        // Prüfe ersten Parameter (sollte Container oder ContainerInterface sein)
        $firstParam = $method->getParameters()[0];
        $firstParamType = $firstParam->getType();

        if ($firstParamType instanceof \ReflectionNamedType) {
            $typeName = $firstParamType->getName();
            $validTypes = ['Framework\\Container\\Container', 'Framework\\Container\\ContainerInterface'];

            if (!in_array($typeName, $validTypes, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get factory registration options
     */
    public function getRegistrationOptions(): array
    {
        return [
            'singleton' => $this->isSingleton,
            'lazy' => $this->lazy,
            'tags' => $this->tags,
            'priority' => $this->priority,
            'scope' => $this->scope,
            'parameters' => $this->parameters,
            'condition' => $this->condition
        ];
    }

    /**
     * Get service ID for the created service
     */
    public function getServiceId(): string
    {
        return $this->creates;
    }

    /**
     * Check if factory has specific tag
     */
    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags, true);
    }

    /**
     * Get factory description from tags
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
     * Convert to array for serialization
     */
    public function toArray(): array
    {
        return [
            'creates' => $this->creates,
            'singleton' => $this->singleton,
            'tags' => $this->tags,
            'priority' => $this->priority,
            'lazy' => $this->lazy,
            'condition' => $this->condition,
            'scope' => $this->scope,
            'parameters' => $this->parameters,
            'is_singleton' => $this->isSingleton,
            'has_condition' => $this->hasCondition,
            'tag_count' => $this->tagCount
        ];
    }

    /**
     * Clone factory with different creates
     */
    public function withCreates(string $creates): self
    {
        return new self(
            $creates,
            $this->singleton,
            $this->tags,
            $this->priority,
            $this->lazy,
            $this->condition,
            $this->scope,
            $this->parameters
        );
    }

    /**
     * Clone factory with additional tags
     */
    public function withTags(array $additionalTags): self
    {
        return new self(
            $this->creates,
            $this->singleton,
            array_unique([...$this->tags, ...$additionalTags]),
            $this->priority,
            $this->lazy,
            $this->condition,
            $this->scope,
            $this->parameters
        );
    }

    /**
     * Clone factory as lazy
     */
    public function asLazy(): self
    {
        return new self(
            $this->creates,
            $this->singleton,
            $this->tags,
            $this->priority,
            true, // lazy = true
            $this->condition,
            $this->scope,
            $this->parameters
        );
    }

    /**
     * Get validation errors
     */
    public function getValidationErrors(): array
    {
        $errors = [];

        try {
            $this->validateCreates();
        } catch (\InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }

        try {
            $this->validateScope();
        } catch (\InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }

        try {
            $this->validateTags();
        } catch (\InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }

        try {
            $this->validatePriority();
        } catch (\InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }

        try {
            $this->validateParameters();
        } catch (\InvalidArgumentException $e) {
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
            'creates' => $this->creates,
            'scope' => $this->scope,
            'is_singleton' => $this->isSingleton,
            'is_lazy' => $this->lazy,
            'tag_count' => $this->tagCount,
            'has_condition' => $this->hasCondition,
            'has_parameters' => $this->hasParameters,
            'priority' => $this->priority,
            'is_valid' => $this->isValid()
        ];
    }

    /**
     * Check if factory configuration is valid
     */
    public function isValid(): bool
    {
        try {
            $this->validateConstruction();
            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * String representation
     */
    public function __toString(): string
    {
        $parts = ["creates:{$this->creates}"];

        if ($this->scope !== 'singleton') {
            $parts[] = "scope:{$this->scope}";
        }

        if ($this->lazy) {
            $parts[] = "lazy";
        }

        if (!empty($this->tags)) {
            $parts[] = "tags:" . implode(',', $this->tags);
        }

        if ($this->condition) {
            $parts[] = "condition:{$this->condition}";
        }

        return 'Factory(' . implode(' ', $parts) . ')';
    }
}