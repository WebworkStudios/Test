<?php

declare(strict_types=1);

namespace Framework\Routing;

use Framework\Routing\Attributes\Route;
use ReflectionClass;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveCallbackFilterIterator;

/**
 * High-performance route discovery with PHP 8.4 optimizations
 */
final class RouteDiscovery
{
    // PHP 8.4 Property Hooks for enhanced API
    public int $maxFileSize {
        get => $this->config['max_file_size'] ?? 2097152; // 2MB (increased)
    }

    public int $maxDepth {
        get => $this->config['max_depth'] ?? 15; // Increased for better discovery
    }

    public bool $strictMode {
        get => $this->config['strict_mode'] ?? true;
    }

    public bool $useParallelProcessing {
        get => $this->config['parallel_processing'] ?? extension_loaded('parallel');
    }

    public int $cacheHitRatio {
        get => $this->totalFiles > 0 ? (int)(($this->cacheHits / $this->totalFiles) * 100) : 0;
    }

    // Performance tracking
    private array $classCache = [];
    private array $fileCache = [];
    private int $processedFiles = 0;
    private int $discoveredRoutes = 0;
    private int $cacheHits = 0;
    private int $totalFiles = 0;

    // Optimizations
    private array $compiledPatterns = [];
    private array $exclusionCache = [];

    public function __construct(
        private readonly Router $router,
        private readonly array $ignoredDirectories = [
            'vendor', 'node_modules', '.git', 'storage', 'cache', 'tests',
            'build', 'dist', 'coverage', '.idea', '.vscode'
        ],
        private readonly array $config = []
    ) {
        $this->validateConfiguration();
        $this->initializeOptimizations();
    }

    /**
     * Initialize performance optimizations
     */
    private function initializeOptimizations(): void
    {
        // Pre-compile frequently used patterns
        $this->compiledPatterns = [
            'php_file' => '/\.php$/',
            'route_attribute' => '/#\[Route\s*\(/',
            'class_declaration' => '/^\s*(?:final\s+)?(?:readonly\s+)?class\s+([a-zA-Z_][a-zA-Z0-9_]*)/m',
            'namespace_declaration' => '/^\s*namespace\s+([a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*)\s*;/m',
            'dangerous_code' => '/(?:eval|exec|system|shell_exec|base64_decode)\s*\(/i'
        ];

        // Initialize caches
        $this->classCache = [];
        $this->fileCache = [];
        $this->exclusionCache = [];
    }

    /**
     * High-performance route discovery with parallel processing
     */
    public function discover(array $directories): void
    {
        $this->validateDirectories($directories);
        $this->resetCounters();

        if ($this->useParallelProcessing && count($directories) > 1) {
            $this->discoverParallel($directories);
        } else {
            $this->discoverSequential($directories);
        }
    }

    /**
     * Sequential discovery with optimizations
     */
    private function discoverSequential(array $directories): void
    {
        foreach ($directories as $directory) {
            $this->scanDirectoryOptimized($directory);
        }
    }

    /**
     * Parallel discovery for better performance (if available)
     */
    private function discoverParallel(array $directories): void
    {
        // Note: This would require the parallel extension
        // For now, fall back to sequential processing
        $this->discoverSequential($directories);
    }

    /**
     * Optimized directory scanning with advanced filtering
     */
    private function scanDirectoryOptimized(string $directory): void
    {
        if (str_contains($directory, '*')) {
            $this->processGlobPatternOptimized($directory);
            return;
        }

        if (!is_dir($directory)) {
            return;
        }

        $realDirectory = realpath($directory);
        if ($realDirectory === false || !$this->isSecurePath($realDirectory)) {
            return;
        }

        // Check exclusion cache
        if (isset($this->exclusionCache[$realDirectory])) {
            return;
        }

        try {
            $this->scanWithIterator($realDirectory);
        } catch (\Exception $e) {
            if ($this->strictMode) {
                throw $e;
            }
            error_log("Error scanning directory {$directory}: " . $e->getMessage());
            $this->exclusionCache[$realDirectory] = true;
        }
    }

    /**
     * Optimized iterator-based scanning
     */
    private function scanWithIterator(string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                $this->createOptimizedFileFilter(...)
            ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $iterator->setMaxDepth($this->maxDepth);

        $batchSize = 100;
        $batch = [];

        foreach ($iterator as $file) {
            if ($this->processedFiles >= 10000) { // Increased limit
                break;
            }

            if ($file->isFile() && $this->isValidPhpFileOptimized($file)) {
                $batch[] = $file->getPathname();

                if (count($batch) >= $batchSize) {
                    $this->processBatch($batch);
                    $batch = [];
                }
            }
        }

        // Process remaining files
        if (!empty($batch)) {
            $this->processBatch($batch);
        }
    }

