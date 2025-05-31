<?php

declare(strict_types=1);

namespace Framework\Routing;

use Framework\Container\ContainerInterface;
use Framework\Http\{Request, Response};
use Framework\Routing\Exceptions\{RouteNotFoundException, MethodNotAllowedException};

/**
 * HTTP Router with attribute-based route registration and secure subdomain support
 */
final class Router
{
    /** @var array<string, array<RouteInfo>> */
    private array $routes = [];

    /** @var array<string, RouteInfo> */
    private array $namedRoutes = [];

    private array $compiledRoutes = [];
    private bool $routesCompiled = false;

    private readonly bool $debugMode;

    // Security configuration with PHP 8.4 property hooks
    public readonly array $allowedSubdomains;
    public readonly string $baseDomain;
    public readonly bool $strictSubdomainMode;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ?RouteCache $cache = null,
        private readonly ?RouteDiscovery $discovery = null,
        array $config = []
    ) {
        $this->debugMode = $config['debug'] ?? $this->detectDebugMode();
        // Secure configuration
        $this->allowedSubdomains = $config['allowed_subdomains'] ?? ['api', 'admin', 'www'];
        $this->baseDomain = $config['base_domain'] ?? $this->detectBaseDomain();
        $this->strictSubdomainMode = $config['strict_subdomain_mode'] ?? true;

        $this->loadCachedRoutes();

        // Auto-discovery if configured
        if ($this->discovery !== null && ($config['auto_discovery'] ?? false)) {
            $this->autoDiscoverRoutes($config['discovery_paths'] ?? ['app/Actions', 'app/Controllers']);
        }
    }

    /**
     * Add a route to the router with comprehensive security validation
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
    }

    /**
     * Dispatch request with comprehensive security checks
     */
    public function dispatch(Request $request): Response
    {
        $this->validateRequestSecurity($request);

        $method = $request->method;
        $path = $this->sanitizePath($request->path);
        $subdomain = $this->extractSecureSubdomain($request->host());

        $this->compileRoutes();

        $availableMethods = $this->getAvailableMethodsForPath($path, $subdomain);

        if (!isset($this->compiledRoutes[$method])) {
            if (!empty($availableMethods)) {
                throw new MethodNotAllowedException(
                    "Method {$method} not allowed. Available methods: " . implode(', ', $availableMethods),
                    $availableMethods
                );
            }
            throw new RouteNotFoundException("No routes found for method {$method}");
        }

        foreach ($this->compiledRoutes[$method] as $routeInfo) {
            if ($routeInfo->matches($method, $path, $subdomain)) {
                $params = $routeInfo->extractParams($path);
                return $this->callAction($routeInfo->actionClass, $request, $params);
            }
        }

        if (!empty($availableMethods)) {
            throw new MethodNotAllowedException(
                "Method {$method} not allowed for path {$path}. Available methods: " . implode(', ', $availableMethods),
                $availableMethods
            );
        }

        throw new RouteNotFoundException("Route not found: {$method} {$path}");
    }

    /**
     * Check if route exists with security validation
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

        foreach ($this->compiledRoutes[$method] as $routeInfo) {
            if ($routeInfo->matches($method, $sanitizedPath, $validatedSubdomain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate secure URL for named route
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

        // Secure parameter replacement
        foreach ($params as $key => $value) {
            $sanitizedKey = $this->sanitizeParameterKey($key);
            $sanitizedValue = $this->sanitizeParameterValue($value);
            $path = str_replace("{{$sanitizedKey}}", $sanitizedValue, $path);
        }

        if (preg_match('/{[^}]+}/', $path)) {
            throw new \InvalidArgumentException("Missing parameters for route '{$name}'");
        }

        // Secure URL generation with subdomain
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
            error_log("Route discovery completed: {$stats['discovered_routes']} routes from {$stats['processed_files']} files");

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

        if ($hostWithoutPort === 'localhost' || filter_var($hostWithoutPort, FILTER_VALIDATE_IP)) {
            return null;
        }

        $parts = explode('.', $hostWithoutPort);
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
            error_log("Warning: Action class {$reflection->getName()} should be final for security");
        }
    }

    /**
     * Compile routes with security optimization
     */
    private function compileRoutes(): void
    {
        if ($this->routesCompiled) {
            return;
        }

        foreach ($this->routes as $method => $routes) {
            usort($routes, function(RouteInfo $a, RouteInfo $b): int {
                // Subdomain-specific routes first
                $aHasSubdomain = $a->subdomain !== null;
                $bHasSubdomain = $b->subdomain !== null;

                if ($aHasSubdomain !== $bHasSubdomain) {
                    return $aHasSubdomain ? -1 : 1;
                }

                // Static routes before parametric
                $aHasParams = !empty($a->paramNames);
                $bHasParams = !empty($b->paramNames);

                if ($aHasParams !== $bHasParams) {
                    return $aHasParams ? 1 : -1;
                }

                // Shorter paths first
                return strlen($a->originalPath) <=> strlen($b->originalPath);
            });

            $this->compiledRoutes[$method] = $routes;
        }

        $this->routesCompiled = true;

        if ($this->cache !== null && $this->isSecureCacheEnvironment()) {
            $this->cache->store($this->compiledRoutes);
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

        if (!is_object($action) || get_class($action) !== $actionClass) {
            throw new \RuntimeException("Container returned invalid action instance");
        }

        if (!is_callable($action)) {
            throw new \RuntimeException("Action {$actionClass} is not invokable");
        }

        try {
            $result = $this->executeActionSecurely($action, $request, $params);
            return $this->convertToResponse($result);
        } catch (\Throwable $e) {
            error_log("Action execution error: " . $e->getMessage());
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
     * Execute action with security monitoring
     */
    private function executeActionSecurely(object $action, Request $request, array $params): mixed
    {
        $memoryBefore = memory_get_usage(true);
        $timeBefore = microtime(true);

        try {

            $result = $action->__invoke($request, $params);

            $executionTime = microtime(true) - $timeBefore;
            if ($executionTime > 30) {
                error_log("Action execution too slow: {$executionTime}s");
            }

            $memoryUsed = memory_get_usage(true) - $memoryBefore;
            if ($memoryUsed > 134217728) {
                error_log("Action used too much memory: " . ($memoryUsed / 1024 / 1024) . "MB");
            }

            return $result;

        } catch (\Throwable $e) {
            error_log("Action execution failed: " . get_class($action) . " - " . $e->getMessage());
            throw $e;
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
}