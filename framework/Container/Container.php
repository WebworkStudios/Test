<?php

declare(strict_types=1);

namespace Framework\Container;

// 1. LAZY OBJECTS - Erweiterte Container-Klasse
final class Container implements ContainerInterface
{
    // Bestehende Properties...
    private array $services = [];
    private array $singletons = [];
    private array $resolved = [];
    private array $tagged = [];
    private array $building = [];
    
    // Neue Performance-Properties
    private array $lazyServices = [];
    private \WeakMap $instanceRefs;
    private \WeakMap $reflectionCache;
    private array $lazyProxies = [];

    public function __construct(array $config = [], array $allowedPaths = [])
    {
        // WeakMap für besseres Memory Management
        $this->instanceRefs = new \WeakMap();
        $this->reflectionCache = new \WeakMap();
        
        // Bestehende Initialisierung...
        $this->config = $config;
        $this->allowedPaths = $allowedPaths ?: [getcwd()];
        $this->discovery = new ServiceDiscovery($this);
        
        $this->instance(ContainerInterface::class, $this);
        $this->instance(self::class, $this);
    }

    /**
     * Register lazy service (PHP 8.4 Lazy Objects)
     */
    public function lazy(string $id, callable $factory, bool $singleton = true): void
    {
        if (!$this->isValidServiceId($id)) {
            throw ContainerException::invalidService($id, 'Invalid service ID format');
        }

        $this->lazyServices[$id] = [
            'factory' => $factory,
            'singleton' => $singleton,
            'proxy' => null
        ];
        
        if ($singleton) {
            $this->singletons[$id] = true;
        }
    }

    /**
     * Erweiterte get() Methode mit Lazy Object Support
     */
    #[Override]
    public function get(string $id): mixed
    {
        if (!$this->isValidServiceId($id)) {
            throw ContainerException::invalidService($id, 'Invalid service ID format');
        }

        // Return already resolved singleton
        if (isset($this->resolved[$id])) {
            return $this->resolved[$id];
        }

        // Handle lazy services
        if (isset($this->lazyServices[$id])) {
            return $this->resolveLazyService($id);
        }

        // Bestehende Logik...
        if (!$this->has($id)) {
            throw ContainerNotFoundException::serviceNotFound($id);
        }

        $concrete = $this->services[$id];
        $instance = $this->resolve($concrete);

        // Track instance für Memory Management
        if (is_object($instance)) {
            $this->trackInstance($id, $instance);
        }

        if (isset($this->singletons[$id])) {
            $this->resolved[$id] = $instance;
        }

        return $instance;
    }

    /**
     * Resolve lazy service mit PHP 8.4 Lazy Objects
     */
    private function resolveLazyService(string $id): object
    {
        $lazyConfig = $this->lazyServices[$id];

        // Bereits existierender Proxy?
        if ($lazyConfig['proxy'] !== null) {
            return $lazyConfig['proxy'];
        }

        // Erstelle Lazy Proxy
        $proxy = $this->createLazyProxy($id, $lazyConfig['factory']);
        
        if ($lazyConfig['singleton']) {
            $this->lazyServices[$id]['proxy'] = $proxy;
            $this->resolved[$id] = $proxy;
        }

        return $proxy;
    }

    /**
     * Erstelle Lazy Proxy Object
     */
    private function createLazyProxy(string $id, callable $factory): object
    {
        // Fallback für PHP < 8.4 oder wenn Lazy Objects nicht verfügbar
        if (!class_exists('\ReflectionClass') || !method_exists('\ReflectionClass', 'newLazyProxy')) {
            return $this->createManualLazyProxy($id, $factory);
        }

        // PHP 8.4 Native Lazy Objects
        try {
            $initializer = function() use ($factory, $id) {
                $instance = $factory($this);
                
                if (is_object($instance)) {
                    $this->trackInstance($id, $instance);
                }
                
                return $instance;
            };

            // Versuche Ziel-Klasse zu ermitteln
            $targetClass = $this->determineTargetClass($factory);
            
            if ($targetClass) {
                $reflection = $this->getCachedReflection($targetClass);
                return $reflection->newLazyProxy($initializer);
            }

            // Fallback: Generic Object Proxy
            return $this->createGenericLazyProxy($initializer);
            
        } catch (\Throwable $e) {
            // Fallback bei Fehlern
            return $this->createManualLazyProxy($id, $factory);
        }
    }

