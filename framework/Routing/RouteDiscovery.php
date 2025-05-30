<?php

declare(strict_types=1);

namespace Framework\Routing;

use Framework\Routing\Attributes\Route;
use ReflectionClass;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveCallbackFilterIterator;

/**
 * Route Discovery Engine with PHP 8.4 features and enhanced security
 */
final class RouteDiscovery
{
    private array $classCache = [];
    private int $processedFiles = 0;
    private int $discoveredRoutes = 0;

    // PHP 8.4 Property Hooks für bessere API
    public int $maxFileSize {
        get => $this->config['max_file_size'] ?? 1048576; // 1MB
    }

    public int $maxDepth {
        get => $this->config['max_depth'] ?? 10;
    }

    public bool $strictMode {
        get => $this->config['strict_mode'] ?? true;
    }

    public function __construct(
        private readonly Router $router,
        private readonly array $ignoredDirectories = ['vendor', 'node_modules', '.git', 'storage', 'cache', 'tests'],
        private readonly array $config = []
    ) {
        $this->validateConfiguration();
    }

    /**
     * Discover routes from directories with PHP 8.4 optimizations
     */
    public function discover(array $directories): void
    {
        $this->validateDirectories($directories);

        $this->processedFiles = 0;
        $this->discoveredRoutes = 0;

        foreach ($directories as $directory) {
            $this->scanDirectory($directory);
        }
    }

