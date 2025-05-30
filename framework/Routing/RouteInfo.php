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
     * @param string $originalPath Original path pattern for URL generation
     * @param array<string> $paramNames Parameter names extracted from path
     * @param string $actionClass Action class name
     * @param array<string> $middleware Route middleware
     * @param string|null $name Route name
     */
    public function __construct(
        public string $method,
        public string $pattern,
        public string $originalPath,
        public array $paramNames,
        public string $actionClass,
        public array $middleware = [],
        public ?string $name = null
    ) {}

    /**
     * Create RouteInfo from path pattern
     */
    public static function fromPath(
        string $method,
        string $path,
        string $actionClass,
        array $middleware = [],
        ?string $name = null
    ): self {
        $paramNames = [];

        // Verbesserte Regex für komplexere Parameter
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)}/',
            function ($matches) use (&$paramNames) {
                $paramNames[] = $matches[1];
                return '([^/]+)';
            },
            $path
        );

        // Sicherere Pattern-Erstellung
        $pattern = '#^' . str_replace('/', '\/', $pattern) . '$#';

        return new self($method, $pattern, $path, $paramNames, $actionClass, $middleware, $name);
    }

    /**
     * Check if route matches request
     */
    public function matches(string $method, string $path): bool
    {
        return $this->method === $method && preg_match($this->pattern, $path);
    }

    /**
     * Extract parameters from matched path with security validation
     */
    public function extractParams(string $path): array
    {
        if (!preg_match($this->pattern, $path, $matches)) {
            return [];
        }

        array_shift($matches);

        $params = array_combine($this->paramNames, $matches) ?: [];

        // Validiere Parameter-Werte für Sicherheit
        foreach ($params as $name => $value) {
            // Verhindere Directory Traversal
            if (str_contains($value, '..') || str_contains($value, '\0')) {
                throw new \InvalidArgumentException("Invalid parameter value: {$name}");
            }

            // Längen-Begrenzung
            if (strlen($value) > 255) {
                throw new \InvalidArgumentException("Parameter too long: {$name}");
            }

            // URL-decode parameter
            $params[$name] = urldecode($value);
        }

        return $params;
    }
}