<?php

declare(strict_types=1);

namespace Framework\Routing;

use ReflectionClass;
use ReflectionException;

/**
 * ✅ OPTIMIZED: Fast file scanner with intelligent caching
 */
final class RouteFileScanner
{
    // PHP 8.4 Property Hooks for computed properties
    private const int MAX_CACHE_SIZE = 500;
    private const int MAX_CONTENT_CACHE = 100;
    public int $maxFileSize {
        get => $this->config['max_file_size'] ?? 1048576; // 1MB (reduced)
    }

    // ✅ OPTIMIZED: Smaller, smarter cache
    public bool $strictMode {
        get => $this->config['strict_mode'] ?? false; // ✅ Default false
    }
    public int $cacheHitRatio {
        get => $this->totalFiles > 0 ? (int)(($this->cacheHits / $this->totalFiles) * 100) : 0;
    } // ✅ Cache file contents
    private array $fileCache = []; // Reduced from 1000
    private array $contentCache = []; // New content cache

    // Performance tracking
    private int $cacheHits = 0;
    private int $totalFiles = 0;

    // ✅ OPTIMIZED: Pre-compiled patterns for speed
    private array $patterns;

    public function __construct(
        private readonly array $config = []
    )
    {
        $this->initializePatterns();
    }

    /**
     * ✅ OPTIMIZED: Pre-compile all regex patterns
     */
    private function initializePatterns(): void
    {
        $this->patterns = [
            'php_file' => '/\.php$/i',
            'route_attribute' => '/#\[Route\s*\(/i',
            'class_declaration' => '/^\s*(?:final\s+)?(?:readonly\s+)?(?:abstract\s+)?class\s+([a-zA-Z_][a-zA-Z0-9_]*)/m',
            'namespace_declaration' => '/^\s*namespace\s+([a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*)\s*;/m',
            'dangerous_code' => '/(?:eval|exec|system|shell_exec)\s*\(/i',
            'route_quick_check' => '/(#\[Route|Route\(|class\s+\w+Action|class\s+\w+Controller)/i'
        ];
    }

    /**
     * ✅ OPTIMIZED: Fast class validation with caching
     */
    public function validateClass(string $className): bool
    {
        // Check cache first
        $cacheKey = 'validate_' . $className;
        if (isset($this->fileCache[$cacheKey])) {
            return $this->fileCache[$cacheKey];
        }

        $result = $this->performClassValidation($className);
        $this->cacheResult($cacheKey, $result);

        return $result;
    }

    /**
     * ✅ OPTIMIZED: Streamlined class validation
     */
    private function performClassValidation(string $className): bool
    {
        if (!class_exists($className)) {
            return false;
        }

        try {
            $reflection = new ReflectionClass($className);

            // Must be invokable
            if (!$reflection->hasMethod('__invoke')) {
                return false;
            }

            // ✅ Quick security check - only essential
            if ($this->hasBasicSecurityRisks($reflection)) {
                return false;
            }

            return true;

        } catch (ReflectionException) {
            return false;
        }
    }

