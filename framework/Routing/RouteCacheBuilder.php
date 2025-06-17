<?php
declare(strict_types=1);

namespace Framework\Routing;

use RuntimeException;
use Throwable;

/**
 * Builds optimized route cache for production performance
 */
final class RouteCacheBuilder
{
    /**
     * Get cache statistics
     */
    public static function getCacheStats(): ?array
    {
        $statsFile = __DIR__ . '/../../storage/cache/routes/stats.json';

        if (!file_exists($statsFile)) {
            return null;
        }

        $stats = json_decode(file_get_contents($statsFile), true);
        return is_array($stats) ? $stats : null;
    }

    /**
     * Clear all route caches
     */
    public static function clearCache(): void
    {
        $files = [
            __DIR__ . '/../../storage/cache/routes/static_map.php',
            __DIR__ . '/../../storage/cache/routes/stats.json'
        ];

        foreach ($files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        // Clear OPcache if available
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }

    /**
     * Auto-rebuild cache if routes have changed
     */
    public static function autoRebuild(Router $router): bool
    {
        if (self::validateCache()) {
            return false; // Cache is valid
        }

        self::buildFromRouter($router);
        return true; // Cache was rebuilt
    }

    /**
     * Validate cache integrity
     */
    public static function validateCache(): bool
    {
        $cacheFile = __DIR__ . '/../../storage/cache/routes/static_map.php';

        if (!file_exists($cacheFile)) {
            return false;
        }

        try {
            $cache = require $cacheFile;

            if (!is_array($cache) || !isset($cache['static']) || !isset($cache['dynamic'])) {
                return false;
            }

            // Check if cache is recent (less than 24 hours old)
            $generated = $cache['meta']['generated'] ?? 0;
            if (time() - $generated > 86400) {
                return false;
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Build cache from Router instance
     */
    public static function buildFromRouter(Router $router): void
    {
        $routes = $router->getRoutes();
        self::buildCache($routes);
    }

    /**
     * Build static route map from discovered routes
     */
    public static function buildCache(array $routes): void
    {
        $staticMap = [];
        $dynamicRoutes = [];
        $stats = [
            'static_count' => 0,
            'dynamic_count' => 0,
            'total_routes' => 0
        ];

        foreach ($routes as $method => $methodRoutes) {
            $staticMap[$method] = [];
            $dynamicRoutes[$method] = [];

            foreach ($methodRoutes as $route) {
                $stats['total_routes']++;

                if ($route->isStatic) {
                    $key = $route->subdomain
                        ? "{$route->subdomain}:{$route->originalPath}"
                        : $route->originalPath;
                    $staticMap[$method][$key] = $route->actionClass;
                    $stats['static_count']++;
                } else {
                    $dynamicRoutes[$method][] = [
                        'pattern' => $route->pattern,
                        'class' => $route->actionClass,
                        'params' => $route->paramNames,
                        'subdomain' => $route->subdomain,
                        'path' => $route->originalPath
                    ];
                    $stats['dynamic_count']++;
                }
            }

            // Sort dynamic routes for optimal matching
            self::optimizeDynamicRoutes($dynamicRoutes[$method]);
        }

        $cacheData = [
            'static' => $staticMap,
            'dynamic' => $dynamicRoutes,
            'meta' => [
                'generated' => time(),
                'php_version' => PHP_VERSION,
                'stats' => $stats
            ]
        ];

        self::writeCache($cacheData);

        // Write human-readable stats file
        self::writeStatsFile($stats);
    }

    /**
     * Optimize dynamic route order for performance
     */
    private static function optimizeDynamicRoutes(array &$routes): void
    {
        usort($routes, function (array $a, array $b): int {
            // Routes with fewer parameters first (faster matching)
            $paramDiff = count($a['params']) - count($b['params']);
            if ($paramDiff !== 0) {
                return $paramDiff;
            }

            // Routes with subdomain constraints first
            if ($a['subdomain'] !== null && $b['subdomain'] === null) {
                return -1;
            }
            if ($a['subdomain'] === null && $b['subdomain'] !== null) {
                return 1;
            }

            // Shorter paths first (more specific)
            return strlen($a['path']) - strlen($b['path']);
        });
    }

    /**
     * Write cache file atomically
     */
    private static function writeCache(array $cacheData): void
    {
        $cacheDir = __DIR__ . '/../../storage/cache/routes';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $cacheFile = $cacheDir . '/static_map.php';
        $tempFile = $cacheFile . '.tmp.' . uniqid();

        // Generate optimized PHP code
        $cacheContent = "<?php\n";
        $cacheContent .= "// Generated route cache - DO NOT EDIT\n";
        $cacheContent .= "// Generated: " . date('Y-m-d H:i:s') . "\n\n";
        $cacheContent .= "return " . var_export($cacheData, true) . ";\n";

        if (file_put_contents($tempFile, $cacheContent, LOCK_EX) === false) {
            throw new RuntimeException("Failed to write route cache");
        }

        if (!rename($tempFile, $cacheFile)) {
            unlink($tempFile);
            throw new RuntimeException("Failed to finalize route cache");
        }

        // Set appropriate permissions
        chmod($cacheFile, 0644);

        // Precompile for OPcache if available
        if (function_exists('opcache_compile_file')) {
            opcache_compile_file($cacheFile);
        }
    }

    /**
     * Write human-readable stats file
     */
    private static function writeStatsFile(array $stats): void
    {
        $statsFile = __DIR__ . '/../../storage/cache/routes/stats.json';

        $statsData = [
            'generated_at' => date('c'),
            'php_version' => PHP_VERSION,
            'framework_version' => '1.0.0',
            'routes' => $stats,
            'performance' => [
                'static_route_ratio' => $stats['total_routes'] > 0
                    ? round(($stats['static_count'] / $stats['total_routes']) * 100, 1)
                    : 0,
                'expected_speedup' => self::calculateSpeedup($stats)
            ]
        ];

        file_put_contents($statsFile, json_encode($statsData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Calculate expected performance improvement
     */
    private static function calculateSpeedup(array $stats): string
    {
        if ($stats['total_routes'] === 0) {
            return 'No routes';
        }

        $staticRatio = $stats['static_count'] / $stats['total_routes'];

        return match (true) {
            $staticRatio >= 0.8 => '5-10x faster',
            $staticRatio >= 0.5 => '3-5x faster',
            $staticRatio >= 0.2 => '2-3x faster',
            default => '1.5-2x faster'
        };
    }
}