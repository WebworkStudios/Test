<?php

declare(strict_types=1);

namespace Framework\Routing;

use Framework\Container\ContainerInterface;
use InvalidArgumentException;
use Framework\Http\{Request, Response};
use Framework\Routing\Exceptions\{MethodNotAllowedException, RouteNotFoundException};
use RuntimeException;
use Throwable;

/**
 * Optimized HTTP Router with PHP 8.4 features
 */
final class Router
{
    // PHP 8.4 Property Hooks for computed properties
    public int $routeCount {
        get => $this->cachedRouteCount ??= $this->calculateRouteCount();
    }

    public bool $isCompiled {
        get => $this->routesCompiled;
    }

    public array $supportedMethods {
        get => array_keys($this->routes);
    }

    public int $staticRouteCount {
        get => array_sum(array_map('count', $this->staticRoutes));
    }

    public int $dynamicRouteCount {
        get => $this->routeCount - $this->staticRouteCount;
    }

    // Core routing data
    private array $routes = [];
    private array $namedRoutes = [];
    private array $staticRoutes = [];
    private array $dynamicRoutes = [];
    private bool $routesCompiled = false;

    // Performance optimizations
    private array $routeCache = [];

    private ?int $cachedRouteCount = null;
    private int $maxCacheSize = 500;

    // Performance tracking
    private int $dispatchCount = 0;
    private int $cacheHits = 0;
    private float $totalDispatchTime = 0.0;

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
            $this->routesCompiled = false; // Will be compiled on first use
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
                if (!($route instanceof RouteInfo)) {
                    return false;
                }