    /**
     * ✅ OPTIMIZED: Basic security check only
     */
    private function hasBasicSecurityRisks(ReflectionClass $reflection): bool
    {
        // Only check for the most dangerous methods
        $dangerousMethods = ['eval', 'exec', 'system', 'shell_exec'];

        foreach ($dangerousMethods as $dangerous) {
            if ($reflection->hasMethod($dangerous)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ✅ OPTIMIZED: LRU cache with size limits
     */
    private function cacheResult(string $key, mixed $value): void
    {
        // Simple LRU for file cache
        if (count($this->fileCache) >= self::MAX_CACHE_SIZE) {
            $oldestKey = array_key_first($this->fileCache);
            unset($this->fileCache[$oldestKey]);
        }

        $this->fileCache[$key] = $value;
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
            'cached_content' => count($this->contentCache),
            'max_file_size' => $this->maxFileSize,
            'strict_mode' => $this->strictMode,
        ];
    }

    /**
     * Clear all caches
     */
    public function clearCache(): void
    {
        $this->fileCache = [];
        $this->contentCache = [];
        $this->cacheHits = 0;
        $this->totalFiles = 0;
    }

    /**
     * ✅ OPTIMIZED: Fast route detection
     */
    public function fileHasRoutes(string $filePath): bool
    {
        $content = $this->readFileWithCache($filePath);
        return $content !== null && $this->hasRouteAttributes($content);
    }

    /**
     * ✅ OPTIMIZED: Content caching for file reads
     */
    private function readFileWithCache(string $filePath): ?string
    {
        $cacheKey = 'content_' . md5($filePath);
        $mtime = filemtime($filePath);

        // Check content cache
        if (isset($this->contentCache[$cacheKey])) {
            $cached = $this->contentCache[$cacheKey];
            if ($cached['mtime'] === $mtime) {
                return $cached['content'];
            }
        }

        $content = $this->readFileSecurely($filePath);

        if ($content !== null && count($this->contentCache) < self::MAX_CONTENT_CACHE) {
            $this->contentCache[$cacheKey] = [
                'content' => $content,
                'mtime' => $mtime
            ];
        }

        return $content;
    }

    /**
     * ✅ OPTIMIZED: Minimal secure file reading
     */
    private function readFileSecurely(string $filePath): ?string
    {
        if (!$this->isValidPhpFile($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        // Basic validation only
        if (!str_starts_with(trim($content), '<?php')) {
            return null;
        }

        // ✅ Only check for dangerous code in strict mode
        if ($this->strictMode && preg_match($this->patterns['dangerous_code'], $content)) {
            return null;
        }

        return $content;
    }

    /**
     * ✅ OPTIMIZED: Fast file validation
     */
    private function isValidPhpFile(string $filePath): bool
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return false;
        }

        // Fast extension and size check
        if (!preg_match($this->patterns['php_file'], $filePath)) {
            return false;
        }

        $size = filesize($filePath);
        if ($size === false || $size === 0 || $size > $this->maxFileSize) {
            return false;
        }

        // Fast filename check
        $filename = basename($filePath);
        return !str_starts_with($filename, '.') && !str_starts_with($filename, '_');
    }

    /**
     * ✅ OPTIMIZED: Super fast route attribute detection
     */
    private function hasRouteAttributes(string $content): bool
    {
        // ✅ Ultra-fast pre-check
        if (!str_contains($content, 'Route') || !str_contains($content, '#[')) {
            return false;
        }

        // ✅ Quick pattern match
        return preg_match($this->patterns['route_quick_check'], $content) === 1;
    }

    /**
     * ✅ OPTIMIZED: Batch file scanning
     */
    public function scanFiles(array $filePaths): array
    {
        $allClasses = [];

        // Process in batches for better memory management
        $batches = array_chunk($filePaths, 20);

        foreach ($batches as $batch) {
            foreach ($batch as $filePath) {
                $classes = $this->scanFile($filePath);
                $allClasses = array_merge($allClasses, $classes);
            }

            // Cleanup memory periodically
            if (count($allClasses) > 100) {
                gc_collect_cycles();
            }
        }

        return array_unique($allClasses);
    }

    /**
     * ✅ OPTIMIZED: Smart file scanning with caching
     */
    public function scanFile(string $filePath): array
    {
        $this->totalFiles++;

        $cacheKey = 'scan_' . md5($filePath);
        $mtime = filemtime($filePath);

        // Check cache first
        if (isset($this->fileCache[$cacheKey])) {
            $cached = $this->fileCache[$cacheKey];
            if ($cached['mtime'] === $mtime) {
                $this->cacheHits++;
                return $cached['classes'];
            }
        }

        $classes = $this->performFileScan($filePath);

        // Cache result
        $this->cacheResult($cacheKey, [
            'classes' => $classes,
            'mtime' => $mtime
        ]);

        return $classes;
    }

    /**
     * ✅ OPTIMIZED: Streamlined file scanning
     */
    private function performFileScan(string $filePath): array
    {
        $content = $this->readFileWithCache($filePath);
        if ($content === null) {
            return [];
        }

        // Fast pre-screening
        if (!$this->hasRouteAttributes($content)) {
            return [];
        }

        return $this->extractClassNames($content);
    }

    /**
     * ✅ OPTIMIZED: Fast class name extraction
     */
    private function extractClassNames(string $content): array
    {
        $namespace = $this->extractNamespace($content);
        $classes = [];

        // Extract class names
        if (preg_match_all($this->patterns['class_declaration'], $content, $matches)) {
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
     * ✅ OPTIMIZED: Fast namespace extraction
     */
    private function extractNamespace(string $content): ?string
    {
        if (preg_match($this->patterns['namespace_declaration'], $content, $matches)) {
            $namespace = trim($matches[1]);
            return $this->isValidNamespace($namespace) ? $namespace : null;
        }

        return null;
    }

    /**
     * ✅ OPTIMIZED: Quick namespace validation
     */
    private function isValidNamespace(string $namespace): bool
    {
        if (strlen($namespace) > 255) {
            return false;
        }

        // Quick check for allowed prefixes
        $allowedPrefixes = ['App\\', 'Framework\\', 'Modules\\'];

        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($namespace, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ✅ OPTIMIZED: Fast class name validation
     */
    private function isValidClassName(string $className): bool
    {
        return strlen($className) <= 100 &&
            preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $className) === 1;
    }

    /**
     * ✅ OPTIMIZED: Quick class filtering
     */
    private function isAllowedClass(string $fullClassName): bool
    {
        // Quick namespace check
        $allowedNamespaces = [
            'App\\Actions\\',
            'App\\Controllers\\',
        ];

        foreach ($allowedNamespaces as $namespace) {
            if (str_starts_with($fullClassName, $namespace)) {
                // Quick suffix check
                return str_ends_with($fullClassName, 'Action') ||
                    str_ends_with($fullClassName, 'Controller');
            }
        }

        return false;
    }

    /**
     * Magic method for debugging
     */
    public function __debugInfo(): array
    {
        return [
            'total_files_scanned' => $this->totalFiles,
            'cache_hit_ratio' => $this->cacheHitRatio . '%',
            'cached_files' => count($this->fileCache),
            'cached_content' => count($this->contentCache),
            'max_file_size' => $this->formatBytes($this->maxFileSize),
            'strict_mode' => $this->strictMode,
        ];
    }

    /**
     * Format bytes for human reading
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }
}