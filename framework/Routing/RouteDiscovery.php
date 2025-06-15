<?php

declare(strict_types=1);

namespace Framework\Routing;

use Exception;
use Framework\Routing\Attributes\Route;
use InvalidArgumentException;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionException;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * Optimized route discovery with PHP 8.4 features
 */
final class RouteDiscovery
{
    // PHP 8.4 Property Hooks for computed properties
    public int $maxDepth {
        get => $this->config['max_depth'] ?? 10;
    }

    public bool $strictMode {
        get => $this->config['strict_mode'] ?? true;
    }

    public int $processedFiles {
        get => $this->processedFiles;
    }

    public int $discoveredRoutes {
        get => $this->discoveredRoutes;
    }

    private array $classCache = [];

    public function __construct(
        private readonly Router           $router,
        private readonly RouteFileScanner $scanner,
        private readonly array            $ignoredDirectories = [
            'vendor', 'node_modules', '.git', 'storage', 'cache', 'tests',
            'build', 'dist', 'coverage', '.idea', '.vscode', 'tmp', 'temp'
        ],
        private readonly array            $config = []
    )
    {
        $this->validateConfiguration();
    }

    /**
     * Validate configuration
     */
    private function validateConfiguration(): void
    {
        if ($this->maxDepth < 1 || $this->maxDepth > 20) {
            throw new InvalidArgumentException('Invalid max depth: must be between 1 and 20');
        }

        // ✅ Validierung lockern für leere Arrays
        foreach ($this->ignoredDirectories as $dir) {
            if (!is_string($dir) || strlen($dir) > 100) {
                throw new InvalidArgumentException('Invalid ignored directory specification');
            }
        }
    }

    /**
     * Create with default scanner
     */
    public static function create(Router $router, array $config = []): self
    {
        $scanner = new RouteFileScanner($config);
        return new self($router, $scanner, config: $config);
    }

    /**
     * Check if class has route attributes
     */
    public function hasRouteAttributes(string $className): bool
    {
        if (!class_exists($className)) {
            return false;
        }

        try {
            $reflection = new ReflectionClass($className);
            return !empty($reflection->getAttributes(Route::class));
        } catch (ReflectionException) {
            return false;
        }
    }

    /**
     * Clear all caches
     */
    public function clearCache(): void
    {
        $this->classCache = [];
        $this->scanner->clearCache();
        $this->resetCounters();
    }

    /**
     * Reset performance counters
     */
    private function resetCounters(): void
    {
        $this->processedFiles = 0;
        $this->discoveredRoutes = 0;
    }

    /**
     * Get cache efficiency metrics
     */
    public function getCacheEfficiency(): array
    {
        $scannerEfficiency = $this->scanner->getCacheEfficiency();

        return [
            'class_cache_size' => count($this->classCache),
            'class_success_rate' => count($this->classCache) > 0
                ? (count(array_filter($this->classCache)) / count($this->classCache)) * 100
                : 0,
            'scanner_efficiency' => $scannerEfficiency,
            'memory_per_class' => count($this->classCache) > 0
                ? memory_get_usage(true) / count($this->classCache)
                : 0,
        ];
    }

    /**
     * Auto-discover with default paths
     */
    public function autoDiscover(): void
    {
        $defaultPaths = [
            'app/Actions',
            'app/Controllers',
            'app/Http/Actions',
            'app/Http/Controllers',
            'src/Actions',
            'src/Controllers'
        ];

        $existingPaths = array_filter($defaultPaths, 'is_dir');

        if (empty($existingPaths)) {
            throw new RuntimeException('No default discovery paths found');
        }

        $this->discover($existingPaths);
    }

    /**
     * Discover routes in specified directories
     */
    public function discover(array $directories): void
    {
        $this->validateDirectories($directories);
        $this->resetCounters();

        foreach ($directories as $directory) {
            $this->scanDirectory($directory);
        }
    }

    /**
     * Validate directories input
     */
    private function validateDirectories(array $directories): void
    {
        if (empty($directories)) {
            throw new InvalidArgumentException('At least one directory must be specified');
        }

        if (count($directories) > 20) {
            throw new InvalidArgumentException('Too many directories to scan (max 20)');
        }

        foreach ($directories as $directory) {
            if (!is_string($directory)) {
                throw new InvalidArgumentException('Directory must be a string');
            }

            if (strlen($directory) > 500) {
                throw new InvalidArgumentException('Directory path too long');
            }

            if (str_contains($directory, "\0") || (!str_contains($directory, '*') && str_contains($directory, '..'))) {
                throw new InvalidArgumentException('Directory path contains invalid characters');
            }
        }
    }

