<?php


declare(strict_types=1);

namespace Framework\Routing;

/**
 * Optimized Route Information class with PHP 8.4 features
 */
final class RouteInfo
{
    // PHP 8.4 Property Hooks for computed properties
    public bool $isStatic {
        get => !str_contains($this->originalPath, '{');
    }

    public bool $hasParameters {
        get => !empty($this->paramNames);
    }

    public int $parameterCount {
        get => count($this->paramNames);
    }

    public string $cacheKey {
        get => hash('xxh3', $this->method . ':' . $this->originalPath . ':' . ($this->subdomain ?? ''));
    }

    public function __construct(
        public readonly string  $method,
        public readonly string  $originalPath,
        public readonly string  $pattern,
        public readonly array   $paramNames,
        public readonly string  $actionClass,
        public readonly array   $middleware = [],
        public readonly ?string $name = null,
        public readonly ?string $subdomain = null,
        public readonly array   $options = []
    )
    {
        $this->validateConstruction();
    }

    /**
     * Validate construction parameters
     */
    private function validateConstruction(): void
    {
        // Validate method
        $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'];
        if (!in_array($this->method, $allowedMethods, true)) {
            throw new \InvalidArgumentException("Invalid HTTP method: {$this->method}");
        }

        // Validate path
        if (!str_starts_with($this->originalPath, '/')) {
            throw new \InvalidArgumentException("Path must start with /");
        }

        if (strlen($this->originalPath) > 2048) {
            throw new \InvalidArgumentException("Path too long");
        }

        // Validate action class
        if (!class_exists($this->actionClass)) {
            throw new \InvalidArgumentException("Action class does not exist: {$this->actionClass}");
        }

        // Validate middleware
        if (count($this->middleware) > 10) {
            throw new \InvalidArgumentException("Too many middleware (max 10)");
        }

        foreach ($this->middleware as $mw) {
            if (!is_string($mw) || strlen($mw) > 100) {
                throw new \InvalidArgumentException("Invalid middleware specification");
            }
        }

        // Validate name
        if ($this->name !== null) {
            if (strlen($this->name) > 255 || !preg_match('/^[a-zA-Z0-9._-]+$/', $this->name)) {
                throw new \InvalidArgumentException("Invalid route name: {$this->name}");
            }
        }

        // Validate subdomain
        if ($this->subdomain !== null) {
            if (strlen($this->subdomain) > 63 ||
                !preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?$/', $this->subdomain)) {
                throw new \InvalidArgumentException("Invalid subdomain: {$this->subdomain}");
            }
        }
    }

    /**
     * Create RouteInfo from path
     */
    public static function fromPath(
        string  $method,
        string  $path,
        string  $actionClass,
        array   $middleware = [],
        ?string $name = null,
        ?string $subdomain = null,
        array   $options = []
    ): self
    {
        $method = strtoupper($method);
        $pattern = self::compilePattern($path);
        $paramNames = self::extractParameterNames($path);

        return new self(
            $method,
            $path,
            $pattern,
            $paramNames,
            $actionClass,
            $middleware,
            $name,
            $subdomain,
            $options
        );
    }

    /**
     * Compile path pattern to regex
     */
    private static function compilePattern(string $path): string
    {
        // Alle nicht-Parameter Teile escapen und Parameter durch Platzhalter ersetzen
        $parts = preg_split('/(\{[^}]+\})/', $path, -1, PREG_SPLIT_DELIM_CAPTURE);

        $pattern = '';
        foreach ($parts as $part) {
            if (preg_match('/^\{([^}]+)\}$/', $part, $matches)) {
                // Parameter gefunden
                $paramName = $matches[1];

                if (str_contains($paramName, ':')) {
                    [$name, $constraint] = explode(':', $paramName, 2);
                    $pattern .= self::getConstraintPattern($constraint);
                } else {
                    $pattern .= '([^/]+)';
                }
            } else {
                // Normaler Text - escapen
                $pattern .= preg_quote($part, '#');
            }
        }

        return '#^' . $pattern . '$#';
    }
    /**
     * Get regex pattern for parameter constraints
     */
    private static function getConstraintPattern(string $constraint): string
    {
        return match ($constraint) {
            'int', 'integer' => '(\d+)',
            'uuid' => '([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})',
            'slug' => '([a-z0-9-]+)',
            'alpha' => '([a-zA-Z]+)',
            'alnum' => '([a-zA-Z0-9]+)',
            default => '([^/]+)'
        };
    }

    /**
     * Extract parameter names from path
     */
    private static function extractParameterNames(string $path): array
    {
        if (!preg_match_all('/{([^}]+)}/', $path, $matches)) {
            return [];
        }

        $paramNames = [];
        foreach ($matches[1] as $match) {
            // Handle parameter constraints
            if (str_contains($match, ':')) {
                [$name] = explode(':', $match, 2);
                $paramNames[] = $name;
            } else {
                $paramNames[] = $match;
            }
        }

        return $paramNames;
    }

