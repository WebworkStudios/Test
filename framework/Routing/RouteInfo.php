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

    // ✅ Performance: Cache computed values
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
     * ✅ OPTIMIZED: Streamlined validation
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
     * ✅ OPTIMIZED: Fast route creation
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

        // ✅ Cache pattern compilation
        static $patternCache = [];
        $pathKey = $path;

        if (!isset($patternCache[$pathKey])) {
            $patternCache[$pathKey] = [
                'pattern' => self::compilePattern($path),
                'params' => self::extractParameterNames($path)
            ];
        }

        $cached = $patternCache[$pathKey];

        return new self(
            $method,
            $path,
            $cached['pattern'],
            $cached['params'],
            $actionClass,
            $middleware,
            $name,
            $subdomain,
            $options
        );
    }

    /**
     * ✅ FINAL FIX: Sichere Pattern-Kompilierung
     */
    private static function compilePattern(string $path): string
    {
        // Fast path for static routes
        if (!str_contains($path, '{')) {
            return '#^' . preg_quote($path, '#') . '$#';
        }

        // ✅ SICHERSTE LÖSUNG: Schritt-für-Schritt ohne Escape-Konflikte

        // Schritt 1: Ersetze Parameter durch eindeutige Platzhalter
        $placeholders = [];
        $placeholderIndex = 0;

        $processedPath = preg_replace_callback(
            '/\{([^}]+)\}/',
            function ($matches) use (&$placeholders, &$placeholderIndex) {
                $param = $matches[1];
                $placeholder = "PLACEHOLDER_{$placeholderIndex}";
                $placeholders[$placeholder] = self::getConstraintPattern($param);
                $placeholderIndex++;
                return $placeholder;
            },
            $path
        );

        // Schritt 2: Escape den Pfad (jetzt ohne Parameter)
        $escapedPath = preg_quote($processedPath, '#');

        // Schritt 3: Ersetze Platzhalter durch Regex-Muster
        foreach ($placeholders as $placeholder => $pattern) {
            $escapedPath = str_replace($placeholder, $pattern, $escapedPath);
        }

        return '#^' . $escapedPath . '$#';
    }

    /**
     * ✅ OPTIMIZED: Cached constraint patterns
     */
    private static function getConstraintPattern(string $param): string
    {
        static $constraintCache = [
            'int' => '(\d+)',
            'integer' => '(\d+)',
            'uuid' => '([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})',
            'slug' => '([a-z0-9-]+)',
            'alpha' => '([a-zA-Z]+)',
            'alnum' => '([a-zA-Z0-9]+)'
        ];

        if (str_contains($param, ':')) {
            [, $constraint] = explode(':', $param, 2);
            return $constraintCache[$constraint] ?? '([^/]+)';
        }

        return '([^/]+)';
    }

    /**
     * ✅ OPTIMIZED: Fast parameter extraction
     */
    private static function extractParameterNames(string $path): array
    {
        if (!str_contains($path, '{')) {
            return [];
        }

        preg_match_all('/\{([^}]+)\}/', $path, $matches);

        return array_map(function ($match) {
            return str_contains($match, ':') ? explode(':', $match, 2)[0] : $match;
        }, $matches[1]);
    }

    /**
     * ✅ OPTIMIZED: Fast cache-friendly creation
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
     * ✅ FIX: Sichere matches() Methode mit Fehlerbehandlung
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
     * ✅ FIX: Sichere extractParams() mit Fehlerbehandlung
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
            $constraints = $this->getParameterConstraints();

            foreach ($this->paramNames as $index => $name) {
                $value = $matches[$index + 1] ?? '';

                // ✅ Fast validation
                if (strlen($value) > 255 || str_contains($value, "\0")) {
                    throw new InvalidArgumentException("Invalid parameter value");
                }

                // ✅ Apply constraint validation if exists
                if (isset($constraints[$name])) {
                    $value = $this->validateConstraint($value, $constraints[$name]);
                }

                $params[$name] = $value;
            }

            return $params;

        } catch (Throwable $e) {
            error_log("❌ Parameter extraction error for pattern '{$this->pattern}' and path '{$path}': " . $e->getMessage());
            throw new InvalidArgumentException("Failed to extract parameters: " . $e->getMessage());
        }
    }

    /**
     * ✅ OPTIMIZED: Cached parameter constraints
     */
    private function getParameterConstraints(): array
    {
        if ($this->cachedConstraints !== null) {
            return $this->cachedConstraints;
        }

        $constraints = [];
        preg_match_all('/\{([^}]+)\}/', $this->originalPath, $matches);

        foreach ($matches[1] as $match) {
            if (str_contains($match, ':')) {
                [$name, $constraint] = explode(':', $match, 2);
                $constraints[$name] = $constraint;
            }
        }

        return $this->cachedConstraints = $constraints;
    }


