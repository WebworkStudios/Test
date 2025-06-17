<?php


declare(strict_types=1);

namespace Framework\Routing;

use Framework\Container\ContainerInterface;
use Framework\Http\{Request, RequestSanitizer, Response};
use Framework\Routing\Exceptions\{MethodNotAllowedException, RouteNotFoundException};
use RuntimeException;
use Throwable;

/**
 * High-Performance Router Core - O(1) static route lookup
 */
final class RouterCore
{
    private static ?array $staticRouteMap = null;
    private static ?array $dynamicRoutes = null;

    // Performance tracking
    private int $dispatchCount = 0;
    private int $staticHits = 0;
    private float $totalDispatchTime = 0.0;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly bool               $debugMode = false,
        private readonly array              $allowedSubdomains = ['api', 'admin', 'www'],
    )
    {
    }

    /**
     * Clear route cache
     */
    public static function clearCache(): void
    {
        self::$staticRouteMap = null;
        self::$dynamicRoutes = null;

        $cacheFile = __DIR__ . '/../../storage/cache/routes/static_map.php';
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
    }

    /**
     * Ultra-fast dispatch - O(1) for static routes
     */
    public function dispatch(Request $request): Response
    {
        $startTime = hrtime(true);
        $this->dispatchCount++;

        try {
            $this->loadRouteCache();

            $method = strtoupper($request->method);
            $path = RequestSanitizer::sanitizePath($request->path);
            $subdomain = RequestSanitizer::extractSubdomain($request->host(), $this->allowedSubdomains);

            // ✅ O(1) Static Route Lookup
            $staticKey = $subdomain ? "{$subdomain}:{$path}" : $path;

            if (isset(self::$staticRouteMap[$method][$staticKey])) {
                $this->staticHits++;
                $actionClass = self::$staticRouteMap[$method][$staticKey];
                return $this->callAction($actionClass, $request, []);
            }

            // ✅ Dynamic Routes nur wenn nötig
            return $this->dispatchDynamic($method, $path, $subdomain, $request);

        } catch (Throwable $e) {
            if ($this->debugMode) {
                error_log("RouterCore dispatch error: " . $e->getMessage());
            }
            throw $e;
        } finally {
            $this->totalDispatchTime += (hrtime(true) - $startTime) / 1_000_000;
        }
    }

    /**
     * Load route cache with fallback
     */
    private function loadRouteCache(): void
    {
        if (self::$staticRouteMap !== null) {
            return; // Already loaded
        }

        $cacheFile = __DIR__ . '/../../storage/cache/routes/static_map.php';

        if (file_exists($cacheFile) && !$this->debugMode) {
            try {
                $cache = require $cacheFile;
                self::$staticRouteMap = $cache['static'] ?? [];
                self::$dynamicRoutes = $cache['dynamic'] ?? [];
            } catch (Throwable $e) {
                // Cache corrupted - rebuild
                self::$staticRouteMap = [];
                self::$dynamicRoutes = [];
            }
        } else {
            // No cache available
            self::$staticRouteMap = [];
            self::$dynamicRoutes = [];
        }
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
     * Handle dynamic routes (with patterns)
     */
    private function dispatchDynamic(string $method, string $path, ?string $subdomain, Request $request): Response
    {
        if (!isset(self::$dynamicRoutes[$method])) {
            $this->handleNoMatch($method, $path, $subdomain);
        }

        foreach (self::$dynamicRoutes[$method] as $route) {
            // Subdomain check
            if ($route['subdomain'] !== $subdomain) {
                continue;
            }

            // Pattern match
            if (preg_match($route['pattern'], $path, $matches)) {
                $params = [];

                // Extract parameters
                for ($i = 1; $i < count($matches); $i++) {
                    $paramName = $route['params'][$i - 1] ?? "param{$i}";
                    $params[$paramName] = RequestSanitizer::sanitizeParameter($matches[$i]);
                }

                return $this->callAction($route['class'], $request, $params);
            }
        }

        $this->handleNoMatch($method, $path, $subdomain);
    }

    /**
     * Handle no route match
     */
    private function handleNoMatch(string $method, string $path, ?string $subdomain): never
    {
        // Check if path exists for other methods
        $availableMethods = [];
        foreach (self::$staticRouteMap as $checkMethod => $routes) {
            $staticKey = $subdomain ? "{$subdomain}:{$path}" : $path;
            if (isset($routes[$staticKey])) {
                $availableMethods[] = $checkMethod;
            }
        }

        // Check dynamic routes for other methods
        foreach (self::$dynamicRoutes as $checkMethod => $routes) {
            if ($checkMethod === $method) continue;

            foreach ($routes as $route) {
                if ($route['subdomain'] === $subdomain && preg_match($route['pattern'], $path)) {
                    $availableMethods[] = $checkMethod;
                    break;
                }
            }
        }

        if (!empty($availableMethods)) {
            throw new MethodNotAllowedException(
                "Method {$method} not allowed for path {$path}",
                array_unique($availableMethods)
            );
        }

        throw new RouteNotFoundException("Route not found: {$method} {$path}" .
            ($subdomain ? " (subdomain: {$subdomain})" : ""));
    }

    /**
     * Get performance statistics
     */
    public function getStats(): array
    {
        return [
            'dispatch_count' => $this->dispatchCount,
            'static_hits' => $this->staticHits,
            'static_hit_ratio' => $this->dispatchCount > 0
                ? round(($this->staticHits / $this->dispatchCount) * 100, 2)
                : 0,
            'average_dispatch_time_ms' => $this->dispatchCount > 0
                ? round($this->totalDispatchTime / $this->dispatchCount, 3)
                : 0,
            'cached_static_routes' => array_sum(array_map('count', self::$staticRouteMap ?? [])),
            'cached_dynamic_routes' => array_sum(array_map('count', self::$dynamicRoutes ?? [])),
        ];
    }
}