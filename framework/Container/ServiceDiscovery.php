<?php

declare(strict_types=1);

namespace Framework\Container;

use Framework\Container\Attributes\{Service, Factory};
use ReflectionClass;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;


/**
 * Sichere Service Discovery engine für attribute-basierte Auto-Registration
 * 
 * Scannt Verzeichnisse nach Klassen mit Service-Attributen und registriert
 * sie automatisch im Container mit erweiterten Sicherheitsprüfungen.
 */
final readonly class ServiceDiscovery
{
    private const MAX_FILE_SIZE = 1024 * 1024; // 1MB Limit
    private const ALLOWED_EXTENSIONS = ['php'];
    
    public function __construct(
        private Container $container
    ) {}

    /**
     * Discover and register services from given directories
     * 
     * @param array<string> $directories Directories to scan for services
     * @param array<string> $extensions File extensions to scan
     */
    public function autodiscover(array $directories, array $extensions = self::ALLOWED_EXTENSIONS): void
    {
        $secureExtensions = $this->validateExtensions($extensions);
        
        foreach ($directories as $directory) {
            if ($this->container->isAllowedPath($directory)) {
                $this->scanDirectory($directory, $secureExtensions);
            }
        }
    }

    /**
     * Validiert erlaubte Dateierweiterungen
     * 
     * @param array<string> $extensions
     * @return array<string>
     */
    private function validateExtensions(array $extensions): array
    {
        return array_filter(
            $extensions,
            fn(string $ext) => in_array($ext, self::ALLOWED_EXTENSIONS, true)
        );
    }

    /**
     * Register a specific class if it has service attributes
     */
    public function registerClass(string $className): void
    {
        if (!$this->isClassSafe($className) || !class_exists($className)) {
            return;
        }

        try {
            $reflection = new ReflectionClass($className);
            
            // Sicherheitsprüfungen
            if ($this->isClassSecure($reflection)) {
                $this->processServiceAttributes($reflection);
                $this->processFactoryMethods($reflection);
            }
        } catch (\ReflectionException) {
            // Skip classes that can't be reflected
        }
    }

    /**
     * Prüft ob eine Klasse sicher ist
     */
    private function isClassSafe(string $className): bool
    {
        return !empty($className) &&
               !str_contains($className, '..') &&
               !str_starts_with($className, '\\') &&
               !str_contains($className, '/') &&
               preg_match('/^[a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*$/', $className) === 1;
    }

    /**
     * Erweiterte Sicherheitsprüfung für Reflection-Klassen
     */
    private function isClassSecure(ReflectionClass $reflection): bool
    {
        $fileName = $reflection->getFileName();
        
        // Prüfe ob Datei in erlaubtem Pfad liegt
        if ($fileName && !$this->container->isAllowedPath(dirname($fileName))) {
            return false;
        }

        // Prüfe auf gefährliche Klassen-Eigenschaften
        if ($reflection->isInternal()) {
            return false;
        }

        // Verbiete bestimmte gefährliche Klassen
        $dangerousClasses = [
            'ReflectionFunction',
            'ReflectionMethod', 
            'eval',
            'system',
            'exec',
            'shell_exec'
        ];

        $className = $reflection->getName();
        foreach ($dangerousClasses as $dangerous) {
            if (str_contains(strtolower($className), strtolower($dangerous))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Scan directory for PHP files with service classes
     */
    private function scanDirectory(string $directory, array $extensions): void
    {
        if (!is_dir($directory)) {
            return;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if (!in_array($file->getExtension(), $extensions, true)) {
                    continue;
                }

                if ($this->isFileSafe($file->getPathname())) {
                    $this->processFile($file->getPathname());
                }
            }
        } catch (\UnexpectedValueException) {
            // Skip directories that can't be read
        }
    }

    /**
     * Sicherheitsprüfung für Dateien
     */
    private function isFileSafe(string $filePath): bool
    {
        // Realpath-Prüfung gegen Directory Traversal
        $realPath = realpath($filePath);
        if ($realPath === false) {
            return false;
        }

        // File size check
        if (filesize($realPath) > self::MAX_FILE_SIZE) {
            return false;
        }

        // Path validation
        return $this->container->isAllowedPath($realPath);
    }

    /**
     * Process PHP file for service classes mit Composer-Integration
     */
    private function processFile(string $filePath): void
    {
        // Versuche zuerst Composer-Autoloader zu nutzen
        $classes = $this->getClassesFromComposer($filePath);
        
        // Fallback auf Parsing wenn Composer nicht verfügbar
        if (empty($classes)) {
            $classes = $this->extractClassNamesFromFile($filePath);
        }
        
        foreach ($classes as $className) {
            $this->registerClass($className);
        }
    }

    /**
     * Sichere Klassenextraktion über Composer-Autoloader APIs
     *
     * @return array<string>
     */
    private function getClassesFromComposer(string $filePath): array
    {
        $classes = [];
        $realFilePath = realpath($filePath);

        if ($realFilePath === false) {
            return [];
        }

        // Methode 1: Über composer/autoload_classmap.php (sicherste Methode)
        $classes = array_merge($classes, $this->getClassesFromClassmap($realFilePath));

        // Methode 2: Über PSR-4 Namespace-Mapping (falls Classmap leer)
        if (empty($classes)) {
            $classes = array_merge($classes, $this->getClassesFromPsr4($realFilePath));
        }

        // Methode 3: Über öffentliche Composer-API (falls verfügbar)
        if (empty($classes) && class_exists('Composer\\Autoload\\ClassLoader')) {
            $classes = array_merge($classes, $this->getClassesFromComposerApi($realFilePath));
        }

        return array_unique($classes);
    }
    
    /**
     * Sichere Extraktion über Composer Classmap-Datei
     * 
     * @return array<string>
     */
    private function getClassesFromClassmap(string $filePath): array
    {
        $classes = [];
        
        // Suche nach vendor/composer/autoload_classmap.php
        $currentDir = dirname($filePath);
        $maxDepth = 10; // Schutz vor unendlichen Schleifen
        
        for ($i = 0; $i < $maxDepth; $i++) {
            $classmapFile = $currentDir . '/vendor/composer/autoload_classmap.php';
            
            if (file_exists($classmapFile) && $this->container->isAllowedPath($classmapFile)) {
                try {
                    // Sichere Inclusion der Classmap
                    $classMap = $this->includeClassmapSafely($classmapFile);
                    
                    if (is_array($classMap)) {
                        foreach ($classMap as $class => $file) {
                            if (realpath($file) === $filePath && $this->isClassSafe($class)) {
                                $classes[] = $class;
                            }
                        }
                    }
                    break;
                } catch (\Throwable) {
                    // Skip fehlerhafte Classmap-Dateien
                }
            }
            
            $parentDir = dirname($currentDir);
            if ($parentDir === $currentDir) {
                break; // Root erreicht
            }
            $currentDir = $parentDir;
        }
        
        return $classes;
    }
    
    /**
     * Sichere Inclusion der Composer Classmap
     */
    private function includeClassmapSafely(string $classmapFile): array
    {
        // Validiere Dateiinhalt vor Inclusion
        $content = file_get_contents($classmapFile);
        if ($content === false) {
            return [];
        }
        
        // Prüfe auf gefährliche PHP-Konstrukte
        $dangerousPatterns = [
            '/eval\s*\(/i',
            '/exec\s*\(/i', 
            '/system\s*\(/i',
            '/shell_exec\s*\(/i',
            '/file_get_contents\s*\(\s*["\']php:\/\//i',
            '/\$_GET\s*\[/i',
            '/\$_POST\s*\[/i',
            '/include\s*\(/i',
            '/require\s*\(/i'
        ];
        
        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                throw new \RuntimeException("Dangerous content detected in classmap file");
            }
        }
        
        // Prüfe dass es nur ein Return-Statement mit Array gibt
        if (!preg_match('/^\s*<\?php\s*return\s*array\s*\(/m', $content)) {
            throw new \RuntimeException("Invalid classmap file format");
        }
        
        // Isolierte Ausführung mit Output-Buffering
        ob_start();
        try {
            $result = include $classmapFile;
            ob_end_clean();
            
            return is_array($result) ? $result : [];
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
    
    /**
     * Sichere Extraktion über PSR-4 Namespace-Mapping
     * 
     * @return array<string>
     */
    private function getClassesFromPsr4(string $filePath): array
    {
        $classes = [];
        
        // Suche nach vendor/composer/autoload_psr4.php
        $currentDir = dirname($filePath);
        $maxDepth = 10;
        
        for ($i = 0; $i < $maxDepth; $i++) {
            $psr4File = $currentDir . '/vendor/composer/autoload_psr4.php';
            
            if (file_exists($psr4File) && $this->container->isAllowedPath($psr4File)) {
                try {
                    $psr4Map = $this->includeClassmapSafely($psr4File);
                    
                    if (is_array($psr4Map)) {
                        $classes = array_merge($classes, $this->resolveClassFromPsr4($filePath, $psr4Map));
                    }
                    break;
                } catch (\Throwable) {
                    // Skip fehlerhafte PSR-4-Dateien
                }
            }
            
            $parentDir = dirname($currentDir);
            if ($parentDir === $currentDir) {
                break;
            }
            $currentDir = $parentDir;
        }
        
        return $classes;
    }
    
    /**
     * Resolviere Klasse aus PSR-4-Mapping
     * 
     * @return array<string>
     */
    private function resolveClassFromPsr4(string $filePath, array $psr4Map): array
    {
        $classes = [];
        $realFilePath = realpath($filePath);
        
        foreach ($psr4Map as $namespace => $paths) {
            if (!is_array($paths)) {
                $paths = [$paths];
            }
            
            foreach ($paths as $path) {
                $realPath = realpath($path);
                if ($realPath && str_starts_with($realFilePath, $realPath)) {
                    // Berechne relativen Pfad
                    $relativePath = substr($realFilePath, strlen($realPath) + 1);
                    
                    // Konvertiere Dateipfad zu Klassenname
                    $className = $namespace . str_replace(
                        ['/', '.php'],
                        ['\\', ''],
                        $relativePath
                    );
                    
                    if ($this->isClassSafe($className) && class_exists($className)) {
                        $classes[] = $className;
                    }
                }
            }
        }
        
        return $classes;
    }


    /**
     * Sichere Extraktion über öffentliche Composer-APIs (ohne Reflection)
     *
     * @return array<string>
     */
    private function getClassesFromComposerApi(string $filePath): array
    {
        $classes = [];

        // Verwende nur öffentliche APIs ohne Reflection-Hacks
        if (function_exists('spl_autoload_functions')) {
            $autoloaders = spl_autoload_functions();

            foreach ($autoloaders as $autoloader) {
                if (is_array($autoloader) &&
                    isset($autoloader[0]) &&
                    is_object($autoloader[0]) &&
                    get_class($autoloader[0]) === 'Composer\\Autoload\\ClassLoader') {

                    // Verwende nur dokumentierte öffentliche Methoden
                    $loader = $autoloader[0];

                    // Prüfe ob die Datei in den konfigurierten Pfaden liegt
                    if (method_exists($loader, 'getPrefixes')) {
                        $prefixes = $loader->getPrefixes();
                        $classes = array_merge($classes, $this->matchFileToClasses($filePath, $prefixes));
                    }

                    if (method_exists($loader, 'getPrefixesPsr4')) {
                        $psr4Prefixes = $loader->getPrefixesPsr4();
                        $classes = array_merge($classes, $this->matchFileToClasses($filePath, $psr4Prefixes));
                    }
                }
            }
        }

        return array_unique($classes);
    }

    /**
     * Matche Datei zu Klassen basierend auf Namespace-Prefixes
     * 
     * @return array<string>
     */
    private function matchFileToClasses(string $filePath, array $prefixes): array
    {
        $classes = [];
        $realFilePath = realpath($filePath);
        
        foreach ($prefixes as $prefix => $paths) {
            if (!is_array($paths)) {
                $paths = [$paths];
            }
            
            foreach ($paths as $path) {
                $realPath = realpath($path);
                if ($realPath && str_starts_with($realFilePath, $realPath)) {
                    $relativePath = substr($realFilePath, strlen($realPath) + 1);
                    $className = $prefix . str_replace(['/', '.php'], ['\\', ''], $relativePath);
                    
                    if ($this->isClassSafe($className) && class_exists($className)) {
                        $classes[] = $className;
                    }
                }
            }
        }
        
        return $classes;
    }

    /**
     * Sichere Fallback-Methode für Klassenextraktion
     * 
     * @return array<string>
     */
    private function extractClassNamesFromFile(string $filePath): array
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return [];
        }

        // Sicherheitsprüfung des Inhalts
        if (!$this->isContentSafe($content)) {
            return [];
        }

        return $this->extractClassNames($content);
    }

    /**
     * Prüft ob Dateiinhalt sicher ist
     */
    private function isContentSafe(string $content): bool
    {
        $dangerousPatterns = [
            '/eval\s*\(/i',
            '/system\s*\(/i',
            '/exec\s*\(/i',
            '/shell_exec\s*\(/i',
            '/passthru\s*\(/i',
            '/file_get_contents\s*\(\s*["\']php:\/\//i',
            '/__halt_compiler\s*\(/i'
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return false;
            }
        }

        return true;
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

        // Extract namespace mit Sicherheitsprüfung
        if (preg_match('/^namespace\s+([a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*);/m', $content, $nsMatch)) {
            $candidate = trim($nsMatch[1]);
            if ($this->isClassSafe($candidate)) {
                $namespace = $candidate . '\\';
            }
        }

        // Extract class declarations mit Sicherheitsprüfung
        $pattern = '/^(?:final\s+)?(?:readonly\s+)?(?:abstract\s+)?class\s+([a-zA-Z_][a-zA-Z0-9_]*)/m';
        if (preg_match_all($pattern, $content, $matches)) {
            foreach ($matches[1] as $className) {
                if ($this->isClassSafe($className)) {
                    $fullClassName = $namespace . $className;
                    if ($this->isClassSafe($fullClassName)) {
                        $classes[] = $fullClassName;
                    }
                }
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
            try {
                /** @var Service $service */
                $service = $attribute->newInstance();
                
                $className = $reflection->getName();
                $serviceId = $service->id ?? $className;
                
                // Sicherheitsprüfung der Service-ID
                if (!$this->isServiceIdSafe($serviceId)) {
                    continue;
                }
                
                // Register the service
                if ($service->singleton) {
                    $this->container->singleton($serviceId, $className);
                } else {
                    $this->container->bind($serviceId, $className);
                }

                // Register by implemented interfaces
                $this->registerInterfaces($reflection, $className, $service->singleton);

                // Handle tags mit Validierung
                $this->registerTags($service->tags, $serviceId);
                
            } catch (\Throwable) {
                // Skip fehlerhafte Service-Attribute
                continue;
            }
        }
    }

    /**
     * Validiert Service-IDs
     */
    private function isServiceIdSafe(string $serviceId): bool
    {
        return !empty($serviceId) &&
               !str_contains($serviceId, '..') &&
               !str_contains($serviceId, '/') &&
               preg_match('/^[a-zA-Z_\\\\][a-zA-Z0-9_\\\\.]*$/', $serviceId) === 1;
    }

    /**
     * Register service by its implemented interfaces
     */
    private function registerInterfaces(ReflectionClass $reflection, string $className, bool $singleton): void
    {
        foreach ($reflection->getInterfaces() as $interface) {
            $interfaceName = $interface->getName();
            
            // Skip built-in und gefährliche interfaces
            if ($this->isInterfaceSafe($interfaceName)) {
                try {
                    if ($singleton) {
                        $this->container->singleton($interfaceName, $className);
                    } else {
                        $this->container->bind($interfaceName, $className);
                    }
                } catch (\Throwable) {
                    // Skip fehlerhafte Interface-Registrierung
                    continue;
                }
            }
        }
    }

    /**
     * Prüft ob Interface sicher ist
     */
    private function isInterfaceSafe(string $interfaceName): bool
    {
        $unsafeInterfaces = [
            'Traversable',
            'Iterator',
            'ArrayAccess',
            'Serializable',
            'Closure'
        ];

        foreach ($unsafeInterfaces as $unsafe) {
            if (str_starts_with($interfaceName, $unsafe)) {
                return false;
            }
        }

        return $this->isServiceIdSafe($interfaceName);
    }

    /**
     * Register service tags mit Validierung
     * 
     * @param array<string> $tags
     */
    private function registerTags(array $tags, string $serviceId): void
    {
        foreach ($tags as $tag) {
            if ($this->isServiceIdSafe($tag)) {
                try {
                    $this->container->tag($serviceId, $tag);
                } catch (\Throwable) {
                    // Skip fehlerhafte Tag-Registrierung
                    continue;
                }
            }
        }
    }

    /**
     * Process Factory method attributes
     */
    private function processFactoryMethods(ReflectionClass $reflection): void
    {
        foreach ($reflection->getMethods() as $method) {
            // Nur statische und sichere Methoden
            if (!$method->isStatic() || !$this->isMethodSafe($method)) {
                continue;
            }

            $attributes = $method->getAttributes(Factory::class);
            
            foreach ($attributes as $attribute) {
                try {
                    /** @var Factory $factory */
                    $factory = $attribute->newInstance();
                    
                    // Sicherheitsprüfung der Factory-Ziele
                    if (!$this->isServiceIdSafe($factory->creates)) {
                        continue;
                    }
                    
                    $factoryCallable = function(Container $container) use ($reflection, $method) {
                        try {
                            return $method->invoke(null, $container);
                        } catch (\Throwable $e) {
                            throw ContainerException::cannotResolve(
                                $factory->creates ?? 'unknown',
                                "Factory method failed: {$e->getMessage()}"
                            );
                        }
                    };
                    
                    if ($factory->singleton) {
                        $this->container->singleton($factory->creates, $factoryCallable);
                    } else {
                        $this->container->bind($factory->creates, $factoryCallable);
                    }

                    // Handle factory tags mit Validierung
                    $this->registerTags($factory->tags, $factory->creates);
                    
                } catch (\Throwable) {
                    // Skip fehlerhafte Factory-Attribute
                    continue;
                }
            }
        }
    }

    /**
     * Prüft ob eine Methode sicher als Factory verwendet werden kann
     */
    private function isMethodSafe(\ReflectionMethod $method): bool
    {
        // Gefährliche Methodennamen
        $dangerousMethods = [
            'eval',
            'system', 
            'exec',
            'shell_exec',
            'passthru',
            '__destruct',
            '__wakeup',
            '__unserialize'
        ];

        $methodName = strtolower($method->getName());
        
        return !in_array($methodName, $dangerousMethods, true) &&
               !str_starts_with($methodName, '__') &&
               $method->isPublic() &&
               $method->isStatic();
    }
}