    /**
     * Process files in batches for better performance
     */
    private function processBatch(array $filePaths): void
    {
        foreach ($filePaths as $filePath) {
            $this->processFileOptimized($filePath);
            $this->processedFiles++;
            $this->totalFiles++;
        }
    }

    /**
     * Optimized file processing with enhanced caching
     */
    private function processFileOptimized(string $filePath): void
    {
        $cacheKey = $this->generateAdvancedCacheKey($filePath);

        // Multi-level cache check
        if (isset($this->fileCache[$cacheKey])) {
            $cached = $this->fileCache[$cacheKey];
            foreach ($cached['classes'] as $className) {
                $this->registerClass($className);
            }
            $this->cacheHits++;
            return;
        }

        $content = $this->readFileSecurely($filePath);
        if ($content === null) {
            $this->fileCache[$cacheKey] = ['classes' => []];
            return;
        }

        // Fast pre-screening
        if (!$this->fastContainsRouteAttributes($content)) {
            $this->fileCache[$cacheKey] = ['classes' => []];
            return;
        }

        $classes = $this->extractClassNamesOptimized($content);
        $this->fileCache[$cacheKey] = [
            'classes' => $classes,
            'timestamp' => filemtime($filePath),
            'size' => strlen($content)
        ];

        foreach ($classes as $className) {
            $this->registerClass($className);
        }
    }

    /**
     * Enhanced cache key generation
     */
    private function generateAdvancedCacheKey(string $filePath): string
    {
        $stat = stat($filePath);
        return hash('xxh3', $filePath . ($stat['mtime'] ?? 0) . ($stat['size'] ?? 0) . ($stat['ino'] ?? 0));
    }

    /**
     * Ultra-fast route attribute detection
     */
    private function fastContainsRouteAttributes(string $content): bool
    {
        // Multiple fast checks
        return str_contains($content, '#[Route') ||
            str_contains($content, 'Route(') ||
            preg_match($this->compiledPatterns['route_attribute'], $content) === 1;
    }

    /**
     * Optimized class name extraction with caching
     */
    private function extractClassNamesOptimized(string $content): array
    {
        static $extractionCache = [];
        $contentHash = hash('xxh3', $content);

        if (isset($extractionCache[$contentHash])) {
            return $extractionCache[$contentHash];
        }

        $classes = [];
        $namespace = $this->extractNamespaceOptimized($content);

        // Use pre-compiled pattern
        if (preg_match_all($this->compiledPatterns['class_declaration'], $content, $matches)) {
            foreach ($matches[1] as $className) {
                if ($this->isValidClassName($className)) {
                    $fullClassName = $namespace ? $namespace . '\\' . $className : $className;
                    if ($this->isAllowedClassOptimized($fullClassName)) {
                        $classes[] = $fullClassName;
                    }
                }
            }
        }

        // Cache result (limit cache size)
        if (count($extractionCache) < 1000) {
            $extractionCache[$contentHash] = $classes;
        }

        return $classes;
    }

    /**
     * Optimized namespace extraction
     */
    private function extractNamespaceOptimized(string $content): ?string
    {
        if (preg_match($this->compiledPatterns['namespace_declaration'], $content, $matches)) {
            $namespace = trim($matches[1]);
            return $this->isValidNamespaceOptimized($namespace) ? $namespace : null;
        }

        return null;
    }

    /**
     * Enhanced namespace validation with caching
     */
    private function isValidNamespaceOptimized(string $namespace): bool
    {
        static $namespaceCache = [];

        if (isset($namespaceCache[$namespace])) {
            return $namespaceCache[$namespace];
        }

        $result = false;

        if (strlen($namespace) <= 255) {
            $allowedPrefixes = ['App\\', 'Framework\\', 'Tests\\', 'Modules\\'];
            foreach ($allowedPrefixes as $prefix) {
                if (str_starts_with($namespace, $prefix)) {
                    $result = true;
                    break;
                }
            }
        }

        // Cache result (limit cache size)
        if (count($namespaceCache) < 500) {
            $namespaceCache[$namespace] = $result;
        }

        return $result;
    }