// ✅ NEUE SICHERE VALIDIERUNGSMETHODEN

    /**
     * ✅ OPTIMIZED: Fast constraint validation
     */
    private function validateConstraint(string $value, string $constraint): string
    {
        // ✅ ZUSÄTZLICHE SICHERHEITSVALIDIERUNG
        if (strlen($value) > 255) {
            throw new InvalidArgumentException("Parameter too long");
        }

        if (str_contains($value, "\0") || str_contains($value, "\x00")) {
            throw new InvalidArgumentException("Parameter contains null bytes");
        }

        return match ($constraint) {
            'int', 'integer' => $this->validateIntegerParam($value),
            'uuid' => $this->validateUuidParam($value),
            'slug' => $this->validateSlugParam($value),
            'alpha' => $this->validateAlphaParam($value),
            'alnum' => $this->validateAlnumParam($value),
            default => $this->sanitizeDefaultParam($value)
        };
    }

    private function validateIntegerParam(string $value): string
    {
        if (!preg_match('/^\d+$/', $value) || strlen($value) > 19) {
            throw new InvalidArgumentException("Invalid integer parameter");
        }
        return $value;
    }

    private function validateUuidParam(string $value): string
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
            throw new InvalidArgumentException("Invalid UUID parameter");
        }
        return strtolower($value);
    }

    // framework/Routing/RouteInfo.php

    private function validateSlugParam(string $value): string
    {
        if (!preg_match('/^[a-z0-9-]+$/', $value)) {
            throw new InvalidArgumentException("Invalid slug parameter");
        }

        // Zusätzliche Slug-Validierung
        if (strlen($value) > 100) {
            throw new InvalidArgumentException("Slug parameter too long");
        }

        if (str_starts_with($value, '-') || str_ends_with($value, '-')) {
            throw new InvalidArgumentException("Slug cannot start or end with hyphen");
        }

        if (str_contains($value, '--')) {
            throw new InvalidArgumentException("Slug cannot contain consecutive hyphens");
        }

        return $value;
    }

    private function validateAlphaParam(string $value): string
    {
        if (!preg_match('/^[a-zA-Z]+$/', $value)) {
            throw new InvalidArgumentException("Invalid alpha parameter");
        }

        if (strlen($value) > 50) {
            throw new InvalidArgumentException("Alpha parameter too long");
        }

        return $value;
    }

    private function validateAlnumParam(string $value): string
    {
        if (!preg_match('/^[a-zA-Z0-9]+$/', $value)) {
            throw new InvalidArgumentException("Invalid alphanumeric parameter");
        }

        if (strlen($value) > 50) {
            throw new InvalidArgumentException("Alphanumeric parameter too long");
        }

        return $value;
    }

    private function sanitizeDefaultParam(string $value): string
    {
        // Entferne gefährliche Zeichen
        $sanitized = preg_replace('/[<>"\'\x00-\x1f\x7f-\x9f]/', '', $value);
        return htmlspecialchars($sanitized, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * ✅ OPTIMIZED: Fast URL generation
     */
    public function generateUrl(array $params = []): string
    {
        $url = $this->originalPath;

        if (empty($params)) {
            return $url;
        }

        foreach ($params as $key => $value) {
            $pattern = '/\{' . preg_quote($key, '/') . '(?::[^}]+)?\}/';
            $url = preg_replace($pattern, urlencode((string)$value), $url);
        }

        // Check for missing parameters
        if (preg_match('/\{[^}]+\}/', $url)) {
            throw new InvalidArgumentException("Missing required parameters for route");
        }

        return $url;
    }

    /**
     * ✅ OPTIMIZED: Lightweight serialization for cache
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

    /**
     * ✅ Performance helpers
     */
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

    /**
     * ✅ OPTIMIZED: Fast clone operations
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

    /**
     * ✅ Optimized debugging
     */
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