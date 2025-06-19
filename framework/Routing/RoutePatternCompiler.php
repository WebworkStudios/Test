<?php

declare(strict_types=1);

namespace Framework\Routing;

use InvalidArgumentException;

/**
 * Zentrale Route-Pattern-Kompilierung mit Caching
 */
final class RoutePatternCompiler
{
    private static array $patternCache = [];
    private static array $constraintCache = [
        'int' => '(\d+)',
        'integer' => '(\d+)',
        'uuid' => '([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})',
        'slug' => '([a-z0-9-]+)',
        'alpha' => '([a-zA-Z]+)',
        'alnum' => '([a-zA-Z0-9]+)'
    ];

    // ✅ NEU: Performance-Tracking
    private static int $cacheHits = 0;
    private static int $compileCalls = 0;
    private static int $maxCacheSize = 1000;

    /**
     * Validiere Parameter gegen Constraints
     */
    public static function validateParameter(string $name, string $value, array $constraints): string
    {
        if (!isset($constraints[$name])) {
            return $value;
        }

        $constraint = $constraints[$name];

        return match ($constraint) {
            'int', 'integer' => self::validateIntegerParameter($value),
            'uuid' => self::validateUuidParameter($value),
            'slug' => self::validateSlugParameter($value),
            'alpha' => self::validateAlphaParameter($value),
            'alnum' => self::validateAlnumParameter($value),
            default => $value
        };
    }

    private static function validateIntegerParameter(string $value): string
    {
        if (!preg_match('/^\d+$/', $value)) {
            throw new InvalidArgumentException("Invalid integer parameter: {$value}");
        }
        return $value;
    }