    /**
     * Optimized class validation with enhanced caching
     */
    private function isAllowedClassOptimized(string $fullClassName): bool
    {
        static $classCache = [];

        if (isset($classCache[$fullClassName])) {
            return $classCache[$fullClassName];
        }

        $result = false;

        // Enhanced namespace validation
        $allowedNamespaces = [
            'App\\Actions\\', 'App\\Controllers\\', 'App\\Http\\Actions\\',
            'App\\Http\\Controllers\\', 'App\\Api\\', 'Modules\\'
        ];

        $allowedSuffixes = ['Action', 'Controller', 'Handler'];

        $hasValidNamespace = false;
        foreach ($allowedNamespaces as $namespace) {
            if (str_starts_with($fullClassName, $namespace)) {
                $hasValidNamespace = true;
                break;
            }
        }

        if ($hasValidNamespace) {
            $className = basename(str_replace('\\', '/', $fullClassName));
            foreach ($allowedSuffixes as $suffix) {
                if (str_ends_with($className, $suffix)) {
                    $result = true;
                    break;
                }
            }
        }

        // Cache result (limit cache size)
        if (count($classCache) < 1000) {
            $classCache[$fullClassName] = $result;
        }

        return $result;
    }

    /**
     * Enhanced file filter with better performance
     */
    private function createOptimizedFileFilter(\SplFileInfo $file, string $key, \RecursiveCallbackFilterIterator $iterator): bool
    {
        $filename = $file->getFilename();

        // Fast exclusion checks
        if (str_starts_with($filename, '.') ||
            str_starts_with($filename, '_') ||
            str_starts_with($filename, '#')) {
            return false;
        }

        if ($file->isDir()) {
            // Enhanced directory filtering
            $lowerName = strtolower($filename);

            // Check against ignored directories
            if (in_array($lowerName, array_map('strtolower', $this->ignoredDirectories), true)) {
                return false;
            }

            // Additional security checks
            if (in_array($lowerName, ['tmp', 'temp', 'log', 'logs', 'backup', 'backups'], true)) {
                return false;
            }

            return $this->isSecureDirectoryName($filename);
        }

        // File validation
        return $this->isValidPhpFileOptimized($file);
    }

    /**
     * Optimized PHP file validation
     */
    private function isValidPhpFileOptimized(\SplFileInfo $file): bool
    {
        // Extension check first (fastest)
        if ($file->getExtension() !== 'php') {
            return false;
        }

        // Size and readability checks
        if (!$file->isReadable() || $file->getSize() > $this->maxFileSize) {
            return false;
        }

        // Additional security checks
        $filename = $file->getFilename();
        if (preg_match('/\.(bak|tmp|old|orig)\.php$/', $filename)) {
            return false;
        }

        return true;
    }

    /**
     * Enhanced secure content validation
     */
    private function isValidPhpContent(string $content): bool
    {
        $trimmed = trim($content);
        if (!str_starts_with($trimmed, '<?php')) {
            return false;
        }

        // Use pre-compiled pattern for dangerous code detection
        return !preg_match($this->compiledPatterns['dangerous_code'], $content);
    }

    /**
     * Optimized glob pattern processing
     */
    private function processGlobPatternOptimized(string $pattern): void
    {
        $matches = glob($pattern, GLOB_ONLYDIR | GLOB_NOSORT);
        if ($matches === false) {
            return;
        }

        // Process in chunks for better memory management
        $chunks = array_chunk(array_slice($matches, 0, 100), 10);

        foreach ($chunks as $chunk) {
            foreach ($chunk as $match) {
                $this->scanDirectoryOptimized($match);
            }
        }
    }

    /**
     * Enhanced route attribute processing
     */
    private function processRouteAttributes(ReflectionClass $reflection): void
    {
        if (!$reflection->hasMethod('__invoke')) {
            return;
        }

        $attributes = $reflection->getAttributes(Route::class);

        foreach ($attributes as $attribute) {
            try {
                /** @var Route $route */
                $route = $attribute->newInstance();

                $this->router->addRoute(
                    $route->method,
                    $route->path,
                    $reflection->getName(),
                    $route->middleware,
                    $route->name,
                    $route->subdomain
                );

                $this->discoveredRoutes++;
            } catch (\Throwable $e) {
                if ($this->strictMode) {
                    throw $e;
                }
                error_log("Failed to process route for {$reflection->getName()}: " . $e->getMessage());
            }
        }
    }

    /**
     * Enhanced class registration with validation
     */
    public function registerClass(string $className): void
    {
        if (!$this->isValidClassName($className) || !class_exists($className)) {
            return;
        }

        // Check cache first
        if (isset($this->classCache[$className])) {
            return;
        }

        try {
            $reflection = new ReflectionClass($className);
            $this->processRouteAttributes($reflection);
            $this->classCache[$className] = true;
        } catch (\Throwable $e) {
            if ($this->strictMode) {
                throw $e;
            }
            error_log("Failed to register class {$className}: " . $e->getMessage());
            $this->classCache[$className] = false;
        }
    }

