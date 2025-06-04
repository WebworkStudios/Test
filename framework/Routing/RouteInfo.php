<?php

declare(strict_types=1);

namespace Framework\Routing;

/**
 * High-performance route information storage with PHP 8.4 optimizations
 */
final  class RouteInfo
{
    // PHP 8.4 Property Hooks for computed properties
    public int $segmentCount {
        get => $this->cachedSegmentCount ??= count(explode('/', trim($this->originalPath, '/')));
    }

    public bool $isStatic {
        get => empty($this->paramNames);
    }

    public bool $hasSubdomain {
        get => $this->subdomain !== null;
    }

    public string $signature {
        get => $this->cachedSignature ??= $this->generateSignature();
    }

    private ?int $cachedSegmentCount = null;
    private ?string $cachedSignature = null;
    private ?array $cachedSegments = null;

    public function __construct(
        public string $method,
        public string $pattern,
        public string $originalPath,
        public array $paramNames,
        public string $actionClass,
        public array $middleware = [],
        public ?string $name = null,
        public ?string $subdomain = null
    ) {
        $this->validateSecureConstruction();
    }

    /**
     * Create RouteInfo from path with enhanced performance
     */
    public static function fromPath(
        string $method,
        string $path,
        string $actionClass,
        array $middleware = [],
        ?string $name = null,
        ?string $subdomain = null
    ): self {
        $paramNames = [];

        // Optimized parameter extraction with single pass
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)}/',
            function ($matches) use (&$paramNames) {
                $paramName = $matches[1];

                if (strlen($paramName) > 50) {
                    throw new \InvalidArgumentException("Parameter name too long: {$paramName}");
                }

                if (in_array($paramName, ['__construct', '__destruct', '__call', '__get', '__set'], true)) {
                    throw new \InvalidArgumentException("Reserved parameter name: {$paramName}");
                }

                $paramNames[] = $paramName;
                return '([^/\0]+)';
            },
            $path
        );

        if ($pattern === null) {
            throw new \InvalidArgumentException("Invalid path pattern: {$path}");
        }

        // Optimized pattern creation
        $escapedPattern = '#^' . str_replace('/', '\/', $pattern) . '$#D';

        return new self(
            $method,
            $escapedPattern,
            $path,
            $paramNames,
            $actionClass,
            $middleware,
            $name,
            $subdomain
        );
    }

    /**
     * Ultra-fast route matching with multiple optimization layers
     */
    public function matches(string $method, string $path, ?string $requestSubdomain = null): bool
    {
        // Phase 1: Method check (fastest)
        if ($this->method !== $method) {
            return false;
        }

        // Phase 2: Static route fast path
        if ($this->isStatic) {
            return $this->originalPath === $path && $this->matchesSubdomain($requestSubdomain);
        }

        // Phase 3: Quick validation checks
        if (strlen($path) > 2048 || str_contains($path, "\0") || str_contains($path, '..')) {
            return false;
        }

        // Phase 4: Subdomain check before expensive operations
        if (!$this->matchesSubdomain($requestSubdomain)) {
            return false;
        }

        // Phase 5: Segment count pre-filter
        $pathSegmentCount = substr_count($path, '/');
        $routeSegmentCount = substr_count($this->originalPath, '/');
        if ($pathSegmentCount !== $routeSegmentCount) {
            return false;
        }

        // Phase 6: Pattern matching (most expensive)
        return preg_match($this->pattern, $path) === 1;
    }

    /**
     * High-performance parameter extraction with caching
     */
    public function extractParams(string $path): array
    {
        // Input validation
        if (strlen($path) > 2048) {
            throw new \InvalidArgumentException('Path too long');
        }

        if (str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Path contains null byte');
        }

        // Use PREG_OFFSET_CAPTURE for better performance
        if (!preg_match($this->pattern, $path, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        // Remove full match
        array_shift($matches);

        if (count($matches) !== count($this->paramNames)) {
            throw new \InvalidArgumentException('Parameter count mismatch');
        }

        $params = [];

        // Optimized parameter processing
        foreach ($this->paramNames as $i => $name) {
            $value = $matches[$i][0] ?? '';
            $params[$name] = $this->optimizedSanitizeParameter($name, $value);
        }

        return $params;
    }

    /**
     * Optimized parameter sanitization with type-specific validation
     */
    private function optimizedSanitizeParameter(string $name, string $value): string
    {
        // Length check
        if (strlen($value) > 255) {
            throw new \InvalidArgumentException("Parameter '{$name}' too long");
        }

        // Combined security check
        if (preg_match('/[\x00-\x1F\x7F]|\.\./', $value)) {
            throw new \InvalidArgumentException("Parameter '{$name}' contains invalid characters");
        }

        // URL decode
        $decoded = urldecode($value);

        // Post-decode validation
        if (strlen($decoded) > 255 || str_contains($decoded, '..') || str_contains($decoded, "\0")) {
            throw new \InvalidArgumentException("Parameter '{$name}' invalid after decoding");
        }

        // Fast type-specific validation
        $this->fastValidateByName($name, $decoded);

        return $decoded;
    }

    /**
     * Fast parameter validation by name pattern with caching
     */
    private function fastValidateByName(string $name, string $value): void
    {
        static $patterns = [
            'id' => '/^\d+$/',
            'slug' => '/^[a-zA-Z0-9\-_]+$/',
        ];

        // Quick pattern-based validation
        $lowerName = strtolower($name);

        if (str_ends_with($lowerName, 'id')) {
            if (!preg_match($patterns['id'], $value) || (int)$value <= 0 || (int)$value > PHP_INT_MAX) {
                throw new \InvalidArgumentException("Parameter '{$name}' must be a valid positive integer");
            }
        } elseif ($lowerName === 'slug' || $lowerName === 'username') {
            if (!preg_match($patterns['slug'], $value)) {
                throw new \InvalidArgumentException("Parameter '{$name}' contains invalid characters");
            }
        } elseif ($lowerName === 'email') {
            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException("Parameter '{$name}' is not a valid email");
            }
        }
    }

    /**
     * Optimized subdomain matching
     */
    private function matchesSubdomain(?string $requestSubdomain): bool
    {
        if ($this->subdomain !== null) {
            if ($requestSubdomain === null) {
                return false;
            }

            if (!$this->isValidSubdomain($requestSubdomain)) {
                return false;
            }

            return $this->subdomain === $requestSubdomain;
        }

        return $requestSubdomain === null || $requestSubdomain === 'www';
    }

    /**
     * Fast subdomain validation
     */
    private function isValidSubdomain(string $subdomain): bool
    {
        if (strlen($subdomain) > 63) {
            return false;
        }

        // Combined validation in single regex
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?$/', $subdomain)) {
            return false;
        }

        // Security check
        if (preg_match('/[<>"\'\0]/', $subdomain)) {
            return false;
        }

        return true;
    }

    /**
     * Generate route signature for caching
     */
    private function generateSignature(): string
    {
        return hash('xxh3', $this->method . ':' . $this->originalPath . ':' . ($this->subdomain ?? ''));
    }

    /**
     * Get cached path segments
     */
    public function getSegments(): array
    {
        return $this->cachedSegments ??= explode('/', trim($this->originalPath, '/'));
    }

    /**
     * Fast segment count calculation
     */
    public function getSegmentCount(): int
    {
        return $this->segmentCount;
    }

    /**
     * Check if route has specific parameter
     */
    public function hasParameter(string $name): bool
    {
        return in_array($name, $this->paramNames, true);
    }

    /**
     * Get parameter count
     */
    public function getParameterCount(): int
    {
        return count($this->paramNames);
    }

    /**
     * Validate secure construction
     */
    private function validateSecureConstruction(): void
    {
        // Pattern security validation
        if (str_contains($this->pattern, '(?')) {
            throw new \InvalidArgumentException('Complex regex patterns not allowed for security');
        }

        // Middleware validation
        foreach ($this->middleware as $middleware) {
            if (!is_string($middleware) || strlen($middleware) > 100) {
                throw new \InvalidArgumentException('Invalid middleware specification');
            }
        }

        // Action class validation
        if (strlen($this->actionClass) > 255) {
            throw new \InvalidArgumentException('Action class name too long');
        }

        // Parameter count validation
        if (count($this->paramNames) > 20) {
            throw new \InvalidArgumentException('Too many route parameters');
        }
    }

    /**
     * Convert to array for serialization
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'pattern' => $this->pattern,
            'originalPath' => $this->originalPath,
            'paramNames' => $this->paramNames,
            'actionClass' => $this->actionClass,
            'middleware' => $this->middleware,
            'name' => $this->name,
            'subdomain' => $this->subdomain,
        ];
    }

    /**
     * Create from array (for unserialization)
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['method'],
            $data['pattern'],
            $data['originalPath'],
            $data['paramNames'],
            $data['actionClass'],
            $data['middleware'] ?? [],
            $data['name'] ?? null,
            $data['subdomain'] ?? null
        );
    }
}