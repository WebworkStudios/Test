<?php

declare(strict_types=1);

namespace Framework\Routing;

use Framework\Container\ContainerInterface;
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

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ?RouteCache $cache = null
    ) {
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

        // Initialisiere Array falls nicht vorhanden
        $this->routes[$method] ??= [];
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
        $path = $request->path; // Verwende path statt uri für saubere Routen

        // Kompiliere Routen für bessere Performance
        $this->compileRoutes();

        // Sammle verfügbare Methoden für diese Route
        $availableMethods = $this->getAvailableMethodsForPath($path);

        if (!isset($this->compiledRoutes[$method])) {
            if (!empty($availableMethods)) {
                throw new MethodNotAllowedException(
                    "Method {$method} not allowed. Available methods: " . implode(', ', $availableMethods),
                    $availableMethods
                );
            }
            throw new RouteNotFoundException("No routes found for method {$method}");
        }

        // Verbesserte Route-Matching mit Early Return
        foreach ($this->compiledRoutes[$method] as $routeInfo) {
            if ($routeInfo->matches($method, $path)) {
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
     * Get available HTTP methods for a given path
     */
    private function getAvailableMethodsForPath(string $path): array
    {
        $this->compileRoutes();
        $methods = [];

        foreach ($this->compiledRoutes as $method => $routes) {
            foreach ($routes as $routeInfo) {
                if ($routeInfo->matches($method, $path)) {
                    $methods[] = $method;
                    break; // Ein Match pro Methode reicht
                }
            }
        }

        return $methods;
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
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * Generate URL for named route
     */
    public function url(string $name, array $params = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new RouteNotFoundException("Named route '{$name}' not found");
        }

        $routeInfo = $this->namedRoutes[$name];
        $path = $routeInfo->originalPath;

        // Ersetze Parameter im Original-Path
        foreach ($params as $key => $value) {
            $path = str_replace("{{$key}}", (string) $value, $path);
        }

        // Prüfe ob alle Parameter ersetzt wurden
        if (preg_match('/{[^}]+}/', $path)) {
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
        $dangerousPatterns = [
            'Reflection', 'PDO', 'mysqli', 'SQLite3', 'DirectoryIterator',
            'SplFileObject', 'SplFileInfo', 'SimpleXMLElement'
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (str_contains($actionClass, $pattern)) {
                throw new \InvalidArgumentException("Invalid action class: {$actionClass}");
            }
        }

        // Namespace-Validierung
        $allowedNamespaces = ['App\\Actions\\', 'App\\Controllers\\', 'App\\Http\\'];
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
            // Sortiere Routen: Statische zuerst, dann Parameter-Routen
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
        return match(true) {
            $result instanceof Response => $result,
            is_array($result) || is_object($result) => Response::json($result),
            default => Response::html((string) $result)
        };
    }
}