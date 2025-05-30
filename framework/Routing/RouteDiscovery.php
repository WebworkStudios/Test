<?php

declare(strict_types=1);

namespace Framework\Routing;

use Framework\Routing\Attributes\Route;
use ReflectionClass;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveCallbackFilterIterator;

/**
 * Route Discovery Engine for attribute-based route registration
 */
final class RouteDiscovery
{
    private array $classCache;
    private array $ignoredDirectories;

    public function __construct(
        private Router $router,
        array $ignoredDirectories = ['vendor', 'node_modules', '.git', 'storage', 'cache', 'tests']
    ) {
        $this->classCache = [];
        $this->ignoredDirectories = $ignoredDirectories;
    }

    /**
     * Discover and register routes from given directories
     *
     * @param array<string> $directories Directories to scan for actions
     */
    public function discover(array $directories): void
    {
        foreach ($directories as $directory) {
            $this->scanDirectoryOptimized($directory);
        }
    }

    /**
     * Register a specific class if it has route attributes
     */
    public function registerClass(string $className): void
    {
        if (!class_exists($className)) {
            return;
        }

        try {
            $reflection = new ReflectionClass($className);
            $this->processRouteAttributes($reflection);
        } catch (\ReflectionException) {
            // Skip classes that can't be reflected
        }
    }

    /**
     * Optimized directory scanning with filtering
     */
    private function scanDirectoryOptimized(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        // Verwende RecursiveCallbackFilterIterator für bessere Performance
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                function ($file, $key, $iterator) {
                    // Ignoriere bekannte Non-PHP-Verzeichnisse
                    if ($iterator->hasChildren() && in_array($file->getFilename(), $this->ignoredDirectories)) {
                        return false;
                    }

                    // Ignoriere versteckte Verzeichnisse
                    if ($iterator->hasChildren() && str_starts_with($file->getFilename(), '.')) {
                        return false;
                    }

                    return $file->isDir() || $file->getExtension() === 'php';
                }
            )
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $this->processFileOptimized($file->getPathname());
            }
        }
    }

    /**
     * Optimized file processing with caching
     */
    private function processFileOptimized(string $filePath): void
    {
        // Cache für bereits verarbeitete Dateien basierend auf Datei-Hash
        $fileHash = hash_file('md5', $filePath);
        if (isset($this->classCache[$fileHash])) {
            foreach ($this->classCache[$fileHash] as $className) {
                $this->registerClass($className);
            }
            return;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return;
        }

        // Schnelle Vorprüfung: Hat die Datei überhaupt Route-Attribute?
        if (!str_contains($content, '#[Route') && !str_contains($content, 'Route(')) {
            return;
        }

        $classes = $this->extractClassNames($content);
        $this->classCache[$fileHash] = $classes;

        foreach ($classes as $className) {
            $this->registerClass($className);
        }
    }

    /**
     * Extract fully qualified class names from PHP file content
     *
     * @return array<string>
     */
    private function extractClassNames(string $content): array
    {
        $classes = [];
        $namespace = '';

        // Extract namespace - verbesserte Regex
        if (preg_match('/^\s*namespace\s+([^;]+);/m', $content, $nsMatch)) {
            $namespace = trim($nsMatch[1]) . '\\';
        }

        // Extract class declarations - erweiterte Regex für alle Klassen-Typen
        $pattern = '/^\s*(?:final\s+)?(?:readonly\s+)?(?:abstract\s+)?(?:class|interface|trait|enum)\s+(\w+)/m';
        if (preg_match_all($pattern, $content, $matches)) {
            foreach ($matches[1] as $className) {
                $classes[] = $namespace . $className;
            }
        }

        return $classes;
    }

    /**
     * Process Route attributes on a class
     */
    private function processRouteAttributes(ReflectionClass $reflection): void
    {
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
                    $route->name
                );
            } catch (\Throwable $e) {
                // Log error but continue processing other routes
                error_log("Failed to register route for class {$reflection->getName()}: " . $e->getMessage());
            }
        }
    }

    /**
     * Get statistics about discovery process
     */
    public function getStats(): array
    {
        return [
            'cached_files' => count($this->classCache),
            'ignored_directories' => $this->ignoredDirectories
        ];
    }
}