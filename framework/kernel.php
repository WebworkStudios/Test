<?php

declare(strict_types=1);

namespace Framework;

use Framework\Container\Container;
use Framework\Http\{Request, Response};
use framework\Http\Session\Session;
use Framework\Routing\{Attributes\Route,
    Exceptions\MethodNotAllowedException,
    Exceptions\RouteNotFoundException,
    RouteCacheBuilder,
    Router};
use Framework\Security\Csrf\CsrfProtection;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Throwable;

/**
 * Enhanced Kernel with Router Performance Integration
 */
final class Kernel
{
    public bool $isBooted {
        get => $this->booted;
    }

    private bool $booted = false;
    private array $providers = [];

    public function __construct(
        private readonly Container $container,
        private readonly array     $config = []
    )
    {
        $this->registerCoreServices();
    }

    /**
     * Register essential framework services
     */
    private function registerCoreServices(): void
    {
        error_log("🔄 Registering core services...");

        try {
            $this->container->instance(self::class, $this);
            $this->container->instance(Kernel::class, $this);
            error_log("✅ Kernel instances registered");

            // Register session
            $this->container->singleton(Session::class, fn() => new Session($this->config['session'] ?? []));
            error_log("✅ Session service registered");

            // Register CSRF protection
            $this->container->singleton(CsrfProtection::class, function (Container $c) {
                return new CsrfProtection($c->get(Session::class));
            });
            error_log("✅ CSRF protection registered");

            // ✅ FIX: Router-Registrierung OHNE sofortige Route-Discovery
            $this->container->singleton(Router::class, function (Container $c) {
                error_log("🔄 Creating router...");
                $router = $this->createOptimizedRouter($c);
                error_log("✅ Router created (without discovery)");
                return $router;
            });
            error_log("✅ Router service registered");

        } catch (Throwable $e) {
            error_log("❌ registerCoreServices() exception: " . $e->getMessage());
            error_log("   File: " . $e->getFile() . ":" . $e->getLine());
            throw $e;
        }
    }

    /**
     * Create optimized router with proper configuration
     */
    private function createOptimizedRouter(Container $container): Router
    {
        $routingConfig = $this->config['routing'] ?? [];

        return Router::create($container, [
            'debug' => $this->config['debug'] ?? false,
            'strict' => $routingConfig['strict'] ?? true,
            'cache_dir' => $routingConfig['cache_dir'] ?? __DIR__ . '/../storage/cache/routes',
            'cache' => $routingConfig['cache'] ?? [],
            'allowed_subdomains' => $routingConfig['allowed_subdomains'] ?? ['api', 'admin', 'www'],
            'base_domain' => $this->config['app']['domain'] ?? 'localhost'
        ]);
    }

    /**
     * Handle incoming HTTP request with performance optimizations
     */
    public function handle(Request $request): Response
    {
        error_log("=== Kernel::handle() START ===");
        error_log("Request: " . $request->method . " " . $request->path);

        try {
            error_log("🔄 Starting boot process...");
            $this->boot();
            error_log("✅ Boot completed");

            error_log("🔄 Getting router from container...");
            $router = $this->container->get(Router::class);
            error_log("✅ Router obtained");

            error_log("🔄 Dispatching request...");
            $response = $router->dispatch($request);
            error_log("✅ Dispatch completed");

            return $response;

        } catch (Throwable $e) {
            error_log("❌ Kernel::handle() exception: " . $e->getMessage());
            error_log("   File: " . $e->getFile() . ":" . $e->getLine());
            return $this->handleException($e, $request);
        }
    }

    /**
     * Boot the kernel and all services
     */
    public function boot(): void
    {
        if ($this->booted) {
            error_log("ℹ️ Kernel already booted, skipping");
            return;
        }

        try {
            error_log("🔄 Starting session...");
            // Start session
            $session = $this->container->get(Session::class);
            $session->start();
            error_log("✅ Session started");

            // ✅ FIX: Route-Discovery HIER ausführen, nur einmal
            if ($this->config['routing']['auto_discover'] ?? true) {
                error_log("🔄 Starting route discovery...");
                $router = $this->container->get(Router::class);
                $this->performRouteDiscoveryImmediate($router);
                error_log("✅ Route discovery completed");
            }

            error_log("🔄 Booting providers...");
            // Boot service providers
            $this->bootProviders();
            error_log("✅ Providers booted");

            $this->booted = true;
            error_log("✅ Kernel boot completed");

        } catch (Throwable $e) {
            error_log("❌ Kernel::boot() exception: " . $e->getMessage());
            error_log("   File: " . $e->getFile() . ":" . $e->getLine());
            throw $e;
        }
    }

