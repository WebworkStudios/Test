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

    public function __construct(
        private readonly Container $container
    ) {}

    /**
     * Add a route to the router
     */
    public function addRoute(
        string $method,
        string $path,
        string $actionClass,
        array $middleware = [],
        ?string $name = null
    ): void {
        $routeInfo = RouteInfo::fromPath($method, $path, $actionClass, $middleware, $name);
        
        $this->routes[$method][] = $routeInfo;
        
        if ($name !== null) {
            $this->namedRoutes[$name] = $routeInfo;
        }
    }

    /**
     * Dispatch request to matching route
     */
    public function dispatch(Request $request): Response
    {
        $method = $request->method;
        $path = $request->uri;

        // Check if method has any routes
        if (!isset($this->routes[$method])) {
            throw new MethodNotAllowedException("Method {$method} not allowed");
        }

        // Find matching route
        foreach ($this->routes[$method] as $routeInfo) {
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
        if (!isset($this->routes[$method])) {
            return false;
        }

        foreach ($this->routes[$method] as $routeInfo) {
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
     * Generate URL for named route
     */
    public function url(string $name, array $params = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new RouteNotFoundException("Named route '{$name}' not found");
        }

        $routeInfo = $this->namedRoutes[$name];
        $path = $routeInfo->pattern;
        
        // Replace parameters in pattern
        foreach ($params as $key => $value) {
            $path = str_replace("{{$key}}", (string) $value, $path);
        }

        return $path;
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