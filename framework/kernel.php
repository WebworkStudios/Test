<?php

declare(strict_types=1);

namespace Framework;

use Framework\Container\Container;
use Framework\Http\{Request, RequestSanitizer, Response};
use framework\Http\Session\Session;
use Framework\Routing\{Router};
use Framework\Routing\Exceptions\{MethodNotAllowedException, RouteNotFoundException};
use Framework\Security\Csrf\CsrfProtection;
use Throwable;

/**
 * Enhanced Kernel with simplified, secure Route Discovery
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

            // Register Router - simplified without immediate discovery
            $this->container->singleton(Router::class, function (Container $c) {
                error_log("🔄 Creating router instance...");

                $routingConfig = $this->config['routing'] ?? [];

                $router = Router::create($c, [
                    'debug' => $this->config['app']['debug'] ?? false,
                    'strict' => $routingConfig['strict'] ?? false,
                    'cache_dir' => $routingConfig['cache_dir'] ?? __DIR__ . '/../storage/cache/routes',
                    'cache' => $routingConfig['cache'] ?? [],
                    'allowed_subdomains' => $routingConfig['allowed_subdomains'] ?? ['api', 'admin', 'www'],
                    'base_domain' => $this->config['app']['domain'] ?? 'localhost'
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
            // Comprehensive request validation with RequestSanitizer
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
     * Boot the kernel and all services
     */
    /**
     * ✅ Cache-Reset bei Boot-Problem
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        try {
            // Start session
            $session = $this->container->get(Session::class);
            $session->start();

            // ✅ CACHE-RESET: Lösche Cache wenn Debug-Mode
            if (($this->config['app']['debug'] ?? false)) {
                $router = $this->container->get(Router::class);
                $router->clearCaches();
                error_log("🧹 Debug mode: Caches cleared");
            }

            // Route-Discovery
            if (($this->config['routing']['auto_discover'] ?? true)) {
                $router = $this->container->get(Router::class);
                $this->performRouteDiscovery($router);
            }

            $this->bootProviders();
            $this->booted = true;

        } catch (Throwable $e) {
            error_log("❌ Kernel::boot() exception: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * ✅ EINFACHSTE LÖSUNG: Discovery immer im Debug-Modus ausführen
     */
    private function performRouteDiscovery(Router $router): void
    {
        // ✅ FIX: Im Debug-Modus immer Discovery ausführen, auch wenn Routen existieren
        $currentRoutes = $router->getRoutes();
        $isDebugMode = $this->config['app']['debug'] ?? false;

        if (!empty($currentRoutes) && !$isDebugMode) {
            error_log("ℹ️ Routes already registered, skipping discovery (production mode)");
            return;
        }

        if (!empty($currentRoutes) && $isDebugMode) {
            error_log("🔄 Routes exist but running discovery anyway (debug mode)");
        }

        $directories = $this->config['routing']['discovery_paths'] ?? [
            __DIR__ . '/../app/Actions',
            __DIR__ . '/../app/Controllers'
        ];

        try {
            // ✅ DIREKTE DISCOVERY: Verwende Router's autoDiscoverRoutes
            $router->autoDiscoverRoutes($directories);

            $routes = $router->getRoutes();
            $totalRoutes = array_sum(array_map('count', $routes));
            error_log("📋 Total {$totalRoutes} routes registered");

        } catch (Throwable $e) {
            error_log("❌ Route-Discovery error: " . $e->getMessage());

            // ✅ ORIGINAL FALLBACK: Direkte Klassen-Registrierung
            if (class_exists('App\\Actions\\HomeAction')) {
                $router->addRoute('GET', '/', 'App\\Actions\\HomeAction', [], 'home');
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