<?php

declare(strict_types=1);

namespace Framework\Routing;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Zentrale Route-Cache-Verwaltung
 * Ersetzt RouteCache + RouteCacheBuilder Funktionalität
 */
final class RouteCacheManager
{
    private const string CACHE_FILE = 'routes.cache';
    private const string STATIC_MAP_FILE = 'static_map.php';
    private const string STATS_FILE = 'stats.json';
    private const int CACHE_TTL = 3600;
    private const int MAX_CACHE_SIZE = 5242880; // 5MB
    private const string CACHE_VERSION = '2.2';

    // Property Hooks für Performance-Metriken
    public bool $isValid {
        get => $this->validateCache();
    }

    public int $cacheSize {
        get => $this->getCacheFileSize();
    }

    public string $cacheSizeFormatted {
        get => $this->formatBytes($this->cacheSize);
    }

    private int $cacheHits = 0;
    private int $totalRequests = 0;

    public function __construct(
        private readonly string $cacheDir,
        private readonly bool $useCompression = true,
        private readonly int $compressionLevel = 6,
        private readonly bool $integrityCheck = true
    ) {
        $this->ensureCacheDirectory();
    }

    /**
     * Store routes mit automatischer Optimierung
     */
    public function store(array $routes): void
    {
        try {
            // Parallel: Standard Cache + Optimized Cache erstellen
            $this->storeStandardCache($routes);
            $this->buildOptimizedCache($routes);
            $this->updateStats($routes);

        } catch (Throwable $e) {
            error_log("Route cache store failed: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Load routes mit automatischer Fallback-Logik
     */
    public function load(): ?array
    {
        $this->totalRequests++;

        // 1. Versuche optimized cache
        $optimized = $this->loadOptimizedCache();
        if ($optimized !== null) {
            $this->cacheHits++;
            return $optimized;
        }

        // 2. Fallback: Standard cache
        $standard = $this->loadStandardCache();
        if ($standard !== null) {
            $this->cacheHits++;
            return $standard;
        }

        return null;
    }

    /**
     * Standard Route-Cache (für Development/Fallback)
     */
    private function storeStandardCache(array $routes): void
    {
        $cacheData = [
            'version' => self::CACHE_VERSION,
            'timestamp' => time(),
            'routes' => $routes,
            'route_count' => $this->countRoutes($routes),
        ];

        $serialized = serialize($cacheData);

        if (strlen($serialized) > self::MAX_CACHE_SIZE) {
            throw new RuntimeException('Cache data too large');
        }

        if ($this->useCompression) {
            $compressed = gzcompress($serialized, $this->compressionLevel);
            if ($compressed !== false) {
                $serialized = $compressed;
            }
        }

        $this->atomicWrite($this->getCacheFile(), $serialized);

        if ($this->integrityCheck) {
            $this->storeIntegrity($serialized);
        }
    }

    /**
     * Optimized Cache (für Production Performance)
     */
    private function buildOptimizedCache(array $routes): void
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

            // Optimize dynamic route order
            $this->optimizeDynamicRoutes($dynamicRoutes[$method]);
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

        $this->writeOptimizedCache($cacheData);
    }

    /**
     * Load Standard Cache
     */
    private function loadStandardCache(): ?array
    {
        try {
            $cacheFile = $this->getCacheFile();

            if (!$this->isValidCacheFile($cacheFile)) {
                return null;
            }

            if ($this->integrityCheck && !$this->verifyIntegrity($cacheFile)) {
                $this->clear();
                return null;
            }

            $data = file_get_contents($cacheFile);
            if ($data === false) {
                return null;
            }

            if ($this->useCompression) {
                $decompressed = gzuncompress($data);
                if ($decompressed !== false) {
                    $data = $decompressed;
                }
            }

            $cacheData = unserialize($data);
            if (!$this->isValidCacheData($cacheData)) {
                $this->clear();
                return null;
            }

            return $cacheData['routes'];

        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Load Optimized Cache (für RouterCore)
     */
    private function loadOptimizedCache(): ?array
    {
        try {
            $cacheFile = $this->getOptimizedCacheFile();

            if (!file_exists($cacheFile)) {
                return null;
            }

            // Check age
            $mtime = filemtime($cacheFile);
            if ($mtime === false || (time() - $mtime) > self::CACHE_TTL) {
                return null;
            }

            $cache = require $cacheFile;

            if (!is_array($cache) || !isset($cache['static'], $cache['dynamic'])) {
                return null;
            }

            // Konvertiere zurück zu Standard-Format für Kompatibilität
            return $this->convertOptimizedToStandard($cache);

        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Konvertiere Optimized Cache zurück zu Standard-Format
     */
    private function convertOptimizedToStandard(array $optimizedCache): array
    {
        $routes = [];

        // Static routes
        foreach ($optimizedCache['static'] as $method => $staticRoutes) {
            $routes[$method] = [];

            foreach ($staticRoutes as $path => $actionClass) {
                $routes[$method][] = RouteInfo::fromPath(
                    $method,
                    $path,
                    $actionClass
                );
            }
        }

        // Dynamic routes
        foreach ($optimizedCache['dynamic'] as $method => $dynamicRoutes) {
            if (!isset($routes[$method])) {
                $routes[$method] = [];
            }

            foreach ($dynamicRoutes as $route) {
                $routes[$method][] = RouteInfo::fromPath(
                    $method,
                    $route['path'],
                    $route['class'],
                    [],
                    null,
                    $route['subdomain']
                );
            }
        }

        return $routes;
    }

    /**
     * Write Optimized Cache als PHP-File
     */
    private function writeOptimizedCache(array $cacheData): void
    {
        $cacheFile = $this->getOptimizedCacheFile();
        $tempFile = $cacheFile . '.tmp.' . uniqid();

        $cacheContent = "<?php\n";
        $cacheContent .= "// Generated route cache - DO NOT EDIT\n";
        $cacheContent .= "// Generated: " . date('Y-m-d H:i:s') . "\n\n";
        $cacheContent .= "return " . var_export($cacheData, true) . ";\n";

        if (file_put_contents($tempFile, $cacheContent, LOCK_EX) === false) {
            throw new RuntimeException("Failed to write optimized cache");
        }

        if (!rename($tempFile, $cacheFile)) {
            unlink($tempFile);
            throw new RuntimeException("Failed to finalize optimized cache");
        }

        chmod($cacheFile, 0644);

        // Precompile für OPcache
        if (function_exists('opcache_compile_file')) {
            opcache_compile_file($cacheFile);
        }
    }

    /**
     * Update Cache-Statistiken
     */
    private function updateStats(array $routes): void
    {
        $routeCount = $this->countRoutes($routes);
        $staticCount = 0;

        foreach ($routes as $methodRoutes) {
            foreach ($methodRoutes as $route) {
                if ($route->isStatic) {
                    $staticCount++;
                }
            }
        }

        $stats = [
            'generated_at' => date('c'),
            'php_version' => PHP_VERSION,
            'cache_version' => self::CACHE_VERSION,
            'routes' => [
                'total_routes' => $routeCount,
                'static_count' => $staticCount,
                'dynamic_count' => $routeCount - $staticCount,
            ],
            'performance' => [
                'static_route_ratio' => $routeCount > 0
                    ? round(($staticCount / $routeCount) * 100, 1)
                    : 0,
                'expected_speedup' => $this->calculateSpeedup($staticCount, $routeCount)
            ]
        ];

        file_put_contents(
            $this->getStatsFile(),
            json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Optimiere Dynamic Route-Reihenfolge
     */
    private function optimizeDynamicRoutes(array &$routes): void
    {
        usort($routes, function (array $a, array $b): int {
            // Routes mit weniger Parametern zuerst
            $paramDiff = count($a['params']) - count($b['params']);
            if ($paramDiff !== 0) {
                return $paramDiff;
            }

            // Subdomain-Routes zuerst
            if ($a['subdomain'] !== null && $b['subdomain'] === null) {
                return -1;
            }
            if ($a['subdomain'] === null && $b['subdomain'] !== null) {
                return 1;
            }

            // Kürzere Pfade zuerst (spezifischer)
            return strlen($a['path']) - strlen($b['path']);
        });
    }

    /**
     * Validate Cache
     */
    public function validateCache(): bool
    {
        $optimizedExists = file_exists($this->getOptimizedCacheFile());
        $standardExists = $this->isValidCacheFile($this->getCacheFile());

        return $optimizedExists || $standardExists;
    }

    /**
     * Clear all caches
     */
    public function clear(): void
    {
        $files = [
            $this->getCacheFile(),
            $this->getCacheFile() . '.integrity',
            $this->getOptimizedCacheFile(),
            $this->getStatsFile()
        ];

        foreach ($files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        $this->resetMetrics();

        // Clear OPcache
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }

    /**
     * Get comprehensive stats
     */
    public function getStats(): array
    {
        $baseStats = [
            'cache_hits' => $this->cacheHits,
            'total_requests' => $this->totalRequests,
            'hit_ratio_percent' => $this->totalRequests > 0
                ? round(($this->cacheHits / $this->totalRequests) * 100, 1)
                : 0,
            'cache_size_bytes' => $this->cacheSize,
            'cache_size_formatted' => $this->cacheSizeFormatted,
            'is_valid' => $this->isValid,
            'has_optimized_cache' => file_exists($this->getOptimizedCacheFile()),
            'has_standard_cache' => file_exists($this->getCacheFile())
        ];

        // Load detailed stats if available
        $statsFile = $this->getStatsFile();
        if (file_exists($statsFile)) {
            $detailedStats = json_decode(file_get_contents($statsFile), true);
            if (is_array($detailedStats)) {
                $baseStats['detailed'] = $detailedStats;
            }
        }

        return $baseStats;
    }

    // Helper methods...
    private function ensureCacheDirectory(): void
    {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        $htaccessFile = $this->cacheDir . '/.htaccess';
        if (!file_exists($htaccessFile)) {
            file_put_contents($htaccessFile, "Deny from all\n");
        }
    }

    private function getCacheFile(): string
    {
        return $this->cacheDir . '/' . self::CACHE_FILE;
    }

    private function getOptimizedCacheFile(): string
    {
        return $this->cacheDir . '/' . self::STATIC_MAP_FILE;
    }

    private function getStatsFile(): string
    {
        return $this->cacheDir . '/' . self::STATS_FILE;
    }

    private function countRoutes(array $routes): int
    {
        return array_sum(array_map('count', $routes));
    }

    private function calculateSpeedup(int $staticCount, int $totalCount): string
    {
        if ($totalCount === 0) return 'No routes';

        $staticRatio = $staticCount / $totalCount;

        return match (true) {
            $staticRatio >= 0.8 => '5-10x faster',
            $staticRatio >= 0.5 => '3-5x faster',
            $staticRatio >= 0.2 => '2-3x faster',
            default => '1.5-2x faster'
        };
    }

    private function atomicWrite(string $filename, string $data): void
    {
        $tempFile = $filename . '.' . uniqid() . '.tmp';

        if (file_put_contents($tempFile, $data, LOCK_EX) === false) {
            throw new RuntimeException("Failed to write cache");
        }

        if (!rename($tempFile, $filename)) {
            unlink($tempFile);
            throw new RuntimeException("Failed to finalize cache");
        }

        chmod($filename, 0644);
    }

    private function storeIntegrity(string $data): void
    {
        $integrityFile = $this->getCacheFile() . '.integrity';
        $hash = hash('sha256', $data);

        file_put_contents($integrityFile, json_encode([
            'hash' => $hash,
            'timestamp' => time(),
            'size' => strlen($data)
        ]), LOCK_EX);
    }

    private function verifyIntegrity(string $cacheFile): bool
    {
        $integrityFile = $cacheFile . '.integrity';

        if (!file_exists($integrityFile)) {
            return false;
        }

        try {
            $integrity = json_decode(file_get_contents($integrityFile), true);
            $cacheData = file_get_contents($cacheFile);

            return $integrity && $cacheData &&
                hash_equals($integrity['hash'], hash('sha256', $cacheData));
        } catch (Throwable) {
            return false;
        }
    }

    private function isValidCacheFile(string $cacheFile): bool
    {
        if (!file_exists($cacheFile) || !is_readable($cacheFile)) {
            return false;
        }

        $mtime = filemtime($cacheFile);
        if ($mtime === false || (time() - $mtime) > self::CACHE_TTL) {
            return false;
        }

        $size = filesize($cacheFile);
        return $size !== false && $size > 0 && $size <= self::MAX_CACHE_SIZE;
    }

    private function isValidCacheData(mixed $cacheData): bool
    {
        return is_array($cacheData) &&
            isset($cacheData['version'], $cacheData['routes']) &&
            $cacheData['version'] === self::CACHE_VERSION &&
            is_array($cacheData['routes']);
    }

    private function getCacheFileSize(): int
    {
        $files = [
            $this->getCacheFile(),
            $this->getOptimizedCacheFile()
        ];

        $totalSize = 0;
        foreach ($files as $file) {
            if (file_exists($file)) {
                $totalSize += filesize($file) ?: 0;
            }
        }

        return $totalSize;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }

    private function resetMetrics(): void
    {
        $this->cacheHits = 0;
        $this->totalRequests = 0;
    }
}