                // Validate action class still exists
                if (!class_exists($route->actionClass)) {
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
                'app/Controllers',
                'app/Http/Actions',
                'app/Http/Controllers'
            ];
        }

        $existingDirs = array_filter($directories, 'is_dir');
        if (!empty($existingDirs)) {
            $this->discovery->discover($existingDirs);
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

        // Create route info (Validierung erfolgt hier automatisch)
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
        $this->clearCaches();
    }

    /**
     * Validate middleware array
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

    /**
     * Validate route name
     */
    private function validateRouteName(?string $name): void
    {
        if ($name === null) {
            return;
        }

        if (strlen($name) > 255 || !preg_match('/^[a-zA-Z0-9._-]+$/', $name)) {
            throw new InvalidArgumentException("Invalid route name: {$name}");
        }
    }

    /**
     * Validate subdomain input
     */
    private function validateSubdomain(?string $subdomain): void
    {
        if ($subdomain !== null && !$this->isValidSubdomain($subdomain)) {
            throw new InvalidArgumentException("Invalid subdomain: {$subdomain}");
        }
    }

    /**
     * Validate subdomain
     */
    private function isValidSubdomain(string $subdomain): bool
    {
        if (strlen($subdomain) > 63 || strlen($subdomain) === 0) {
            return false;
        }

        return preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?$/', $subdomain) === 1;
    }

    /**
     * Clear all caches
     */
    public function clearCaches(): void
    {
        $this->routeCache = [];
        $this->cache?->clear();
    }

    /**
     * Dispatch HTTP request
     */
    public function dispatch(Request $request): Response
    {
        $startTime = hrtime(true);
        $this->dispatchCount++;

        try {
            $this->validateRequest($request);
            $this->compileRoutes();

            $method = strtoupper($request->method);
            $path = $this->sanitizePath($request->path);
            $subdomain = $this->extractSubdomain($request->host());

            // Debug nur bei spezifischen Problemen
            if ($this->debugMode && ($path === '/user/123' || str_contains($path, 'E:'))) {
                error_log("=== ROUTER DEBUG ===");
                error_log("Raw URI: {$request->uri}");
                error_log("Raw path: {$request->path}");
                error_log("Sanitized path: {$path}");
                error_log("Method: {$method}");
                error_log("Host: {$request->host()}");
                error_log("Subdomain: " . ($subdomain ?? 'none'));
            }

            // Check cache first
            $cacheKey = $this->generateCacheKey($method, $path, $subdomain);

            if (isset($this->routeCache[$cacheKey])) {
                $cachedRoute = $this->routeCache[$cacheKey];
                if ($this->isCacheValid($cachedRoute, $request)) {
                    $this->cacheHits++;
                    $params = $cachedRoute['route']->extractParams($path);
                    return $this->callAction($cachedRoute['route']->actionClass, $request, $params);
                } else {
                    unset($this->routeCache[$cacheKey]);
                }
            }

            // Find matching route
            $matchResult = $this->findMatchingRoute($method, $path, $subdomain);

            if ($matchResult === null) {
                $this->handleNoMatch($method, $path, $subdomain);
            }

            [$route, $params] = $matchResult;

            // Cache successful match
            $this->cacheMatch($cacheKey, $route, $params);

            return $this->callAction($route->actionClass, $request, $params);

        } catch (Throwable $e) {
            if ($this->debugMode) {
                error_log("Router dispatch error: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
            }
            throw $e;
        } finally {
            $this->totalDispatchTime += (hrtime(true) - $startTime) / 1_000_000;
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

        // Check for absolute paths (Windows drive letters)
        if (preg_match('/^[A-Z]:/i', $request->path) || preg_match('/^[A-Z]:/i', $request->uri)) {
            throw new InvalidArgumentException("Absolute file paths not allowed in URL");
        }

        if (!$this->isValidHost($request->host())) {
            throw new InvalidArgumentException("Invalid host header");
        }
    }

    /**
     * Validate host header
     */
    private function isValidHost(string $host): bool
    {
        $hostWithoutPort = explode(':', $host)[0];

        // Allow localhost for development
        if ($hostWithoutPort === 'localhost' || str_ends_with($hostWithoutPort, '.localhost') || str_ends_with($hostWithoutPort, '.local')) {
            return true;
        }

        // Validate IP addresses
        if (filter_var($hostWithoutPort, FILTER_VALIDATE_IP)) {
            return true;
        }

        // Validate domain format
        if (!preg_match('/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $hostWithoutPort)) {
            return false;
        }

        return true;
    }

    /**
     * Compile routes for optimized matching
     */
    private function compileRoutes(): void
    {
        if ($this->routesCompiled) {
            return;
        }

        $this->staticRoutes = [];
        $this->dynamicRoutes = [];

        foreach ($this->routes as $method => $routes) {
            $this->staticRoutes[$method] = [];
            $this->dynamicRoutes[$method] = [];

            foreach ($routes as $route) {
                if ($route->isStatic) {
                    // Static route - direct lookup
                    $key = $this->generateStaticKey($route->originalPath, $route->subdomain);
                    $this->staticRoutes[$method][$key] = $route;
                } else {
                    // Dynamic route - pattern matching required
                    $this->dynamicRoutes[$method][] = $route;
                }
            }

            // Sort dynamic routes for optimal matching
            $this->optimizeDynamicRoutes($method);
        }

        $this->routesCompiled = true;

        // Store in cache if available
        if ($this->cache !== null && !$this->debugMode) {
            $this->cache->store($this->routes);
        }
    }

    /**
     * Generate static route key
     */
    private function generateStaticKey(string $path, ?string $subdomain): string
    {
        return $subdomain ? "{$subdomain}:{$path}" : $path;
    }

    /**
     * Optimize dynamic routes order for better performance
     */
    private function optimizeDynamicRoutes(string $method): void
    {
        if (!isset($this->dynamicRoutes[$method])) {
            return;
        }

        usort($this->dynamicRoutes[$method], function (RouteInfo $a, RouteInfo $b): int {
            // Routes with fewer parameters first
            $paramDiff = count($a->paramNames) - count($b->paramNames);
            if ($paramDiff !== 0) {
                return $paramDiff;
            }

            // Routes with subdomain constraints first
            if ($a->subdomain !== null && $b->subdomain === null) {
                return -1;
            }
            if ($a->subdomain === null && $b->subdomain !== null) {
                return 1;
            }

            // Shorter paths first
            return strlen($a->originalPath) - strlen($b->originalPath);
        });
    }

    /**
     * Sanitize path with improved Windows path detection
     */
    private function sanitizePath(string $path): string
    {
        // Remove dangerous sequences
        $cleaned = str_replace(['../', '.\\', '..\\', "\0"], '', $path);

        // Handle Windows drive letters (absolute paths) - reject immediately
        if (preg_match('/^[A-Z]:/i', $cleaned)) {
            throw new InvalidArgumentException('Absolute file paths not allowed in URL');
        }

        // Remove any backslashes (Windows path separators)
        $cleaned = str_replace('\\', '/', $cleaned);

        // Ensure path starts with /
        if (!str_starts_with($cleaned, '/')) {
            $cleaned = '/' . $cleaned;
        }

        // Remove double slashes
        $cleaned = preg_replace('#/+#', '/', $cleaned);

        // Additional validation
        if (strlen($cleaned) > 2048) {
            throw new InvalidArgumentException('Path too long');
        }

        return $cleaned;
    }

    /**
     * Extract subdomain from host
     */
    /**
     * Extract subdomain from host - korrigierte Version
     */
    private function extractSubdomain(string $host): ?string
    {
        $hostWithoutPort = explode(':', $host)[0];

        // Handle localhost und IP-Adressen - KEINE Subdomain
        if ($hostWithoutPort === 'localhost' || filter_var($hostWithoutPort, FILTER_VALIDATE_IP)) {
            return null;
        }

        // Handle .local domains (Development) - KEINE Subdomain-Extraktion
        if (str_ends_with($hostWithoutPort, '.local')) {
            // Für .local domains extrahieren wir KEINE Subdomain
            // kickerscup.local -> null (nicht "kickerscup")
            return null;
        }

        // Handle .localhost domains
        if (str_ends_with($hostWithoutPort, '.localhost')) {
            $parts = explode('.', $hostWithoutPort);
            if (count($parts) >= 3) { // Nur echte Subdomains wie api.app.localhost
                $subdomain = $parts[0];
                return $this->isValidSubdomain($subdomain) ? $subdomain : null;
            }
            return null; // app.localhost -> null
        }

        // Handle production domains (mindestens 3 Teile für Subdomain)
        $parts = explode('.', $hostWithoutPort);
        if (count($parts) < 3) {
            return null; // example.com -> null
        }

        $subdomain = $parts[0];
        if (!$this->isValidSubdomain($subdomain)) {
            return null;
        }

        // In production strict mode checken
        if ($this->strictMode && !in_array($subdomain, $this->allowedSubdomains, true)) {
            throw new InvalidArgumentException("Subdomain not allowed: {$subdomain}");
        }

        return $subdomain;
    }

    /**
     * Generate cache key
     */
    private function generateCacheKey(string $method, string $path, ?string $subdomain): string
    {
        return hash('xxh3', $method . ':' . $path . ':' . ($subdomain ?? ''));
    }

    /**
     * Check if cache entry is valid
     */
    private function isCacheValid(array $cachedRoute, Request $request): bool
    {
        // Simple validation - could be extended
        return isset($cachedRoute['route']) && $cachedRoute['route'] instanceof RouteInfo;
    }

    /**
     * Call action class
     */
    private function callAction(string $actionClass, Request $request, array $params): Response
    {
        $this->validateActionCall($actionClass, $params);

        $action = $this->container->get($actionClass);

        if (!is_callable($action)) {
            throw new RuntimeException("Action {$actionClass} is not callable");
        }

        try {
            $result = $action($request, $params);
            return $this->convertToResponse($result);
        } catch (Throwable $e) {
            if ($this->debugMode) {
                error_log("Action execution error in {$actionClass}: " . $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * Validate action call
     */
    private function validateActionCall(string $actionClass, array $params): void
    {
        if (count($params) > 20) {
            throw new InvalidArgumentException("Too many route parameters");
        }

        foreach ($params as $key => $value) {
            if (strlen($key) > 50 || strlen((string)$value) > 1000) {
                throw new InvalidArgumentException("Parameter too large: {$key}");
            }
        }
    }

    /**
     * Convert result to Response
     */
    private function convertToResponse(mixed $result): Response
    {
        return match (true) {
            $result instanceof Response => $result,
            is_array($result) || is_object($result) => Response::json($result),
            default => Response::html(htmlspecialchars((string)$result, ENT_QUOTES | ENT_HTML5, 'UTF-8'))
        };
    }

    /**
     * Find matching route
     */
    private function findMatchingRoute(string $method, string $path, ?string $subdomain): ?array
    {
        if ($this->debugMode && ($path === '/user/123' || str_contains($path, 'user'))) {
            error_log("=== FINDING MATCHING ROUTE ===");
            error_log("Looking for: {$method} {$path} (subdomain: " . ($subdomain ?? 'none') . ")");
        }

        if (!isset($this->routes[$method])) {
            if ($this->debugMode) {
                error_log("❌ No routes for method: {$method}");
                error_log("Available methods: " . implode(', ', array_keys($this->routes)));
            }
            return null;
        }

        if ($this->debugMode && str_contains($path, 'user')) {
            error_log("Routes available for {$method}: " . count($this->routes[$method]));
        }

        // Try static routes first (O(1) lookup)
        if (isset($this->staticRoutes[$method])) {
            $staticKey = $this->generateStaticKey($path, $subdomain);

            if (isset($this->staticRoutes[$method][$staticKey])) {
                if ($this->debugMode && str_contains($path, 'user')) {
                    error_log("✅ Static route match found");
                }
                return [$this->staticRoutes[$method][$staticKey], []];
            }
        }

        // Try dynamic routes
        if (isset($this->dynamicRoutes[$method])) {
            if ($this->debugMode && str_contains($path, 'user')) {
                error_log("Checking " . count($this->dynamicRoutes[$method]) . " dynamic routes");
            }

            foreach ($this->dynamicRoutes[$method] as $index => $route) {
                if ($this->debugMode && str_contains($path, 'user')) {
                    error_log("Testing route #{$index}: {$route->originalPath} -> {$route->actionClass}");
                }

                if ($route->matches($method, $path, $subdomain)) {
                    if ($this->debugMode && str_contains($path, 'user')) {
                        error_log("✅ Route matches! Extracting parameters...");
                    }
                    try {
                        $params = $route->extractParams($path);
                        if ($this->debugMode && str_contains($path, 'user')) {
                            error_log("Parameters extracted: " . json_encode($params));
                        }
                        return [$route, $params];
                    } catch (InvalidArgumentException $e) {
                        if ($this->debugMode) {
                            error_log("❌ Parameter extraction failed: " . $e->getMessage());
                        }
                        continue; // Try next route
                    }
                }
            }
        }

        if ($this->debugMode && str_contains($path, 'user')) {
            error_log("❌ No matching route found");
        }
        return null;
    }

    /**
     * Handle no route match
     */
    private function handleNoMatch(string $method, string $path, ?string $subdomain): never
    {
        // Check if path exists for other methods
        $availableMethods = $this->getAvailableMethodsForPath($path, $subdomain);

        if (!empty($availableMethods)) {
            throw new MethodNotAllowedException(
                "Method {$method} not allowed for path {$path}. Available methods: " . implode(', ', $availableMethods),
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
     * Cache route match
     */
    private function cacheMatch(string $cacheKey, RouteInfo $route, array $params): void
    {
        // Limit cache size
        if (count($this->routeCache) >= $this->maxCacheSize) {
            // Remove oldest entries (simple FIFO)
            $this->routeCache = array_slice($this->routeCache, 100, null, true);
        }

        $this->routeCache[$cacheKey] = [
            'route' => $route,
            'params' => $params,
            'timestamp' => time()
        ];
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
            return $this->buildUrl($routeSubdomain, $path);
        }

        return $path;
    }

    /**
     * Sanitize parameter key
     */
    private function sanitizeParameterKey(string $key): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) {
            throw new InvalidArgumentException("Invalid parameter key: {$key}");
        }
        return $key;
    }

    /**
     * Sanitize parameter value
     */
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

    /**
     * Build URL with subdomain
     */
    private function buildUrl(string $subdomain, string $path): string
    {
        return "//{$subdomain}.{$this->baseDomain}{$path}";
    }

    /**
     * Check if route exists
     */
    public function hasRoute(string $method, string $path, ?string $subdomain = null): bool
    {
        $this->compileRoutes();

        $method = strtoupper($method);
        $path = $this->sanitizePath($path);
        $subdomain = $this->validateSubdomainInput($subdomain);

        return $this->findMatchingRoute($method, $path, $subdomain) !== null;
    }

    /**
     * Validate subdomain input and return normalized value
     */
    private function validateSubdomainInput(?string $subdomain): ?string
    {
        if ($subdomain === null) {
            return null;
        }

        $this->validateSubdomain($subdomain);
        return $subdomain;
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
     * Get router statistics
     */
    public function getStats(): array
    {
        return [
            'route_count' => $this->routeCount,
            'static_routes' => $this->staticRouteCount,
            'dynamic_routes' => $this->dynamicRouteCount,
            'named_routes' => count($this->namedRoutes),
            'supported_methods' => $this->supportedMethods,
            'is_compiled' => $this->isCompiled,
            'dispatch_count' => $this->dispatchCount,
            'cache_hits' => $this->cacheHits,
            'cache_hit_ratio' => $this->dispatchCount > 0
                ? round(($this->cacheHits / $this->dispatchCount) * 100, 2)
                : 0,
            'average_dispatch_time_ms' => $this->dispatchCount > 0
                ? round($this->totalDispatchTime / $this->dispatchCount, 3)
                : 0,
            'cached_routes' => count($this->routeCache),
            'memory_usage' => memory_get_usage(true),
        ];
    }

    /**
     * Warm up router caches
     */
    public function warmUp(): void
    {
        $this->compileRoutes();

        // Pre-cache common paths if available
        $commonPaths = ['/', '/home', '/about', '/api/health'];
        foreach ($commonPaths as $path) {
            foreach ($this->supportedMethods as $method) {
                try {
                    $this->findMatchingRoute($method, $path, null);
                } catch (Throwable) {
                    // Ignore errors during warm-up
                }
            }
        }
    }

    /**
     * Debug route matching
     */
    public function debugRoute(string $method, string $path, ?string $subdomain = null): array
    {
        if (!$this->debugMode) {
            throw new RuntimeException('Debug mode must be enabled');
        }

        $this->compileRoutes();

        $method = strtoupper($method);
        $path = $this->sanitizePath($path);
        $subdomain = $this->validateSubdomainInput($subdomain);

        $debug = [
            'method' => $method,
            'path' => $path,
            'subdomain' => $subdomain,
            'static_key' => $this->generateStaticKey($path, $subdomain),
            'cache_key' => $this->generateCacheKey($method, $path, $subdomain),
            'has_static_match' => false,
            'dynamic_candidates' => [],
            'matched_route' => null,
        ];

        // Check static routes
        if (isset($this->staticRoutes[$method])) {
            $staticKey = $debug['static_key'];
            if (isset($this->staticRoutes[$method][$staticKey])) {
                $debug['has_static_match'] = true;
                $debug['matched_route'] = [
                    'type' => 'static',
                    'class' => $this->staticRoutes[$method][$staticKey]->actionClass,
                    'path' => $this->staticRoutes[$method][$staticKey]->originalPath,
                ];
            }
        }

        // Check dynamic routes
        if (isset($this->dynamicRoutes[$method])) {
            foreach ($this->dynamicRoutes[$method] as $route) {
                $routeDebug = [
                    'class' => $route->actionClass,
                    'pattern' => $route->pattern,
                    'original_path' => $route->originalPath,
                    'param_names' => $route->paramNames,
                    'subdomain' => $route->subdomain,
                    'matches' => $route->matches($method, $path, $subdomain),
                ];

                if ($routeDebug['matches']) {
                    try {
                        $routeDebug['extracted_params'] = $route->extractParams($path);
                        if ($debug['matched_route'] === null) {
                            $debug['matched_route'] = [
                                'type' => 'dynamic',
                                'class' => $route->actionClass,
                                'path' => $route->originalPath,
                                'params' => $routeDebug['extracted_params'],
                            ];
                        }
                    } catch (InvalidArgumentException $e) {
                        $routeDebug['extraction_error'] = $e->getMessage();
                    }
                }

                $debug['dynamic_candidates'][] = $routeDebug;
            }
        }

        return $debug;
    }

    /**
     * Magic method for debugging
     */
    public function __debugInfo(): array
    {
        return [
            'route_count' => $this->routeCount,
            'static_routes' => $this->staticRouteCount,
            'dynamic_routes' => $this->dynamicRouteCount,
            'named_routes' => count($this->namedRoutes),
            'is_compiled' => $this->isCompiled,
            'supported_methods' => $this->supportedMethods,
            'cache_size' => count($this->routeCache),
            'performance' => [
                'dispatch_count' => $this->dispatchCount,
                'cache_hits' => $this->cacheHits,
                'average_time_ms' => $this->dispatchCount > 0
                    ? round($this->totalDispatchTime / $this->dispatchCount, 3)
                    : 0,
            ],
        ];
    }

    /**
     * Calculate total route count
     */
    private function calculateRouteCount(): int
    {
        return array_sum(array_map('count', $this->routes));
    }
}