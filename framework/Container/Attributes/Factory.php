<?php

declare(strict_types=1);

namespace Framework\Container\Attributes;

use Attribute;

/**
 * Mark a method as a factory for creating services mit PHP 8.4 Features
 *
 * Factory methods provide custom instantiation logic for complex services
 * that require special setup or configuration beyond simple constructor injection.
 *
 * @example
 * #[Factory(creates: DatabaseConnection::class, singleton: true, lazy: true)]
 * public static function createConnection(Container $container): DatabaseConnection
 * {
 *     return new DatabaseConnection($container->get('config.database'));
 * }
 *
 * #[Factory(creates: 'logger.file', condition: 'app.logging.file.enabled')]
 * public static function createFileLogger(Container $container): LoggerInterface
 * {
 *     return new FileLogger($container->config('logging.file.path'));
 * }
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Factory
{
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
        public string $creates,
        public bool $singleton = true,
        public array $tags = [],
        public int $priority = 0,
        public bool $lazy = false,
        public ?string $condition = null,
        public string $scope = 'singleton',
        public array $parameters = []
    ) {
        // Validation mit PHP 8.4 Features
        match (true) {
            empty($this->creates) =>
            throw new \InvalidArgumentException('Factory creates cannot be empty'),
            str_contains($this->creates, '..') =>
            throw new \InvalidArgumentException('Factory creates cannot contain ".."'),
            default => null
        };

        match ($this->scope) {
            'singleton', 'transient', 'request', 'session' => null,
            default => throw new \InvalidArgumentException("Invalid scope: {$this->scope}")
        };

        // Validate tags
        foreach ($this->tags as $tag) {
            if (!is_string($tag) || empty($tag) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $tag)) {
                throw new \InvalidArgumentException("Invalid tag format: {$tag}");
            }
        }
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
     * Evaluate condition string (simplified version)
     */
    private function evaluateCondition(string $condition, array $config): bool
    {
        return match (true) {
            str_contains($condition, 'app.logging.file.enabled') =>
                ($config['app']['logging']['file']['enabled'] ?? false) === true,
            str_contains($condition, 'app.env === "production"') =>
                ($config['app']['env'] ?? '') === 'production',
            str_contains($condition, 'app.env === "development"') =>
                ($config['app']['env'] ?? '') === 'development',
            str_contains($condition, 'app.debug === true') =>
                ($config['app']['debug'] ?? false) === true,
            default => true
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
     * Get factory registration options
     */
    public function getRegistrationOptions(): array
    {
        return [
            'singleton' => $this->isSingleton(),
            'lazy' => $this->lazy,
            'tags' => $this->tags,
            'priority' => $this->priority,
            'scope' => $this->scope,
            'parameters' => $this->parameters
        ];
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
            '__destruct', '__wakeup', '__unserialize'
        ];

        $methodName = strtolower($method->getName());
        if (in_array($methodName, $dangerousMethods, true)) {
            return false;
        }

        // Mindestens ein Parameter (Container) sollte vorhanden sein
        if ($method->getNumberOfParameters() < 1) {
            return false;
        }

        return true;
    }

    /**
     * Get service ID for the created service
     */
    public function getServiceId(): string
    {
        return $this->creates;
    }
}