    /**
     * Create from array (for unserialization)
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['method'],
            $data['original_path'],
            $data['pattern'],
            $data['param_names'] ?? [],
            $data['action_class'],
            $data['middleware'] ?? [],
            $data['name'] ?? null,
            $data['subdomain'] ?? null,
            $data['options'] ?? []
        );
    }

    /**
     * Check if route matches request
     */
    public function matches(string $method, string $path, ?string $subdomain = null): bool
    {
        // Method check
        if ($this->method !== strtoupper($method)) {
            error_log("Method mismatch: {$this->method} !== " . strtoupper($method));
            return false;
        }

        // Subdomain check
        if ($this->subdomain !== $subdomain) {
            error_log("Subdomain mismatch: {$this->subdomain} !== {$subdomain}");
            return false;
        }

        // Static route exact match
        if ($this->isStatic) {
            $matches = $this->originalPath === $path;
            error_log("Static route check: {$this->originalPath} === {$path} = " . ($matches ? 'true' : 'false'));
            return $matches;
        }

        // Dynamic route pattern match
        error_log("Testing pattern: {$this->pattern} against path: {$path}");
        $result = preg_match($this->pattern, $path);
        error_log("Pattern match result: " . ($result === 1 ? 'MATCH' : 'NO MATCH'));

        if ($result === false) {
            error_log("Pattern error: " . preg_last_error());
        }

        return $result === 1;
    }

    /**
     * Extract parameters from path
     */
    public function extractParams(string $path): array
    {
        if ($this->isStatic || empty($this->paramNames)) {
            return [];
        }

        if (!preg_match($this->pattern, $path, $matches)) {
            throw new \InvalidArgumentException("Path does not match route pattern");
        }

        $params = [];
        foreach ($this->paramNames as $index => $name) {
            $value = $matches[$index + 1] ?? '';
            $params[$name] = $this->sanitizeParameterValue($value);
        }

        return $params;
    }

    /**
     * Sanitize parameter value
     */
    private function sanitizeParameterValue(string $value): string
    {
        if (strlen($value) > 255) {
            throw new \InvalidArgumentException("Parameter value too long");
        }

        if (str_contains($value, "\0")) {
            throw new \InvalidArgumentException("Parameter contains null bytes");
        }

        return $value;
    }

    /**
     * Generate URL with parameters
     */
    public function generateUrl(array $params = []): string
    {
        $url = $this->originalPath;

        foreach ($params as $key => $value) {
            $sanitizedValue = urlencode((string)$value);
            $url = str_replace("{{$key}}", $sanitizedValue, $url);
        }

        // Check for missing parameters
        if (preg_match('/{[^}]+}/', $url)) {
            throw new \InvalidArgumentException("Missing required parameters for route");
        }

        return $url;
    }

    /**
     * Get route priority
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
     * Get cache TTL for this route
     */
    public function getCacheTtl(): int
    {
        return $this->options['cache_ttl'] ?? 3600;
    }

    /**
     * Check if route requires authentication
     */
    public function requiresAuth(): bool
    {
        return ($this->options['auth_required'] ?? false) === true ||
            $this->hasMiddleware('auth');
    }

    /**
     * Check if route has specific middleware
     */
    public function hasMiddleware(string $middleware): bool
    {
        return in_array($middleware, $this->middleware, true);
    }

    /**
     * Get route description
     */
    public function getDescription(): ?string
    {
        return $this->options['description'] ?? null;
    }

    /**
     * Convert to array for serialization
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'original_path' => $this->originalPath,
            'pattern' => $this->pattern,
            'param_names' => $this->paramNames,
            'action_class' => $this->actionClass,
            'middleware' => $this->middleware,
            'name' => $this->name,
            'subdomain' => $this->subdomain,
            'options' => $this->options,
            'is_static' => $this->isStatic,
            'parameter_count' => $this->parameterCount,
        ];
    }

    /**
     * Clone route with different parameters
     */
    public function withMethod(string $method): self
    {
        return new self(
            strtoupper($method),
            $this->originalPath,
            $this->pattern,
            $this->paramNames,
            $this->actionClass,
            $this->middleware,
            $this->name,
            $this->subdomain,
            $this->options
        );
    }

    /**
     * Clone route with additional middleware
     */
    public function withMiddleware(array $additionalMiddleware): self
    {
        $allMiddleware = array_unique(array_merge($this->middleware, $additionalMiddleware));

        return new self(
            $this->method,
            $this->originalPath,
            $this->pattern,
            $this->paramNames,
            $this->actionClass,
            $allMiddleware,
            $this->name,
            $this->subdomain,
            $this->options
        );
    }

    /**
     * Clone route with different subdomain
     */
    public function withSubdomain(?string $subdomain): self
    {
        return new self(
            $this->method,
            $this->originalPath,
            $this->pattern,
            $this->paramNames,
            $this->actionClass,
            $this->middleware,
            $this->name,
            $subdomain,
            $this->options
        );
    }

    /**
     * Magic method for debugging
     */
    public function __debugInfo(): array
    {
        return [
            'method' => $this->method,
            'path' => $this->originalPath,
            'action' => $this->actionClass,
            'is_static' => $this->isStatic,
            'parameter_count' => $this->parameterCount,
            'middleware_count' => count($this->middleware),
            'name' => $this->name,
            'subdomain' => $this->subdomain,
            'pattern' => $this->pattern,
            'cache_key' => $this->cacheKey,
        ];
    }

    /**
     * String representation
     */
    public function __toString(): string
    {
        $parts = [$this->method, $this->originalPath];

        if ($this->subdomain) {
            $parts[] = "subdomain:{$this->subdomain}";
        }

        if ($this->name) {
            $parts[] = "name:{$this->name}";
        }

        return implode(' ', $parts) . " -> {$this->actionClass}";
    }
}