    /**
     * Ermittle Ziel-Klasse aus Factory
     */
    private function determineTargetClass(callable $factory): ?string
    {
        if (is_string($factory) && class_exists($factory)) {
            return $factory;
        }

        if (is_array($factory) && count($factory) === 2) {
            [$class, $method] = $factory;
            if (is_string($class) && class_exists($class)) {
                // Versuche Return-Type zu ermitteln
                try {
                    $reflection = $this->getCachedReflection($class);
                    $methodReflection = $reflection->getMethod($method);
                    $returnType = $methodReflection->getReturnType();
                    
                    if ($returnType instanceof \ReflectionNamedType && !$returnType->isBuiltin()) {
                        return $returnType->getName();
                    }
                } catch (\ReflectionException) {
                    // Ignore
                }
            }
        }

        return null;
    }

    /**
     * Generic Lazy Proxy für unbekannte Typen
     */
    private function createGenericLazyProxy(callable $initializer): object
    {
        return new class($initializer) {
            private mixed $instance = null;
            private bool $initialized = false;

            public function __construct(private readonly callable $initializer) {}

            private function initialize(): void
            {
                if (!$this->initialized) {
                    $this->instance = ($this->initializer)();
                    $this->initialized = true;
                }
            }

            public function __call(string $name, array $arguments): mixed
            {
                $this->initialize();
                return $this->instance?->$name(...$arguments);
            }

            public function __get(string $name): mixed
            {
                $this->initialize();
                return $this->instance?->$name;
            }

            public function __set(string $name, mixed $value): void
            {
                $this->initialize();
                if ($this->instance) {
                    $this->instance->$name = $value;
                }
            }

            public function __isset(string $name): bool
            {
                $this->initialize();
                return isset($this->instance?->$name);
            }
        };
    }

    /**
     * Fallback Lazy Proxy für ältere PHP-Versionen
     */
    private function createManualLazyProxy(string $id, callable $factory): object
    {
        return $this->createGenericLazyProxy(fn() => $factory($this));
    }

    /**
     * Cached Reflection mit WeakMap
     */
    private function getCachedReflection(string $className): \ReflectionClass
    {
        // Versuche aus WeakMap Cache
        foreach ($this->reflectionCache as $object => $reflection) {
            if ($object instanceof \ReflectionClass && $object->getName() === $className) {
                return $reflection;
            }
        }

        // Erstelle neue Reflection
        try {
            $reflection = new \ReflectionClass($className);
            $this->reflectionCache[$reflection] = $reflection;
            return $reflection;
        } catch (\ReflectionException $e) {
            throw ContainerException::cannotResolve($className, 'Class does not exist');
        }
    }

    /**
     * Track instance für Memory Management
     */
    private function trackInstance(string $id, object $instance): void
    {
        $this->instanceRefs[$instance] = [
            'id' => $id,
            'created_at' => time(),
            'type' => get_class($instance)
        ];
    }

    /**
     * Memory Management - Cleanup nicht mehr referenzierte Services
     */
    public function gc(): int
    {
        $cleaned = 0;
        $now = time();

        // Cleanup alte Lazy Proxies
        foreach ($this->lazyServices as $id => $config) {
            if ($config['proxy'] !== null && !isset($this->resolved[$id])) {
                $this->lazyServices[$id]['proxy'] = null;
                $cleaned++;
            }
        }

        // WeakMap bereinigt sich automatisch, aber wir können Stats sammeln
        $activeInstances = 0;
        foreach ($this->instanceRefs as $instance => $info) {
            $activeInstances++;
            
            // Optional: Cleanup nach Zeitlimit
            if (isset($info['created_at']) && ($now - $info['created_at']) > 3600) {
                // Instance ist älter als 1 Stunde - könnte für Cleanup markiert werden
                // In der Praxis würde WeakMap das automatisch handhaben
            }
        }

        return $cleaned;
    }

