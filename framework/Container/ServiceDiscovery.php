<?php

declare(strict_types=1);

namespace Framework\Container;

use Framework\Container\Attributes\{Service, Factory};
use ReflectionClass;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Service Discovery Engine for attribute-based auto-registration
 * 
 * Scans directories for classes with service attributes and automatically
 * registers them in the container, eliminating manual configuration.
 */
final readonly class ServiceDiscovery
{
    public function __construct(
        private Container $container
    ) {}

    /**
     * Discover and register services from given directories
     * 
     * @param array<string> $directories Directories to scan for services
     * @param array<string> $extensions File extensions to scan (default: ['php'])
     */
    public function autodiscover(array $directories, array $extensions = ['php']): void
    {
        foreach ($directories as $directory) {
            $this->scanDirectory($directory, $extensions);
        }
    }

    /**
     * Register a specific class if it has service attributes
     */
    public function registerClass(string $className): void
    {
        if (!class_exists($className)) {
            return;
        }

        try {
            $reflection = new ReflectionClass($className);
            $this->processServiceAttributes($reflection);
            $this->processFactoryMethods($reflection);
        } catch (\ReflectionException) {
            // Skip classes that can't be reflected
        }
    }

    /**
     * Scan directory for PHP files with service classes
     */
    private function scanDirectory(string $directory, array $extensions): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!in_array($file->getExtension(), $extensions, true)) {
                continue;
            }

            $this->processFile($file->getPathname());
        }
    }

    /**
     * Process PHP file for service classes
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
     * Process Service attributes on a class
     */
    private function processServiceAttributes(ReflectionClass $reflection): void
    {
        $attributes = $reflection->getAttributes(Service::class);

        foreach ($attributes as $attribute) {
            /** @var Service $service */
            $service = $attribute->newInstance();
            
            $className = $reflection->getName();
            $serviceId = $service->id ?? $className;
            
            // Register the service
            if ($service->singleton) {
                $this->container->singleton($serviceId, $className);
            } else {
                $this->container->bind($serviceId, $className);
            }

            // Register by implemented interfaces
            $this->registerInterfaces($reflection, $className, $service->singleton);

            // Handle tags
            $this->registerTags($service->tags, $serviceId);
        }
    }

    /**
     * Register service by its implemented interfaces
     */
    private function registerInterfaces(ReflectionClass $reflection, string $className, bool $singleton): void
    {
        foreach ($reflection->getInterfaces() as $interface) {
            $interfaceName = $interface->getName();
            
            // Skip built-in interfaces
            if (str_starts_with($interfaceName, 'Traversable') ||
                str_starts_with($interfaceName, 'Iterator') ||
                str_starts_with($interfaceName, 'ArrayAccess')) {
                continue;
            }

            if ($singleton) {
                $this->container->singleton($interfaceName, $className);
            } else {
                $this->container->bind($interfaceName, $className);
            }
        }
    }

    /**
     * Register service tags
     * 
     * @param array<string> $tags
     */
    private function registerTags(array $tags, string $serviceId): void
    {
        foreach ($tags as $tag) {
            $this->container->tag($serviceId, $tag);
        }
    }

    /**
     * Process Factory method attributes
     */
    private function processFactoryMethods(ReflectionClass $reflection): void
    {
        foreach ($reflection->getMethods() as $method) {
            $attributes = $method->getAttributes(Factory::class);
            
            foreach ($attributes as $attribute) {
                /** @var Factory $factory */
                $factory = $attribute->newInstance();
                
                $factoryCallable = function(Container $container) use ($reflection, $method) {
                    return $method->invoke(null, $container);
                };
                
                if ($factory->singleton) {
                    $this->container->singleton($factory->creates, $factoryCallable);
                } else {
                    $this->container->bind($factory->creates, $factoryCallable);
                }

                // Handle factory tags
                $this->registerTags($factory->tags, $factory->creates);
            }
        }
    }
}