    private static function validateUuidParameter(string $value): string
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
            throw new InvalidArgumentException("Invalid UUID parameter: {$value}");
        }
        return strtolower($value);
    }

    private static function validateSlugParameter(string $value): string
    {
        if (!preg_match('/^[a-z0-9-]+$/', $value)) {
            throw new InvalidArgumentException("Invalid slug parameter: {$value}");
        }
        return $value;
    }

    private static function validateAlphaParameter(string $value): string
    {
        if (!preg_match('/^[a-zA-Z]+$/', $value)) {
            throw new InvalidArgumentException("Invalid alpha parameter: {$value}");
        }
        return $value;
    }

    private static function validateAlnumParameter(string $value): string
    {
        if (!preg_match('/^[a-zA-Z0-9]+$/', $value)) {
            throw new InvalidArgumentException("Invalid alphanumeric parameter: {$value}");
        }
        return $value;
    }

    /**
     * ✅ NEU: Cache-Optimierung für Production
     */
    public static function optimizeCache(): array
    {
        $beforeSize = count(self::$patternCache);
        $beforeMemory = self::getMemoryUsage()['cache_memory_bytes'];

        // Entferne am wenigsten genutzte Patterns (simuliert durch Alter)
        if (count(self::$patternCache) > (self::$maxCacheSize * 0.8)) {
            $keepCount = (int)(self::$maxCacheSize * 0.6);
            self::$patternCache = array_slice(self::$patternCache, -$keepCount, preserve_keys: true);
        }

        $afterSize = count(self::$patternCache);
        $afterMemory = self::getMemoryUsage()['cache_memory_bytes'];

        return [
            'patterns_removed' => $beforeSize - $afterSize,
            'memory_freed' => self::formatBytes($beforeMemory - $afterMemory),
            'cache_size_after' => $afterSize,
            'optimization_effective' => ($beforeSize - $afterSize) > 0
        ];
    }

    /**
     * ✅ NEU: Memory-Usage für Cache
     */
    private static function getMemoryUsage(): array
    {
        $cacheMemory = 0;
        foreach (self::$patternCache as $pattern) {
            $cacheMemory += strlen(serialize($pattern));
        }

        return [
            'cache_memory_bytes' => $cacheMemory,
            'cache_memory_formatted' => self::formatBytes($cacheMemory),
            'average_pattern_size' => count(self::$patternCache) > 0
                ? round($cacheMemory / count(self::$patternCache), 2)
                : 0
        ];
    }

    /**
     * ✅ NEU: Format bytes für Menschen-lesbare Ausgabe
     */
    private static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }

    /**
     * ✅ NEU: Precompile häufig genutzte Pattern
     */
    public static function precompileCommonPatterns(): void
    {
        $commonPatterns = [
            '/',
            '/api/users/{id}',
            '/api/users/{id:int}',
            '/users/{id:int}/posts/{slug:slug}',
            '/blog/{year:int}/{month:int}/{slug:slug}',
            '/api/{version}/users/{uuid:uuid}',
            '/admin/users/{id:int}',
            '/api/v1/users',
            '/auth/login',
            '/auth/logout'
        ];

        foreach ($commonPatterns as $pattern) {
            self::compile($pattern);
        }
    }

    /**
     * ✅ GEÄNDERT: Verbesserte Cache-Performance mit Statistiken
     */
    public static function compile(string $path): array
    {
        self::$compileCalls++;

        // Cache-Key basierend auf Path
        $cacheKey = hash('xxh3', $path);

        if (isset(self::$patternCache[$cacheKey])) {
            self::$cacheHits++;

            // ✅ LRU: Move to end für bessere Cache-Performance
            $result = self::$patternCache[$cacheKey];
            unset(self::$patternCache[$cacheKey]);
            self::$patternCache[$cacheKey] = $result;
            return $result;
        }

        $result = [
            'pattern' => self::compilePattern($path),
            'params' => self::extractParameterNames($path),
            'constraints' => self::extractConstraints($path),
            'is_static' => !str_contains($path, '{')
        ];

        // ✅ LRU: Remove oldest wenn Cache voll
        if (count(self::$patternCache) >= self::$maxCacheSize) {
            array_shift(self::$patternCache);
        }

        self::$patternCache[$cacheKey] = $result;
        return $result;
    }

    /**
     * Sichere Pattern-Kompilierung ohne Escape-Konflikte
     */
    private static function compilePattern(string $path): string
    {
        // Fast path für statische Routes
        if (!str_contains($path, '{')) {
            return '#^' . preg_quote($path, '#') . '$#';
        }

        // Schritt 1: Parameter durch Platzhalter ersetzen
        $placeholders = [];
        $placeholderIndex = 0;

        $processedPath = preg_replace_callback(
            '/\{([^}]+)}/',
            function ($matches) use (&$placeholders, &$placeholderIndex) {
                $param = $matches[1];
                $placeholder = "PLACEHOLDER_{$placeholderIndex}";
                $placeholders[$placeholder] = self::getConstraintPattern($param);
                $placeholderIndex++;
                return $placeholder;
            },
            $path
        );

        // Schritt 2: Pfad escapen (ohne Parameter)
        $escapedPath = preg_quote($processedPath, '#');

        // Schritt 3: Platzhalter durch Regex-Muster ersetzen
        foreach ($placeholders as $placeholder => $pattern) {
            $escapedPath = str_replace($placeholder, $pattern, $escapedPath);
        }

        return '#^' . $escapedPath . '$#';
    }

    /**
     * Hole Constraint-Pattern für Parameter
     */
    private static function getConstraintPattern(string $param): string
    {
        if (str_contains($param, ':')) {
            [, $constraint] = explode(':', $param, 2);
            return self::$constraintCache[$constraint] ?? '([^/]+)';
        }

        return '([^/]+)';
    }

    /**
     * Extrahiere Parameter-Namen aus Route-Path
     */
    private static function extractParameterNames(string $path): array
    {
        if (!str_contains($path, '{')) {
            return [];
        }

        preg_match_all('/\{([^}]+)}/', $path, $matches);

        return array_map(function ($match) {
            return str_contains($match, ':') ? explode(':', $match, 2)[0] : $match;
        }, $matches[1]);
    }

    /**
     * Extrahiere Parameter-Constraints aus Route-Path
     */
    private static function extractConstraints(string $path): array
    {
        $constraints = [];
        preg_match_all('/\{([^}]+)}/', $path, $matches);

        foreach ($matches[1] as $match) {
            if (str_contains($match, ':')) {
                [$name, $constraint] = explode(':', $match, 2);

                if (!isset(self::$constraintCache[$constraint])) {
                    throw new InvalidArgumentException("Unknown constraint: {$constraint}");
                }

                $constraints[$name] = $constraint;
            }
        }

        return $constraints;
    }

    /**
     * Cache leeren (für Tests/Development)
     */
    public static function clearCache(): void
    {
        self::$patternCache = [];
        self::$cacheHits = 0;
        self::$compileCalls = 0;
    }

    /**
     * ✅ NEU: Cache-Konfiguration anpassen
     */
    public static function configureCache(int $maxSize = 1000): void
    {
        if ($maxSize < 100 || $maxSize > 10000) {
            throw new InvalidArgumentException('Cache size must be between 100 and 10000');
        }

        self::$maxCacheSize = $maxSize;

        // Trim cache if needed
        if (count(self::$patternCache) > $maxSize) {
            self::$patternCache = array_slice(self::$patternCache, -$maxSize, preserve_keys: true);
        }
    }

    /**
     * ✅ NEU: Detaillierte Performance-Analyse
     */
    public static function getPerformanceAnalysis(): array
    {
        $stats = self::getCacheStats();

        return [
            'summary' => [
                'total_compilations' => $stats['compile_calls'],
                'cache_efficiency' => $stats['cache_efficiency'],
                'hit_ratio' => $stats['cache_hit_ratio'] . '%',
                'memory_usage' => $stats['memory_usage']['cache_memory_formatted']
            ],
            'performance_indicators' => [
                'compilations_saved' => $stats['cache_hits'],
                'estimated_time_saved_ms' => round($stats['cache_hits'] * 0.1, 2), // ~0.1ms pro Kompilierung
                'memory_efficiency' => count(self::$patternCache) > 0
                    ? round($stats['memory_usage']['cache_memory_bytes'] / count(self::$patternCache), 2) . ' bytes/pattern'
                    : 'No patterns cached'
            ],
            'recommendations' => self::getOptimizationRecommendations($stats)
        ];
    }

    /**
     * ✅ NEU: Cache-Statistiken
     */
    public static function getCacheStats(): array
    {
        return [
            'cached_patterns' => count(self::$patternCache),
            'compile_calls' => self::$compileCalls,
            'cache_hits' => self::$cacheHits,
            'cache_hit_ratio' => self::$compileCalls > 0
                ? round((self::$cacheHits / self::$compileCalls) * 100, 1)
                : 0,
            'cache_efficiency' => self::calculateCacheEfficiency(),
            'available_constraints' => array_keys(self::$constraintCache),
            'memory_usage' => self::getMemoryUsage(),
            'max_cache_size' => self::$maxCacheSize
        ];
    }

    /**
     * ✅ NEU: Berechne Cache-Effizienz
     */
    private static function calculateCacheEfficiency(): string
    {
        if (self::$compileCalls === 0) {
            return 'No calls';
        }

        $hitRatio = (self::$cacheHits / self::$compileCalls) * 100;

        return match (true) {
            $hitRatio >= 95 => 'Excellent',
            $hitRatio >= 85 => 'Very Good',
            $hitRatio >= 70 => 'Good',
            $hitRatio >= 50 => 'Fair',
            default => 'Poor'
        };
    }

    /**
     * ✅ NEU: Optimierungs-Empfehlungen
     */
    private static function getOptimizationRecommendations(array $stats): array
    {
        $recommendations = [];

        if ($stats['cache_hit_ratio'] < 70) {
            $recommendations[] = 'Consider precompiling common patterns to improve cache hit ratio';
        }

        if (count(self::$patternCache) > (self::$maxCacheSize * 0.9)) {
            $recommendations[] = 'Cache is nearly full, consider increasing max cache size';
        }

        if ($stats['memory_usage']['cache_memory_bytes'] > 1048576) { // 1MB
            $recommendations[] = 'Cache memory usage is high, consider optimizing or clearing cache';
        }

        if (empty($recommendations)) {
            $recommendations[] = 'Pattern compiler performance is optimal';
        }

        return $recommendations;
    }
}