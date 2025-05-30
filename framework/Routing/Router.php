<?php

declare(strict_types=1);

namespace Framework\Routing;

use Framework\Container\Container;
use Framework\Http\{Request, Response};
use Framework\Routing\Exceptions\{RouteNotFoundException, MethodNotAllowedException};

/**
 * HTTP Router with attribute-based route registration
 */
final class Router
{
    /** @var array<string, array<RouteInfo>> */
    private array $routes = [];

    /** @var array<string, RouteInfo> */
    private array $namedRoutes = [];

    // Performance-Optimierung: Route-Caching
    private array $compiledRoutes = [];
    private bool $routesCompiled = false;
    private ?RouteCache $cache = null;

    public function __construct(
        private readonly Container $container,
        ?RouteCache $cache = null
    ) {
        $this->cache = $cache ?? new RouteCache();
        $this->loadCachedRoutes();
    }

    /**
     * Add a route to the router with validation
     */
    public function addRoute(
        string $method,
        string $path,
        string $actionClass,
        array $middleware = [],
        ?string $name = null
    ): void {
        // Validierung der Action-Klasse
        $this->validateActionClass($actionClass);

        $routeInfo = RouteInfo::fromPath($method, $path, $actionClass, $middleware, $name);

        $this->routes[$method][] = $routeInfo;

        if ($name !== null) {
            // Prüfe auf doppelte Namen
            if (isset($this->namedRoutes[$name])) {
                throw new \InvalidArgumentException("Route name '{$name}' already exists");
            }
            $this->namedRoutes[$name] = $routeInfo;
        }

        // Cache invalidieren
        $this->routesCompiled = false;
    }

    /**
     * Dispatch request to matching route with optimized performance
     */
    public function dispatch(Request $request): Response
    {
        $method = $request->method;
        $path = $request->uri;

        // Kompiliere Routen für bessere Performance
        $this->compileRoutes();

        if (!isset($this->compiledRoutes[$method])) {
            // Sammle verfügbare Methoden für bessere Fehlermeldung
            $availableMethods = array_keys($this->compiledRoutes);

            if (empty($availableMethods)) {
                throw new MethodNotAllowedException("Method {$method} not allowed");
            }

            // Prüfe ob Route für andere Methoden existiert
            foreach ($availableMethods as $availableMethod) {
                foreach ($this->compiledRoutes[$availableMethod] as $routeInfo) {
                    if ($routeInfo->matches($availableMethod, $path)) {
                        throw new MethodNotAllowedException(
                            "Method {$method} not allowed. Available methods: " . implode(', ', $availableMethods)
                        );
                    }
                }
            }

            throw new MethodNotAllowedException("Method {$method} not allowed");
        }

        // Verbessertes Matching mit Early Return
        foreach ($this->compiledRoutes[$method] as $routeInfo) {
            if ($routeInfo->matches($method, $path)) {
                $params = $routeInfo->extractParams($path);
                return $this->callAction($routeInfo->actionClass, $request, $params);
            }
        }

        throw new RouteNotFoundException("Route not found: {$method} {$path}");
    }

    /**
     * Check if route exists for given method and path
     */
    public function hasRoute(string $method, string $path): bool
    {
        $this->compileRoutes();

        if (!isset($this->compiledRoutes[$method])) {
            return false;
        }

        foreach ($this->compiledRoutes[$method] as $routeInfo) {
            if ($routeInfo->matches($method, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all registered routes
     *
     * @return array<string, array<RouteInfo>>
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * Generate URL for named route - FIXED
     */
    public function url(string $name, array $params = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new RouteNotFoundException("Named route '{$name}' not found");
        }

        $routeInfo = $this->namedRoutes[$name];

        // Verwende Original-Path für URL-Generation
        $path = $routeInfo->originalPath;

        // Ersetze Parameter im Original-Path
        foreach ($params as $key => $value) {
            $path = str_replace("{{$key}}", (string) $value, $path);
        }

        // Prüfe ob alle Parameter ersetzt wurden
        if (preg_match('/\{[^}]+\}/', $path)) {
            throw new \InvalidArgumentException("Missing parameters for route '{$name}'");
        }

        return $path;
    }

    /**
     * Validate action class for security
     */
    private function validateActionClass(string $actionClass): void
    {
        if (!class_exists($actionClass)) {
            throw new \InvalidArgumentException("Action class {$actionClass} does not exist");
        }

        $reflection = new \ReflectionClass($actionClass);

        // Prüfe ob Klasse invokable ist
        if (!$reflection->hasMethod('__invoke')) {
            throw new \InvalidArgumentException("Action class {$actionClass} must be invokable");
        }

        // Sicherheitsprüfung: Keine System-Klassen
        $dangerousClasses = [
            'ReflectionClass', 'ReflectionFunction', 'ReflectionMethod',
            'PDO', 'mysqli', 'SQLite3',
            'DirectoryIterator', 'RecursiveDirectoryIterator',
            'SplFileObject', 'SplFileInfo'
        ];

        foreach ($dangerousClasses as $dangerous) {
            if (str_starts_with($actionClass, $dangerous)) {
                throw new \InvalidArgumentException("Invalid action class: {$actionClass}");
            }
        }

        // Prüfe Namespace-Whitelist (optional - kann konfiguriert werden)
        $allowedNamespaces = ['App\\Actions\\', 'App\\Controllers\\'];
        $isAllowed = false;

        foreach ($allowedNamespaces as $namespace) {
            if (str_starts_with($actionClass, $namespace)) {
                $isAllowed = true;
                break;
            }
        }

        if (!$isAllowed) {
            error_log("Warning: Action class {$actionClass} not in allowed namespaces");
        }
    }

    /**
     * Compile routes for better performance
     */
    private function compileRoutes(): void
    {
        if ($this->routesCompiled) {
            return;
        }

        foreach ($this->routes as $method => $routes) {
            $this->compiledRoutes[$method] = [];

            // Sortiere Routen: Statische zuerst, dann Parameter-Routen
            // Das verbessert die Performance da statische Routen häufiger sind
            usort($routes, function(RouteInfo $a, RouteInfo $b): int {
                $aHasParams = !empty($a->paramNames);
                $bHasParams = !empty($b->paramNames);

                if ($aHasParams === $bHasParams) {
                    // Bei gleicher Kategorie: kürzere Pfade zuerst
                    return strlen($a->originalPath) <=> strlen($b->originalPath);
                }

                return $aHasParams ? 1 : -1;
            });

            $this->compiledRoutes[$method] = $routes;
        }

        $this->routesCompiled = true;

        // Cache aktualisieren
        $this->cache?->store($this->compiledRoutes);
    }

    /**
     * Load cached routes for performance
     */
    private function loadCachedRoutes(): void
    {
        if ($this->cache === null) {
            return;
        }

        $cached = $this->cache->load();
        if ($cached !== null) {
            $this->compiledRoutes = $cached;
            $this->routesCompiled = true;
        }
    }

    /**
     * Call action class with dependency injection
     */
    private function callAction(string $actionClass, Request $request, array $params): Response
    {
        // Resolve action from container
        $action = $this->container->get($actionClass);

        // Ensure action is invokable
        if (!is_callable($action)) {
            throw new \InvalidArgumentException("Action {$actionClass} is not invokable");
        }

        // Call action with request and route parameters
        $result = $action($request, $params);

        // Convert result to Response if needed
        if ($result instanceof Response) {
            return $result;
        }

        // Auto-convert arrays/objects to JSON
        if (is_array($result) || is_object($result)) {
            return Response::json($result);
        }

        // Convert strings to HTML response
        return Response::html((string) $result);
    }
}