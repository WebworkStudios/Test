<?php

declare(strict_types=1);

namespace Framework;

use Framework\Container\Container;
use Framework\Http\{Request, Response};
use framework\Http\Session\Session;
use Framework\Routing\{
    Exceptions\MethodNotAllowedException,
    Exceptions\RouteNotFoundException,
    RouteDiscovery,
    RouteFileScanner,
    Router,
    RouteCacheBuilder
};
use Framework\Security\Csrf\CsrfProtection;
use Throwable;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use ReflectionClass;

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

            // ✅ FIXED: Router-Registrierung vereinfachen und sofort Route-Discovery ausführen
            $this->container->singleton(Router::class, function (Container $c) {
                error_log("🔄 Creating router...");
                $router = $this->createOptimizedRouter($c);
                error_log("✅ Router created");

                // ✅ Route-Discovery sofort hier ausführen, nicht verzögert
                if ($this->config['routing']['auto_discover'] ?? true) {
                    error_log("🔄 Starting route discovery...");
                    $this->performRouteDiscoveryImmediate($router);
                    error_log("✅ Route discovery completed");
                }

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
     * ✅ NEUE METHODE: Sofortige Route-Discovery
     */
    private function performRouteDiscoveryImmediate(Router $router): void
    {
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

                // ✅ Hilfe bei der Fehlerbehebung
                if (file_exists($dir)) {
                    error_log("   ℹ️ Pfad existiert, aber ist " . (is_dir($dir) ? "nicht lesbar" : "kein Verzeichnis"));
                } else {
                    error_log("   ℹ️ Pfad existiert nicht");
                }
            }
        }

        if (empty($existingDirs)) {
            error_log("⚠️ Keine gültigen Verzeichnisse für Route-Discovery gefunden!");

            // ✅ Erstelle Verzeichnis falls es nicht existiert
            foreach ($directories as $dir) {
                if (!is_dir($dir)) {
                    error_log("🛠️ Versuche Verzeichnis zu erstellen: {$dir}");
                    if (mkdir($dir, 0755, true)) {
                        error_log("✅ Verzeichnis erstellt: {$dir}");
                    } else {
                        error_log("❌ Konnte Verzeichnis nicht erstellen: {$dir}");
                    }
                }
            }
            return;
        }

        try {
            // ✅ Vereinfachte Route-Discovery ohne komplexe Scanner
            foreach ($existingDirs as $dir) {
                error_log("🔍 Scanne Verzeichnis: {$dir}");
                $this->scanDirectoryForRoutes($router, $dir);
            }

            // Zeige gefundene Routen
            $routes = $router->getRoutes();
            $totalRoutes = array_sum(array_map('count', $routes));
            error_log("📋 Insgesamt {$totalRoutes} Routen registriert");

            foreach ($routes as $method => $methodRoutes) {
                error_log("   - {$method}: " . count($methodRoutes) . " Routen");
                foreach ($methodRoutes as $route) {
                    error_log("     → {$route->originalPath} -> {$route->actionClass}");
                }
            }

        } catch (Throwable $e) {
            error_log("❌ Route-Discovery Fehler: " . $e->getMessage());
            error_log("   Datei: " . $e->getFile() . ":" . $e->getLine());
        }
    }

    /**
     * ✅ NEUE METHODE: Vereinfachte Verzeichnis-Scanner
     */
    private function scanDirectoryForRoutes(Router $router, string $directory): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php' && $file->isFile()) {
                $this->scanFileForRoutes($router, $file->getPathname());
            }
        }
    }

    /**
     * ✅ NEUE METHODE: Vereinfachte Datei-Scanner
     */
    private function scanFileForRoutes(Router $router, string $filePath): void
    {
        try {
            $content = file_get_contents($filePath);
            if ($content === false || !str_contains($content, '#[Route')) {
                return;
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
                return;
            }

            $fullClassName = $namespace . '\\' . $className;

            // Prüfe ob Klasse existiert
            if (!class_exists($fullClassName)) {
                error_log("⚠️ Klasse nicht gefunden: {$fullClassName}");
                return;
            }

            $reflection = new ReflectionClass($fullClassName);
            $attributes = $reflection->getAttributes(\Framework\Routing\Attributes\Route::class);

            if (empty($attributes)) {
                return;
            }

            error_log("🎯 Routen gefunden in: {$fullClassName}");

            // Registriere alle Route-Attribute
            foreach ($attributes as $attribute) {
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
            }

        } catch (Throwable $e) {
            error_log("❌ Fehler beim Scannen von {$filePath}: " . $e->getMessage());
        }
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