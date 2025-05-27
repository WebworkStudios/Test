<?php

declare(strict_types=1);

namespace Framework\Routing;

use Framework\Routing\Attributes\Route;
use ReflectionClass;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Route Discovery Engine for attribute-based route registration
 */
final readonly class RouteDiscovery
{
    public function __construct(
        private Router $router
    ) {}

    /**
     * Discover and register routes from given directories
     * 
     * @param array<string> $directories Directories to scan for actions
     */
    public function discover(array $directories): void
    {
        foreach ($directories as $directory) {
            $this->scanDirectory($directory);
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
     * Scan directory for PHP files with action classes
     */
    private function scanDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $this->processFile($file->getPathname());
        }
    }

    /**
     * Process PHP file for action classes
     */
    private function processFile(string $filePath): void
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return;
        }

        $classes = $this->extractClassNames($content);
        
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

        // Extract namespace
        if (preg_match('/^namespace\s+([^;]+);/m', $content, $nsMatch)) {
            $namespace = trim($nsMatch[1]) . '\\';
        }

        // Extract class declarations
        $pattern = '/^(?:final\s+)?(?:readonly\s+)?(?:abstract\s+)?class\s+(\w+)/m';
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
            /** @var Route $route */
            $route = $attribute->newInstance();
            
            $this->router->addRoute(
                $route->method,
                $route->path,
                $reflection->getName(),
                $route->middleware,
                $route->name
            );
        }
    }
}