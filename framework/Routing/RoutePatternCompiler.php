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

    /**
     * Kompiliere Route-Pattern mit Caching
     */
    public static function compile(string $path): array
    {
        // Cache-Key basierend auf Path
        $cacheKey = hash('xxh3', $path);

        if (isset(self::$patternCache[$cacheKey])) {
            return self::$patternCache[$cacheKey];
        }

        $result = [
            'pattern' => self::compilePattern($path),
            'params' => self::extractParameterNames($path),
            'constraints' => self::extractConstraints($path),
            'is_static' => !str_contains($path, '{')
        ];

        // Cache begrenzen auf 1000 Einträge
        if (count(self::$patternCache) >= 1000) {
            self::$patternCache = array_slice(self::$patternCache, -500, preserve_keys: true);
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
     * Cache leeren (für Tests/Development)
     */
    public static function clearCache(): void
    {
        self::$patternCache = [];
    }

    /**
     * Cache-Statistiken
     */
    public static function getCacheStats(): array
    {
        return [
            'cached_patterns' => count(self::$patternCache),
            'available_constraints' => array_keys(self::$constraintCache)
        ];
    }
}