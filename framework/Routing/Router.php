<?php

declare(strict_types=1);

namespace Framework\Routing;

use Framework\Container\ContainerInterface;
use Framework\Http\{Request, Response};
use Framework\Routing\Exceptions\{MethodNotAllowedException, RouteNotFoundException};
use InvalidArgumentException;
use RuntimeException;

/**
 * Optimized Router with RouterCore delegation for performance
 */
final class Router
{
    // Property Hooks für computed properties
    public int $routeCount {
        get => $this->cachedRouteCount ??= $this->calculateRouteCount();
    }

    public bool $isCompiled {
        get => $this->routesCompiled;
    }

    public array $supportedMethods {
        get => array_keys($this->routes);
    }

    // Core routing data
    private array $routes = [];
    private array $namedRoutes = [];
    private bool $routesCompiled = false;
    private ?int $cachedRouteCount = null;

    // Performance core
    private ?RouterCore $core = null;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ?RouteCache        $cache = null,
        private readonly ?RouteDiscovery    $discovery = null,
        private readonly bool               $debugMode = false,
        private readonly bool               $strictMode = true,
        private readonly array              $allowedSubdomains = ['api', 'admin', 'www'],
        private readonly string             $baseDomain = 'localhost'
    )
    {
        $this->loadCachedRoutes();
    }

    /**
     * Create router with default configuration
     */
    public static function create(ContainerInterface $container, array $config = []): self
    {
        $cache = isset($config['cache_dir'])
            ? new RouteCache($config['cache_dir'], ...$config['cache'] ?? [])
            : null;

        $discovery = isset($config['discovery'])
            ? RouteDiscovery::create(new self($container), $config['discovery'])
            : null;

        return new self(
            container: $container,
            cache: $cache,
            discovery: $discovery,
            debugMode: $config['debug'] ?? false,
            strictMode: $config['strict'] ?? true,
            allowedSubdomains: $config['allowed_subdomains'] ?? ['api', 'admin', 'www'],
            baseDomain: $config['base_domain'] ?? 'localhost'
        );
    }

    /**
     * High-Performance dispatch - delegates to RouterCore
     */
    public function dispatch(Request $request): Response
    {
        // ✅ Production: Use RouterCore for maximum performance
        if (!$this->debugMode && $this->hasCompiledRoutes()) {
            return $this->getCore()->dispatch($request);
        }

        // ✅ Debug/Development: Use original logic with full debugging
        return $this->dispatchOriginal($request);
    }

    /**
     * Check if we have compiled routes available
     */
    private function hasCompiledRoutes(): bool
    {
        return RouteCacheBuilder::validateCache();
    }

    /**
     * Get RouterCore instance (lazy initialization)
     */
    private function getCore(): RouterCore
    {
        if ($this->core === null) {
            $this->core = new RouterCore(
                $this->container,
                $this->debugMode,
                $this->allowedSubdomains,
                $this->baseDomain
            );
        }

        return $this->core;
    }

    /**
     * Original dispatch method for debug/development
     */
    private function dispatchOriginal(Request $request): Response
    {
        $this->validateRequest($request);
        $this->compileRoutes();

        $method = strtoupper($request->method);
        $path = $this->sanitizePath($request->path);
        $subdomain = $this->extractSubdomain($request->host());

        // Debug logging nur im Debug-Mode
        if ($this->debugMode && str_contains($path, 'user')) {
            error_log("=== ROUTER DEBUG ===");
            error_log("Method: {$method}, Path: {$path}, Subdomain: " . ($subdomain ?? 'none'));
        }

        $matchResult = $this->findMatchingRoute($method, $path, $subdomain);

        if ($matchResult === null) {
            $this->handleNoMatch($method, $path, $subdomain);
        }

        [$route, $params] = $matchResult;
        return $this->callAction($route->actionClass, $request, $params);
    }

    /**
     * Auto-discover routes
     */
    public function autoDiscoverRoutes(array $directories = []): void
    {
        if ($this->discovery === null) {
            throw new RuntimeException('RouteDiscovery not configured');
        }

        if (empty($directories)) {
            $directories = [
                'app/Actions',
                'app/Controllers'
            ];
        }

        $existingDirs = array_filter($directories, 'is_dir');
        if (!empty($existingDirs)) {
            $this->discovery->discover($existingDirs);

            // ✅ Auto-build cache after discovery
            $this->buildCache();
        }
    }

    /**
     * Build route cache for production performance
     */
    public function buildCache(): void
    {
        RouteCacheBuilder::buildFromRouter($this);

        // Clear RouterCore cache to force reload
        if ($this->core !== null) {
            RouterCore::clearCache();
            $this->core = null;
        }
    }

    /**
     * Add route to router
     */
    public function addRoute(
        string  $method,
        string  $path,
        string  $actionClass,
        array   $middleware = [],
        ?string $name = null,
        ?string $subdomain = null
    ): void
    {
        $this->validateMiddleware($middleware);
        $this->validateRouteName($name);
        $this->validateSubdomain($subdomain);

        // Create route info
        $routeInfo = RouteInfo::fromPath(
            strtoupper($method),
            $path,
            $actionClass,
            $middleware,
            $name,
            $subdomain
        );

        // Store route
        $method = strtoupper($method);
        $this->routes[$method] ??= [];
        $this->routes[$method][] = $routeInfo;

        // Store named route
        if ($name !== null) {
            if (isset($this->namedRoutes[$name])) {
                throw new InvalidArgumentException("Route name '{$name}' already exists");
            }
            $this->namedRoutes[$name] = $routeInfo;
        }

        // Reset compilation state
        $this->routesCompiled = false;
        $this->cachedRouteCount = null;
    }

    /**
     * Generate URL for named route
     */
    public function url(string $name, array $params = [], ?string $subdomain = null): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new RouteNotFoundException("Named route '{$name}' not found");
        }

        $route = $this->namedRoutes[$name];
        $path = $route->originalPath;

        // Replace parameters
        foreach ($params as $key => $value) {
            $sanitizedKey = $this->sanitizeParameterKey($key);
            $sanitizedValue = $this->sanitizeParameterValue($value);
            $path = str_replace("{{$sanitizedKey}}", $sanitizedValue, $path);
        }

        // Check for missing parameters
        if (preg_match('/{[^}]+}/', $path)) {
            throw new InvalidArgumentException("Missing parameters for route '{$name}'");
        }

        // Add subdomain if specified
        $routeSubdomain = $subdomain ?? $route->subdomain;
        if ($routeSubdomain !== null) {
            return "//{$routeSubdomain}.{$this->baseDomain}{$path}";
        }

        return $path;
    }

    /**
     * Check if route exists
     */
    public function hasRoute(string $method, string $path, ?string $subdomain = null): bool
    {
        $this->compileRoutes();

        $method = strtoupper($method);
        $path = $this->sanitizePath($path);

        return $this->findMatchingRoute($method, $path, $subdomain) !== null;
    }

    /**
     * Get all registered routes
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * Get named routes
     */
    public function getNamedRoutes(): array
    {
        return $this->namedRoutes;
    }

    /**
     * Get router statistics including RouterCore stats
     */
    public function getStats(): array
    {
        $baseStats = [
            'route_count' => $this->routeCount,
            'named_routes' => count($this->namedRoutes),
            'supported_methods' => $this->supportedMethods,
            'is_compiled' => $this->isCompiled,
            'cache_available' => $this->hasCompiledRoutes(),
            'using_core' => !$this->debugMode && $this->hasCompiledRoutes(),
        ];

        // Add RouterCore stats if available
        if ($this->core !== null) {
            $baseStats['core_stats'] = $this->core->getStats();
        }

        // Add cache stats if available
        $cacheStats = RouteCacheBuilder::getCacheStats();
        if ($cacheStats !== null) {
            $baseStats['cache_stats'] = $cacheStats;
        }

        return $baseStats;
    }

    /**
     * Warm up router caches
     */
    public function warmUp(): void
    {
        // Build cache if not exists
        if (!$this->hasCompiledRoutes()) {
            $this->buildCache();
        }

        // Pre-initialize RouterCore
        $this->getCore();
    }

    /**
     * Clear all caches
     */
    public function clearCaches(): void
    {
        $this->cache?->clear();
        RouteCacheBuilder::clearCache();

        if ($this->core !== null) {
            RouterCore::clearCache();
            $this->core = null;
        }
    }

    // ===== PRIVATE HELPER METHODS =====

    /**
     * Load cached routes
     */
    private function loadCachedRoutes(): void
    {
        if ($this->cache === null || $this->debugMode) {
            return;
        }

        $cached = $this->cache->load();
        if ($cached !== null && $this->validateCachedRoutes($cached)) {
            $this->routes = $cached;
            $this->rebuildNamedRoutes();
        }
    }

    /**
     * Validate cached routes
     */
    private function validateCachedRoutes(array $cached): bool
    {
        foreach ($cached as $method => $routes) {
            if (!is_string($method) || !is_array($routes)) {
                return false;
            }

            foreach ($routes as $route) {
                if (!($route instanceof RouteInfo) || !class_exists($route->actionClass)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Rebuild named routes from cached data
     */
    private function rebuildNamedRoutes(): void
    {
        $this->namedRoutes = [];
        foreach ($this->routes as $routes) {
            foreach ($routes as $route) {
                if ($route->name !== null) {
                    $this->namedRoutes[$route->name] = $route;
                }
            }
        }
    }

    /**
     * Validate request
     */
    private function validateRequest(Request $request): void
    {
        if (strlen($request->path) > 2048) {
            throw new InvalidArgumentException("Request path too long");
        }

        if (str_contains($request->path, "\0") || str_contains($request->uri, "\0")) {
            throw new InvalidArgumentException("Request contains null bytes");
        }

        if (preg_match('/^[A-Z]:[\\\\\/]/', $request->path)) {
            throw new InvalidArgumentException("Absolute file paths not allowed in URL");
        }
    }

    /**
     * Compile routes for optimized matching
     */
    private function compileRoutes(): void
    {
        if ($this->routesCompiled) {
            return;
        }

        $this->routesCompiled = true;

        // Store in cache if available
        if ($this->cache !== null && !$this->debugMode) {
            $this->cache->store($this->routes);
        }
    }

    /**
     * Sanitize path - from Phase 1 security fix
     */
    private function sanitizePath(string $path): string
    {
        $dangerous = [
            '../', '..\\', '..../', '...//', '....//',
            '%2e%2e%2f', '%2e%2e%5c', '%2e%2e/',
            '%2E%2E%2F', '%2E%2E%5C', '%2E%2E/',
            "\0", '/./', '/.//', '/../',
            '%00', '%2F%2E%2E', '%5C%2E%2E'
        ];

        do {
            $before = $path;
            $path = str_replace($dangerous, '', $path);
        } while ($before !== $path);

        if (preg_match('/^[A-Z]:[\\\\\/]/', $path)) {
            throw new InvalidArgumentException('Absolute file paths not allowed in URL');
        }

        $cleaned = str_replace('\\', '/', $path);
        if (!str_starts_with($cleaned, '/')) {
            $cleaned = '/' . $cleaned;
        }
        $cleaned = preg_replace('#/+#', '/', $cleaned);

        if (strlen($cleaned) > 2048) {
            throw new InvalidArgumentException('Path too long');
        }

        return $cleaned;
    }

    /**
     * Extract subdomain from host
     */
    private function extractSubdomain(string $host): ?string
    {
        $hostWithoutPort = explode(':', $host)[0];

        if ($hostWithoutPort === 'localhost' || filter_var($hostWithoutPort, FILTER_VALIDATE_IP)) {
            return null;
        }

        if (str_ends_with($hostWithoutPort, '.local') || str_ends_with($hostWithoutPort, '.localhost')) {
            return null;
        }

        $parts = explode('.', $hostWithoutPort);
        if (count($parts) < 3) {
            return null;
        }

        $subdomain = $parts[0];
        if (!$this->isValidSubdomain($subdomain)) {
            return null;
        }

        if ($this->strictMode && !in_array($subdomain, $this->allowedSubdomains, true)) {
            throw new InvalidArgumentException("Subdomain not allowed: {$subdomain}");
        }

        return $subdomain;
    }

    /**
     * Find matching route
     */
    private function findMatchingRoute(string $method, string $path, ?string $subdomain): ?array
    {
        if (!isset($this->routes[$method])) {
            return null;
        }

        foreach ($this->routes[$method] as $route) {
            if ($route->matches($method, $path, $subdomain)) {
                try {
                    $params = $route->extractParams($path);
                    return [$route, $params];
                } catch (InvalidArgumentException) {
                    continue;
                }
            }
        }

        return null;
    }

    /**
     * Call action class
     */
    private function callAction(string $actionClass, Request $request, array $params): Response
    {
        $action = $this->container->get($actionClass);

        if (!is_callable($action)) {
            throw new RuntimeException("Action {$actionClass} is not callable");
        }

        $result = $action($request, $params);

        return match (true) {
            $result instanceof Response => $result,
            is_array($result) || is_object($result) => Response::json($result),
            default => Response::html(htmlspecialchars((string)$result, ENT_QUOTES | ENT_HTML5, 'UTF-8'))
        };
    }

    /**
     * Handle no route match
     */
    private function handleNoMatch(string $method, string $path, ?string $subdomain): never
    {
        $availableMethods = $this->getAvailableMethodsForPath($path, $subdomain);

        if (!empty($availableMethods)) {
            throw new MethodNotAllowedException(
                "Method {$method} not allowed for path {$path}",
                $availableMethods
            );
        }

        throw new RouteNotFoundException("Route not found: {$method} {$path}" .
            ($subdomain ? " (subdomain: {$subdomain})" : ""));
    }

    /**
     * Get available methods for path
     */
    private function getAvailableMethodsForPath(string $path, ?string $subdomain): array
    {
        $methods = [];
        foreach ($this->routes as $method => $routes) {
            if ($this->findMatchingRoute($method, $path, $subdomain) !== null) {
                $methods[] = $method;
            }
        }
        return $methods;
    }

    /**
     * Validation helper methods
     */
    private function validateMiddleware(array $middleware): void
    {
        if (count($middleware) > 10) {
            throw new InvalidArgumentException('Too many middleware (max 10)');
        }

        foreach ($middleware as $mw) {
            if (!is_string($mw) || strlen($mw) > 100) {
                throw new InvalidArgumentException('Invalid middleware specification');
            }
        }
    }

    private function validateRouteName(?string $name): void
    {
        if ($name === null) {
            return;
        }

        if (strlen($name) > 255 || !preg_match('/^[a-zA-Z0-9._-]+$/', $name)) {
            throw new InvalidArgumentException("Invalid route name: {$name}");
        }
    }

    private function validateSubdomain(?string $subdomain): void
    {
        if ($subdomain !== null && !$this->isValidSubdomain($subdomain)) {
            throw new InvalidArgumentException("Invalid subdomain: {$subdomain}");
        }
    }

    private function isValidSubdomain(string $subdomain): bool
    {
        return strlen($subdomain) <= 63 &&
            preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?$/', $subdomain) === 1;
    }

    private function sanitizeParameterKey(string $key): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) {
            throw new InvalidArgumentException("Invalid parameter key: {$key}");
        }
        return $key;
    }

    private function sanitizeParameterValue(mixed $value): string
    {
        $stringValue = (string)$value;

        if (strlen($stringValue) > 255) {
            throw new InvalidArgumentException("Parameter value too long");
        }

        if (str_contains($stringValue, "\0")) {
            throw new InvalidArgumentException("Parameter contains null bytes");
        }

        return urlencode($stringValue);
    }

    private function calculateRouteCount(): int
    {
        return array_sum(array_map('count', $this->routes));
    }
}