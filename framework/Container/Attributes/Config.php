<?php

declare(strict_types=1);

namespace Framework\Container\Attributes;

use Attribute;

/**
 * Mark a parameter to be injected with a configuration value mit PHP 8.4 Features
 *
 * Enables direct injection of configuration values using dot notation
 * for nested array access, eliminating the need for manual config retrieval.
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
final readonly class Config
{
    /**
     * @param string $key Configuration key (dot notation supported for nested arrays)
     * @param mixed $default Default value if configuration key not found
     * @param string|null $env Environment variable name to check first
     * @param bool $required Whether this config value is required (throws if missing)
     * @param callable|null $transform Transform function to apply to the value
     */
    public function __construct(
        public string $key,
        public mixed $default = null,
        public ?string $env = null,
        public bool $required = false,
        public mixed $transform = null
    ) {
        // Validation mit PHP 8.4 Features
        match (true) {
            empty($this->key) =>
            throw new \InvalidArgumentException('Config key cannot be empty'),
            str_contains($this->key, '..') =>
            throw new \InvalidArgumentException('Config key cannot contain ".."'),
            !preg_match('/^[a-zA-Z_][a-zA-Z0-9_\.]*$', $this->key) =>
            throw new \InvalidArgumentException('Invalid config key format'),
            default => null
        };

        if ($this->env !== null && !preg_match('/^[A-Z_][A-Z0-9_]*$/', $this->env)) {
            throw new \InvalidArgumentException('Invalid environment variable name format');
        }

        if ($this->transform !== null && !is_callable($this->transform)) {
            throw new \InvalidArgumentException('Transform must be callable');
        }
    }

    /**
     * Get the configuration value with environment variable support
     */
    public function getValue(array $config): mixed
    {
        // Check environment variable first if specified
        if ($this->env !== null) {
            $envValue = $_ENV[$this->env] ?? $_SERVER[$this->env] ?? null;
            if ($envValue !== null) {
                return $this->transformValue($this->parseEnvValue($envValue));
            }
        }

        // Get from config array
        $value = $this->getNestedValue($config, $this->key);

        if ($value === null) {
            if ($this->required && $this->default === null) {
                throw new \RuntimeException("Required config key '{$this->key}' not found");
            }
            return $this->transformValue($this->default);
        }

        return $this->transformValue($value);
    }

    /**
     * Parse environment variable value with type conversion
     */
    private function parseEnvValue(string $value): mixed
    {
        return match (strtolower($value)) {
            'true', 'yes', '1', 'on' => true,
            'false', 'no', '0', 'off', '' => false,
            'null' => null,
            default => is_numeric($value) ? (str_contains($value, '.') ? (float)$value : (int)$value) : $value
        };
    }

    /**
     * Get nested configuration value using dot notation
     */
    private function getNestedValue(array $config, string $key): mixed
    {
        $keys = explode('.', $key);
        $value = $config;

        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Apply transformation function if provided
     */
    private function transformValue(mixed $value): mixed
    {
        if ($this->transform === null) {
            return $value;
        }

        return ($this->transform)($value);
    }

    /**
     * Check if the config key exists in the given config array
     */
    public function exists(array $config): bool
    {
        if ($this->env !== null && (isset($_ENV[$this->env]) || isset($_SERVER[$this->env]))) {
            return true;
        }

        return $this->getNestedValue($config, $this->key) !== null;
    }
}