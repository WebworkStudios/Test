<?php

declare(strict_types=1);

namespace Framework;

use Framework\Container\Container;
use Framework\Http\{Request, RequestSanitizer, Response};
use framework\Http\Session\Session;
use Framework\Routing\{Exceptions\MethodNotAllowedException, Exceptions\RouteNotFoundException, Router};
use Framework\Security\Csrf\CsrfProtection;
use RuntimeException;
use Throwable;

/**
 * Enhanced Kernel with Router Performance Integration and Fail Fast Discovery
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

            // ✅ Router-Registrierung ohne sofortige Discovery
            $this->container->singleton(Router::class, function (Container $c) {
                error_log("🔄 Creating router instance...");

                $routingConfig = $this->config['routing'] ?? [];

                $router = Router::create($c, [
                    'debug' => $this->config['app']['debug'] ?? false,
                    'strict' => $routingConfig['strict'] ?? false,
                    'cache_dir' => $routingConfig['cache_dir'] ?? __DIR__ . '/../storage/cache/routes',
                    'cache' => $routingConfig['cache'] ?? false,
                    'allowed_subdomains' => $routingConfig['allowed_subdomains'] ?? ['api', 'admin', 'www'],
                    'base_domain' => $this->config['app']['domain'] ?? 'localhost',
                    'discovery' => [
                        'strict_mode' => false,
                        'max_depth' => 5,
                        'max_file_size' => 2097152
                    ]
                ]);

                error_log("✅ Router instance created");
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
     * Handle incoming HTTP request with performance optimizations
     */
    public function handle(Request $request): Response
    {
        error_log("=== Kernel::handle() START ===");
        error_log("Request: " . $request->method . " " . $request->path);

        try {
            // ✅ Umfassende Request-Validierung mit RequestSanitizer
            error_log("🔒 Validating request security...");
            RequestSanitizer::validateRequest($request);
            error_log("✅ Request security validation passed");

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
     * Boot the kernel and all services with Eager Loading
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

            // ✅ FIX: Router eager laden und Discovery sofort ausführen
            if (($this->config['routing']['auto_discover'] ?? true)) {
                error_log("🔄 Eager loading router with discovery...");
                $router = $this->container->get(Router::class);

                // Force Discovery jetzt - nicht lazy
                if (empty($router->getRoutes())) {
                    error_log("🔄 Router has no routes - starting discovery...");
                    $this->performSingleRouteDiscovery($router);

                    // Prüfe ob Routen registriert wurden
                    $routeCount = array_sum(array_map('count', $router->getRoutes()));
                    error_log("📋 Discovery result: {$routeCount} routes registered");

                    if ($routeCount === 0) {
                        throw new RuntimeException("Critical: Route discovery completed but no routes were registered");
                    }
                } else {
                    $routeCount = array_sum(array_map('count', $router->getRoutes()));
                    error_log("ℹ️ Router already has {$routeCount} routes");
                }
                error_log("✅ Router eager loading completed");
            }

            error_log("🔄 Booting providers...");
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
     * ✅ VEREINFACHT: Route-Discovery mit Fail Fast Ansatz
     */
    private function performSingleRouteDiscovery(Router $router): void
    {
        $directories = $this->config['routing']['discovery_paths'] ?? [
            __DIR__ . '/../app/Actions',
            __DIR__ . '/../app/Controllers'
        ];

        error_log("🔍 Route-Discovery startet für Verzeichnisse: " . implode(', ', $directories));

        // Debug: Zeige Dateien in den Verzeichnissen
        foreach ($directories as $dir) {
            if (is_dir($dir)) {
                $files = glob($dir . '/*.php');
                error_log("📁 Verzeichnis {$dir} enthält " . count($files) . " PHP-Dateien:");
                foreach ($files as $file) {
                    error_log("   - " . basename($file));

                    $content = file_get_contents($file);
                    if (str_contains($content, '#[Route')) {
                        error_log("     ✅ Enthält Route-Attribute!");
                    } else {
                        error_log("     ❌ Keine Route-Attribute gefunden");
                    }
                }
            }
        }

        // Filtere existierende Verzeichnisse
        $existingDirs = array_filter($directories, function ($dir) {
            $exists = is_dir($dir) && is_readable($dir);
            error_log($exists ? "✅ Verzeichnis OK: {$dir}" : "❌ Verzeichnis fehlt: {$dir}");
            return $exists;
        });

        if (empty($existingDirs)) {
            throw new RuntimeException(
                "❌ Route Discovery failed: No valid directories found.\n" .
                "Searched in: " . implode(', ', $directories) . "\n" .
                "Please check if directories exist and are readable."
            );
        }

        try {
            error_log("🔄 Starting router auto-discovery...");
            $router->autoDiscoverRoutes($existingDirs);

            // Prüfe Ergebnis
            $routes = $router->getRoutes();
            $totalRoutes = array_sum(array_map('count', $routes));
            error_log("📋 Discovery result: {$totalRoutes} routes registered");

            // ✅ KLARER FEHLER statt Fallback
            if ($totalRoutes === 0) {
                $this->diagnoseDiscoveryFailure($existingDirs);

                throw new RuntimeException(
                    "❌ Route Discovery failed: Found 0 routes despite having Action files.\n" .
                    "This indicates a problem with:\n" .
                    "- Route attributes syntax\n" .
                    "- Class autoloading\n" .
                    "- Namespace configuration\n" .
                    "Check the logs above for detailed diagnosis."
                );
            }

            // Success: Zeige gefundene Routen
            foreach ($routes as $method => $methodRoutes) {
                error_log("   {$method}: " . count($methodRoutes) . " Routen");
                foreach ($methodRoutes as $route) {
                    error_log("     - {$route->originalPath} -> {$route->actionClass}");
                }
            }

        } catch (RuntimeException $e) {
            // Re-throw unsere eigenen Fehler
            throw $e;
        } catch (Throwable $e) {
            // Andere Fehler mit Context
            throw new RuntimeException(
                "❌ Route Discovery failed with exception: " . $e->getMessage() . "\n" .
                "File: " . $e->getFile() . ":" . $e->getLine() . "\n" .
                "This usually indicates a problem with Route attribute syntax or class loading.",
                previous: $e
            );
        }
    }

    /**
     * ✅ Diagnose bei Discovery-Fehlern
     */
    private function diagnoseDiscoveryFailure(array $directories): void
    {
        error_log("🔍 Diagnosing discovery failure...");

        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $phpFiles = glob($dir . '/*.php');
            error_log("📁 Analyzing directory: {$dir}");
            error_log("   Found " . count($phpFiles) . " PHP files");

            foreach ($phpFiles as $file) {
                $content = file_get_contents($file);
                $basename = basename($file);

                // Detaillierte Datei-Analyse
                $hasRoute = str_contains($content, '#[Route');
                $hasNamespace = preg_match('/namespace\s+([^;]+);/', $content, $nsMatches);
                $hasClass = preg_match('/class\s+(\w+)/', $content, $classMatches);

                error_log("   📄 {$basename}:");
                error_log("     - Has #[Route: " . ($hasRoute ? 'YES' : 'NO'));
                error_log("     - Has namespace: " . ($hasNamespace ? $nsMatches[1] : 'NO'));
                error_log("     - Has class: " . ($hasClass ? $classMatches[1] : 'NO'));

                if ($hasRoute && $hasNamespace && $hasClass) {
                    $fullClassName = trim($nsMatches[1]) . '\\' . trim($classMatches[1]);
                    $classExists = class_exists($fullClassName);
                    error_log("     - Full class: {$fullClassName}");
                    error_log("     - Class exists: " . ($classExists ? 'YES' : 'NO'));

                    if (!$classExists) {
                        error_log("     ❌ Class not found - possible autoload issue");
                    }
                }
            }
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