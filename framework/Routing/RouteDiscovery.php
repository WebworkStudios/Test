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
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * ✅ OPTIMIZED: Route discovery mit PHP 8.4 Features, batching und memory management
 */
final class RouteDiscovery
{
    // PHP 8.4 Property Hooks for computed properties
    private const int MAX_CACHE_SIZE = 200;

    public int $maxDepth {
        get => $this->config['max_depth'] ?? 10;
    }

    public bool $strictMode {
        get => $this->config['strict_mode'] ?? false; // ✅ Default false for better compatibility
    }

    public int $processedFiles {
        get => $this->processedFiles;
    }

    public int $discoveredRoutes {
        get => $this->discoveredRoutes;
    }

    // ✅ OPTIMIZED: Smaller cache with LRU
    public float $successRate {
        get => $this->processedFiles > 0
            ? (count(array_filter($this->classCache)) / count($this->classCache)) * 100
            : 0.0;
    }

    private array $classCache = []; // Reduced from unlimited

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
     * ✅ OPTIMIZED: Lightweight validation mit PHP 8.4
     */
    private function validateConfiguration(): void
    {
        if ($this->maxDepth < 1 || $this->maxDepth > 20) {
            throw new InvalidArgumentException('Max depth must be between 1 and 20');
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
     * ✅ OPTIMIZED: Auto-discover mit PHP 8.4 Array-Funktionen
     */
    public function autoDiscover(): void
    {
        $defaultPaths = [
            'app/Actions',
            'app/Controllers'
        ];

        // ✅ PHP 8.4: Prüfe ob mindestens ein Pfad existiert
        if (!array_any($defaultPaths, fn($path) => is_dir($path) && is_readable($path))) {
            if (!$this->strictMode) {
                return; // Graceful failure in non-strict mode
            }
            throw new RuntimeException('No valid discovery paths found');
        }

        // ✅ PHP 8.4: Filtere gültige Pfade effizienter
        $existingPaths = array_filter($defaultPaths, fn($path) => is_dir($path) && is_readable($path));

        $this->discover($existingPaths);
    }

    /**
     * ✅ OPTIMIZED: Discover mit PHP 8.4 Verbesserungen
     */
    public function discover(array $directories): void
    {
        $this->validateDirectories($directories);
        $this->resetCounters();

        // ✅ PHP 8.4: Elegante Validierung aller Verzeichnisse
        if (!array_any($directories, fn($dir) => is_dir($dir) && is_readable($dir))) {
            if ($this->strictMode) {
                throw new RuntimeException('No accessible directories found');
            }
            return;
        }

        foreach ($directories as $directory) {
            try {
                $this->scanDirectory($directory);
            } catch (Throwable $e) {
                if ($this->strictMode) {
                    throw $e;
                }
                error_log("Discovery warning for {$directory}: " . $e->getMessage());
            }
        }

        // ✅ Trigger garbage collection after discovery
        if ($this->processedFiles > 100) {
            gc_collect_cycles();
        }
    }

    /**
     * ✅ OPTIMIZED: Streamlined directory validation mit PHP 8.4
     */
    private function validateDirectories(array $directories): void
    {
        if (empty($directories)) {
            throw new InvalidArgumentException('At least one directory must be specified');
        }

        if (count($directories) > 50) { // Increased limit
            throw new InvalidArgumentException('Too many directories to scan (max 50)');
        }

        // ✅ PHP 8.4: Elegante Validierung aller Pfade
        if (!array_all($directories, 'is_string')) {
            throw new InvalidArgumentException('All directory paths must be strings');
        }

        // ✅ PHP 8.4: Prüfe auf zu lange Pfade
        $tooLongPath = array_find($directories, fn($dir) => strlen($dir) > 500);
        if ($tooLongPath !== null) {
            throw new InvalidArgumentException("Directory path too long: {$tooLongPath}");
        }

        // ✅ PHP 8.4: Prüfe auf gefährliche Zeichen
        $dangerousPath = array_find($directories, fn($dir) =>
            str_contains($dir, "\0") || str_contains($dir, '..')
        );
        if ($dangerousPath !== null) {
            throw new InvalidArgumentException("Directory path contains invalid characters: {$dangerousPath}");
        }
    }

    /**
     * ✅ OPTIMIZED: Directory scanning with early returns
     */
    private function scanDirectory(string $directory): void
    {
        if (str_contains($directory, '*')) {
            $this->processGlobPattern($directory);
            return;
        }

        if (!is_dir($directory) || !is_readable($directory)) {
            if ($this->strictMode) {
                throw new InvalidArgumentException("Directory not accessible: {$directory}");
            }
            return;
        }

        $realDirectory = realpath($directory);
        if ($realDirectory === false || !$this->isSecurePath($realDirectory)) {
            if ($this->strictMode) {
                throw new InvalidArgumentException("Invalid directory: {$directory}");
            }
            return;
        }

        $this->scanWithIterator($realDirectory);
    }

    /**
     * ✅ OPTIMIZED: Faster glob processing mit PHP 8.4
     */
    private function processGlobPattern(string $pattern): void
    {
        $matches = glob($pattern, GLOB_ONLYDIR | GLOB_NOSORT);
        if ($matches === false) {
            return;
        }

        // ✅ PHP 8.4: Finde erstes gültiges Verzeichnis
        $firstValid = array_find($matches, fn($match) => is_dir($match) && is_readable($match));
        if ($firstValid === null && $this->strictMode) {
            throw new RuntimeException("No valid directories found in pattern: {$pattern}");
        }

        // Limit and sort for predictable results
        $matches = array_slice($matches, 0, 20);
        sort($matches);

        foreach ($matches as $match) {
            $this->scanDirectory($match);
        }
    }

    /**
     * ✅ OPTIMIZED: Basic security check
     */
    private function isSecurePath(string $path): bool
    {
        return !str_contains($path, '..') && !str_contains($path, "\0");
    }

    /**
     * ✅ OPTIMIZED: Iterator with memory management
     */
    private function scanWithIterator(string $directory): void
    {
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                    $this->createFileFilter(...)
                ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            $iterator->setMaxDepth($this->maxDepth);

            // ✅ Smaller batches for better memory usage
            $batchSize = 25;
            $batch = [];

            foreach ($iterator as $file) {
                if ($this->processedFiles >= 2000) { // Reasonable limit
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

        } catch (Exception $e) {
            if ($this->strictMode) {
                throw $e;
            }
            error_log("Iterator error in {$directory}: " . $e->getMessage());
        }
    }

    /**
     * ✅ OPTIMIZED: Batch processing with error isolation
     */
    private function processBatch(array $filePaths): void
    {
        $allClasses = $this->scanner->scanFiles($filePaths);

        foreach ($allClasses as $className) {
            try {
                $this->registerClass($className);
            } catch (Throwable $e) {
                if ($this->strictMode) {
                    throw $e;
                }
                // Silently skip invalid classes in non-strict mode
            }
        }

        $this->processedFiles += count($filePaths);
    }

    /**
     * ✅ OPTIMIZED: Class registration with caching
     */
    public function registerClass(string $className): void
    {
        // Check cache first
        if (isset($this->classCache[$className])) {
            return;
        }

        if (!$this->scanner->validateClass($className)) {
            $this->cacheResult($className, false);
            return;
        }

        try {
            $reflection = new ReflectionClass($className);
            $registered = $this->processRouteAttributes($reflection);
            $this->cacheResult($className, $registered);

            if ($registered) {
                $this->discoveredRoutes++;
            }

        } catch (Throwable $e) {
            $this->cacheResult($className, false);
            if ($this->strictMode) {
                throw $e;
            }
        }
    }

    /**
     * ✅ OPTIMIZED: LRU cache with size limit
     */
    private function cacheResult(string $className, bool $result): bool
    {
        // Implement simple LRU
        if (count($this->classCache) >= self::MAX_CACHE_SIZE) {
            // Remove first (oldest) entry
            $oldestKey = array_key_first($this->classCache);
            unset($this->classCache[$oldestKey]);
        }

        $this->classCache[$className] = $result;
        return $result;
    }

    /**
     * ✅ OPTIMIZED: Route attribute processing
     */
    private function processRouteAttributes(ReflectionClass $reflection): bool
    {
        $attributes = $reflection->getAttributes(Route::class);

        if (empty($attributes)) {
            return false;
        }

        $registered = false;

        foreach ($attributes as $attribute) {
            try {
                /** @var Route $route */
                $route = $attribute->newInstance();

                // Register main route
                $this->registerRoute($route, $reflection->getName());
                $registered = true;

                // ✅ Register additional methods more efficiently
                if (!empty($route->methods)) {
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
                }

                $this->discoveredRoutes++;

            } catch (Throwable $e) {
                if ($this->strictMode) {
                    throw $e;
                }
                // Skip invalid route in non-strict mode
            }
        }

        return $registered;
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
     * ✅ OPTIMIZED: Pattern discovery with limits and PHP 8.4
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

        // ✅ PHP 8.4: Finde erstes PHP-File mit Routes
        $hasRouteFiles = array_any($files, fn($file) =>
            str_ends_with($file, '.php') && $this->hasRouteAttributes($file)
        );

        if (!$hasRouteFiles && $this->strictMode) {
            throw new RuntimeException("No route files found with pattern: {$fullPattern}");
        }

        // Limit file count
        $files = array_slice($files, 0, 500);
        $this->discoverInFiles($files);
    }

    /**
     * ✅ OPTIMIZED: File discovery with validation and PHP 8.4
     */
    public function discoverInFiles(array $filePaths): void
    {
        $this->validateFilePaths($filePaths);
        $this->resetCounters();

        // ✅ PHP 8.4: Validiere alle Dateipfade elegant
        if (!array_all($filePaths, fn($path) => is_string($path) && str_ends_with($path, '.php'))) {
            throw new InvalidArgumentException('All file paths must be PHP files');
        }

        // ✅ PHP 8.4: Prüfe ob mindestens eine Datei existiert
        if (!array_any($filePaths, 'file_exists')) {
            if ($this->strictMode) {
                throw new RuntimeException('No existing files found');
            }
            return;
        }

        // Process in smaller batches
        $batches = array_chunk($filePaths, 50);
        foreach ($batches as $batch) {
            $this->processBatch($batch);
        }
    }

    /**
     * ✅ OPTIMIZED: Streamlined file path validation mit PHP 8.4
     */
    private function validateFilePaths(array $filePaths): void
    {
        if (empty($filePaths)) {
            throw new InvalidArgumentException('At least one file path must be specified');
        }

        if (count($filePaths) > 500) { // Increased limit
            throw new InvalidArgumentException('Too many files to scan (max 500)');
        }

        // ✅ PHP 8.4: Prüfe auf ungültige Pfade
        $invalidPath = array_find($filePaths, fn($path) =>
            !is_string($path) ||
            strlen($path) > 500 ||
            str_contains($path, "\0") ||
            str_contains($path, '..')
        );

        if ($invalidPath !== null) {
            throw new InvalidArgumentException("Invalid file path: {$invalidPath}");
        }
    }

    /**
     * ✅ PHP 8.4: Neue Hilfsmethoden mit Array-Funktionen
     */

    /**
     * Finde erstes Verzeichnis mit Route-Dateien
     */
    public function findDirectoryWithRoutes(array $directories): ?string
    {
        return array_find($directories, function($dir) {
            if (!is_dir($dir)) return false;

            $phpFiles = glob($dir . '/*.php') ?: [];
            return array_any($phpFiles, fn($file) => $this->hasRouteAttributes($file));
        });
    }

    /**
     * Prüfe ob alle Verzeichnisse Route-Dateien haben
     */
    public function allDirectoriesHaveRoutes(array $directories): bool
    {
        return array_all($directories, function($dir) {
            if (!is_dir($dir)) return false;

            $phpFiles = glob($dir . '/*.php') ?: [];
            return array_any($phpFiles, fn($file) => $this->hasRouteAttributes($file));
        });
    }

    /**
     * Finde erste gültige Klasse
     */
    public function findFirstValidClass(array $classes): ?string
    {
        return array_find($classes, fn($class) =>
            class_exists($class) &&
            $this->scanner->validateClass($class)
        );
    }

    /**
     * Get discovery statistics
     */
    public function getStats(): array
    {
        return [
            'processed_files' => $this->processedFiles,
            'discovered_routes' => $this->discoveredRoutes,
            'cached_classes' => count($this->classCache),
            'success_rate' => round($this->successRate, 1),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'strict_mode' => $this->strictMode,
            'max_depth' => $this->maxDepth,
            'scanner_stats' => $this->scanner->getStats(),
        ];
    }

    /**
     * Get discovered services summary
     */
    public function getDiscoveredServices(): array
    {
        return [
            'total_services' => $this->discoveredRoutes,
            'services_per_file' => $this->processedFiles > 0
                ? round($this->discoveredRoutes / $this->processedFiles, 2)
                : 0,
            'successful_classes' => count(array_filter($this->classCache)),
            'failed_classes' => count(array_filter($this->classCache, fn($success) => !$success)),
            'success_rate' => $this->successRate
        ];
    }

    /**
     * ✅ Prüfe ob Datei Route-Attribute enthält (ersetzt fileHasRoutes)
     */
    private function hasRouteAttributes(string $filePath): bool
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return false;
        }

        try {
            $content = file_get_contents($filePath);
            if ($content === false) {
                return false;
            }

            // Schnelle String-Suche nach Route-Attributen
            return str_contains($content, '#[Route(') ||
                str_contains($content, '#[Route ') ||
                str_contains($content, 'use Framework\Routing\Attributes\Route');

        } catch (Throwable) {
            return false;
        }
    }
    public function __debugInfo(): array
    {
        return [
            'processed_files' => $this->processedFiles,
            'discovered_routes' => $this->discoveredRoutes,
            'cached_classes' => count($this->classCache),
            'success_rate' => round($this->successRate, 2) . '%',
            'strict_mode' => $this->strictMode,
            'max_depth' => $this->maxDepth,
        ];
    }

    /**
     * ✅ OPTIMIZED: Simple file filter
     */
    private function createFileFilter(SplFileInfo $file, string $key, RecursiveIterator $iterator): bool
    {
        $filename = $file->getFilename();

        // Fast exclusion checks
        if (str_starts_with($filename, '.') || str_starts_with($filename, '_')) {
            return false;
        }

        if ($file->isDir()) {
            return $this->isAllowedDirectory($filename);
        }

        // File validation
        return $file->getExtension() === 'php' &&
            $file->isReadable() &&
            $file->getSize() > 10 &&
            $file->getSize() <= 1048576; // 1MB limit
    }

    /**
     * ✅ OPTIMIZED: Fast directory check
     */
    private function isAllowedDirectory(string $dirname): bool
    {
        $lowerName = strtolower($dirname);
        return !in_array($lowerName, $this->ignoredDirectories, true);
    }
}