    /**
     * Debug-Informationen für Memory Usage
     */
    public function getMemoryStats(): array
    {
        $stats = [
            'total_services' => count($this->services),
            'resolved_singletons' => count($this->resolved),
            'lazy_services' => count($this->lazyServices),
            'active_instances' => 0,
            'reflection_cache_size' => 0
        ];

        // Zähle aktive Instanzen via WeakMap
        foreach ($this->instanceRefs as $instance => $info) {
            $stats['active_instances']++;
        }

        foreach ($this->reflectionCache as $reflection => $cached) {
            $stats['reflection_cache_size']++;
        }

        return $stats;
    }

    /**
     * Erweiterte forget() Methode mit Lazy Service Support
     */
    #[Override]
    public function forget(string $id): void
    {
        unset(
            $this->resolved[$id], 
            $this->services[$id], 
            $this->singletons[$id]
        );

        // Cleanup lazy services
        if (isset($this->lazyServices[$id])) {
            $this->lazyServices[$id]['proxy'] = null;
            unset($this->lazyServices[$id]);
        }
    }

    /**
     * Memory-bewusste flush() Methode
     */
    #[Override]
    public function flush(): void
    {
        $this->resolved = [];
        $this->building = [];
        $this->lazyServices = [];
        
        // WeakMaps bereinigen sich automatisch, aber wir können sie neu initialisieren
        $this->instanceRefs = new \WeakMap();
        $this->reflectionCache = new \WeakMap();
        
        // Container-Selbst-Referenzen beibehalten
        $this->instance(ContainerInterface::class, $this);
        $this->instance(self::class, $this);
    }
}