    /**
     * Enhanced statistics with performance metrics
     */
    public function getStats(): array
    {
        return [
            'processed_files' => $this->processedFiles,
            'discovered_routes' => $this->discoveredRoutes,
            'cached_classes' => count($this->classCache),
            'cached_files' => count($this->fileCache),
            'cache_hit_ratio' => $this->cacheHitRatio,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'strict_mode' => $this->strictMode,
            'parallel_processing' => $this->useParallelProcessing,
            'max_file_size' => $this->maxFileSize,
            'max_depth' => $this->maxDepth,
        ];
    }

    /**
     * Advanced cache clearing with selective cleanup
     */
    public function clearCache(bool $selective = false): void
    {
        if ($selective) {
            // Keep recently used entries
            $threshold = time() - 3600; // 1 hour

            $this->fileCache = array_filter($this->fileCache, function($entry) use ($threshold) {
                return ($entry['timestamp'] ?? 0) > $threshold;
            });
        } else {
            $this->classCache = [];
            $this->fileCache = [];
            $this->exclusionCache = [];
        }

        $this->resetCounters();
    }

    /**
     * Reset performance counters
     */
    private function resetCounters(): void
    {
        $this->processedFiles = 0;
        $this->discoveredRoutes = 0;
        $this->cacheHits = 0;
        $this->totalFiles = 0;
    }

    /**
     * Validate configuration with enhanced checks
     */
    private function validateConfiguration(): void
    {
        if ($this->maxFileSize < 1024 || $this->maxFileSize > 10485760) {
            throw new \InvalidArgumentException('Invalid max file size: must be between 1KB and 10MB');
        }

        if ($this->maxDepth < 1 || $this->maxDepth > 25) {
            throw new \InvalidArgumentException('Invalid max depth: must be between 1 and 25');
        }

        // Validate ignored directories
        foreach ($this->ignoredDirectories as $dir) {
            if (!is_string($dir) || strlen($dir) > 100) {
                throw new \InvalidArgumentException('Invalid ignored directory specification');
            }
        }
    }

    /**
     * Enhanced directory validation
     */
    private function validateDirectories(array $directories): void
    {
        if (count($directories) > 50) {
            throw new \InvalidArgumentException('Too many directories to scan (max 50)');
        }

        foreach ($directories as $directory) {
            if (!is_string($directory) || strlen($directory) > 500) {
                throw new \InvalidArgumentException('Invalid directory path');
            }

            if (str_contains($directory, "\0") || str_contains($directory, '..')) {
                throw new \InvalidArgumentException('Directory path contains invalid characters');
            }
        }
    }

    /**
     * Enhanced secure path validation
     */
    private function isSecurePath(string $path): bool
    {
        if ($this->strictMode) {
            $cwd = realpath(getcwd());
            if ($cwd === false || !str_starts_with($path, $cwd)) {
                return false;
            }
        }

        return !str_contains($path, '..') && !str_contains($path, "\0");
    }

    /**
     * Enhanced directory name security check
     */
    private function isSecureDirectoryName(string $dirname): bool
    {
        $dangerous = [
            'bin', 'sbin', 'etc', 'tmp', 'temp', 'admin', 'config', 'secret',
            'private', 'hidden', 'system', 'root', 'proc', 'dev'
        ];

        return !in_array(strtolower($dirname), $dangerous, true);
    }

    /**
     * Enhanced class name validation
     */
    private function isValidClassName(string $className): bool
    {
        return strlen($className) <= 200 &&
            preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $className) === 1 &&
            !in_array(strtolower($className), ['class', 'interface', 'trait', 'enum'], true);
    }

    /**
     * Enhanced file reading with better error handling
     */
    private function readFileSecurely(string $filePath): ?string
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return null;
        }

        // Additional security check
        $realPath = realpath($filePath);
        if ($realPath === false || !$this->isSecurePath($realPath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false || !$this->isValidPhpContent($content)) {
            return null;
        }

        return $content;
    }

    /**
     * Get cache efficiency metrics
     */
    public function getCacheEfficiency(): array
    {
        return [
            'file_cache_size' => count($this->fileCache),
            'class_cache_size' => count($this->classCache),
            'exclusion_cache_size' => count($this->exclusionCache),
            'cache_hit_ratio' => $this->cacheHitRatio,
            'memory_per_cached_file' => count($this->fileCache) > 0
                ? memory_get_usage(true) / count($this->fileCache)
                : 0,
        ];
    }
}