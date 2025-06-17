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
        $this->container->instance(self::class, $this);
        $this->container->instance(Kernel::class, $this);

        // Register session
        $this->container->singleton(Session::class, fn() => new Session($this->config['session'] ?? []));

        // Register CSRF protection
        $this->container->singleton(CsrfProtection::class, function (Container $c) {
            return new CsrfProtection($c->get(Session::class));
        });

        // ✅ ENHANCED: Register optimized router
        $this->container->singleton(Router::class, function (Container $c) {
            $router = $this->createOptimizedRouter($c);

            // ✅ Auto-discover routes if enabled
            if ($this->config['routing']['auto_discover'] ?? true) {
                $this->performRouteDiscovery($router);
            }

            // ✅ Build cache for production
            if (!($this->config['debug'] ?? false)) {
                $this->ensureRouteCache($router);
            }

            return $router;
        });
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
     * Enhanced route discovery with error handling
     */
    private function performRouteDiscovery(Router $router): void
    {
        $directories = $this->config['routing']['discovery_paths'] ?? [
            'app/Actions',
            'app/Controllers'
        ];

        $existingDirs = array_filter($directories, function($dir) {
            $realPath = realpath($dir);
            return $realPath !== false && is_dir($realPath);
        });

        if (empty($existingDirs)) {
            if ($this->config['debug'] ?? false) {
                error_log("⚠️ No valid discovery directories found");
            }
            return;
        }

        try {
            $scanner = new RouteFileScanner([
                'strict_mode' => false,
                'max_file_size' => 2097152 // 2MB
            ]);

            $discovery = new RouteDiscovery(
                router: $router,
                scanner: $scanner,
                config: ['strict_mode' => false]
            );

            $discovery->discover($existingDirs);

            if ($this->config['debug'] ?? false) {
                $stats = $discovery->getStats();
                error_log("✅ Route discovery: {$stats['discovered_routes']} routes in {$stats['processed_files']} files");
            }

        } catch (Throwable $e) {
            if ($this->config['debug'] ?? false) {
                error_log("❌ Route discovery failed: " . $e->getMessage());
            }
        }
    }

    /**
     * Ensure route cache exists for production performance
     */
    private function ensureRouteCache(Router $router): void
    {
        try {
            // Check if cache exists and is valid
            if (!RouteCacheBuilder::validateCache()) {
                // Build cache for production performance
                $router->buildCache();

                if ($this->config['debug'] ?? false) {
                    $stats = RouteCacheBuilder::getCacheStats();
                    if ($stats) {
                        error_log("✅ Route cache built: {$stats['routes']['total_routes']} routes");
                        error_log("📈 Expected speedup: {$stats['performance']['expected_speedup']}");
                    }
                }
            }
        } catch (Throwable $e) {
            if ($this->config['debug'] ?? false) {
                error_log("⚠️ Route cache build failed: " . $e->getMessage());
            }
        }
    }

    /**
     * Handle incoming HTTP request with performance optimizations
     */
    public function handle(Request $request): Response
    {
        $this->boot();

        try {
            // ✅ Use optimized router
            $router = $this->container->get(Router::class);
            return $router->dispatch($request);

        } catch (Throwable $e) {
            return $this->handleException($e, $request);
        }
    }

    /**
     * Boot the kernel and all services
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        // Start session
        $session = $this->container->get(Session::class);
        $session->start();

        // Boot service providers
        $this->bootProviders();

        $this->booted = true;
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