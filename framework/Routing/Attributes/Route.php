<?php

declare(strict_types=1);

namespace Framework\Routing\Attributes;

use Attribute;

/**
 * High-performance Route attribute with PHP 8.4 optimizations
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final  class Route
{
    // PHP 8.4 Property Hooks for computed properties
    public string $normalizedMethod {
        get => strtoupper($this->method);
    }

    public bool $hasParameters {
        get => str_contains($this->path, '{');
    }

    public bool $isSecure {
        get => $this->validateSecuritySettings();
    }

    public string $signature {
        get => $this->generateSignature();
    }

    /**
     * @param string $method HTTP method (GET, POST, PUT, DELETE, etc.)
     * @param string $path URL path with optional parameters like /user/{id}
     * @param array<string> $middleware Optional middleware for this route
     * @param string|null $name Optional route name for URL generation
     * @param string|null $subdomain Optional subdomain constraint (e.g., 'api', 'admin')
     * @param array<string, mixed> $options Additional route options
     * @param array<string> $schemes Allowed schemes (http, https)
     * @param array<string> $methods Additional HTTP methods for multi-method routes
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $middleware = [],
        public ?string $name = null,
        public ?string $subdomain = null,
        public array $options = [],
        public array $schemes = ['http', 'https'],
        public array $methods = []
    ) {
        $this->validateAndNormalize();
    }

    /**
     * Comprehensive validation and normalization
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
     * Enhanced HTTP method validation
     */
    private function validateMethod(string $method): void
    {
        $allowedMethods = [
            'GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS',
            'TRACE', 'CONNECT', 'PROPFIND', 'PROPPATCH', 'MKCOL', 'COPY', 'MOVE'
        ];

        $normalizedMethod = strtoupper(trim($method));

        if ($normalizedMethod === '') {
            throw new \InvalidArgumentException('HTTP method cannot be empty');
        }

        if (!in_array($normalizedMethod, $allowedMethods, true)) {
            throw new \InvalidArgumentException("Invalid HTTP method: {$method}");
        }

        // Override readonly property through reflection for normalization
        $this->setNormalizedMethod($normalizedMethod);
    }

    /**
     * Enhanced path validation with security focus
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

        // Enhanced security checks
        $dangerousPatterns = [
            '/\.\./', // Directory traversal
            '/\0/',   // Null bytes
            '/<script/i', // XSS attempts
            '/javascript:/i', // JavaScript URLs
            '/data:/i', // Data URLs
            '/vbscript:/i', // VBScript URLs
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

        // Check for valid URL structure
        if (!$this->isValidUrlStructure($path)) {
            throw new \InvalidArgumentException('Invalid URL structure in path');
        }
    }

    /**
     * Validate parameter names in path
     */
    private function validateParameterName(string $param): void
    {
        if (strlen($param) === 0) {
            throw new \InvalidArgumentException('Parameter name cannot be empty');
        }

        if (strlen($param) > 50) {
            throw new \InvalidArgumentException("Parameter name too long: {$param}");
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $param)) {
            throw new \InvalidArgumentException("Invalid parameter name: {$param}");
        }

        // Reserved parameter names
        $reserved = [
            '__construct', '__destruct', '__call', '__get', '__set', '__isset',
            '__unset', '__sleep', '__wakeup', '__serialize', '__unserialize',
            'class', 'interface', 'trait', 'enum', 'function', 'var', 'const'
        ];

        if (in_array(strtolower($param), $reserved, true)) {
            throw new \InvalidArgumentException("Reserved parameter name: {$param}");
        }
    }

    /**
     * Validate URL structure
     */
    private function isValidUrlStructure(string $path): bool
    {
        // Check for valid segment structure
        $segments = explode('/', trim($path, '/'));

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue; // Allow empty segments
            }

            // Check segment length
            if (strlen($segment) > 100) {
                return false;
            }

            // Check for valid characters (including parameters)
            if (!preg_match('/^[a-zA-Z0-9\-_.{}]+$/', $segment)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Enhanced middleware validation
     */
    private function validateMiddleware(array $middleware): void
    {
        if (count($middleware) > 20) {
            throw new \InvalidArgumentException('Too many middleware (max 20)');
        }

        foreach ($middleware as $mw) {
            if (!is_string($mw)) {
                throw new \InvalidArgumentException('Middleware must be strings');
            }

            if (strlen($mw) === 0) {
                throw new \InvalidArgumentException('Middleware name cannot be empty');
            }

            if (strlen($mw) > 100) {
                throw new \InvalidArgumentException("Middleware name too long: {$mw}");
            }

            if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $mw)) {
                throw new \InvalidArgumentException("Invalid middleware name: {$mw}");
            }
        }
    }

    /**
     * Enhanced route name validation
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

        // Check for reserved names
        $reserved = ['index', 'show', 'create', 'store', 'edit', 'update', 'destroy'];
        if (in_array(strtolower($name), $reserved, true)) {
            // Warning but not error for reserved names
            error_log("Warning: Using reserved route name: {$name}");
        }
    }

    /**
     * Enhanced subdomain validation
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

        // RFC 1123 hostname validation with enhanced security
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?$/', $subdomain)) {
            throw new \InvalidArgumentException('Invalid subdomain format');
        }

        // Enhanced security checks
        $dangerous = [
            'admin', 'root', 'www', 'mail', 'ftp', 'localhost', 'test', 'staging',
            'api', 'cdn', 'static', 'assets', 'media', 'img', 'images', 'css', 'js',
            'dev', 'development', 'prod', 'production', 'beta', 'alpha'
        ];

        if (in_array(strtolower($subdomain), $dangerous, true)) {
            error_log("Warning: Using potentially dangerous subdomain: {$subdomain}");
        }

        // Check for XSS and injection attempts
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
            'cache', 'timeout', 'priority', 'tags', 'description',
            'deprecated', 'version', 'auth_required', 'rate_limit',
            'cors', 'csrf', 'ssl_required'
        ];

        foreach ($options as $key => $value) {
            if (!is_string($key) || strlen($key) > 50) {
                throw new \InvalidArgumentException('Invalid option key');
            }

            if (!in_array($key, $allowedOptions, true)) {
                error_log("Warning: Unknown route option: {$key}");
            }

            // Validate specific option types
            match ($key) {
                'cache' => $this->validateCacheOption($value),
                'timeout' => $this->validateTimeoutOption($value),
                'priority' => $this->validatePriorityOption($value),
                'deprecated' => $this->validateBooleanOption($value, $key),
                'auth_required' => $this->validateBooleanOption($value, $key),
                'ssl_required' => $this->validateBooleanOption($value, $key),
                default => null
            };
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
     * Validate additional methods for multi-method routes
     */
    private function validateMethods(array $methods): void
    {
        foreach ($methods as $method) {
            $this->validateMethod($method);
        }
    }

    /**
     * Validate cache option
     */
    private function validateCacheOption(mixed $value): void
    {
        if (!is_bool($value) && !is_int($value) && !is_array($value)) {
            throw new \InvalidArgumentException('Cache option must be boolean, integer, or array');
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
     * Check if route has secure configuration
     */
    private function validateSecuritySettings(): bool
    {
        // Check for HTTPS requirement
        if (isset($this->options['ssl_required']) && $this->options['ssl_required'] === true) {
            return true;
        }

        // Check schemes
        if (in_array('https', $this->schemes, true) && !in_array('http', $this->schemes, true)) {
            return true;
        }

        // Check for auth requirement
        if (isset($this->options['auth_required']) && $this->options['auth_required'] === true) {
            return true;
        }

        return false;
    }

    /**
     * Generate unique signature for route
     */
    private function generateSignature(): string
    {
        $data = [
            $this->normalizedMethod,
            $this->path,
            $this->subdomain,
            implode(',', $this->middleware),
            implode(',', $this->schemes)
        ];

        return hash('xxh3', implode('|', $data));
    }

    /**
     * Get all HTTP methods for this route
     */
    public function getAllMethods(): array
    {
        return array_unique(array_merge([$this->normalizedMethod], array_map('strtoupper', $this->methods)));
    }

    /**
     * Check if route supports specific method
     */
    public function supportsMethod(string $method): bool
    {
        return in_array(strtoupper($method), $this->getAllMethods(), true);
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
     * Internal method to set normalized method (workaround for readonly)
     */
    private function setNormalizedMethod(string $method): void
    {
        // In a real implementation, this would use reflection
        // or the property would not be readonly
        $reflection = new \ReflectionProperty($this, 'method');
        $reflection->setValue($this, $method);
    }
}