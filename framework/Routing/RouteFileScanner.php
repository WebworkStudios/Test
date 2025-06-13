<?php

declare(strict_types=1);

namespace Framework\Routing;

/**
 * Optimized file scanner for route discovery with PHP 8.4 features
 */
final class RouteFileScanner
{
    // PHP 8.4 Property Hooks for computed properties
    public int $maxFileSize {
        get => $this->config['max_file_size'] ?? 2097152; // 2MB
    }

    public bool $strictMode {
        get => $this->config['strict_mode'] ?? true;
    }

    public int $cacheHitRatio {
        get => $this->totalFiles > 0 ? (int)(($this->cacheHits / $this->totalFiles) * 100) : 0;
    }

    // Performance tracking
    private array $fileCache = [];
    private array $compiledPatterns = [];
    private int $cacheHits = 0;
    private int $totalFiles = 0;

    public function __construct(
        private readonly array $config = []
    ) {
        $this->initializePatterns();
    }

    /**
     * Initialize compiled regex patterns for better performance
     */
    private function initializePatterns(): void
    {
        $this->compiledPatterns = [
            'php_file' => '/\.php$/',
            'route_attribute' => '/#\[Route\s*\(/',
            'class_declaration' => '/^\s*(?:final\s+)?(?:readonly\s+)?class\s+([a-zA-Z_][a-zA-Z0-9_]*)/m',
            'namespace_declaration' => '/^\s*namespace\s+([a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*)\s*;/m',
            'dangerous_code' => '/(?:eval|exec|system|shell_exec|base64_decode)\s*\(/i'
        ];
    }

    /**
     * Scan single file for route classes
     */
    public function scanFile(string $filePath): array
    {
        $this->totalFiles++;

        if (!$this->isValidPhpFile($filePath)) {
            return [];
        }

        $cacheKey = $this->generateCacheKey($filePath);

        // Check cache first
        if (isset($this->fileCache[$cacheKey])) {
            $cached = $this->fileCache[$cacheKey];
            if ($this->isCacheValid($filePath, $cached['timestamp'])) {
                $this->cacheHits++;
                return $cached['classes'];
            }
        }

        $content = $this->readFileSecurely($filePath);
        if ($content === null) {
            $this->cacheResult($cacheKey, []);
            return [];
        }

        // Fast pre-screening
        if (!$this->hasRouteAttributes($content)) {
            $this->cacheResult($cacheKey, []);
            return [];
        }

        $classes = $this->extractClassNames($content);
        $this->cacheResult($cacheKey, $classes, filemtime($filePath));

        return $classes;
    }