    /**
     * ✅ NEUE METHODE: Sofortige Route-Discovery mit Duplikat-Schutz
     */
    private function performRouteDiscoveryImmediate(Router $router): void
    {
        // ✅ Prüfe ob bereits Routen registriert sind
        $currentRoutes = $router->getRoutes();
        if (!empty($currentRoutes)) {
            error_log("⚠️ Routen bereits registriert, überspringe Discovery");
            return;
        }

        $directories = $this->config['routing']['discovery_paths'] ?? [
            __DIR__ . '/../app/Actions',
            __DIR__ . '/../app/Controllers'
        ];

        error_log("🔍 Route-Discovery startet...");
        error_log("📁 Aktuelles Verzeichnis: " . getcwd());

        // Prüfe ob Verzeichnisse existieren
        $existingDirs = [];
        foreach ($directories as $dir) {
            $realPath = realpath($dir);
            error_log("🔍 Prüfe Verzeichnis: {$dir} (realpath: " . ($realPath ?: 'false') . ")");

            if ($realPath !== false && is_dir($realPath) && is_readable($realPath)) {
                $existingDirs[] = $realPath;
                error_log("✅ Verzeichnis gefunden: {$realPath}");

                // ✅ Zeige Inhalt des Verzeichnisses
                $files = glob($realPath . '/*.php');
                error_log("   📄 PHP-Dateien: " . count($files));
                foreach ($files as $file) {
                    error_log("     - " . basename($file));
                }
            } else {
                error_log("❌ Verzeichnis nicht zugänglich: {$dir}");
            }
        }

        if (empty($existingDirs)) {
            error_log("⚠️ Keine gültigen Verzeichnisse für Route-Discovery gefunden!");
            return;
        }

        try {
            // ✅ Vereinfachte Route-Discovery ohne mehrfache Registrierung
            $discoveredRoutes = 0;

            foreach ($existingDirs as $dir) {
                error_log("🔍 Scanne Verzeichnis: {$dir}");
                $routesInDir = $this->scanDirectoryForRoutesOnce($router, $dir);
                $discoveredRoutes += $routesInDir;
            }

            // Zeige gefundene Routen
            $routes = $router->getRoutes();
            $totalRoutes = array_sum(array_map('count', $routes));
            error_log("📋 Insgesamt {$totalRoutes} Routen registriert");

        } catch (Throwable $e) {
            error_log("❌ Route-Discovery Fehler: " . $e->getMessage());
            error_log("   Datei: " . $e->getFile() . ":" . $e->getLine());
        }
    }

