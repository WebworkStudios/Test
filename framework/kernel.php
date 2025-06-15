<?php
declare(strict_types=1);

namespace Framework;

use Framework\Container\Container;
use Framework\Http\{Request, Response};
use Framework\Routing\{Router, RouteDiscovery};
use Framework\Security\Csrf\CsrfProtection;
use framework\Http\Session\Session;

/**
 * Framework Kernel - Core application orchestrator
 */
final class Kernel
{
    // Property Hooks for status tracking
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
        // Self-register kernel
        $this->container->instance(self::class, $this);
        $this->container->instance(Kernel::class, $this);

        // Register session
        $this->container->singleton(Session::class, fn() => new Session($this->config['session'] ?? []));

        // Register CSRF protection
        $this->container->singleton(CsrfProtection::class, function (Container $c) {
            return new CsrfProtection($c->get(Session::class));
        });

        // Register router with auto-discovery
        $this->container->singleton(Router::class, function (Container $c) {
            // ✅ Router OHNE Discovery erstellen - Discovery separat hinzufügen
            $router = new Router(
                container: $c,
                cache: null,
                discovery: null, // ⚠️ Nicht hier - verhindert zirkuläre Dependencies
                debugMode: $this->config['debug'] ?? false,
                strictMode: $this->config['routing']['strict'] ?? true
            );

            // ✅ Auto-Discovery NACH Router-Erstellung
            if (($this->config['routing']['auto_discover'] ?? true)) {
                $this->performRouteDiscovery($router);
            }

            return $router;
        });
    }

    private function performRouteDiscovery(Router $router): void
    {
        $directories = $this->config['routing']['discovery_paths'] ?? [
            'app/Actions',
            'app/Controllers'
        ];

        $normalizedDirs = [];
        foreach ($directories as $dir) {
            if (is_string($dir)) {
                $realPath = realpath($dir);
                if ($realPath !== false && is_dir($realPath)) {
                    $normalizedDirs[] = $realPath;
                }
            }
        }

        // ✅ Debug-Logs entfernen oder nur bei debug=true
        if ($this->config['debug'] ?? false) {
            error_log("Looking for routes in: " . implode(', ', $directories));
            error_log("Existing directories: " . implode(', ', $normalizedDirs));
        }

        if (empty($normalizedDirs)) {
            return;
        }

        try {
            $scanner = new \Framework\Routing\RouteFileScanner([
                'strict_mode' => false
            ]);

            $discovery = new \Framework\Routing\RouteDiscovery(
                router: $router,
                scanner: $scanner,
                config: ['strict_mode' => false]
            );

            $discovery->discover($normalizedDirs);

            if ($this->config['debug'] ?? false) {
                $stats = $discovery->getStats();
                error_log("✅ Discovery completed: {$stats['discovered_routes']} routes in {$stats['processed_files']} files");
            }

        } catch (\Throwable $e) {
            if ($this->config['debug'] ?? false) {
                error_log("❌ Route discovery failed: " . $e->getMessage());
            }
        }
    }

    /**
     * Auto-discover routes in default directories
     */
    private function discoverRoutes(Router $router): void
    {
        $directories = $this->config['routing']['discovery_paths'] ?? [
            'app/Actions',
            'app/Controllers'
        ];

        $existingDirs = array_filter($directories, 'is_dir');
        if (empty($existingDirs)) {
            return;
        }

        try {
            $discovery = RouteDiscovery::create($router);
            $discovery->discover($existingDirs);
        } catch (\Throwable $e) {
            if ($this->config['debug'] ?? false) {
                error_log("Route discovery failed: " . $e->getMessage());
            }
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
     * Handle incoming HTTP request
     */
    public function handle(Request $request): Response
    {
        $this->boot();

        try {
            // Get router and dispatch
            $router = $this->container->get(Router::class);
            return $router->dispatch($request);

        } catch (\Throwable $e) {
            return $this->handleException($e, $request);
        }
    }

    /**
     * Handle exceptions and convert to HTTP responses
     */
    private function handleException(\Throwable $e, Request $request): Response
    {
        // Log error in debug mode
        if ($this->config['debug'] ?? false) {
            error_log("Kernel exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        }

        // Return appropriate HTTP response
        return match (true) {
            $e instanceof \Framework\Routing\Exceptions\RouteNotFoundException =>
            Response::notFound('Route not found'),
            $e instanceof \Framework\Routing\Exceptions\MethodNotAllowedException =>
            Response::json(['error' => 'Method not allowed'], 405),
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