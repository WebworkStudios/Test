<?php

declare(strict_types=1);

namespace Framework\Container;

use Framework\Container\Attributes\{Factory, Service};
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;

/**
 * Optimized service discovery with PHP 8.4 features and simplified architecture
 */
final class ServiceDiscovery
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

    public int $discoveredServices {
        get => $this->discoveredServices;
    }

    public float $successRate {
        get => $this->processedFiles > 0
            ? (count(array_filter($this->classCache)) / count($this->classCache)) * 100
            : 0.0;
    }

    private array $classCache = [];

    private readonly SecurityValidator $securityValidator;

    public function __construct(
        private readonly Container $container,
        private readonly array     $ignoredDirectories = [
            'vendor', 'node_modules', '.git', 'storage', 'cache', 'tests',
            'build', 'dist', 'coverage', '.idea', '.vscode', 'tmp', 'temp'
        ],
        private readonly array     $config = []
    )
    {
        $this->validateConfiguration();
        $this->securityValidator = new SecurityValidator(
            strictMode: $this->strictMode,
            allowedPaths: $this->getAllowedPaths()
        );
    }

    /**
     * Validate configuration
     */
    private function validateConfiguration(): void
    {
        if ($this->maxDepth < 1 || $this->maxDepth > 20) {
            throw new \InvalidArgumentException('Invalid max depth: must be between 1 and 20');
        }

        // Validate ignored directories
        foreach ($this->ignoredDirectories as $dir) {
            if (!is_string($dir) || strlen($dir) > 100) {
                throw new \InvalidArgumentException('Invalid ignored directory specification');
            }
        }
    }

    /**
     * Get allowed paths for security validator
     */
    private function getAllowedPaths(): array
    {
        return [
            getcwd(),
            getcwd() . '/app',
            getcwd() . '/src',
            getcwd() . '/modules'
        ];
    }

    /**
     * Create with default configuration
     */
    public static function create(Container $container, array $config = []): self
    {
        return new self($container, config: $config);
    }

    /**
     * Check if class has service attributes
     */
    public function hasServiceAttributes(string $className): bool
    {
        if (!class_exists($className)) {
            return false;
        }

        try {
            $reflection = new ReflectionClass($className);

            // Check for Service attribute
            if (!empty($reflection->getAttributes(Service::class))) {
                return true;
            }

            // Check for Factory methods
            return $this->hasFactoryMethods($reflection);

        } catch (\ReflectionException) {
            return false;
        }
    }

    /**
     * Check if class has factory methods
     */
    private function hasFactoryMethods(ReflectionClass $reflection): bool
    {
        $methods = $reflection->getMethods(ReflectionMethod::IS_STATIC | ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            if (!empty($method->getAttributes(Factory::class))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get discovery statistics
     */
    public function getStats(): array
    {
        return [
            'processed_files' => $this->processedFiles,
            'discovered_services' => $this->discoveredServices,
            'cached_classes' => count($this->classCache),
            'successful_classes' => count(array_filter($this->classCache)),
            'failed_classes' => count(array_filter($this->classCache, fn($success) => !$success)),
            'success_rate' => $this->successRate,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'strict_mode' => $this->strictMode,
            'max_depth' => $this->maxDepth,
        ];
    }

    /**
     * Clear all caches
     */
    public function clearCache(): void
    {
        $this->classCache = [];
        $this->resetCounters();
    }

    /**
     * Reset performance counters
     */
    private function resetCounters(): void
    {
        $this->processedFiles = 0;
        $this->discoveredServices = 0;
    }

    /**
     * Auto-discover with default paths
     */
    public function autoDiscover(): void
    {
        $defaultPaths = [
            'app/Services',
            'app/Repositories',
            'app/Handlers',
            'app/Providers',
            'src/Services',
            'src/Domain'
        ];

        $existingPaths = array_filter($defaultPaths, 'is_dir');

        if (empty($existingPaths)) {
            throw new \RuntimeException('No default discovery paths found');
        }

        $this->discover($existingPaths);
    }

    /**
     * Discover services in specified directories
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
            throw new \InvalidArgumentException('At least one directory must be specified');
        }

        if (count($directories) > 20) {
            throw new \InvalidArgumentException('Too many directories to scan (max 20)');
        }

        foreach ($directories as $directory) {
            if (!is_string($directory)) {
                throw new \InvalidArgumentException('Directory must be a string');
            }

            if (strlen($directory) > 500) {
                throw new \InvalidArgumentException('Directory path too long');
            }

            if (str_contains($directory, "\0") || (!str_contains($directory, '*') && str_contains($directory, '..'))) {
                throw new \InvalidArgumentException('Directory path contains invalid characters');
            }
        }
    }

    /**
     * Scan single directory for services
     */
    private function scanDirectory(string $directory): void
    {
        if (str_contains($directory, '*')) {
            $this->processGlobPattern($directory);
            return;
        }

        if (!is_dir($directory)) {
            if ($this->strictMode) {
                throw new \InvalidArgumentException("Directory does not exist: {$directory}");
            }
            return;
        }

        $realDirectory = realpath($directory);
        if ($realDirectory === false || !$this->securityValidator->isPathSafe($realDirectory)) {
            if ($this->strictMode) {
                throw new \InvalidArgumentException("Invalid or insecure directory: {$directory}");
            }
            return;
        }

        try {
            $this->scanWithIterator($realDirectory);
        } catch (\Exception $e) {
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
        $allClasses = [];

        foreach ($filePaths as $filePath) {
            $classes = $this->scanFile($filePath);
            $allClasses = array_merge($allClasses, $classes);
        }

        foreach ($allClasses as $className) {
            $this->registerClass($className);
        }

        $this->processedFiles += count($filePaths);
    }

    /**
     * Scan single file for service classes
     */
    private function scanFile(string $filePath): array
    {
        if (!$this->securityValidator->isFileSafe($filePath)) {
            return [];
        }

        $content = $this->readFileSecurely($filePath);
        if ($content === null) {
            return [];
        }

        // Fast pre-screening - umbenennen
        if (!$this->hasServiceAttributesInContent($content)) {
            return [];
        }

        return $this->extractClassNames($content, $filePath);
    }

    /**
     * Read file content securely
     */
    private function readFileSecurely(string $filePath): ?string
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        // Validate content security
        if (!$this->securityValidator->isContentSafe($content)) {
            if ($this->strictMode) {
                throw new \RuntimeException("Unsafe content detected in file: {$filePath}");
            }
            return null;
        }

        return $content;
    }

    /**
     * Fast check for service attributes in file content
     */
    private function hasServiceAttributesInContent(string $content): bool
    {
        return str_contains($content, '#[Service') ||
            str_contains($content, '#[Factory') ||
            str_contains($content, 'Service(') ||
            str_contains($content, 'Factory(') ||
            preg_match('/#\[(Service|Factory)\s*\(/', $content) === 1;
    }

    /**
     * Extract class names from file content
     */
    private function extractClassNames(string $content, string $filePath): array
    {
        $namespace = $this->extractNamespace($content);
        $classes = [];

        // Extract class declarations
        $pattern = '/^\s*(?:final\s+)?(?:readonly\s+)?(?:abstract\s+)?class\s+([a-zA-Z_][a-zA-Z0-9_]*)/m';
        if (preg_match_all($pattern, $content, $matches)) {
            foreach ($matches[1] as $className) {
                if ($this->securityValidator->isClassNameSafe($className)) {
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
        $pattern = '/^\s*namespace\s+([a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*)\s*;/m';
        if (preg_match($pattern, $content, $matches)) {
            $namespace = trim($matches[1]);
            return $this->securityValidator->isNamespaceSafe($namespace) ? $namespace : null;
        }

        return null;
    }

    /**
     * Check if class is allowed for service discovery
     */
    private function isAllowedClass(string $fullClassName): bool
    {
        // Allowed namespaces for service classes
        $allowedNamespaces = [
            'App\\Services\\',
            'App\\Repositories\\',
            'App\\Handlers\\',
            'App\\Providers\\',
            'App\\Domain\\',
            'Modules\\',
            'Framework\\',
            'Infrastructure\\'
        ];

        foreach ($allowedNamespaces as $namespace) {
            if (str_starts_with($fullClassName, $namespace)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Register discovered class with container
     */
    public function registerClass(string $className): void
    {
        // Check cache first
        if (isset($this->classCache[$className])) {
            return;
        }

        if (!$this->validateClass($className)) {
            $this->classCache[$className] = false;
            return;
        }

        try {
            $reflection = new ReflectionClass($className);

            // Security validation
            if (!$this->securityValidator->isClassSecure($reflection)) {
                $this->classCache[$className] = false;
                return;
            }

            $registered = false;

            // Process Service attributes
            $registered |= $this->processServiceAttributes($reflection);

            // Process Factory methods
            $registered |= $this->processFactoryMethods($reflection);

            $this->classCache[$className] = $registered;

            if ($registered) {
                $this->discoveredServices++;
            }

        } catch (\Throwable $e) {
            $this->classCache[$className] = false;

            if ($this->strictMode) {
                throw $e;
            }
            error_log("Failed to register class {$className}: " . $e->getMessage());
        }
    }

    /**
     * Validate discovered class
     */
    private function validateClass(string $className): bool
    {
        if (!$this->securityValidator->isClassNameSafe($className)) {
            return false;
        }

        if (!class_exists($className)) {
            return false;
        }

        try {
            $reflection = new ReflectionClass($className);

            // Abstract classes are only valid if they have factory methods
            if ($reflection->isAbstract()) {
                return $this->hasFactoryMethods($reflection);
            }

            // Interface classes are not valid for service discovery
            if ($reflection->isInterface()) {
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
     * Process service attributes for class
     */
    private function processServiceAttributes(ReflectionClass $reflection): bool
    {
        $attributes = $reflection->getAttributes(Service::class);

        if (empty($attributes)) {
            return false;
        }

        foreach ($attributes as $attribute) {
            try {
                /** @var Service $service */
                $service = $attribute->newInstance();

                // Check if service should be registered
                if (!$service->shouldRegister($this->container->config)) {
                    continue;
                }

                $serviceId = $service->getServiceId($reflection->getName());
                $options = $service->getRegistrationOptions();

                // Register service
                if ($options['lazy']) {
                    $this->container->lazy($serviceId, fn() => new ($reflection->getName()), $options['singleton']);
                } else {
                    $this->container->bind($serviceId, $reflection->getName(), $options['singleton']);
                }

                // Add tags
                foreach ($options['tags'] as $tag) {
                    $this->container->tag($serviceId, $tag);
                }

                // Register interfaces
                foreach ($options['interfaces'] as $interface) {
                    if ($reflection->implementsInterface($interface)) {
                        $this->container->bind($interface, $serviceId, $options['singleton']);
                    }
                }

                return true;

            } catch (\Throwable $e) {
                if ($this->strictMode) {
                    throw $e;
                }
                error_log("Failed to process service attribute for {$reflection->getName()}: " . $e->getMessage());
            }
        }

        return false;
    }

    /**
     * Process factory methods for class
     */
    private function processFactoryMethods(ReflectionClass $reflection): bool
    {
        $methods = $reflection->getMethods(ReflectionMethod::IS_STATIC | ReflectionMethod::IS_PUBLIC);
        $registered = false;

        foreach ($methods as $method) {
            $attributes = $method->getAttributes(Factory::class);

            foreach ($attributes as $attribute) {
                try {
                    /** @var Factory $factory */
                    $factory = $attribute->newInstance();

                    // Validate factory method
                    if (!$factory->validateMethod($method)) {
                        continue;
                    }

                    // Check if factory should be registered
                    if (!$factory->shouldRegister($this->container->config)) {
                        continue;
                    }

                    $serviceId = $factory->getServiceId();
                    $options = $factory->getRegistrationOptions();

                    // Create factory closure
                    $factoryClosure = function (Container $container) use ($reflection, $method, $options) {
                        return $method->invoke(null, $container, ...array_values($options['parameters']));
                    };

                    // Register factory
                    if ($options['lazy']) {
                        $this->container->lazy($serviceId, $factoryClosure, $options['singleton']);
                    } else {
                        $this->container->bind($serviceId, $factoryClosure, $options['singleton']);
                    }

                    // Add tags
                    foreach ($options['tags'] as $tag) {
                        $this->container->tag($serviceId, $tag);
                    }

                    $registered = true;

                } catch (\Throwable $e) {
                    if ($this->strictMode) {
                        throw $e;
                    }
                    error_log("Failed to process factory method {$method->getName()} in {$reflection->getName()}: " . $e->getMessage());
                }
            }
        }

        return $registered;
    }

    /**
     * Discover services with pattern matching
     */
    public function discoverWithPattern(string $baseDir, string $pattern = '**/*{Service,Repository,Handler}.php'): void
    {
        if (!is_dir($baseDir)) {
            throw new \InvalidArgumentException("Base directory does not exist: {$baseDir}");
        }

        if (!$this->securityValidator->isPathSafe($baseDir)) {
            throw new \InvalidArgumentException("Base directory is not safe: {$baseDir}");
        }

        $fullPattern = rtrim($baseDir, '/') . '/' . ltrim($pattern, '/');
        $files = glob($fullPattern, GLOB_BRACE);

        if ($files === false) {
            throw new \RuntimeException("Failed to execute glob pattern: {$fullPattern}");
        }

        $this->discoverInFiles($files);
    }

    /**
     * Discover services in specific files
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
            throw new \InvalidArgumentException('At least one file path must be specified');
        }

        if (count($filePaths) > 100) {
            throw new \InvalidArgumentException('Too many files to scan (max 100)');
        }

        foreach ($filePaths as $filePath) {
            if (!is_string($filePath)) {
                throw new \InvalidArgumentException('File path must be a string');
            }

            if (strlen($filePath) > 500) {
                throw new \InvalidArgumentException('File path too long');
            }

            if (str_contains($filePath, "\0") || str_contains($filePath, '..')) {
                throw new \InvalidArgumentException('File path contains invalid characters');
            }
        }
    }

    // === Validation Methods ===

    /**
     * Get discovered services summary
     */
    public function getDiscoveredServices(): array
    {
        return [
            'total_services' => $this->discoveredServices,
            'services_per_file' => $this->processedFiles > 0
                ? round($this->discoveredServices / $this->processedFiles, 2)
                : 0,
            'successful_classes' => count(array_filter($this->classCache)),
            'failed_classes' => count(array_filter($this->classCache, fn($success) => !$success)),
            'success_rate' => $this->successRate
        ];
    }

    /**
     * Magic method for debugging
     */
    public function __debugInfo(): array
    {
        return [
            'processed_files' => $this->processedFiles,
            'discovered_services' => $this->discoveredServices,
            'cached_classes' => count($this->classCache),
            'success_rate' => round($this->successRate, 2) . '%',
            'strict_mode' => $this->strictMode,
            'max_depth' => $this->maxDepth,
            'has_security_validator' => true
        ];
    }

    /**
     * Create file filter for iterator
     */
    private function createFileFilter(\SplFileInfo $file, string $key, \RecursiveCallbackFilterIterator $iterator): bool
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