    /**
     * ✅ NEUE METHODE: Einmalige Verzeichnis-Scanner mit Duplikat-Schutz
     */
    private function scanDirectoryForRoutesOnce(Router $router, string $directory): int
    {
        static $processedFiles = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $routesAdded = 0;

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php' && $file->isFile()) {
                $filePath = $file->getPathname();

                // ✅ Überspringe bereits verarbeitete Dateien
                if (isset($processedFiles[$filePath])) {
                    continue;
                }

                $processedFiles[$filePath] = true;
                $routesAdded += $this->scanFileForRoutesOnce($router, $filePath);
            }
        }

        return $routesAdded;
    }

    /**
     * ✅ NEUE METHODE: Einmalige Datei-Scanner mit besserer Fehlerbehandlung
     */
    private function scanFileForRoutesOnce(Router $router, string $filePath): int
    {
        try {
            $content = file_get_contents($filePath);
            if ($content === false || !str_contains($content, '#[Route')) {
                return 0;
            }

            // Extrahiere Namespace und Klassennamen
            $namespace = null;
            $className = null;

            if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
                $namespace = trim($matches[1]);
            }

            if (preg_match('/class\s+(\w+)/', $content, $matches)) {
                $className = trim($matches[1]);
            }

            if (!$namespace || !$className) {
                return 0;
            }

            $fullClassName = $namespace . '\\' . $className;

            // Prüfe ob Klasse existiert
            if (!class_exists($fullClassName)) {
                error_log("⚠️ Klasse nicht gefunden: {$fullClassName}");
                return 0;
            }

            $reflection = new ReflectionClass($fullClassName);
            $attributes = $reflection->getAttributes(Route::class);

            if (empty($attributes)) {
                return 0;
            }

            error_log("🎯 Routen gefunden in: {$fullClassName}");

            $routesAdded = 0;

            // Registriere alle Route-Attribute
            foreach ($attributes as $attribute) {
                try {
                    $route = $attribute->newInstance();

                    $router->addRoute(
                        $route->method,
                        $route->path,
                        $fullClassName,
                        $route->middleware,
                        $route->name,
                        $route->subdomain
                    );

                    error_log("   ✅ Route registriert: {$route->method} {$route->path} -> {$fullClassName}");
                    $routesAdded++;

                } catch (Throwable $e) {
                    // ✅ Überspringe doppelte Routen-Namen ohne Fehler
                    if (str_contains($e->getMessage(), 'already exists')) {
                        error_log("   ⚠️ Route übersprungen (bereits vorhanden): {$route->method} {$route->path}");
                    } else {
                        error_log("❌ Fehler beim Registrieren: " . $e->getMessage());
                    }
                }
            }

            return $routesAdded;

        } catch (Throwable $e) {
            error_log("❌ Fehler beim Scannen von {$filePath}: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Boot registered service providers
     */
    private function bootProviders(): void
    {
        foreach ($this->providers as $provider) {
            if (method_exists($provider, 'boot')) {
                $provider->boot();
            }
        }
    }

    /**
     * Enhanced exception handling with better error responses
     */
    private function handleException(Throwable $e, Request $request): Response
    {
        // Log error in debug mode
        if ($this->config['debug'] ?? false) {
            error_log("Kernel exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        }

        // Return appropriate HTTP response based on exception type
        return match (true) {
            $e instanceof RouteNotFoundException =>
            Response::notFound('Route not found'),

            $e instanceof MethodNotAllowedException =>
            Response::json([
                'error' => 'Method not allowed',
                'allowed_methods' => $e->getAllowedMethods()
            ], 405),

            default => Response::serverError(
                ($this->config['debug'] ?? false) ? $e->getMessage() : 'Internal server error'
            )
        };
    }

    /**
     * Register service provider
     */
    public function register(object $provider): void
    {
        if (method_exists($provider, 'register')) {
            $provider->register();
        }

        $this->providers[] = $provider;

        // Boot immediately if kernel already booted
        if ($this->booted && method_exists($provider, 'boot')) {
            $provider->boot();
        }
    }

    /**
     * Get container instance
     */
    public function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * Get framework performance statistics
     */
    public function getPerformanceStats(): array
    {
        $stats = [
            'kernel' => [
                'is_booted' => $this->booted,
                'providers_count' => count($this->providers),
                'memory_usage' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true)
            ]
        ];

        // Add router stats if available
        try {
            $router = $this->container->get(Router::class);
            $stats['router'] = $router->getStats();
        } catch (Throwable) {
            $stats['router'] = ['error' => 'Router not available'];
        }

        // Add cache stats if available
        $cacheStats = RouteCacheBuilder::getCacheStats();
        if ($cacheStats) {
            $stats['route_cache'] = $cacheStats;
        }

        return $stats;
    }

    /**
     * Warm up all framework caches
     */
    public function warmUp(): void
    {
        try {
            // Warm up router
            $router = $this->container->get(Router::class);
            $router->warmUp();

            if ($this->config['debug'] ?? false) {
                error_log("✅ Framework warm-up completed");
            }
        } catch (Throwable $e) {
            if ($this->config['debug'] ?? false) {
                error_log("❌ Framework warm-up failed: " . $e->getMessage());
            }
        }
    }

    /**
     * Clear all framework caches
     */
    public function clearCaches(): void
    {
        try {
            // Clear router caches
            $router = $this->container->get(Router::class);
            $router->clearCaches();

            if ($this->config['debug'] ?? false) {
                error_log("✅ Framework caches cleared");
            }
        } catch (Throwable $e) {
            if ($this->config['debug'] ?? false) {
                error_log("❌ Cache clearing failed: " . $e->getMessage());
            }
        }
    }

    /**
     * Terminate the kernel
     */
    public function terminate(): void
    {
        // Cleanup providers
        foreach ($this->providers as $provider) {
            if (method_exists($provider, 'terminate')) {
                $provider->terminate();
            }
        }

        $this->booted = false;
    }
}