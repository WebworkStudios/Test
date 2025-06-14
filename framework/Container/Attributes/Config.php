<?php

declare(strict_types=1);

namespace Framework\Container\Attributes;

use Attribute;

/**
 * Mark a parameter to be injected with a configuration value mit PHP 8.4 Features
 *
 * Vereinfachte Config-Attribut-Klasse mit optimierter Validierung,
 * Environment-Support und verbesserter Type-Conversion.
 *
 * @example
 * public function __construct(
 *     #[Config('database.host', 'localhost')] string $host,
 *     #[Config('database.port', 3306)] int $port,
 *     #[Config('app.debug', false)] bool $debug,
 *     #[Config('cache.ttl', env: 'CACHE_TTL')] int $ttl
 * ) {}
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Config
{
    // Property Hooks für computed properties
    public bool $hasDefault {
        get => $this->default !== null;
    }

    public bool $hasEnvironmentVariable {
        get => $this->env !== null;
    }

    public bool $isRequired {
        get => $this->required;
    }

    public bool $hasTransform {
        get => $this->transform !== null;
    }

    public array $keySegments {
        get => array_filter(explode('.', $this->key), 'strlen');
    }

    public int $keyDepth {
        get => count($this->keySegments);
    }

    /**
     * @param string $key Configuration key (dot notation supported for nested arrays)
     * @param mixed $default Default value if configuration key not found
     * @param string|null $env Environment variable name to check first
     * @param bool $required Whether this config value is required (throws if missing)
     * @param callable|null $transform Transform function to apply to the value
     */
    public function __construct(
        public string  $key,
        public mixed   $default = null,
        public ?string $env = null,
        public bool    $required = false,
        public mixed   $transform = null
    )
    {
        $this->validateConstruction();
    }

    /**
     * Validate all constructor parameters
     */
    private function validateConstruction(): void
    {
        $this->validateKey();
        $this->validateEnvironmentVariable();
        $this->validateTransform();
        $this->validateRequiredLogic();
    }

    /**
     * Validate configuration key
     */
    private function validateKey(): void
    {
        match (true) {
            empty($this->key) =>
            throw new \InvalidArgumentException('Config key cannot be empty'),
            strlen($this->key) > 255 =>
            throw new \InvalidArgumentException('Config key too long (max 255 characters)'),
            str_contains($this->key, '..') =>
            throw new \InvalidArgumentException('Config key cannot contain ".."'),
            str_contains($this->key, '/') =>
            throw new \InvalidArgumentException('Config key cannot contain "/"'),
            str_contains($this->key, '\\') =>
            throw new \InvalidArgumentException('Config key cannot contain "\\"'),
            !preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $this->key) =>
            throw new \InvalidArgumentException('Invalid config key format'),
            $this->keyDepth > 10 =>
            throw new \InvalidArgumentException('Config key too deep (max 10 levels)'),
            default => null
        };
    }

    /**
     * Validate environment variable name
     */
    private function validateEnvironmentVariable(): void
    {
        if ($this->env === null) {
            return;
        }

        match (true) {
            empty($this->env) =>
            throw new \InvalidArgumentException('Environment variable name cannot be empty'),
            strlen($this->env) > 100 =>
            throw new \InvalidArgumentException('Environment variable name too long'),
            !preg_match('/^[A-Z_][A-Z0-9_]*$/', $this->env) =>
            throw new \InvalidArgumentException('Invalid environment variable name format'),
            default => null
        };
    }

    /**
     * Validate transform function
     */
    private function validateTransform(): void
    {
        if ($this->transform !== null && !is_callable($this->transform)) {
            throw new \InvalidArgumentException('Transform must be callable');
        }
    }

    /**
     * Validate required logic
     */
    private function validateRequiredLogic(): void
    {
        if ($this->required && $this->default !== null) {
            throw new \InvalidArgumentException('Required config cannot have default value');
        }
    }

    /**
     * Create config with type validation
     */
    public static function typed(
        string  $key,
        string  $type,
        mixed   $default = null,
        ?string $env = null
    ): self
    {
        $transform = match ($type) {
            'string' => fn($v) => (string)$v,
            'int', 'integer' => fn($v) => (int)$v,
            'float', 'double' => fn($v) => (float)$v,
            'bool', 'boolean' => fn($v) => (bool)$v,
            'array' => fn($v) => is_array($v) ? $v : [$v],
            default => null
        };

        return new self($key, $default, $env, transform: $transform);
    }

    /**
     * Create required config
     */
    public static function required(string $key, ?string $env = null): self
    {
        return new self($key, required: true, env: $env);
    }

    /**
     * Create config with environment fallback
     */
    public static function env(string $envKey, mixed $default = null, ?string $configKey = null): self
    {
        return new self(
            key: $configKey ?? strtolower(str_replace('_', '.', $envKey)),
            default: $default,
            env: $envKey
        );
    }

    /**
     * Create config with transform function
     */
    public static function transform(string $key, callable $transform, mixed $default = null): self
    {
        return new self($key, $default, transform: $transform);
    }

    /**
     * Create from array (for cache/serialization)
     */
    public static function fromArray(array $data): self
    {
        return new self(
            key: $data['key'],
            default: $data['default'] ?? null,
            env: $data['env'] ?? null,
            required: $data['required'] ?? false
        // Note: transform functions cannot be serialized
        );
    }

    /**
     * Get the configuration value with environment variable support
     */
    public function getValue(array $config): mixed
    {
        // Check environment variable first if specified
        if ($this->env !== null) {
            $envValue = $this->getEnvironmentValue();
            if ($envValue !== null) {
                return $this->transformValue($envValue);
            }
        }

        // Get from config array
        $value = $this->getNestedValue($config, $this->key);

        if ($value === null) {
            return $this->handleMissingValue();
        }

        return $this->transformValue($value);
    }

    /**
     * Get environment variable value with type conversion
     */
    private function getEnvironmentValue(): mixed
    {
        $envValue = $_ENV[$this->env] ?? $_SERVER[$this->env] ?? null;

        if ($envValue === null) {
            return null;
        }

        return $this->parseEnvValue((string)$envValue);
    }

    /**
     * Parse environment variable value with smart type conversion
     */
    private function parseEnvValue(string $value): mixed
    {
        return match (strtolower(trim($value))) {
            'true', 'yes', '1', 'on' => true,
            'false', 'no', '0', 'off', '' => false,
            'null', 'nil' => null,
            default => $this->parseNumericOrString($value)
        };
    }

    /**
     * Parse numeric values or return string
     */
    private function parseNumericOrString(string $value): mixed
    {
        return match (true) {
            is_numeric($value) && str_contains($value, '.') => (float)$value,
            is_numeric($value) => (int)$value,
            $this->isJsonString($value) => $this->parseJsonValue($value),
            default => $value
        };
    }

    /**
     * Check if string is JSON
     */
    private function isJsonString(string $value): bool
    {
        $trimmed = trim($value);
        return (str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}')) ||
            (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']'));
    }

    /**
     * Parse JSON value safely
     */
    private function parseJsonValue(string $value): mixed
    {
        try {
            return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $value; // Return as string if JSON parsing fails
        }
    }

    /**
     * Apply transformation function if provided
     */
    private function transformValue(mixed $value): mixed
    {
        if ($this->transform === null) {
            return $value;
        }

        try {
            return ($this->transform)($value);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "Transform function failed for config key '{$this->key}': " . $e->getMessage(),
                previous: $e
            );
        }
    }

    /**
     * Get nested configuration value using dot notation
     */
    private function getNestedValue(array $config, string $key): mixed
    {
        $value = $config;

        foreach ($this->keySegments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Handle missing configuration value
     */
    private function handleMissingValue(): mixed
    {
        if ($this->required) {
            throw new \RuntimeException("Required config key '{$this->key}' not found");
        }

        return $this->transformValue($this->default);
    }

    /**
     * Check if the config key exists in the given config array
     */
    public function exists(array $config): bool
    {
        // Check environment variable first
        if ($this->env !== null && $this->getEnvironmentValue() !== null) {
            return true;
        }

        return $this->getNestedValue($config, $this->key) !== null;
    }

    /**
     * Get the raw value without transformation
     */
    public function getRawValue(array $config): mixed
    {
        // Check environment variable first
        if ($this->env !== null) {
            $envValue = $this->getEnvironmentValue();
            if ($envValue !== null) {
                return $envValue;
            }
        }

        $value = $this->getNestedValue($config, $this->key);
        return $value ?? $this->default;
    }

    /**
     * Validate that config value matches expected type
     */
    public function validateType(mixed $value, string $expectedType): bool
    {
        return match ($expectedType) {
            'string' => is_string($value),
            'int', 'integer' => is_int($value),
            'float', 'double' => is_float($value),
            'bool', 'boolean' => is_bool($value),
            'array' => is_array($value),
            'object' => is_object($value),
            'null' => is_null($value),
            default => true // Unknown type, allow anything
        };
    }

    /**
     * Clone config with different default
     */
    public function withDefault(mixed $default): self
    {
        return new self(
            $this->key,
            $default,
            $this->env,
            $this->required,
            $this->transform
        );
    }

    /**
     * Clone config with environment variable
     */
    public function withEnv(string $env): self
    {
        return new self(
            $this->key,
            $this->default,
            $env,
            $this->required,
            $this->transform
        );
    }

    /**
     * Clone config as required
     */
    public function asRequired(): self
    {
        return new self(
            $this->key,
            null, // Required configs cannot have defaults
            $this->env,
            true,
            $this->transform
        );
    }

    /**
     * Convert to array for serialization
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'default' => $this->default,
            'env' => $this->env,
            'required' => $this->required,
            'has_transform' => $this->hasTransform,
            'key_depth' => $this->keyDepth
        ];
    }

    /**
     * Get validation errors
     */
    public function getValidationErrors(): array
    {
        $errors = [];

        try {
            $this->validateKey();
        } catch (\InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }

        try {
            $this->validateEnvironmentVariable();
        } catch (\InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }

        try {
            $this->validateTransform();
        } catch (\InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }

        try {
            $this->validateRequiredLogic();
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
            'key' => $this->key,
            'key_depth' => $this->keyDepth,
            'has_default' => $this->hasDefault,
            'has_env' => $this->hasEnvironmentVariable,
            'is_required' => $this->isRequired,
            'has_transform' => $this->hasTransform,
            'is_valid' => $this->isValid()
        ];
    }

    /**
     * Check if configuration is valid
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
        $parts = ["key:{$this->key}"];

        if ($this->hasDefault) {
            $parts[] = "default:" . json_encode($this->default);
        }

        if ($this->hasEnvironmentVariable) {
            $parts[] = "env:{$this->env}";
        }

        if ($this->isRequired) {
            $parts[] = "required";
        }

        if ($this->hasTransform) {
            $parts[] = "transform";
        }

        return 'Config(' . implode(' ', $parts) . ')';
    }
}