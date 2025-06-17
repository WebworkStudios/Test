<?php

declare(strict_types=1);

namespace Framework\Routing;

use InvalidArgumentException;
use Throwable;

/**
 * Optimized Route Information class with caching
 */
final class RouteInfo
{
    // PHP 8.4 Property Hooks for computed properties
    public bool $isStatic {
        get => $this->cachedIsStatic ??= !str_contains($this->originalPath, '{');
    }

    public bool $hasParameters {
        get => !empty($this->paramNames);
    }

    public int $parameterCount {
        get => count($this->paramNames);
    }

    public string $cacheKey {
        get => $this->cachedCacheKey ??= hash('xxh3', $this->method . ':' . $this->originalPath . ':' . ($this->subdomain ?? ''));
    }

    // Performance: Cache computed values
    private ?bool $cachedIsStatic = null;
    private ?string $cachedCacheKey = null;
    private ?array $cachedConstraints = null;

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
     * Streamlined validation
     */
    private function validateConstruction(): void
    {
        // Fast validation - only essential checks
        if (!in_array($this->method, ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'], true)) {
            throw new InvalidArgumentException("Invalid HTTP method: {$this->method}");
        }

        if (!str_starts_with($this->originalPath, '/') || strlen($this->originalPath) > 2048) {
            throw new InvalidArgumentException("Invalid path format");
        }

        if (!class_exists($this->actionClass)) {
            throw new InvalidArgumentException("Action class does not exist: {$this->actionClass}");
        }
    }

    /**
     * Fast route creation mit zentraler Pattern-Kompilierung
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

        // Nutze zentrale Pattern-Kompilierung
        $compiled = RoutePatternCompiler::compile($path);

        return new self(
            $method,
            $path,
            $compiled['pattern'],
            $compiled['params'],
            $actionClass,
            $middleware,
            $name,
            $subdomain,
            $options
        );
    }

    /**
     * Fast cache-friendly creation
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
     * Sichere matches() Methode mit Fehlerbehandlung
     */
    public function matches(string $method, string $path, ?string $subdomain = null): bool
    {
        // Fast method check
        if ($this->method !== strtoupper($method)) {
            return false;
        }

        // Fast subdomain check
        if ($this->subdomain !== $subdomain) {
            return false;
        }

        // Static route exact match
        if ($this->isStatic) {
            return $this->originalPath === $path;
        }

        // Dynamic route pattern match with error handling
        try {
            return preg_match($this->pattern, $path) === 1;
        } catch (Throwable $e) {
            error_log("❌ Regex error in route pattern '{$this->pattern}' for path '{$path}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Parameter-Extraktion mit Pattern-Compiler-Validierung
     */
    public function extractParams(string $path): array
    {
        if ($this->isStatic || empty($this->paramNames)) {
            return [];
        }

        try {
            if (!preg_match($this->pattern, $path, $matches)) {
                throw new InvalidArgumentException("Path does not match route pattern");
            }

            $params = [];
            $compiled = RoutePatternCompiler::compile($this->originalPath);

            foreach ($this->paramNames as $index => $name) {
                $value = $matches[$index + 1] ?? '';

                // Nutze Pattern-Compiler für Validierung
                $params[$name] = RoutePatternCompiler::validateParameter(
                    $name,
                    $value,
                    $compiled['constraints']
                );
            }

            return $params;

        } catch (Throwable $e) {
            throw new InvalidArgumentException("Failed to extract parameters: " . $e->getMessage());
        }
    }

    /**
     * Fast URL generation
     */
    public function generateUrl(array $params = []): string
    {
        $url = $this->originalPath;

        if (empty($params)) {
            return $url;
        }

        foreach ($params as $key => $value) {
            $pattern = '/\{' . preg_quote($key, '/') . '(?::[^}]+)?}/';
            $url = preg_replace($pattern, urlencode((string)$value), $url);
        }

        // Check for missing parameters
        if (preg_match('/\{[^}]+}/', $url)) {
            throw new InvalidArgumentException("Missing required parameters for route");
        }

        return $url;
    }

    /**
     * Lightweight serialization for cache
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
            'is_static' => $this->isStatic
        ];
    }

    // === Performance helpers ===

    public function getPriority(): int
    {
        return $this->options['priority'] ?? 50;
    }

    public function isDeprecated(): bool
    {
        return ($this->options['deprecated'] ?? false) === true;
    }

    public function getCacheTtl(): int
    {
        return $this->options['cache_ttl'] ?? 3600;
    }

    public function requiresAuth(): bool
    {
        return ($this->options['auth_required'] ?? false) === true ||
            in_array('auth', $this->middleware, true);
    }

    // === Fast clone operations ===

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

    public function withMiddleware(array $additionalMiddleware): self
    {
        return new self(
            $this->method,
            $this->originalPath,
            $this->pattern,
            $this->paramNames,
            $this->actionClass,
            [...$this->middleware, ...$additionalMiddleware],
            $this->name,
            $this->subdomain,
            $this->options
        );
    }

    // === Debugging ===

    public function __debugInfo(): array
    {
        return [
            'method' => $this->method,
            'path' => $this->originalPath,
            'action' => $this->actionClass,
            'is_static' => $this->isStatic,
            'parameter_count' => $this->parameterCount,
            'cache_key' => $this->cacheKey
        ];
    }

    public function __toString(): string
    {
        return "{$this->method} {$this->originalPath} -> {$this->actionClass}";
    }
}