    /**
     * Scan single directory for routes
     */
    private function scanDirectory(string $directory): void
    {
        if (str_contains($directory, '*')) {
            $this->processGlobPattern($directory);
            return;
        }

        if (!is_dir($directory)) {
            if ($this->strictMode) {
                throw new InvalidArgumentException("Directory does not exist: {$directory}");
            }
            return;
        }

        $realDirectory = realpath($directory);
        if ($realDirectory === false || !$this->isSecurePath($realDirectory)) {
            if ($this->strictMode) {
                throw new InvalidArgumentException("Invalid or insecure directory: {$directory}");
            }
            return;
        }

        try {
            $this->scanWithIterator($realDirectory);
        } catch (Exception $e) {
            if ($this->strictMode) {
                throw $e;
            }
            error_log("Error scanning directory {$directory}: " . $e->getMessage());
        }
    }

    /**
     * Process glob pattern
     */
    private function processGlobPattern(string $pattern): void
    {
        $matches = glob($pattern, GLOB_ONLYDIR | GLOB_NOSORT);
        if ($matches === false) {
            return;
        }

        // Limit number of directories
        $matches = array_slice($matches, 0, 50);

        foreach ($matches as $match) {
            $this->scanDirectory($match);
        }
    }

    /**
     * Check if path is secure
     */
    private function isSecurePath(string $path): bool
    {
        // Basic security check
        if (str_contains($path, '..') || str_contains($path, "\0")) {
            return false;
        }

        // Check if path is within allowed boundaries (if strict mode)
        if ($this->strictMode) {
            $cwd = realpath(getcwd());
            if ($cwd === false || !str_starts_with($path, $cwd)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Scan directory using optimized iterator
     */
    private function scanWithIterator(string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                $this->createFileFilter(...)
            ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $iterator->setMaxDepth($this->maxDepth);

        $batchSize = 50;
        $batch = [];

        foreach ($iterator as $file) {
            if ($this->processedFiles >= 5000) { // Reasonable limit
                break;
            }

            if ($file->isFile() && $file->getExtension() === 'php') {
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
        $allClasses = $this->scanner->scanFiles($filePaths);

        foreach ($allClasses as $className) {
            $this->registerClass($className);
        }

        $this->processedFiles += count($filePaths);
    }

    /**
     * Register discovered class with router
     */
    public function registerClass(string $className): void
    {
        // Check cache first
        if (isset($this->classCache[$className])) {
            return;
        }

        if (!$this->scanner->validateClass($className)) {
            $this->classCache[$className] = false;
            return;
        }

        try {
            $reflection = new ReflectionClass($className);
            $this->processRouteAttributes($reflection);
            $this->classCache[$className] = true;

        } catch (Throwable $e) {
            $this->classCache[$className] = false;

            if ($this->strictMode) {
                throw $e;
            }
            error_log("Failed to register class {$className}: " . $e->getMessage());
        }
    }

    /**
     * Process route attributes for class
     */
    private function processRouteAttributes(ReflectionClass $reflection): void
    {
        $attributes = $reflection->getAttributes(Route::class);

        foreach ($attributes as $attribute) {
            try {
                /** @var Route $route */
                $route = $attribute->newInstance();

                // Register main route
                $this->registerRoute($route, $reflection->getName());

                // Register additional methods if specified
                foreach ($route->methods as $additionalMethod) {
                    $additionalRoute = new Route(
                        $additionalMethod,
                        $route->path,
                        $route->middleware,
                        $route->name ? $route->name . '.' . strtolower($additionalMethod) : null,
                        $route->subdomain,
                        $route->options,
                        $route->schemes
                    );
                    $this->registerRoute($additionalRoute, $reflection->getName());
                }

                $this->discoveredRoutes++;

            } catch (Throwable $e) {
                if ($this->strictMode) {
                    throw $e;
                }
                error_log("Failed to process route for {$reflection->getName()}: " . $e->getMessage());
            }
        }
    }

    /**
     * Register single route with router
     */
    private function registerRoute(Route $route, string $actionClass): void
    {
        $this->router->addRoute(
            $route->method,
            $route->path,
            $actionClass,
            $route->middleware,
            $route->name,
            $route->subdomain
        );
    }

    /**
     * Discover routes with pattern matching
     */
    public function discoverWithPattern(string $baseDir, string $pattern = '**/*{Action,Controller}.php'): void
    {
        if (!is_dir($baseDir)) {
            throw new InvalidArgumentException("Base directory does not exist: {$baseDir}");
        }

        $fullPattern = rtrim($baseDir, '/') . '/' . ltrim($pattern, '/');
        $files = glob($fullPattern, GLOB_BRACE);

        if ($files === false) {
            throw new RuntimeException("Failed to execute glob pattern: {$fullPattern}");
        }

        $this->discoverInFiles($files);
    }

    /**
     * Discover routes in specific files
     */
    public function discoverInFiles(array $filePaths): void
    {
        $this->validateFilePaths($filePaths);
        $this->resetCounters();

        $this->processBatch($filePaths);
    }

    /**
     * Validate file paths input
     */
    private function validateFilePaths(array $filePaths): void
    {
        if (empty($filePaths)) {
            throw new InvalidArgumentException('At least one file path must be specified');
        }

        if (count($filePaths) > 100) {
            throw new InvalidArgumentException('Too many files to scan (max 100)');
        }

        foreach ($filePaths as $filePath) {
            if (!is_string($filePath)) {
                throw new InvalidArgumentException('File path must be a string');
            }

            if (strlen($filePath) > 500) {
                throw new InvalidArgumentException('File path too long');
            }

            if (str_contains($filePath, "\0") || str_contains($filePath, '..')) {
                throw new InvalidArgumentException('File path contains invalid characters');
            }
        }
    }

    /**
     * Get discovered routes summary
     */
    public function getDiscoveredRoutes(): array
    {
        return [
            'total_routes' => $this->discoveredRoutes,
            'routes_per_file' => $this->processedFiles > 0
                ? round($this->discoveredRoutes / $this->processedFiles, 2)
                : 0,
            'successful_classes' => count(array_filter($this->classCache)),
            'failed_classes' => count(array_filter($this->classCache, fn($success) => !$success)),
        ];
    }

    /**
     * Magic method for debugging
     */
    public function __debugInfo(): array
    {
        return [
            'processed_files' => $this->processedFiles,
            'discovered_routes' => $this->discoveredRoutes,
            'cached_classes' => count($this->classCache),
            'strict_mode' => $this->strictMode,
            'max_depth' => $this->maxDepth,
            'scanner_stats' => $this->scanner->getStats(),
        ];
    }

    /**
     * Get discovery statistics
     */
    public function getStats(): array
    {
        $scannerStats = $this->scanner->getStats();

        return [
            'processed_files' => $this->processedFiles,
            'discovered_routes' => $this->discoveredRoutes,
            'cached_classes' => count($this->classCache),
            'successful_classes' => count(array_filter($this->classCache)),
            'failed_classes' => count(array_filter($this->classCache, fn($success) => !$success)),
            'scanner_stats' => $scannerStats,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'strict_mode' => $this->strictMode,
            'max_depth' => $this->maxDepth,
        ];
    }

    /**
     * Create file filter for iterator
     */
    private function createFileFilter(SplFileInfo $file, string $key, RecursiveIterator $iterator): bool
    {
        $filename = $file->getFilename();

        // Fast exclusion checks
        if (str_starts_with($filename, '.') ||
            str_starts_with($filename, '_') ||
            str_starts_with($filename, '#')) {
            return false;
        }

        if ($file->isDir()) {
            return $this->isAllowedDirectory($filename);
        }

        // File validation
        return $file->getExtension() === 'php' &&
            $file->isReadable() &&
            $file->getSize() > 0 &&
            $file->getSize() <= 2097152; // 2MB limit
    }

    /**
     * Check if directory is allowed
     */
    private function isAllowedDirectory(string $dirname): bool
    {
        $lowerName = strtolower($dirname);

        // Check ignored directories
        if (in_array($lowerName, array_map('strtolower', $this->ignoredDirectories), true)) {
            return false;
        }

        // Additional security checks
        $dangerousDirs = ['tmp', 'temp', 'log', 'logs', 'backup', 'backups'];
        if (in_array($lowerName, $dangerousDirs, true)) {
            return false;
        }

        return $this->isSecureDirectoryName($dirname);
    }

    /**
     * Check if directory name is secure
     */
    private function isSecureDirectoryName(string $dirname): bool
    {
        // Dangerous directory names
        $dangerous = [
            'bin', 'sbin', 'etc', 'proc', 'dev', 'sys',
            'admin', 'config', 'secret', 'private', 'hidden'
        ];

        return !in_array(strtolower($dirname), $dangerous, true);
    }
}