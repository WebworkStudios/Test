<?php

declare(strict_types=1);

namespace Framework\Routing;

use Framework\Container\ContainerInterface;
use Framework\Http\{Request, RequestSanitizer, Response};
use Framework\Routing\Exceptions\{MethodNotAllowedException, RouteNotFoundException};
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Optimized Router with RouteCacheManager integration
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
        private readonly ?RouteCacheManager $cacheManager = null,
        private readonly ?RouteDiscovery    $discovery = null,
        private readonly bool               $debugMode = false,
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
        // Create RouteCacheManager if cache directory specified
        $cacheManager = null;
        if (isset($config['cache_dir'])) {
            $cacheConfig = $config['cache'] ?? [];
            $cacheManager = new RouteCacheManager(
                $config['cache_dir'],
                $cacheConfig['useCompression'] ?? true,
                $cacheConfig['compressionLevel'] ?? 6,
                $cacheConfig['integrityCheck'] ?? true
            );
        }

        // Create RouteDiscovery if needed
        $discovery = null;
        if (isset($config['discovery'])) {
            $discoveryConfig = is_array($config['discovery']) ? $config['discovery'] : [];
            $discovery = RouteDiscovery::create(
                new self($container), // Temporary instance for discovery
                $discoveryConfig
            );
        }

        return new self(
            container: $container,
            cacheManager: $cacheManager,
            discovery: $discovery,
            debugMode: $config['debug'] ?? false,
            allowedSubdomains: $config['allowed_subdomains'] ?? ['api', 'admin', 'www'],
            baseDomain: $config['base_domain'] ?? 'localhost'
        );
    }

    /**
     * Load cached routes using RouteCacheManager
     */
    private function loadCachedRoutes(): void
    {
        if ($this->cacheManager === null || $this->debugMode) {
            return;
        }

        $cached = $this->cacheManager->load();
        if ($cached !== null) {
            $this->routes = $cached;
            $this->rebuildNamedRoutes();
        }
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
     * High-Performance dispatch - delegates to RouterCore when available
     */
    public function dispatch(Request $request): Response
    {
        // Im Debug-Modus immer die Original-Methode verwenden
        if ($this->debugMode) {
            return $this->dispatchOriginal($request);
        }

        // Production: RouterCore nur wenn optimized cache vorhanden
        if ($this->hasOptimizedCache()) {
            try {
                return $this->getCore()->dispatch($request);
            } catch (Throwable $e) {
                // Fallback bei RouterCore-Fehlern
                error_log("RouterCore failed, falling back to original: " . $e->getMessage());
                return $this->dispatchOriginal($request);
            }
        }

        // Fallback: Original-Methode wenn kein optimized cache
        return $this->dispatchOriginal($request);
    }

    /**
     * Original dispatch method for debug/development
     */
    private function dispatchOriginal(Request $request): Response
    {
        $this->validateRequest($request);
        $this->compileRoutes();

        $method = strtoupper($request->method);
        $path = RequestSanitizer::sanitizePath($request->path);
        $subdomain = RequestSanitizer::extractSubdomain($request->host(), $this->allowedSubdomains);

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
     * Validate request using RequestSanitizer
     */
    private function validateRequest(Request $request): void
    {
        RequestSanitizer::validateRequest($request);
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
        if ($this->cacheManager !== null && !$this->debugMode) {
            $this->cacheManager->store($this->routes);
        }
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
     * Check if we have optimized cache available
     */
    private function hasOptimizedCache(): bool
    {
        return $this->cacheManager?->isValid ?? false;
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
                $this->allowedSubdomains
            );
        }

        return $this->core;
    }

    /**
     * Auto-discover routes in specified directories
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
            $this->buildCache();
        }
    }

    /**
     * Build route cache for production performance
     */
    public function buildCache(): void
    {
        if ($this->cacheManager === null) {
            return;
        }

        $this->cacheManager->store($this->routes);

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

        // Create route info using RoutePatternCompiler
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

    /**
     * Check if route exists
     */
    public function hasRoute(string $method, string $path, ?string $subdomain = null): bool
    {
        $this->compileRoutes();

        $method = strtoupper($method);
        $path = RequestSanitizer::sanitizePath($path);

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
     * Get router statistics including cache stats
     */
    public function getStats(): array
    {
        $baseStats = [
            'route_count' => $this->routeCount,
            'named_routes' => count($this->namedRoutes),
            'supported_methods' => $this->supportedMethods,
            'is_compiled' => $this->isCompiled,
            'cache_available' => $this->hasOptimizedCache(),
            'using_core' => !$this->debugMode && $this->hasOptimizedCache(),
        ];

        // Add RouterCore stats if available
        if ($this->core !== null) {
            $baseStats['core_stats'] = $this->core->getStats();
        }

        // Add cache stats if available
        if ($this->cacheManager !== null) {
            $baseStats['cache_stats'] = $this->cacheManager->getStats();
        }

        return $baseStats;
    }

    /**
     * Debug route matching for development
     */
    public function debugRoute(string $method, string $path, ?string $subdomain = null): array
    {
        if (!$this->debugMode) {
            throw new RuntimeException('Debug mode must be enabled for route debugging');
        }

        $method = strtoupper($method);
        $sanitizedPath = RequestSanitizer::sanitizePath($path);
        $extractedSubdomain = RequestSanitizer::extractSubdomain($subdomain ?? 'localhost', $this->allowedSubdomains);

        $debug = [
            'method' => $method,
            'original_path' => $path,
            'sanitized_path' => $sanitizedPath,
            'subdomain' => $extractedSubdomain,
            'static_key' => $extractedSubdomain ? "{$extractedSubdomain}:{$sanitizedPath}" : $sanitizedPath,
            'has_static_match' => false,
            'dynamic_candidates' => [],
            'matched_route' => null
        ];

        // Check for routes in this method
        if (!isset($this->routes[$method])) {
            $debug['error'] = "No routes registered for method {$method}";
            return $debug;
        }

        // Test each route
        foreach ($this->routes[$method] as $route) {
            $routeDebug = [
                'class' => $route->actionClass,
                'pattern' => $route->pattern,
                'original_path' => $route->originalPath,
                'param_names' => $route->paramNames,
                'subdomain' => $route->subdomain,
                'matches' => false,
                'extracted_params' => []
            ];

            if ($route->matches($method, $sanitizedPath, $extractedSubdomain)) {
                $routeDebug['matches'] = true;
                try {
                    $routeDebug['extracted_params'] = $route->extractParams($sanitizedPath);
                    $debug['matched_route'] = [
                        'type' => $route->isStatic ? 'static' : 'dynamic',
                        'class' => $route->actionClass,
                        'path' => $route->originalPath,
                        'params' => $routeDebug['extracted_params']
                    ];
                } catch (InvalidArgumentException $e) {
                    $routeDebug['error'] = $e->getMessage();
                }
            }

            if ($route->isStatic) {
                $debug['has_static_match'] = $routeDebug['matches'];
            } else {
                $debug['dynamic_candidates'][] = $routeDebug;
            }
        }

        return $debug;
    }

    /**
     * Warm up router caches
     */
    public function warmUp(): void
    {
        // Build cache if not exists
        if (!$this->hasOptimizedCache()) {
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
        $this->cacheManager?->clear();

        if ($this->core !== null) {
            RouterCore::clearCache();
            $this->core = null;
        }
    }

    /**
     * Calculate total route count
     */
    private function calculateRouteCount(): int
    {
        return array_sum(array_map('count', $this->routes));
    }
}