// 2. SERVICE DISCOVERY CACHING - Erweiterte ServiceDiscovery
final readonly class ServiceDiscovery
{
    private const CACHE_VERSION = '1.0';
    
    public function __construct(
        private Container $container,
        private ?string $cacheDir = null
    ) {}

    /**
     * Auto-discover mit Caching-Support
     */
    public function autodiscoverCached(
        array $directories, 
        ?string $cacheFile = null,
        bool $forceRefresh = false
    ): void {
        $cacheFile = $cacheFile ?? $this->getDefaultCacheFile();
        
        if (!$forceRefresh && $this->isCacheValid($cacheFile, $directories)) {
            $this->loadFromCache($cacheFile);
            return;
        }

        // Normale Discovery
        $this->autodiscover($directories);
        
        // Cache speichern
        $this->saveToCache($cacheFile, $directories);
    }

    /**
     * Prüfe ob Cache noch gültig ist
     */
    private function isCacheValid(string $cacheFile, array $directories): bool
    {
        if (!file_exists($cacheFile) || !$this->container->isAllowedPath($cacheFile)) {
            return false;
        }

        $cacheTime = filemtime($cacheFile);
        
        // Prüfe ob irgendeine Datei in den Directories neuer ist
        foreach ($directories as $directory) {
            if (!$this->container->isAllowedPath($directory) || !is_dir($directory)) {
                continue;
            }

            $latestFile = $this->getLatestFileTime($directory);
            if ($latestFile > $cacheTime) {
                return false;
            }
        }

        return true;
    }

    /**
     * Finde neueste Datei in Directory
     */
    private function getLatestFileTime(string $directory): int
    {
        $latest = 0;
        
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $latest = max($latest, $file->getMTime());
                }
            }
        } catch (\UnexpectedValueException) {
            // Directory nicht lesbar
            return time(); // Force refresh
        }

        return $latest;
    }

    /**
     * Lade Services aus Cache
     */
    private function loadFromCache(string $cacheFile): void
    {
        try {
            $cacheData = $this->readCacheFile($cacheFile);
            
            if (!$this->validateCacheData($cacheData)) {
                return;
            }

            foreach ($cacheData['services'] as $serviceData) {
                $this->registerCachedService($serviceData);
            }
            
        } catch (\Throwable) {
            // Cache korrupt - ignorieren und normale Discovery machen
            $this->autodiscover($cacheData['directories'] ?? []);
        }
    }

    /**
     * Sichere Cache-Datei lesen
     */
    private function readCacheFile(string $cacheFile): array
    {
        $content = file_get_contents($cacheFile);
        if ($content === false) {
            throw new \RuntimeException('Cannot read cache file');
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid cache JSON');
        }

        return $data;
    }

    /**
     * Validiere Cache-Daten
     */
    private function validateCacheData(array $data): bool
    {
        return isset($data['version'], $data['services']) &&
               $data['version'] === self::CACHE_VERSION &&
               is_array($data['services']);
    }

    /**
     * Registriere Service aus Cache-Daten
     */
    private function registerCachedService(array $serviceData): void
    {
        if (!isset($serviceData['class']) || !$this->isClassSafe($serviceData['class'])) {
            return;
        }

        $className = $serviceData['class'];
        
        // Service-Attribute
        if (isset($serviceData['service'])) {
            $service = $serviceData['service'];
            $serviceId = $service['id'] ?? $className;
            
            if ($service['singleton'] ?? true) {
                $this->container->singleton($serviceId, $className);
            } else {
                $this->container->bind($serviceId, $className);
            }

            // Tags
            foreach ($service['tags'] ?? [] as $tag) {
                if ($this->isValidTag($tag)) {
                    $this->container->tag($serviceId, $tag);
                }
            }
        }

        // Factory-Methoden
        foreach ($serviceData['factories'] ?? [] as $factoryData) {
            if (!isset($factoryData['creates'], $factoryData['method'])) {
                continue;
            }

            $factoryCallable = [$className, $factoryData['method']];
            
            if ($factoryData['singleton'] ?? true) {
                $this->container->singleton($factoryData['creates'], $factoryCallable);
            } else {
                $this->container->bind($factoryData['creates'], $factoryCallable);
            }
        }
    }

    /**
     * Speichere Discovery-Ergebnisse in Cache
     */
    private function saveToCache(string $cacheFile, array $directories): void
    {
        $cacheData = [
            'version' => self::CACHE_VERSION,
            'created_at' => time(),
            'directories' => $directories,
            'services' => $this->extractServiceData()
        ];

        $cacheDir = dirname($cacheFile);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        file_put_contents($cacheFile, json_encode($cacheData, JSON_PRETTY_PRINT));
    }

    /**
     * Extrahiere Service-Daten für Cache
     */
    private function extractServiceData(): array
    {
        // Hier würden wir die registrierten Services sammeln
        // Für diese Implementierung vereinfacht
        return [];
    }

    /**
     * Standard Cache-Datei-Pfad
     */
    private function getDefaultCacheFile(): string
    {
        $cacheDir = $this->cacheDir ?? sys_get_temp_dir() . '/container_cache';
        return $cacheDir . '/services.json';
    }

    /**
     * Cache invalidieren
     */
    public function clearCache(?string $cacheFile = null): bool
    {
        $cacheFile = $cacheFile ?? $this->getDefaultCacheFile();
        
        if (file_exists($cacheFile) && $this->container->isAllowedPath($cacheFile)) {
            return unlink($cacheFile);
        }
        
        return true;
    }

    // Bestehende Sicherheitsmethoden...
    private function isClassSafe(string $className): bool
    {
        return !empty($className) &&
               !str_contains($className, '..') &&
               preg_match('/^[a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*$/', $className) === 1;
    }

    private function isValidTag(string $tag): bool
    {
        return !empty($tag) && 
               !str_contains($tag, '..') && 
               preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $tag) === 1;
    }
}