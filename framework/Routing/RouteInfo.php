<?php

declare(strict_types=1);

namespace Framework\Routing;

/**
 * Internal route information storage
 */
final readonly class RouteInfo
{
    /**
     * @param string $method HTTP method
     * @param string $pattern Compiled regex pattern
     * @param array<string> $paramNames Parameter names extracted from path
     * @param string $actionClass Action class name
     * @param array<string> $middleware Route middleware
     * @param string|null $name Route name
     */
    public function __construct(
        public string $method,
        public string $pattern,
        public array $paramNames,
        public string $actionClass,
        public array $middleware = [],
        public ?string $name = null
    ) {}

    /**
     * Create RouteInfo from path pattern
     */
    public function fromPath(
        string $method,
        string $path,
        string $actionClass,
        array $middleware = [],
        ?string $name = null
    ): self {
        $paramNames = [];
        
        // Convert /user/{id}/posts/{postId} to regex pattern
        $pattern = preg_replace_callback(
            '/\{([^}]+)\}/',
            function ($matches) use (&$paramNames) {
                $paramNames[] = $matches[1];
                return '([^/]+)';
            },
            $path
        );
        
        // Escape forward slashes and add anchors
        $pattern = '#^' . str_replace('/', '\/', $pattern) . '$#';
        
        return new self($method, $pattern, $paramNames, $actionClass, $middleware, $name);
    }

    /**
     * Check if route matches request
     */
    public function matches(string $method, string $path): bool
    {
        return $this->method === $method && preg_match($this->pattern, $path);
    }

    /**
     * Extract parameters from matched path
     */
    public function extractParams(string $path): array
    {
        if (!preg_match($this->pattern, $path, $matches)) {
            return [];
        }
        
        // Remove full match from results
        array_shift($matches);
        
        // Combine parameter names with values
        return array_combine($this->paramNames, $matches) ?: [];
    }
}