<?php

declare(strict_types=1);

namespace Framework\Routing;

use Framework\Container\ContainerInterface;
use Framework\Http\{Request, Response};
use Framework\Routing\Exceptions\{RouteNotFoundException, MethodNotAllowedException};

/**
 * Complete High-Performance HTTP Router with PHP 8.4 optimizations
 */
final class Router
{
    private array $routes = [];
    private array $namedRoutes = [];
    private array $compiledRoutes = [];
    private bool $routesCompiled = false;
    private readonly bool $debugMode;

    // PHP 8.4 Property Hooks for better API
    public readonly array $allowedSubdomains;
    public readonly string $baseDomain;
    public readonly bool $strictSubdomainMode;

    public int $routeCount {
        get => $this->cachedRouteCount ??= $this->calculateRouteCount();
    }

    public PerformanceMetrics $metrics {
        get => $this->performanceMetrics ??= new PerformanceMetrics();
    }

    // Performance optimizations
    private array $staticRouteLookup = [];
    private array $segmentTreeCache = [];
    private ?int $cachedRouteCount = null;
    private ?PerformanceMetrics $performanceMetrics = null;

    // Advanced caching
    private array $patternCache = [];
    private array $segmentCache = [];
    private int $maxCacheSize = 1000;

    // Concurrency optimizations
    private array $immutableRoutes = [];
    private bool $lockFreeMode = false;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ?RouteCache $cache = null,
        private readonly ?RouteDiscovery $discovery = null,
        array $config = []
    ) {
        $this->debugMode = $config['debug'] ?? $this->detectDebugMode();
        $this->allowedSubdomains = $config['allowed_subdomains'] ?? ['api', 'admin', 'www'];
        $this->baseDomain = $config['base_domain'] ?? $this->detectBaseDomain();
        $this->strictSubdomainMode = $config['strict_subdomain_mode'] ?? true;
        $this->maxCacheSize = $config['cache_size'] ?? 1000;
        $this->lockFreeMode = $config['lock_free'] ?? true;

        $this->loadCachedRoutes();
        $this->initializePerformanceOptimizations();

        if ($this->discovery !== null && ($config['auto_discovery'] ?? false)) {
            $this->autoDiscoverRoutes($config['discovery_paths'] ?? ['app/Actions', 'app/Controllers']);
        }
    }

    /**
     * Initialize performance optimizations
     */
    private function initializePerformanceOptimizations(): void
    {
        // Pre-allocate caches
        $this->patternCache = [];
        $this->segmentCache = [];

        // Enable APCu if available
        if (extension_loaded('apcu') && apcu_enabled()) {
            $this->enableAPCuCache();
        }
    }

    /**
     * Add route with enhanced validation and optimization
     */
    public function addRoute(
        string $method,
        string $path,
        string $actionClass,
        array $middleware = [],
        ?string $name = null,
        ?string $subdomain = null
    ): void {
        $this->validateSecureInput($method, $path, $actionClass, $middleware, $name, $subdomain);
        $this->validateActionClass($actionClass);

        $routeInfo = RouteInfo::fromPath($method, $path, $actionClass, $middleware, $name, $subdomain);

        $this->routes[$method] ??= [];
        $this->routes[$method][] = $routeInfo;

        if ($name !== null) {
            if (isset($this->namedRoutes[$name])) {
                throw new \InvalidArgumentException("Route name '{$name}' already exists");
            }
            $this->namedRoutes[$name] = $routeInfo;
        }

        $this->routesCompiled = false;
        $this->cachedRouteCount = null;
        $this->metrics->incrementRouteRegistrations();
    }

    /**
     * Ultra-fast dispatch with multiple optimization layers
     */
    public function dispatch(Request $request): Response
    {
        $startTime = hrtime(true);

        try {
            $this->validateRequestSecurity($request);

            $method = $request->method;
            $path = $this->sanitizePath($request->path);
            $subdomain = $this->extractSecureSubdomain($request->host());

            $this->compileRoutes();

            if (!isset($this->compiledRoutes[$method])) {
                $availableMethods = $this->getAvailableMethodsForPath($path, $subdomain);
                if (!empty($availableMethods)) {
                    throw new MethodNotAllowedException(
                        "Method {$method} not allowed. Available methods: " . implode(', ', $availableMethods),
                        $availableMethods
                    );
                }
                throw new RouteNotFoundException("No routes found for method {$method}");
            }

            $response = $this->lockFreeMode
                ? $this->lockFreeDispatch($method, $path, $subdomain, $request)
                : $this->standardDispatch($method, $path, $subdomain, $request);

            $this->recordPerformanceMetrics($startTime, true);
            return $response;

        } catch (\Throwable $e) {
            $this->recordPerformanceMetrics($startTime, false);
            throw $e;
        }
    }

    /**
     * Lock-free dispatch for better concurrency
     */
    private function lockFreeDispatch(string $method, string $path, ?string $subdomain, Request $request): Response
    {
        // Use immutable route data
        $routes = $this->getImmutableRoutes($method);

        // Thread-local pattern cache
        static $threadCaches = [];
        $threadId = \getmypid();

        if (!isset($threadCaches[$threadId])) {
            $threadCaches[$threadId] = new \SplFixedArray($this->maxCacheSize);
        }

        return $this->dispatchWithOptimizedMatching($routes, $path, $subdomain, $request, $threadCaches[$threadId]);
    }

    /**
     * Standard dispatch with optimizations
     */
    private function standardDispatch(string $method, string $path, ?string $subdomain, Request $request): Response
    {
        return $this->ultraFastRouteMatch($method, $path, $subdomain, $request);
    }

    /**
     * Dispatch with optimized matching
     */
    private function dispatchWithOptimizedMatching(array $routes, string $path, ?string $subdomain, Request $request, \SplFixedArray $cache): Response
    {
        $cacheKey = hash('xxh3', $path . ($subdomain ?? ''));
        $cacheIndex = abs(crc32($cacheKey)) % $this->maxCacheSize;

        if (isset($cache[$cacheIndex])) {
            $cached = $cache[$cacheIndex];
            if ($cached['path'] === $path && $cached['subdomain'] === $subdomain) {
                try {
                    $params = $cached['route']->extractParams($path);
                    return $this->callAction($cached['route']->actionClass, $request, $params);
                } catch (\InvalidArgumentException) {
                    unset($cache[$cacheIndex]);
                }
            }
        }

        // Find matching route
        foreach ($routes as $route) {
            if ($route->matches($request->method, $path, $subdomain)) {
                try {
                    $params = $route->extractParams($path);

                    // Cache successful match
                    $cache[$cacheIndex] = [
                        'path' => $path,
                        'subdomain' => $subdomain,
                        'route' => $route
                    ];

                    return $this->callAction($route->actionClass, $request, $params);
                } catch (\InvalidArgumentException) {
                    continue;
                }
            }
        }

        $this->handleNoMatch($request->method, $path, $subdomain);
    }

    /**
     * Ultra-fast route matching with segment tree optimization
     */
    private function ultraFastRouteMatch(string $method, string $path, ?string $subdomain, Request $request): Response
    {
        $routes = $this->compiledRoutes[$method];

        // Phase 1: Static route hash lookup (O(1))
        $staticKey = $this->generateStaticRouteKey($path, $subdomain);
        if (isset($this->staticRouteLookup[$method][$staticKey])) {
            return $this->callAction($this->staticRouteLookup[$method][$staticKey], $request, []);
        }

        // Phase 2: Pattern cache check (O(1))
        $cacheKey = $method . ':' . $this->getPathSignature($path);
        if (isset($this->patternCache[$cacheKey])) {
            $cachedRoute = $this->patternCache[$cacheKey];
            if ($this->matchesSubdomain($cachedRoute->subdomain, $subdomain)) {
                try {
                    $params = $cachedRoute->extractParams($path);
                    return $this->callAction($cachedRoute->actionClass, $request, $params);
                } catch (\InvalidArgumentException) {
                    unset($this->patternCache[$cacheKey]);
                }
            }
        }

        // Phase 3: Optimized parametric matching with segment tree
        $pathSegments = $this->segmentCache[$path] ??= explode('/', trim($path, '/'));
        $segmentCount = count($pathSegments);

        // Use segment tree for fast pre-filtering
        $candidateRoutes = $this->getRoutesBySegmentCount($method, $segmentCount);

        foreach ($candidateRoutes as $routeInfo) {
            if (!$this->matchesSubdomain($routeInfo->subdomain, $subdomain)) {
                continue;
            }

            // Fast segment matching before expensive regex
            if ($this->fastSegmentMatch($routeInfo, $pathSegments)) {
                try {
                    $params = $routeInfo->extractParams($path);

                    // Cache successful matches
                    if (count($this->patternCache) < $this->maxCacheSize) {
                        $this->patternCache[$cacheKey] = $routeInfo;
                    }

                    return $this->callAction($routeInfo->actionClass, $request, $params);
                } catch (\InvalidArgumentException) {
                    continue;
                }
            }
        }

        // No match found
        $this->handleNoMatch($method, $path, $subdomain);
    }

    /**
     * Get routes by segment count for fast pre-filtering
     */
    private function getRoutesBySegmentCount(string $method, int $segmentCount): array
    {
        $cacheKey = $method . ':' . $segmentCount;

        if (!isset($this->segmentTreeCache[$cacheKey])) {
            $routes = [];
            foreach ($this->compiledRoutes[$method] as $route) {
                if (!empty($route->paramNames) && $this->getSegmentCount($route->originalPath) === $segmentCount) {
                    $routes[] = $route;
                }
            }
            $this->segmentTreeCache[$cacheKey] = $routes;
        }

        return $this->segmentTreeCache[$cacheKey];
    }

    /**
     * Generate path signature for caching
     */
    private function getPathSignature(string $path): string
    {
        return count(explode('/', trim($path, '/'))) . ':' . \hash('xxh3', $path);
    }

    /**
     * Fast segment matching without regex
     */
    private function fastSegmentMatch(RouteInfo $routeInfo, array $pathSegments): bool
    {
        $routeSegments = explode('/', trim($routeInfo->originalPath, '/'));

        if (count($routeSegments) !== count($pathSegments)) {
            return false;
        }

        // Quick static segment verification with SIMD-like optimization
        for ($i = 0, $count = count($routeSegments); $i < $count; $i++) {
            $routeSegment = $routeSegments[$i];

            if (!str_contains($routeSegment, '{')) {
                if ($routeSegment !== ($pathSegments[$i] ?? '')) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Optimized route compilation with segment tree building
     */
    private function compileRoutes(): void
    {
        if ($this->routesCompiled) {
            return;
        }

        $this->staticRouteLookup = [];
        $this->segmentTreeCache = [];

        foreach ($this->routes as $method => $routes) {
            [$staticRoutes, $parametricRoutes] = $this->separateRouteTypes($routes);

            // Build static route lookup
            $this->buildStaticRouteLookup($method, $staticRoutes);

            // Optimize parametric routes
            $optimizedParametricRoutes = $this->optimizeParametricRoutes($parametricRoutes);

            $this->compiledRoutes[$method] = array_merge($staticRoutes, $optimizedParametricRoutes);
        }

        $this->routesCompiled = true;
        $this->buildImmutableRoutes();

        if ($this->cache !== null && $this->isSecureCacheEnvironment()) {
            $this->cache->store($this->compiledRoutes);
        }
    }

    /**
     * Separate static and parametric routes for optimized handling
     */
    private function separateRouteTypes(array $routes): array
    {
        $static = [];
        $parametric = [];

        foreach ($routes as $route) {
            if (empty($route->paramNames)) {
                $static[] = $route;
            } else {
                $parametric[] = $route;
            }
        }

        return [$static, $parametric];
    }

    /**
     * Build static route lookup table
     */
    private function buildStaticRouteLookup(string $method, array $staticRoutes): void
    {
        foreach ($staticRoutes as $route) {
            $key = $this->generateStaticRouteKey($route->originalPath, $route->subdomain);
            $this->staticRouteLookup[$method][$key] = $route->actionClass;
        }
    }

    /**
     * Optimize parametric routes with advanced sorting
     */
    private function optimizeParametricRoutes(array $parametricRoutes): array
    {
        // Multi-level sorting for optimal matching order
        usort($parametricRoutes, function(RouteInfo $a, RouteInfo $b): int {
            // 1. Fewer parameters = higher priority
            $paramDiff = count($a->paramNames) - count($b->paramNames);
            if ($paramDiff !== 0) return $paramDiff;

            // 2. More static segments = higher priority
            $aStaticSegments = $this->countStaticSegments($a->originalPath);
            $bStaticSegments = $this->countStaticSegments($b->originalPath);
            $staticDiff = $bStaticSegments - $aStaticSegments;
            if ($staticDiff !== 0) return $staticDiff;

            // 3. Subdomain-specific routes first
            if ($a->subdomain !== null && $b->subdomain === null) return -1;
            if ($a->subdomain === null && $b->subdomain !== null) return 1;

            // 4. Shorter paths first
            return strlen($a->originalPath) - strlen($b->originalPath);
        });

        return $parametricRoutes;
    }

    /**
     * Build immutable routes for lock-free access
     */
    private function buildImmutableRoutes(): void
    {
        if (!$this->lockFreeMode) {
            return;
        }

        $this->immutableRoutes = [];
        foreach ($this->compiledRoutes as $method => $routes) {
            $this->immutableRoutes[$method] = array_map(
                fn(RouteInfo $route) => $this->createImmutableRoute($route),
                $routes
            );
        }
    }

    /**
     * Create immutable route copy
     */
    private function createImmutableRoute(RouteInfo $route): RouteInfo
    {
        return new RouteInfo(
            $route->method,
            $route->pattern,
            $route->originalPath,
            $route->paramNames,
            $route->actionClass,
            $route->middleware,
            $route->name,
            $route->subdomain
        );
    }

    /**
     * Get immutable routes for lock-free access
     */
    private function getImmutableRoutes(string $method): array
    {
        return $this->immutableRoutes[$method] ?? $this->compiledRoutes[$method] ?? [];
    }

    /**
     * Enhanced route existence check with caching
     */
    public function hasRoute(string $method, string $path, ?string $subdomain = null): bool
    {
        if (strlen($path) > 2048 || str_contains($path, "\0")) {
            return false;
        }

        $this->compileRoutes();

        if (!isset($this->compiledRoutes[$method])) {
            return false;
        }

        $sanitizedPath = $this->sanitizePath($path);
        $validatedSubdomain = $this->validateSubdomainInput($subdomain);

        // Fast static route check
        $staticKey = $this->generateStaticRouteKey($sanitizedPath, $validatedSubdomain);
        if (isset($this->staticRouteLookup[$method][$staticKey])) {
            return true;
        }

        // Fast parametric route check
        $pathSegments = explode('/', trim($sanitizedPath, '/'));
        $segmentCount = count($pathSegments);
        $candidateRoutes = $this->getRoutesBySegmentCount($method, $segmentCount);

        foreach ($candidateRoutes as $routeInfo) {
            if ($this->matchesSubdomain($routeInfo->subdomain, $validatedSubdomain) &&
                $this->fastSegmentMatch($routeInfo, $pathSegments)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate URL with enhanced caching
     */
    public function url(string $name, array $params = [], ?string $subdomain = null): string
    {
        if (strlen($name) > 255 || !preg_match('/^[a-zA-Z0-9._-]+$/', $name)) {
            throw new \InvalidArgumentException("Invalid route name: {$name}");
        }

        if (!isset($this->namedRoutes[$name])) {
            throw new RouteNotFoundException("Named route '{$name}' not found");
        }

        $routeInfo = $this->namedRoutes[$name];
        $path = $routeInfo->originalPath;

        // Optimized parameter replacement
        foreach ($params as $key => $value) {
            $sanitizedKey = $this->sanitizeParameterKey($key);
            $sanitizedValue = $this->sanitizeParameterValue($value);
            $path = str_replace("{{$sanitizedKey}}", $sanitizedValue, $path);
        }

        if (preg_match('/{[^}]+}/', $path)) {
            throw new \InvalidArgumentException("Missing parameters for route '{$name}'");
        }

        $routeSubdomain = $subdomain ?? $routeInfo->subdomain;
        if ($routeSubdomain !== null) {
            $validatedSubdomain = $this->validateSubdomainInput($routeSubdomain);
            return $this->buildSecureUrl($validatedSubdomain, $path);
        }

        return $path;
    }

    /**
     * Auto-discover routes using RouteDiscovery
     */
    public function autoDiscoverRoutes(array $directories): void
    {
        if ($this->discovery === null) {
            throw new \RuntimeException('RouteDiscovery not configured');
        }

        try {
            $this->discovery->discover($directories);

            $stats = $this->discovery->getStats();
            if ($this->debugMode) {
                error_log("Route discovery completed: {$stats['discovered_routes']} routes from {$stats['processed_files']} files");
            }

        } catch (\Throwable $e) {
            error_log("Route discovery failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Manual route discovery for specific directories
     */
    public function discoverRoutes(array $directories): array
    {
        if ($this->discovery === null) {
            throw new \RuntimeException('RouteDiscovery not configured');
        }

        $statsBefore = $this->discovery->getStats();
        $this->discovery->discover($directories);
        $statsAfter = $this->discovery->getStats();

        return [
            'discovered_routes' => $statsAfter['discovered_routes'] - $statsBefore['discovered_routes'],
            'processed_files' => $statsAfter['processed_files'] - $statsBefore['processed_files']
        ];
    }

    /**
     * Get discovery statistics
     */
    public function getDiscoveryStats(): ?array
    {
        return $this->discovery?->getStats();
    }

    /**
     * Clear discovery cache
     */
    public function clearDiscoveryCache(): void
    {
        $this->discovery?->clearCache();
    }

    /**
     * Get all registered routes
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * Record performance metrics
     */
    private function recordPerformanceMetrics(int $startTime, bool $success): void
    {
        $duration = (hrtime(true) - $startTime) / 1_000_000; // Convert to milliseconds

        if ($success) {
            $this->metrics->recordSuccessfulDispatch($duration);
        } else {
            $this->metrics->recordFailedDispatch($duration);
        }
    }

    /**
     * Calculate total route count
     */
    private function calculateRouteCount(): int
    {
        return array_sum(array_map('count', $this->routes));
    }

    /**
     * Enable APCu caching if available
     */
    private function enableAPCuCache(): void
    {
        if (!apcu_enabled()) {
            return;
        }

        $cacheKey = 'framework_routes_' . $this->baseDomain;
        $cached = apcu_fetch($cacheKey);

        if ($cached !== false && $this->validateCachedRoutes($cached)) {
            $this->compiledRoutes = $cached;
            $this->routesCompiled = true;
        }
    }

    /**
     * Handle no match scenario
     */
    private function handleNoMatch(string $method, string $path, ?string $subdomain): never
    {
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
     * Validate all input for security
     */
    private function validateSecureInput(
        string $method,
        string $path,
        string $actionClass,
        array $middleware,
        ?string $name,
        ?string $subdomain
    ): void {
        // Method validation
        $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'];
        if (!in_array(strtoupper($method), $allowedMethods, true)) {
            throw new \InvalidArgumentException("Invalid HTTP method: {$method}");
        }

        // Path validation
        if (strlen($path) > 2048 || !str_starts_with($path, '/')) {
            throw new \InvalidArgumentException("Invalid path: {$path}");
        }

        // Action Class validation
        if (strlen($actionClass) > 255 || !preg_match('/^[a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*$/', $actionClass)) {
            throw new \InvalidArgumentException("Invalid action class: {$actionClass}");
        }

        // Middleware validation
        foreach ($middleware as $mw) {
            if (!is_string($mw) || strlen($mw) > 100 || !preg_match('/^[a-zA-Z0-9_.-]+$/', $mw)) {
                throw new \InvalidArgumentException("Invalid middleware: {$mw}");
            }
        }

        // Name validation
        if ($name !== null && (strlen($name) > 255 || !preg_match('/^[a-zA-Z0-9._-]+$/', $name))) {
            throw new \InvalidArgumentException("Invalid route name: {$name}");
        }

        // Subdomain validation
        if ($subdomain !== null) {
            $this->validateSubdomainInput($subdomain);
        }
    }

    /**
     * Validate request for security issues
     */
    private function validateRequestSecurity(Request $request): void
    {
        $host = $request->host();
        if (!$this->isValidHost($host)) {
            throw new \InvalidArgumentException("Invalid host header: {$host}");
        }

        if (strlen($request->path) > 2048) {
            throw new \InvalidArgumentException("Request path too long");
        }

        if (str_contains($request->path, "\0") || str_contains($request->uri, "\0")) {
            throw new \InvalidArgumentException("Request contains null bytes");
        }
    }

    /**
     * Validate host header against allowed hosts
     */
    private function isValidHost(string $host): bool
    {
        $hostWithoutPort = explode(':', $host)[0];

        // Validate IP addresses
        if (filter_var($hostWithoutPort, FILTER_VALIDATE_IP)) {
            return filter_var($hostWithoutPort, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        // Allow localhost and *.localhost for development
        if ($hostWithoutPort === 'localhost' || str_ends_with($hostWithoutPort, '.localhost')) {
            return true;
        }

        // Validate domain format
        if (!preg_match('/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $hostWithoutPort)) {
            return false;
        }

        // Base domain check
        $parts = explode('.', $hostWithoutPort);
        if (count($parts) >= 2) {
            $domain = implode('.', array_slice($parts, -2));
            return $domain === $this->baseDomain;
        }

        return false;
    }

    /**
     * Extract and validate subdomain securely
     */
    private function extractSecureSubdomain(string $host): ?string
    {
        $hostWithoutPort = explode(':', $host)[0];

        // Localhost und IP-Adressen haben keine Subdomain
        if ($hostWithoutPort === 'localhost' || filter_var($hostWithoutPort, FILTER_VALIDATE_IP)) {
            return null;
        }

        $parts = explode('.', $hostWithoutPort);

        // Bessere Subdomain-Erkennung
        if (str_ends_with($hostWithoutPort, '.localhost')) {
            // api.localhost -> 'api'
            if (count($parts) >= 2 && $parts[count($parts) - 1] === 'localhost') {
                $subdomain = $parts[0];
                if ($this->isValidSubdomain($subdomain)) {
                    return $subdomain;
                }
            }
            return null;
        }

        // Normale Domain: subdomain.example.com
        if (count($parts) < 3) {
            return null;
        }

        $subdomain = $parts[0];

        if (!$this->isValidSubdomain($subdomain)) {
            throw new \InvalidArgumentException("Invalid subdomain: {$subdomain}");
        }

        if ($this->strictSubdomainMode && !in_array($subdomain, $this->allowedSubdomains, true)) {
            throw new \InvalidArgumentException("Subdomain not allowed: {$subdomain}");
        }

        return $subdomain;
    }

    /**
     * Validate subdomain format and security
     */
    private function isValidSubdomain(string $subdomain): bool
    {
        if (strlen($subdomain) > 63 || strlen($subdomain) === 0) {
            return false;
        }

        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?$/', $subdomain)) {
            return false;
        }

        if (preg_match('/[<>"\'\0]/', $subdomain)) {
            return false;
        }

        return true;
    }

    /**
     * Sanitize path for security
     */
    private function sanitizePath(string $path): string
    {
        $cleaned = preg_replace('/[^\w\-_\/{}.]/', '', $path);
        $cleaned = str_replace(['../', '.\\', '..\\'], '', $cleaned);

        if (!str_starts_with($cleaned, '/')) {
            $cleaned = '/' . $cleaned;
        }

        return $cleaned;
    }

    /**
     * Validate subdomain input
     */
    private function validateSubdomainInput(?string $subdomain): ?string
    {
        if ($subdomain === null) {
            return null;
        }

        if (!$this->isValidSubdomain($subdomain)) {
            throw new \InvalidArgumentException("Invalid subdomain format: {$subdomain}");
        }

        return $subdomain;
    }

    /**
     * Sanitize parameter key
     */
    private function sanitizeParameterKey(string $key): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) {
            throw new \InvalidArgumentException("Invalid parameter key: {$key}");
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
            throw new \InvalidArgumentException("Parameter value too long");
        }

        if (str_contains($stringValue, "\0") || str_contains($stringValue, '..')) {
            throw new \InvalidArgumentException("Parameter contains invalid characters");
        }

        return urlencode($stringValue);
    }

    /**
     * Build secure URL with subdomain
     */
    private function buildSecureUrl(string $subdomain, string $path): string
    {
        if (!$this->isValidSubdomain($subdomain)) {
            throw new \InvalidArgumentException("Invalid subdomain for URL: {$subdomain}");
        }

        if (!preg_match('/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $this->baseDomain) && $this->baseDomain !== 'localhost') {
            throw new \InvalidArgumentException("Invalid base domain: {$this->baseDomain}");
        }

        return "//{$subdomain}.{$this->baseDomain}{$path}";
    }

    /**
     * Detect base domain securely
     */
    private function detectBaseDomain(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $hostWithoutPort = explode(':', $host)[0];

        if (!$this->isValidHostForDetection($hostWithoutPort)) {
            return 'localhost';
        }

        $parts = explode('.', $hostWithoutPort);

        if (count($parts) >= 2 && $hostWithoutPort !== 'localhost') {
            return implode('.', array_slice($parts, -2));
        }

        return 'localhost';
    }

    /**
     * Validate host for base domain detection
     */
    private function isValidHostForDetection(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }

        if ($host === 'localhost') {
            return true;
        }

        return preg_match('/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $host) === 1;
    }

    /**
     * Get available HTTP methods for path and subdomain
     */
    private function getAvailableMethodsForPath(string $path, ?string $subdomain = null): array
    {
        $this->compileRoutes();
        $methods = [];

        foreach ($this->compiledRoutes as $method => $routes) {
            foreach ($routes as $routeInfo) {
                if ($routeInfo->matches($method, $path, $subdomain)) {
                    $methods[] = $method;
                    break;
                }
            }
        }

        return $methods;
    }

    /**
     * Enhanced action class validation with security focus
     */
    private function validateActionClass(string $actionClass): void
    {
        if (!class_exists($actionClass)) {
            throw new \InvalidArgumentException("Action class {$actionClass} does not exist");
        }

        $reflection = new \ReflectionClass($actionClass);

        if (!$reflection->hasMethod('__invoke')) {
            throw new \InvalidArgumentException("Action class {$actionClass} must be invokable");
        }

        // Security checks: Block dangerous classes
        $dangerousPatterns = [
            'Reflection', 'PDO', 'mysqli', 'SQLite3', 'DirectoryIterator',
            'SplFileObject', 'SplFileInfo', 'SimpleXMLElement', 'DOMDocument',
            'XMLReader', 'XMLWriter', 'ZipArchive', 'Phar'
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (str_contains($actionClass, $pattern)) {
                throw new \InvalidArgumentException("Dangerous action class not allowed: {$actionClass}");
            }
        }

        // Namespace whitelist
        $allowedNamespaces = [
            'App\\Actions\\',
            'App\\Controllers\\',
            'App\\Http\\Actions\\',
            'App\\Http\\Controllers\\'
        ];

        $isAllowed = false;
        foreach ($allowedNamespaces as $namespace) {
            if (str_starts_with($actionClass, $namespace)) {
                $isAllowed = true;
                break;
            }
        }

        if (!$isAllowed) {
            throw new \InvalidArgumentException("Action class not in allowed namespace: {$actionClass}");
        }

        $this->validateActionClassSecurity($reflection);
    }

    /**
     * Additional security validation for action classes
     */
    private function validateActionClassSecurity(\ReflectionClass $reflection): void
    {
        // Check for dangerous methods
        $dangerousMethods = [
            'exec', 'system', 'shell_exec', 'passthru', 'eval', 'file_get_contents',
            'file_put_contents', 'fopen', 'fwrite', 'include', 'require',
            'include_once', 'require_once'
        ];

        $classMethods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
        foreach ($classMethods as $method) {
            if (in_array($method->getName(), $dangerousMethods, true)) {
                throw new \InvalidArgumentException("Action class contains dangerous method: {$method->getName()}");
            }
        }

        if ($reflection->implementsInterface('Serializable')) {
            throw new \InvalidArgumentException("Action class implements Serializable interface (security risk)");
        }

        if (!$reflection->isFinal()) {
            if ($this->debugMode) {
                error_log("Warning: Action class {$reflection->getName()} should be final for security");
            }
        }
    }

    /**
     * Check if caching environment is secure
     */
    private function isSecureCacheEnvironment(): bool
    {
        return !($this->isDebugMode() || $this->isUnsafeEnvironment());
    }

    private function isDebugMode(): bool
    {
        return $this->debugMode;
    }

    private function detectDebugMode(): bool
    {
        return match(true) {
            defined('APP_DEBUG') && APP_DEBUG === true => true,
            ($_ENV['APP_DEBUG'] ?? '') === 'true' => true,
            ($_SERVER['APP_DEBUG'] ?? '') === 'true' => true,
            default => false
        };
    }

    /**
     * Check for unsafe environment
     */
    private function isUnsafeEnvironment(): bool
    {
        $env = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'production';
        return in_array($env, ['development', 'dev', 'local', 'testing'], true);
    }

    /**
     * Load cached routes with security validation
     */
    private function loadCachedRoutes(): void
    {
        if ($this->cache === null || !$this->isSecureCacheEnvironment()) {
            return;
        }

        $cached = $this->cache->load();
        if ($cached !== null && $this->validateCachedRoutes($cached)) {
            $this->compiledRoutes = $cached;
            $this->routesCompiled = true;
        }
    }

    /**
     * Validate cached routes for security
     */
    private function validateCachedRoutes(array $cached): bool
    {
        if (!is_array($cached)) {
            return false;
        }

        foreach ($cached as $method => $routes) {
            if (!is_string($method) || !is_array($routes)) {
                return false;
            }

            foreach ($routes as $route) {
                if (!($route instanceof RouteInfo) || !$this->isValidCachedRoute($route)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Validate individual cached route
     */
    private function isValidCachedRoute(RouteInfo $route): bool
    {
        if (!class_exists($route->actionClass)) {
            return false;
        }

        if (str_contains($route->pattern, '(?') || strlen($route->pattern) > 1000) {
            return false;
        }

        if ($route->subdomain !== null && !$this->isValidSubdomain($route->subdomain)) {
            return false;
        }

        return true;
    }

    /**
     * Call action with enhanced security
     */
    private function callAction(string $actionClass, Request $request, array $params): Response
    {
        $this->validateActionInvocation($actionClass, $request, $params);

        $action = $this->container->get($actionClass);

        if ($action === null) {
            throw new \RuntimeException("Container returned null for action {$actionClass}");
        }

        if (!is_object($action)) {
            throw new \RuntimeException("Container returned invalid action instance for {$actionClass}");
        }

        if (!($action instanceof $actionClass) && get_class($action) !== $actionClass) {
            throw new \RuntimeException("Container returned wrong action type for {$actionClass}");
        }

        if (!is_callable($action)) {
            throw new \RuntimeException("Action {$actionClass} is not invokable");
        }

        try {
            // DEBUG: Parameter-Validierung vor Aufruf
            if ($this->debugMode) {
                error_log(sprintf(
                    "Router: Calling action %s with request %s %s and %d params: %s",
                    $actionClass,
                    $request->method,
                    $request->path,
                    count($params),
                    json_encode($params)
                ));
            }

            // Direkter callable Aufruf
            $result = $action($request, $params);
            return $this->convertToResponse($result);
        } catch (\Throwable $e) {
            if ($this->debugMode) {
                error_log("Action execution error in {$actionClass}: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
            }
            throw $e;
        }
    }

    /**
     * Validate action invocation for security
     */
    private function validateActionInvocation(string $actionClass, Request $request, array $params): void
    {
        if (count($params) > 20) {
            throw new \InvalidArgumentException("Too many route parameters");
        }

        foreach ($params as $key => $value) {
            if (strlen($key) > 50 || strlen((string)$value) > 1000) {
                throw new \InvalidArgumentException("Parameter too large: {$key}");
            }
        }

        if (strlen($request->raw()) > 10485760) { // 10MB
            throw new \InvalidArgumentException("Request body too large");
        }
    }

    /**
     * Convert action result to Response with security
     */
    private function convertToResponse(mixed $result): Response
    {
        return match(true) {
            $result instanceof Response => $result,
            is_array($result) || is_object($result) => $this->createSecureJsonResponse($result),
            default => Response::html($this->sanitizeHtmlOutput((string) $result))
        };
    }

    /**
     * Create secure JSON response
     */
    private function createSecureJsonResponse(array|object $data): Response
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        if (strlen($json) > 10485760) { // 10MB limit
            throw new \RuntimeException("Response too large");
        }

        return Response::json($data);
    }

    /**
     * Sanitize HTML output for security
     */
    private function sanitizeHtmlOutput(string $html): string
    {
        $escaped = htmlspecialchars($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (strlen($escaped) > 10485760) { // 10MB
            throw new \RuntimeException("HTML response too large");
        }

        return $escaped;
    }

    /**
     * Generate static route lookup key
     */
    private function generateStaticRouteKey(string $path, ?string $subdomain): string
    {
        return $subdomain ? "{$subdomain}:{$path}" : $path;
    }

    /**
     * Get segment count for route
     */
    private function getSegmentCount(string $path): int
    {
        return count(explode('/', trim($path, '/')));
    }

    /**
     * Count static segments in path for better route prioritization
     */
    private function countStaticSegments(string $path): int
    {
        $segments = explode('/', trim($path, '/'));
        $staticCount = 0;

        foreach ($segments as $segment) {
            if (!empty($segment) && !str_contains($segment, '{')) {
                $staticCount++;
            }
        }

        return $staticCount;
    }

    /**
     * Check subdomain matching
     */
    private function matchesSubdomain(?string $routeSubdomain, ?string $requestSubdomain): bool
    {
        if ($routeSubdomain === null) {
            return $requestSubdomain === null || $requestSubdomain === 'www';
        }
        return $routeSubdomain === $requestSubdomain;
    }

    /**
     * Get router performance statistics
     */
    public function getPerformanceStats(): array
    {
        return [
            'route_count' => $this->routeCount,
            'compiled_routes' => $this->routesCompiled,
            'static_routes_cached' => array_sum(array_map('count', $this->staticRouteLookup)),
            'pattern_cache_size' => count($this->patternCache),
            'segment_cache_size' => count($this->segmentCache),
            'metrics' => $this->metrics->getStats(),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
        ];
    }

    /**
     * Clear all caches
     */
    public function clearCaches(): void
    {
        $this->patternCache = [];
        $this->segmentCache = [];
        $this->segmentTreeCache = [];
        $this->staticRouteLookup = [];
        $this->immutableRoutes = [];
        $this->routesCompiled = false;
        $this->cachedRouteCount = null;

        $this->cache?->clear();
        $this->discovery?->clearCache();
    }

    /**
     * Warm up caches for better performance
     */
    public function warmUpCaches(): void
    {
        $this->compileRoutes();

        // Pre-populate common patterns
        $commonPaths = ['/', '/home', '/about', '/contact', '/api/health'];
        foreach ($commonPaths as $path) {
            $this->segmentCache[$path] = explode('/', trim($path, '/'));
        }
    }

    /**
     * Debug route information
     */
    public function debugRoute(string $method, string $path, ?string $subdomain = null): array
    {
        if (!$this->debugMode) {
            throw new \RuntimeException('Debug mode must be enabled');
        }

        $this->compileRoutes();

        $debug = [
            'method' => $method,
            'path' => $path,
            'subdomain' => $subdomain,
            'sanitized_path' => $this->sanitizePath($path),
            'static_route_key' => $this->generateStaticRouteKey($path, $subdomain),
            'path_signature' => $this->getPathSignature($path),
            'segment_count' => $this->getSegmentCount($path),
            'has_static_match' => isset($this->staticRouteLookup[$method][$this->generateStaticRouteKey($path, $subdomain)]),
            'candidate_routes' => [],
            'matched_route' => null,
        ];

        if (isset($this->compiledRoutes[$method])) {
            $pathSegments = explode('/', trim($path, '/'));
            $candidateRoutes = $this->getRoutesBySegmentCount($method, count($pathSegments));

            foreach ($candidateRoutes as $route) {
                $routeDebug = [
                    'class' => $route->actionClass,
                    'pattern' => $route->pattern,
                    'original_path' => $route->originalPath,
                    'param_names' => $route->paramNames,
                    'subdomain' => $route->subdomain,
                    'matches_subdomain' => $this->matchesSubdomain($route->subdomain, $subdomain),
                    'fast_segment_match' => $this->fastSegmentMatch($route, $pathSegments),
                ];

                if ($route->matches($method, $path, $subdomain)) {
                    $debug['matched_route'] = $routeDebug;
                    try {
                        $routeDebug['extracted_params'] = $route->extractParams($path);
                    } catch (\InvalidArgumentException $e) {
                        $routeDebug['extraction_error'] = $e->getMessage();
                    }
                }

                $debug['candidate_routes'][] = $routeDebug;
            }
        }

        return $debug;
    }
}