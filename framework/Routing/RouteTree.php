<?php

declare(strict_types=1);

namespace Framework\Routing;

/**
 * Optimized Route Tree for O(log n) lookup performance
 */
final class RouteTree
{
    private array $staticNodes = [];
    private array $dynamicNodes = [];
    private array $methodIndex = [];

    public function addRoute(RouteInfo $route): void
    {
        $segments = explode('/', trim($route->originalPath, '/'));
        $method = $route->method;

        // Build tree structure
        if (empty($route->paramNames)) {
            // Static route - direct hash lookup
            $key = $method . ':' . $route->originalPath;
            $this->staticNodes[$key] = $route;
        } else {
            // Dynamic route - build tree
            $this->buildDynamicTree($method, $segments, $route);
        }

        // Method index for fast filtering
        $this->methodIndex[$method][] = $route;
    }

    private function buildDynamicTree(string $method, array $segments, RouteInfo $route): void
    {
        $node = &$this->dynamicNodes;

        // Create method node
        if (!isset($node[$method])) {
            $node[$method] = ['_static' => [], '_param' => null, '_route' => null];
        }

        $node = &$node[$method];

        // Build path tree
        foreach ($segments as $i => $segment) {
            if (str_starts_with($segment, '{') && str_ends_with($segment, '}')) {
                // Parameter node
                if (!isset($node['_param'])) {
                    $node['_param'] = ['_static' => [], '_param' => null, '_route' => null];
                }
                $node = &$node['_param'];
            } else {
                // Static node
                if (!isset($node['_static'][$segment])) {
                    $node['_static'][$segment] = ['_static' => [], '_param' => null, '_route' => null];
                }
                $node = &$node['_static'][$segment];
            }
        }

        // Store route at leaf
        $node['_route'] = $route;
    }

    public function match(string $method, string $path): ?array
    {
        // Try static first (O(1))
        $key = $method . ':' . $path;
        if (isset($this->staticNodes[$key])) {
            return ['route' => $this->staticNodes[$key], 'params' => []];
        }

        // Try dynamic tree (O(log n))
        if (!isset($this->dynamicNodes[$method])) {
            return null;
        }

        $segments = explode('/', trim($path, '/'));
        return $this->traverseTree($this->dynamicNodes[$method], $segments, 0);
    }

    private function traverseTree(array $node, array $segments, int $index): ?array
    {
        // End of path
        if ($index >= count($segments)) {
            return $node['_route'] ? ['route' => $node['_route'], 'params' => []] : null;
        }

        $segment = $segments[$index];

        // Try static match first
        if (isset($node['_static'][$segment])) {
            $result = $this->traverseTree($node['_static'][$segment], $segments, $index + 1);
            if ($result !== null) {
                return $result;
            }
        }

        // Try parameter match
        if ($node['_param'] !== null) {
            $result = $this->traverseTree($node['_param'], $segments, $index + 1);
            if ($result !== null) {
                // Extract parameter value
                $result['params'][] = $segment;
                return $result;
            }
        }

        return null;
    }
}