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

    // Sicherheits-Konfiguration
    private readonly array $allowedSubdomains;
    private readonly string $baseDomain;
    private readonly bool $strictSubdomainMode;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ?RouteCache $cache = null,
        array $config = []
    ) {
        // Sichere Konfiguration
        $this->allowedSubdomains = $config['allowed_subdomains'] ?? ['api', 'admin', 'www'];
        $this->baseDomain = $config['base_domain'] ?? $this->detectBaseDomain();
        $this->strictSubdomainMode = $config['strict_subdomain_mode'] ?? true;

        $this->loadCachedRoutes();
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
        // Umfassende Sicherheitsvalidierung
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
        // Sichere Request-Validierung
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
        // Sichere Input-Validierung
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
        // Input-Validierung
        if (strlen($name) > 255 || !preg_match('/^[a-zA-Z0-9._-]+$/', $name)) {
            throw new \InvalidArgumentException("Invalid route name: {$name}");
        }

        if (!isset($this->namedRoutes[$name])) {
            throw new RouteNotFoundException("Named route '{$name}' not found");
        }

        $routeInfo = $this->namedRoutes[$name];
        $path = $routeInfo->originalPath;

        // Sichere Parameter-Ersetzung
        foreach ($params as $key => $value) {
            $sanitizedKey = $this->sanitizeParameterKey($key);
            $sanitizedValue = $this->sanitizeParameterValue($value);
            $path = str_replace("{{$sanitizedKey}}", $sanitizedValue, $path);
        }

        if (preg_match('/{[^}]+}/', $path)) {
            throw new \InvalidArgumentException("Missing parameters for route '{$name}'");
        }

        // Sichere URL-Generierung mit Subdomain
        $routeSubdomain = $subdomain ?? $routeInfo->subdomain;
        if ($routeSubdomain !== null) {
            $validatedSubdomain = $this->validateSubdomainInput($routeSubdomain);
            return $this->buildSecureUrl($validatedSubdomain, $path);
        }

        return $path;
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
        // Method Validierung
        $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'];
        if (!in_array(strtoupper($method), $allowedMethods, true)) {
            throw new \InvalidArgumentException("Invalid HTTP method: {$method}");
        }

        // Path Validierung
        if (strlen($path) > 2048 || !str_starts_with($path, '/')) {
            throw new \InvalidArgumentException("Invalid path: {$path}");
        }

        // Action Class Validierung
        if (strlen($actionClass) > 255 || !preg_match('/^[a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*$/', $actionClass)) {
            throw new \InvalidArgumentException("Invalid action class: {$actionClass}");
        }

        // Middleware Validierung
        foreach ($middleware as $mw) {
            if (!is_string($mw) || strlen($mw) > 100 || !preg_match('/^[a-zA-Z0-9_.-]+$/', $mw)) {
                throw new \InvalidArgumentException("Invalid middleware: {$mw}");
            }
        }

        // Name Validierung
        if ($name !== null && (strlen($name) > 255 || !preg_match('/^[a-zA-Z0-9._-]+$/', $name))) {
            throw new \InvalidArgumentException("Invalid route name: {$name}");
        }

        // Subdomain Validierung
        if ($subdomain !== null) {
            $this->validateSubdomainInput($subdomain);
        }
    }

    /**
     * Validate request for security issues
     */
    private function validateRequestSecurity(Request $request): void
    {
        // Host Header Validation (gegen Host Header Injection)
        $host = $request->host();
        if (!$this->isValidHost($host)) {
            throw new \InvalidArgumentException("Invalid host header: {$host}");
        }

        // Path Length Check
        if (strlen($request->path) > 2048) {
            throw new \InvalidArgumentException("Request path too long");
        }

        // Null Byte Check
        if (str_contains($request->path, "\0") || str_contains($request->uri, "\0")) {
            throw new \InvalidArgumentException("Request contains null bytes");
        }
    }

    /**
     * Validate host header against allowed hosts
     */
    private function isValidHost(string $host): bool
    {
        // Port entfernen falls vorhanden
        $hostWithoutPort = explode(':', $host)[0];

        // IP-Adressen validieren
        if (filter_var($hostWithoutPort, FILTER_VALIDATE_IP)) {
            // Nur lokale IPs in Development erlauben
            return filter_var($hostWithoutPort, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        // Domain-Format validieren
        if (!preg_match('/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $hostWithoutPort) && $hostWithoutPort !== 'localhost') {
            return false;
        }

        // Base Domain Check
        $parts = explode('.', $hostWithoutPort);
        if (count($parts) >= 2) {
            $domain = implode('.', array_slice($parts, -2));
            return $domain === $this->baseDomain || $hostWithoutPort === 'localhost';
        }

        return $hostWithoutPort === 'localhost';
    }

    /**
     * Extract and validate subdomain securely
     */
    private function extractSecureSubdomain(string $host): ?string
    {
        $hostWithoutPort = explode(':', $host)[0];

        // Localhost oder IP - keine Subdomain
        if ($hostWithoutPort === 'localhost' || filter_var($hostWithoutPort, FILTER_VALIDATE_IP)) {
            return null;
        }

        $parts = explode('.', $hostWithoutPort);

        if (count($parts) < 3) {
            return null;
        }

        $subdomain = $parts[0];

        // Sicherheitsvalidierung
        if (!$this->isValidSubdomain($subdomain)) {
            throw new \InvalidArgumentException("Invalid subdomain: {$subdomain}");
        }

        // Strict Mode: Nur erlaubte Subdomains
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
        // Längen-Check
        if (strlen($subdomain) > 63 || strlen($subdomain) === 0) {
            return false;
        }

        // RFC 1123 hostname validation
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?$/', $subdomain)) {
            return false;
        }

        // Gefährliche Zeichen und Sequenzen
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
        // Entferne gefährliche Zeichen
        $cleaned = preg_replace('/[^\w\-_\/{}.]/', '', $path);

        // Directory Traversal Prevention
        $cleaned = str_replace(['../', '.\\', '..\\'], '', $cleaned);

        // Ensure starts with /
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
        // Validiere finalen Subdomain nochmal
        if (!$this->isValidSubdomain($subdomain)) {
            throw new \InvalidArgumentException("Invalid subdomain for URL: {$subdomain}");
        }

        // Validiere Base Domain
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

        // Validate host
        if (!$this->isValidHostForDetection($hostWithoutPort)) {
            return 'localhost'; // Fallback für Sicherheit
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
        // IP-Adressen ablehnen
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }

        // Localhost erlauben
        if ($host === 'localhost') {
            return true;
        }

        // Domain-Format validieren
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
        // Existenz-Check
        if (!class_exists($actionClass)) {
            throw new \InvalidArgumentException("Action class {$actionClass} does not exist");
        }

        $reflection = new \ReflectionClass($actionClass);

        // Invokability Check
        if (!$reflection->hasMethod('__invoke')) {
            throw new \InvalidArgumentException("Action class {$actionClass} must be invokable");
        }

        // Sicherheits-Checks: Gefährliche Klassen blocken
        $dangerousPatterns = [
            'Reflection', 'PDO', 'mysqli', 'SQLite3', 'DirectoryIterator',
            'SplFileObject', 'SplFileInfo', 'SimpleXMLElement', 'DOMDocument',
            'XMLReader', 'XMLWriter', 'ZipArchive', 'Phar', 'SplFileInfo',
            'RecursiveDirectoryIterator', 'FilesystemIterator', 'GlobIterator',
            'SplTempFileObject', 'SplFileObject', 'finfo', 'Imagick'
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (str_contains($actionClass, $pattern)) {
                throw new \InvalidArgumentException("Dangerous action class not allowed: {$actionClass}");
            }
        }

        // Namespace-Whitelist (strenger)
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

        // Zusätzliche Sicherheitsprüfungen
        $this->validateActionClassSecurity($reflection);
    }

    /**
     * Additional security validation for action classes
     */
    private function validateActionClassSecurity(\ReflectionClass $reflection): void
    {
        // Prüfe auf gefährliche Methoden
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

        // Prüfe auf Serializable Interface (potenzielle Deserialization-Angriffe)
        if ($reflection->implementsInterface('Serializable')) {
            throw new \InvalidArgumentException("Action class implements Serializable interface (security risk)");
        }

        // Prüfe auf final class (Best Practice für Action Classes)
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
            // Sortierung für Sicherheit und Performance
            usort($routes, function(RouteInfo $a, RouteInfo $b): int {
                // 1. Subdomain-spezifische Routen zuerst (präziser)
                $aHasSubdomain = $a->subdomain !== null;
                $bHasSubdomain = $b->subdomain !== null;

                if ($aHasSubdomain !== $bHasSubdomain) {
                    return $aHasSubdomain ? -1 : 1;
                }

                // 2. Statische Routen vor parametrischen
                $aHasParams = !empty($a->paramNames);
                $bHasParams = !empty($b->paramNames);

                if ($aHasParams !== $bHasParams) {
                    return $aHasParams ? 1 : -1;
                }

                // 3. Kürzere Pfade zuerst
                return strlen($a->originalPath) <=> strlen($b->originalPath);
            });

            $this->compiledRoutes[$method] = $routes;
        }

        $this->routesCompiled = true;

        // Cache nur speichern wenn sicher
        if ($this->cache !== null && $this->isSecureCacheEnvironment()) {
            $this->cache->store($this->compiledRoutes);
        }
    }

    /**
     * Check if caching environment is secure
     */
    private function isSecureCacheEnvironment(): bool
    {
        // Prüfe ob wir in einer sicheren Umgebung sind
        // (nicht in Debug-Mode, korrekter Cache-Pfad, etc.)
        return !($this->isDebugMode() || $this->isUnsafeEnvironment());
    }

    /**
     * Check if in debug mode
     */
    private function isDebugMode(): bool
    {
        return defined('APP_DEBUG') && APP_DEBUG === true;
    }

    /**
     * Check for unsafe environment
     */
    private function isUnsafeEnvironment(): bool
    {
        // Prüfe auf Development-Umgebung
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
        // Basis-Validierung der Cache-Struktur
        if (!is_array($cached)) {
            return false;
        }

        // Validiere jede Route im Cache
        foreach ($cached as $method => $routes) {
            if (!is_string($method) || !is_array($routes)) {
                return false;
            }

            foreach ($routes as $route) {
                if (!($route instanceof RouteInfo)) {
                    return false;
                }

                // Zusätzliche Sicherheitsvalidierung
                if (!$this->isValidCachedRoute($route)) {
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
        // Validiere Action Class existiert noch
        if (!class_exists($route->actionClass)) {
            return false;
        }

        // Validiere Pattern ist sicher
        if (str_contains($route->pattern, '(?') || strlen($route->pattern) > 1000) {
            return false;
        }

        // Validiere Subdomain falls vorhanden
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
        // Finale Sicherheitsvalidierung vor Action-Aufruf
        $this->validateActionInvocation($actionClass, $request, $params);

        // Resolve action from container
        $action = $this->container->get($actionClass);

        // Security: Validiere dass Container korrekte Instanz zurückgibt
        if (!is_object($action) || get_class($action) !== $actionClass) {
            throw new \RuntimeException("Container returned invalid action instance");
        }

        // Security: Validiere dass Action invokable ist
        if (!is_callable($action)) {
            throw new \RuntimeException("Action {$actionClass} is not invokable");
        }

        try {
            // Call action with security monitoring
            $result = $this->executeActionSecurely($action, $request, $params);

            // Convert result to Response if needed
            return $this->convertToResponse($result);
        } catch (\Throwable $e) {
            // Log security-relevant errors
            error_log("Action execution error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Validate action invocation for security
     */
    private function validateActionInvocation(string $actionClass, Request $request, array $params): void
    {
        // Validiere Parameter-Anzahl (DoS Prevention)
        if (count($params) > 20) {
            throw new \InvalidArgumentException("Too many route parameters");
        }

        // Validiere Parameter-Größe
        foreach ($params as $key => $value) {
            if (strlen($key) > 50 || strlen((string)$value) > 1000) {
                throw new \InvalidArgumentException("Parameter too large: {$key}");
            }
        }

        // Request-Größe validieren
        if (strlen($request->raw()) > 10485760) { // 10MB
            throw new \InvalidArgumentException("Request body too large");
        }
    }

    /**
     * Execute action with security monitoring
     */
    private function executeActionSecurely(object $action, Request $request, array $params): mixed
    {
        // Memory limit monitoring
        $memoryBefore = memory_get_usage(true);

        // Execution time monitoring
        $timeBefore = microtime(true);

        try {
            $result = $action($request, $params);

            // Check execution time (DoS prevention)
            $executionTime = microtime(true) - $timeBefore;
            if ($executionTime > 30) { // 30 seconds max
                error_log("Action execution too slow: {$executionTime}s");
            }

            // Check memory usage (DoS prevention)
            $memoryUsed = memory_get_usage(true) - $memoryBefore;
            if ($memoryUsed > 134217728) { // 128MB
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
        // Größen-Validierung
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
        // Basis HTML-Escaping
        $escaped = htmlspecialchars($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Größen-Validierung
        if (strlen($escaped) > 10485760) { // 10MB
            throw new \RuntimeException("HTML response too large");
        }

        return $escaped;
    }

    /**
     * Get all registered routes
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}