    /**
     * Validate PHP file
     */
    private function isValidPhpFile(string $filePath): bool
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return false;
        }

        // Extension check
        if (!preg_match($this->compiledPatterns['php_file'], $filePath)) {
            return false;
        }

        // Size check
        $size = filesize($filePath);
        if ($size === false || $size > $this->maxFileSize || $size === 0) {
            return false;
        }

        // Security checks for filename
        $filename = basename($filePath);
        if (str_starts_with($filename, '.') ||
            str_starts_with($filename, '_') ||
            preg_match('/\.(bak|tmp|old|orig)\.php$/', $filename)) {
            return false;
        }

        return true;
    }

    /**
     * Generate cache key for file
     */
    private function generateCacheKey(string $filePath): string
    {
        $stat = stat($filePath);
        return hash('xxh3', $filePath . ($stat['mtime'] ?? 0) . ($stat['size'] ?? 0));
    }

    /**
     * Check if cache entry is still valid
     */
    private function isCacheValid(string $filePath, int $cachedTimestamp): bool
    {
        $currentTimestamp = filemtime($filePath);
        return $currentTimestamp !== false && $currentTimestamp === $cachedTimestamp;
    }

    /**
     * Cache scan result
     */
    private function cacheResult(string $cacheKey, array $classes, ?int $timestamp = null): void
    {
        // Limit cache size to prevent memory issues
        if (count($this->fileCache) >= 1000) {
            // Remove oldest entries (simple FIFO)
            $this->fileCache = array_slice($this->fileCache, 100, null, true);
        }

        $this->fileCache[$cacheKey] = [
            'classes' => $classes,
            'timestamp' => $timestamp ?? time(),
        ];
    }

    /**
     * Read file content securely
     */
    private function readFileSecurely(string $filePath): ?string
    {
        // Security validation
        $realPath = realpath($filePath);
        if ($realPath === false) {
            return null;
        }

        // Prevent directory traversal
        if (str_contains($realPath, '..') || str_contains($realPath, "\0")) {
            return null;
        }

        $content = file_get_contents($realPath);
        if ($content === false) {
            return null;
        }

        // Validate PHP content
        if (!$this->isValidPhpContent($content)) {
            return null;
        }

        return $content;
    }

    /**
     * Validate PHP file content
     */
    private function isValidPhpContent(string $content): bool
    {
        $trimmed = trim($content);

        // Must start with PHP tag
        if (!str_starts_with($trimmed, '<?php')) {
            return false;
        }

        // Check for dangerous code patterns
        if (preg_match($this->compiledPatterns['dangerous_code'], $content)) {
            if ($this->strictMode) {
                return false;
            }
            error_log("Warning: Potentially dangerous code found in file");
        }

        return true;
    }

    /**
     * Fast check for route attributes
     */
    private function hasRouteAttributes(string $content): bool
    {
        // Multiple fast checks for better performance
        return str_contains($content, '#[Route') ||
            str_contains($content, 'Route(') ||
            preg_match($this->compiledPatterns['route_attribute'], $content) === 1;
    }

    /**
     * Extract class names from file content
     */
    private function extractClassNames(string $content): array
    {
        $namespace = $this->extractNamespace($content);
        $classes = [];

        // Extract class declarations
        if (preg_match_all($this->compiledPatterns['class_declaration'], $content, $matches)) {
            foreach ($matches[1] as $className) {
                if ($this->isValidClassName($className)) {
                    $fullClassName = $namespace ? $namespace . '\\' . $className : $className;

                    if ($this->isAllowedClass($fullClassName)) {
                        $classes[] = $fullClassName;
                    }
                }
            }
        }

        return $classes;
    }

    /**
     * Extract namespace from content
     */
    private function extractNamespace(string $content): ?string
    {
        if (preg_match($this->compiledPatterns['namespace_declaration'], $content, $matches)) {
            $namespace = trim($matches[1]);
            return $this->isValidNamespace($namespace) ? $namespace : null;
        }

        return null;
    }

    /**
     * Validate namespace
     */
    private function isValidNamespace(string $namespace): bool
    {
        if (strlen($namespace) > 255) {
            return false;
        }

        // Check allowed namespace prefixes
        $allowedPrefixes = [
            'App\\',
            'Framework\\',
            'Modules\\',
            'Domain\\',
            'Infrastructure\\'
        ];

        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($namespace, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate class name
     */
    private function isValidClassName(string $className): bool
    {
        if (strlen($className) > 100) {
            return false;
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $className)) {
            return false;
        }

        // Check for reserved words
        $reserved = ['class', 'interface', 'trait', 'enum', 'function', 'var', 'const'];
        if (in_array(strtolower($className), $reserved, true)) {
            return false;
        }

        return true;
    }

    /**
     * Check if class is allowed for route discovery
     */
    private function isAllowedClass(string $fullClassName): bool
    {
        // Allowed namespaces for route classes
        $allowedNamespaces = [
            'App\\Actions\\',
            'App\\Controllers\\',
            'App\\Http\\Actions\\',
            'App\\Http\\Controllers\\',
            'App\\Api\\',
            'Modules\\'
        ];

        $hasValidNamespace = false;
        foreach ($allowedNamespaces as $namespace) {
            if (str_starts_with($fullClassName, $namespace)) {
                $hasValidNamespace = true;
                break;
            }
        }

        if (!$hasValidNamespace) {
            return false;
        }

        // Check class name suffixes
        $className = basename(str_replace('\\', '/', $fullClassName));
        $allowedSuffixes = ['Action', 'Controller', 'Handler'];

        foreach ($allowedSuffixes as $suffix) {
            if (str_ends_with($className, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate discovered class
     */
    public function validateClass(string $className): bool
    {
        if (!class_exists($className)) {
            return false;
        }

        try {
            $reflection = new \ReflectionClass($className);

            // Must be invokable (have __invoke method)
            if (!$reflection->hasMethod('__invoke')) {
                return false;
            }

            // Security checks
            if ($this->hasSecurityRisks($reflection)) {
                return false;
            }

            return true;

        } catch (\ReflectionException $e) {
            if ($this->strictMode) {
                throw $e;
            }
            error_log("Reflection error for class {$className}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check class for security risks
     */
    private function hasSecurityRisks(\ReflectionClass $reflection): bool
    {
        // Check for dangerous methods
        $dangerousMethods = [
            'exec', 'system', 'shell_exec', 'passthru', 'eval',
            'file_get_contents', 'file_put_contents', 'fopen'
        ];

        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
        foreach ($methods as $method) {
            if (in_array($method->getName(), $dangerousMethods, true)) {
                return true;
            }
        }

        // Check dangerous interfaces
        $dangerousInterfaces = ['Serializable'];
        foreach ($dangerousInterfaces as $interface) {
            if ($reflection->implementsInterface($interface)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get scanning statistics
     */
    public function getStats(): array
    {
        return [
            'total_files_scanned' => $this->totalFiles,
            'cache_hits' => $this->cacheHits,
            'cache_hit_ratio' => $this->cacheHitRatio,
            'cached_files' => count($this->fileCache),
            'max_file_size' => $this->maxFileSize,
            'strict_mode' => $this->strictMode,
            'memory_usage' => memory_get_usage(true),
        ];
    }

    /**
     * Clear file cache
     */
    public function clearCache(): void
    {
        $this->fileCache = [];
        $this->cacheHits = 0;
        $this->totalFiles = 0;
    }

    /**
     * Get cache efficiency metrics
     */
    public function getCacheEfficiency(): array
    {
        return [
            'cache_size' => count($this->fileCache),
            'hit_ratio' => $this->cacheHitRatio,
            'memory_per_file' => count($this->fileCache) > 0
                ? memory_get_usage(true) / count($this->fileCache)
                : 0,
        ];
    }

    /**
     * Check if specific file has route attributes
     */
    public function fileHasRoutes(string $filePath): bool
    {
        if (!$this->isValidPhpFile($filePath)) {
            return false;
        }

        $content = $this->readFileSecurely($filePath);
        return $content !== null && $this->hasRouteAttributes($content);
    }

    /**
     * Batch scan multiple files
     */
    public function scanFiles(array $filePaths): array
    {
        $allClasses = [];

        foreach ($filePaths as $filePath) {
            $classes = $this->scanFile($filePath);
            $allClasses = array_merge($allClasses, $classes);
        }

        return array_unique($allClasses);
    }

    /**
     * Magic method for debugging
     */
    public function __debugInfo(): array
    {
        return [
            'total_files_scanned' => $this->totalFiles,
            'cache_hits' => $this->cacheHits,
            'cache_hit_ratio' => $this->cacheHitRatio . '%',
            'cached_files' => count($this->fileCache),
            'max_file_size' => $this->formatBytes($this->maxFileSize),
            'strict_mode' => $this->strictMode,
        ];
    }

    /**
     * Format bytes for human reading
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }
}