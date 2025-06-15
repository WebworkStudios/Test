<?php

declare(strict_types=1);

namespace Framework\Routing\Attributes;

use Attribute;

/**
 * Optimized Route attribute for PHP 8.4
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Route
{
    public string $normalizedMethod {
        get => strtoupper($this->method);
    }

    public bool $hasParameters {
        get => str_contains($this->path, '{');
    }

    public array $allMethods {
        get => array_unique(array_merge([$this->normalizedMethod], array_map('strtoupper', $this->methods)));
    }

    /**
     * @param string $method HTTP method (GET, POST, PUT, DELETE, etc.)
     * @param string $path URL path with optional parameters like /user/{id} or /user/{id:int}
     * @param array<string> $middleware Optional middleware for this route
     * @param string|null $name Optional route name for URL generation
     * @param string|null $subdomain Optional subdomain constraint (e.g., 'api', 'admin')
     * @param array<string, mixed> $options Additional route options
     * @param array<string> $schemes Allowed schemes (http, https)
     * @param array<string> $methods Additional HTTP methods for multi-method routes
     */
    public function __construct(
        public string  $method,
        public string  $path,
        public array   $middleware = [],
        public ?string $name = null,
        public ?string $subdomain = null,
        public array   $options = [],
        public array   $schemes = ['http', 'https'],
        public array   $methods = []
    )
    {
        $this->validateAndNormalize();
    }

    /**
     * Validate and normalize all input
     */
    private function validateAndNormalize(): void
    {
        $this->validateMethod($this->method);
        $this->validatePath($this->path);
        $this->validateMiddleware($this->middleware);
        $this->validateName($this->name);
        $this->validateSubdomain($this->subdomain);
        $this->validateOptions($this->options);
        $this->validateSchemes($this->schemes);
        $this->validateMethods($this->methods);
    }

    /**
     * Validate HTTP method
     */
    private function validateMethod(string $method): void
    {
        $allowedMethods = [
            'GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'
        ];

        $normalizedMethod = strtoupper(trim($method));

        if ($normalizedMethod === '') {
            throw new \InvalidArgumentException('HTTP method cannot be empty');
        }

        if (!in_array($normalizedMethod, $allowedMethods, true)) {
            throw new \InvalidArgumentException("Invalid HTTP method: {$method}");
        }

        $this->method = $normalizedMethod;
    }

    /**
     * Validate path with security checks
     */
    private function validatePath(string $path): void
    {
        if (strlen($path) === 0) {
            throw new \InvalidArgumentException('Path cannot be empty');
        }

        if (!str_starts_with($path, '/')) {
            throw new \InvalidArgumentException('Path must start with /');
        }

        if (strlen($path) > 2048) {
            throw new \InvalidArgumentException('Path too long (max 2048 characters)');
        }

        // Security checks
        $dangerousPatterns = [
            '/\.\./', // Directory traversal
            '/\0/',   // Null bytes
            '/<script/i', // XSS attempts
            '/javascript:/i', // JavaScript URLs
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $path)) {
                throw new \InvalidArgumentException('Path contains dangerous sequences');
            }
        }

        // Validate parameter format
        if (preg_match_all('/\{([^}]*)}/', $path, $matches)) {
            foreach ($matches[1] as $param) {
                $this->validateParameterName($param);
            }
        }
    }

    /**
     * Validate parameter names with constraint support
     */
    private function validateParameterName(string $param): void
    {
        if (strlen($param) === 0) {
            throw new \InvalidArgumentException('Parameter name cannot be empty');
        }

        if (strlen($param) > 50) {
            throw new \InvalidArgumentException("Parameter name too long: {$param}");
        }

        // ✅ Support for constraints: name:constraint format
        if (str_contains($param, ':')) {
            [$name, $constraint] = explode(':', $param, 2);

            // Validate parameter name part
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
                throw new \InvalidArgumentException("Invalid parameter name: {$name}");
            }

            // Validate constraint part
            $validConstraints = ['int', 'integer', 'uuid', 'slug', 'alpha', 'alnum'];
            if (!in_array($constraint, $validConstraints, true)) {
                throw new \InvalidArgumentException("Invalid parameter constraint: {$constraint}. Valid constraints: " . implode(', ', $validConstraints));
            }

            // Check for reserved parameter names
            $this->checkReservedParameterName($name);
            return;
        }

        // Standard parameter without constraint
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $param)) {
            throw new \InvalidArgumentException("Invalid parameter name: {$param}");
        }

        $this->checkReservedParameterName($param);
    }

    /**
     * Check for reserved parameter names
     */
    private function checkReservedParameterName(string $name): void
    {
        $reserved = [
            '__construct', '__destruct', '__call', '__get', '__set',
            'class', 'interface', 'trait', 'enum', 'function'
        ];

        if (in_array(strtolower($name), $reserved, true)) {
            throw new \InvalidArgumentException("Reserved parameter name: {$name}");
        }
    }

    /**
     * Validate middleware array
     */
    private function validateMiddleware(array $middleware): void
    {
        if (count($middleware) > 10) {
            throw new \InvalidArgumentException('Too many middleware (max 10)');
        }

        foreach ($middleware as $mw) {
            if (!is_string($mw)) {
                throw new \InvalidArgumentException('Middleware must be strings');
            }

            if (strlen($mw) === 0 || strlen($mw) > 100) {
                throw new \InvalidArgumentException('Invalid middleware name length');
            }

            if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $mw)) {
                throw new \InvalidArgumentException("Invalid middleware name: {$mw}");
            }
        }
    }

    /**
     * Validate route name
     */
    private function validateName(?string $name): void
    {
        if ($name === null) {
            return;
        }

        if (strlen($name) === 0) {
            throw new \InvalidArgumentException('Route name cannot be empty string');
        }

        if (strlen($name) > 255) {
            throw new \InvalidArgumentException('Route name too long (max 255 characters)');
        }

        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $name)) {
            throw new \InvalidArgumentException('Route name contains invalid characters');
        }
    }

    /**
     * Validate subdomain
     */
    private function validateSubdomain(?string $subdomain): void
    {
        if ($subdomain === null) {
            return;
        }

        if (strlen($subdomain) === 0) {
            throw new \InvalidArgumentException('Subdomain cannot be empty string');
        }

        if (strlen($subdomain) > 63) {
            throw new \InvalidArgumentException('Subdomain too long (max 63 characters)');
        }

        // RFC 1123 hostname validation
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?$/', $subdomain)) {
            throw new \InvalidArgumentException('Invalid subdomain format');
        }

        // Check for dangerous characters
        if (preg_match('/[<>"\'\0&;]/', $subdomain)) {
            throw new \InvalidArgumentException('Subdomain contains dangerous characters');
        }
    }

    /**
     * Validate route options
     */
    private function validateOptions(array $options): void
    {
        $allowedOptions = [
            'cache', 'timeout', 'priority', 'description',
            'deprecated', 'version', 'auth_required', 'rate_limit'
        ];

        foreach ($options as $key => $value) {
            if (!is_string($key) || strlen($key) > 50) {
                throw new \InvalidArgumentException('Invalid option key');
            }

            if (!in_array($key, $allowedOptions, true)) {
                // Warning statt Exception für unbekannte Optionen
                error_log("Warning: Unknown route option: {$key}");
            }

            // Validate specific option types
            match ($key) {
                'cache' => $this->validateBooleanOrIntOption($value, $key),
                'timeout' => $this->validateTimeoutOption($value),
                'priority' => $this->validatePriorityOption($value),
                'deprecated' => $this->validateBooleanOption($value, $key),
                'auth_required' => $this->validateBooleanOption($value, $key),
                default => null
            };
        }
    }

    /**
     * Validate boolean or int option
     */
    private function validateBooleanOrIntOption(mixed $value, string $optionName): void
    {
        if (!is_bool($value) && !is_int($value)) {
            throw new \InvalidArgumentException("{$optionName} option must be boolean or integer");
        }
    }

    /**
     * Validate timeout option
     */
    private function validateTimeoutOption(mixed $value): void
    {
        if (!is_int($value) || $value < 1 || $value > 3600) {
            throw new \InvalidArgumentException('Timeout must be integer between 1 and 3600');
        }
    }

    /**
     * Validate priority option
     */
    private function validatePriorityOption(mixed $value): void
    {
        if (!is_int($value) || $value < 1 || $value > 100) {
            throw new \InvalidArgumentException('Priority must be integer between 1 and 100');
        }
    }

    /**
     * Validate boolean option
     */
    private function validateBooleanOption(mixed $value, string $optionName): void
    {
        if (!is_bool($value)) {
            throw new \InvalidArgumentException("{$optionName} option must be boolean");
        }
    }

    /**
     * Validate allowed schemes
     */
    private function validateSchemes(array $schemes): void
    {
        $allowedSchemes = ['http', 'https'];

        if (empty($schemes)) {
            throw new \InvalidArgumentException('At least one scheme must be specified');
        }

        foreach ($schemes as $scheme) {
            if (!is_string($scheme) || !in_array(strtolower($scheme), $allowedSchemes, true)) {
                throw new \InvalidArgumentException("Invalid scheme: {$scheme}");
            }
        }
    }

    /**
     * Validate additional methods
     */
    private function validateMethods(array $methods): void
    {
        foreach ($methods as $method) {
            if (!is_string($method)) {
                throw new \InvalidArgumentException('Methods must be strings');
            }
            $this->validateMethod($method);
        }
    }

    /**
     * Create from array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['method'],
            $data['path'],
            $data['middleware'] ?? [],
            $data['name'] ?? null,
            $data['subdomain'] ?? null,
            $data['options'] ?? [],
            $data['schemes'] ?? ['http', 'https'],
            $data['methods'] ?? []
        );
    }

    /**
     * Check if route supports specific method
     */
    public function supportsMethod(string $method): bool
    {
        return in_array(strtoupper($method), $this->allMethods, true);
    }

    /**
     * Get cache configuration
     */
    public function getCacheConfig(): mixed
    {
        return $this->options['cache'] ?? false;
    }

    /**
     * Convert to array for serialization
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'path' => $this->path,
            'middleware' => $this->middleware,
            'name' => $this->name,
            'subdomain' => $this->subdomain,
            'options' => $this->options,
            'schemes' => $this->schemes,
            'methods' => $this->methods,
        ];
    }

    /**
     * Magic method for debugging
     */
    public function __debugInfo(): array
    {
        return [
            'method' => $this->normalizedMethod,
            'path' => $this->path,
            'has_parameters' => $this->hasParameters,
            'all_methods' => $this->allMethods,
            'middleware_count' => count($this->middleware),
            'name' => $this->name,
            'subdomain' => $this->subdomain,
            'requires_https' => $this->requiresHttps(),
            'priority' => $this->getPriority(),
            'is_deprecated' => $this->isDeprecated(),
        ];
    }

    /**
     * Check if route requires HTTPS
     */
    public function requiresHttps(): bool
    {
        return !in_array('http', $this->schemes, true) ||
            ($this->options['ssl_required'] ?? false) === true;
    }

    /**
     * Get route priority (higher number = higher priority)
     */
    public function getPriority(): int
    {
        return $this->options['priority'] ?? 50;
    }

    /**
     * Check if route is deprecated
     */
    public function isDeprecated(): bool
    {
        return ($this->options['deprecated'] ?? false) === true;
    }
}