    /**
     * Register single class with route attributes
     */
    public function registerClass(string $className): void
    {
        if (!$this->isValidClassName($className) || !class_exists($className)) {
            return;
        }

        try {
            $reflection = new ReflectionClass($className);
            $this->processRouteAttributes($reflection);
        } catch (\Throwable $e) {
            if ($this->strictMode) {
                throw $e;
            }
            error_log("Failed to register class {$className}: " . $e->getMessage());
        }
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
            'memory_usage' => memory_get_usage(true),
            'strict_mode' => $this->strictMode
        ];
    }

    /**
     * Clear discovery cache
     */
    public function clearCache(): void
    {
        $this->classCache = [];
        $this->processedFiles = 0;
        $this->discoveredRoutes = 0;
    }

    /**
     * Validate configuration
     */
    private function validateConfiguration(): void
    {
        if ($this->maxFileSize < 1024 || $this->maxFileSize > 10485760) {
            throw new \InvalidArgumentException('Invalid max file size: must be between 1KB and 10MB');
        }

        if ($this->maxDepth < 1 || $this->maxDepth > 20) {
            throw new \InvalidArgumentException('Invalid max depth: must be between 1 and 20');
        }
    }

    /**
     * Validate directories for security
     */
    private function validateDirectories(array $directories): void
    {
        foreach ($directories as $directory) {
            if (!is_string($directory) || strlen($directory) > 255) {
                throw new \InvalidArgumentException('Invalid directory path');
            }

            // Security: Prevent directory traversal
            if (str_contains($directory, '..') || str_contains($directory, "\0")) {
                throw new \InvalidArgumentException('Directory path contains invalid characters');
            }

            // Security: Basic path validation
            if (!preg_match('/^[a-zA-Z0-9_\-\/\\\\*]+$/', $directory)) {
                throw new \InvalidArgumentException('Directory contains invalid characters');
            }
        }
    }

    /**
     * Scan directory for PHP files with route attributes
     */
    private function scanDirectory(string $directory): void
    {
        // Handle glob patterns
        if (str_contains($directory, '*')) {
            $this->processGlobPattern($directory);
            return;
        }

        if (!is_dir($directory)) {
            return;
        }

        $realDirectory = realpath($directory);
        if ($realDirectory === false || !$this->isSecurePath($realDirectory)) {
            return;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($realDirectory, RecursiveDirectoryIterator::SKIP_DOTS),
                    $this->createFileFilter(...)
                ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            $iterator->setMaxDepth($this->maxDepth);

            foreach ($iterator as $file) {
                if ($this->processedFiles >= 1000) { // Prevent DoS
                    break;
                }

                if ($file->isFile() && $this->isValidPhpFile($file)) {
                    $this->processFile($file->getPathname());
                    $this->processedFiles++;
                }
            }
        } catch (\Exception $e) {
            if ($this->strictMode) {
                throw $e;
            }
            error_log("Error scanning directory {$directory}: " . $e->getMessage());
        }
    }

    /**
     * Process glob patterns
     */
    private function processGlobPattern(string $pattern): void
    {
        $matches = glob($pattern, GLOB_ONLYDIR);
        if ($matches === false) {
            return;
        }

        foreach (array_slice($matches, 0, 50) as $match) { // Limit results
            $this->scanDirectory($match);
        }
    }

    /**
     * File filter callback using PHP 8.4 first-class callable syntax
     */
    private function createFileFilter(\SplFileInfo $file, string $key, \RecursiveCallbackFilterIterator $iterator): bool
    {
        $filename = $file->getFilename();

        // Skip hidden files and ignored directories
        if (str_starts_with($filename, '.') ||
            ($file->isDir() && in_array($filename, $this->ignoredDirectories, true))) {
            return false;
        }

        // For directories: additional security checks
        if ($file->isDir()) {
            return $this->isSecureDirectoryName($filename);
        }

        // For files: only PHP files
        return $file->getExtension() === 'php';
    }

    /**
     * Check if directory name is secure
     */
    private function isSecureDirectoryName(string $dirname): bool
    {
        $dangerous = ['bin', 'sbin', 'etc', 'tmp', 'temp', 'admin', 'config', 'secret'];
        return !in_array(strtolower($dirname), $dangerous, true);
    }

    /**
     * Validate PHP file
     */
    private function isValidPhpFile(\SplFileInfo $file): bool
    {
        return $file->isReadable() &&
            $file->getSize() <= $this->maxFileSize &&
            $file->getExtension() === 'php';
    }

    /**
     * Check if path is secure
     */
    private function isSecurePath(string $path): bool
    {
        // Must be within current working directory in strict mode
        if ($this->strictMode) {
            $cwd = realpath(getcwd());
            return $cwd !== false && str_starts_with($path, $cwd);
        }

        // Basic security checks
        return !str_contains($path, '..') && !str_contains($path, "\0");
    }

    /**
     * Process single PHP file
     */
    private function processFile(string $filePath): void
    {
        $cacheKey = $this->generateCacheKey($filePath);

        // Check cache first
        if (isset($this->classCache[$cacheKey])) {
            foreach ($this->classCache[$cacheKey] as $className) {
                $this->registerClass($className);
            }
            return;
        }

        $content = $this->readFileSecurely($filePath);
        if ($content === null || !$this->containsRouteAttributes($content)) {
            $this->classCache[$cacheKey] = [];
            return;
        }

        $classes = $this->extractClassNames($content);
        $this->classCache[$cacheKey] = $classes;

        foreach ($classes as $className) {
            $this->registerClass($className);
        }
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
     * Read file securely
     */
    private function readFileSecurely(string $filePath): ?string
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false || !$this->isValidPhpContent($content)) {
            return null;
        }

        return $content;
    }

    /**
     * Check if content contains Route attributes
     */
    private function containsRouteAttributes(string $content): bool
    {
        return str_contains($content, '#[Route') ||
            (str_contains($content, 'Route(') && str_contains($content, 'use Framework\Routing\Attributes\Route'));
    }

    /**
     * Validate PHP content for security
     */
    private function isValidPhpContent(string $content): bool
    {
        $trimmed = trim($content);
        if (!str_starts_with($trimmed, '<?php')) {
            return false;
        }

        // Check for dangerous patterns using PHP 8.4 match expression
        $dangerousPatterns = [
            '/eval\s*\(/i',
            '/exec\s*\(/i',
            '/system\s*\(/i',
            '/shell_exec\s*\(/i',
            '/base64_decode\s*\(/i'
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Extract class names from content
     */
    private function extractClassNames(string $content): array
    {
        $classes = [];
        $namespace = $this->extractNamespace($content);

        // Extract class declarations
        $pattern = '/^\s*(?:final\s+)?(?:readonly\s+)?class\s+([a-zA-Z_][a-zA-Z0-9_]*)/m';
        if (preg_match_all($pattern, $content, $matches)) {
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
        if (preg_match('/^\s*namespace\s+([a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*)\s*;/m', $content, $matches)) {
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

        // Only allow specific namespace patterns
        $allowedPrefixes = ['App\\', 'Framework\\', 'Tests\\'];
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
        return strlen($className) <= 100 &&
            preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $className) === 1 &&
            !in_array(strtolower($className), ['class', 'interface', 'trait', 'enum'], true);
    }

    /**
     * Check if class is allowed
     */
    private function isAllowedClass(string $fullClassName): bool
    {
        // Must be in allowed namespaces and end with Action or Controller
        $allowedNamespaces = ['App\\Actions\\', 'App\\Controllers\\', 'App\\Http\\'];
        $allowedSuffixes = ['Action', 'Controller'];

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

        $className = basename(str_replace('\\', '/', $fullClassName));
        foreach ($allowedSuffixes as $suffix) {
            if (str_ends_with($className, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Process route attributes from reflection class
     */
    private function processRouteAttributes(ReflectionClass $reflection): void
    {
        // Validate class is invokable
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
}