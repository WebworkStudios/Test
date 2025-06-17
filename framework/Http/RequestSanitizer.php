<?php


declare(strict_types=1);

namespace Framework\Http;

use InvalidArgumentException;

/**
 * Zentrale Request-Sanitization für das gesamte Framework
 * Eliminiert Redundanz zwischen Router, RouterCore und anderen Komponenten
 */
final class RequestSanitizer
{
    private const array DANGEROUS_PATH_PATTERNS = [
        '../', '..\\', '..../', '...//', '....//',
        '%2e%2e%2f', '%2e%2e%5c', '%2e%2e/',
        '%2E%2E%2F', '%2E%2E%5C', '%2E%2E/',
        "\0", '/./', '/.//', '/../',
        '%00', '%2F%2E%2E', '%5C%2E%2E',
        'php://', 'file://', 'data://', 'zip://',
        'ftp://', 'http://', 'https://',
        '\\.\\', '//\\', '\\/\\', '\\\\',
        '%5c%5c', '%2f%5c', '%5c%2f',
        // Zusätzliche gefährliche Muster
        '..%2f', '..%5c', '%2e%2e',
        '%c0%af', '%c1%9c'
    ];

    private const int MAX_PATH_LENGTH = 2048;
    private const int MAX_SUBDOMAIN_LENGTH = 63;
    private const int MAX_PARAMETER_LENGTH = 255;

    /**
     * Extrahiere und validiere Subdomain aus Host
     */
    public static function extractSubdomain(string $host, array $allowedSubdomains = []): ?string
    {
        if (empty($host)) {
            return null;
        }

        // Entferne Port
        $hostWithoutPort = explode(':', $host)[0];

        // Basis-Validierung
        if (!self::isValidHostname($hostWithoutPort)) {
            return null;
        }

        // Localhost und IP-Adressen haben keine Subdomains
        if (self::isLocalhost($hostWithoutPort) || filter_var($hostWithoutPort, FILTER_VALIDATE_IP)) {
            return null;
        }

        // Development domains (.local, .localhost)
        if (str_ends_with($hostWithoutPort, '.local') || str_ends_with($hostWithoutPort, '.localhost')) {
            return null;
        }

        // Domain-Parts extrahieren
        $parts = explode('.', $hostWithoutPort);
        if (count($parts) < 3) {
            return null; // Braucht mindestens subdomain.domain.tld
        }

        $subdomain = $parts[0];

        // Subdomain validieren
        if (!self::isValidSubdomain($subdomain)) {
            return null;
        }

        // Prüfe erlaubte Subdomains wenn angegeben
        if (!empty($allowedSubdomains) && !in_array($subdomain, $allowedSubdomains, true)) {
            return null;
        }

        return $subdomain;
    }

    /**
     * Validiere Hostname-Format
     */
    private static function isValidHostname(string $hostname): bool
    {
        if (strlen($hostname) > 253) {
            return false;
        }

        // RFC 1123 Hostname validation
        return preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-\.]{0,251}[a-zA-Z0-9])?$/', $hostname) === 1;
    }

    /**
     * Prüfe ob es localhost ist
     */
    private static function isLocalhost(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    /**
     * Validiere Subdomain nach RFC Standards
     */
    private static function isValidSubdomain(string $subdomain): bool
    {
        if (strlen($subdomain) > self::MAX_SUBDOMAIN_LENGTH || empty($subdomain)) {
            return false;
        }

        // RFC 1123 subdomain validation
        if (preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?$/', $subdomain) !== 1) {
            return false;
        }

        // Reservierte Subdomains
        $reserved = ['www', 'mail', 'ftp', 'localhost', 'test'];
        if (in_array(strtolower($subdomain), $reserved, true)) {
            return false;
        }

        return true;
    }

    /**
     * Batch-Sanitization für Arrays
     */
    public static function sanitizeParameters(array $parameters, array $types = []): array
    {
        $sanitized = [];

        foreach ($parameters as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue; // Überspringe ungültige Parameter
            }

            try {
                $type = $types[$key] ?? 'default';
                $sanitized[$key] = self::sanitizeParameter($value, $type);
            } catch (InvalidArgumentException $e) {
                // Log den Fehler aber werfe nicht - ermöglicht graceful degradation
                error_log("Parameter sanitization failed for key '{$key}': " . $e->getMessage());
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize einzelne Parameter
     */
    public static function sanitizeParameter(string $value, string $type = 'default'): string
    {
        // Längencheck
        if (strlen($value) > self::MAX_PARAMETER_LENGTH) {
            throw new InvalidArgumentException('Parameter value too long (max ' . self::MAX_PARAMETER_LENGTH . ' characters)');
        }

        // Null-Byte-Check
        if (str_contains($value, "\0")) {
            throw new InvalidArgumentException('Parameter contains null bytes');
        }

        // Control-Character-Check
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException('Parameter contains control characters');
        }

        return match ($type) {
            'integer' => self::sanitizeIntegerParameter($value),
            'uuid' => self::sanitizeUuidParameter($value),
            'slug' => self::sanitizeSlugParameter($value),
            'alpha' => self::sanitizeAlphaParameter($value),
            'alnum' => self::sanitizeAlnumParameter($value),
            'email' => self::sanitizeEmailParameter($value),
            'url' => self::sanitizeUrlParameter($value),
            default => self::sanitizeDefaultParameter($value)
        };
    }

    /**
     * Parameter-spezifische Sanitization
     */
    private static function sanitizeIntegerParameter(string $value): string
    {
        if (!preg_match('/^\d+$/', $value) || strlen($value) > 19) {
            throw new InvalidArgumentException("Invalid integer parameter: {$value}");
        }
        return $value;
    }

    private static function sanitizeUuidParameter(string $value): string
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
            throw new InvalidArgumentException("Invalid UUID parameter: {$value}");
        }
        return strtolower($value);
    }

    private static function sanitizeSlugParameter(string $value): string
    {
        if (!preg_match('/^[a-z0-9-]+$/', $value)) {
            throw new InvalidArgumentException("Invalid slug parameter: {$value}");
        }

        if (strlen($value) > 100) {
            throw new InvalidArgumentException('Slug parameter too long (max 100 characters)');
        }

        if (str_starts_with($value, '-') || str_ends_with($value, '-')) {
            throw new InvalidArgumentException('Slug cannot start or end with hyphen');
        }

        if (str_contains($value, '--')) {
            throw new InvalidArgumentException('Slug cannot contain consecutive hyphens');
        }

        return $value;
    }

    private static function sanitizeAlphaParameter(string $value): string
    {
        if (!preg_match('/^[a-zA-Z]+$/', $value)) {
            throw new InvalidArgumentException("Invalid alpha parameter: {$value}");
        }

        if (strlen($value) > 50) {
            throw new InvalidArgumentException('Alpha parameter too long (max 50 characters)');
        }

        return $value;
    }

    private static function sanitizeAlnumParameter(string $value): string
    {
        if (!preg_match('/^[a-zA-Z0-9]+$/', $value)) {
            throw new InvalidArgumentException("Invalid alphanumeric parameter: {$value}");
        }

        if (strlen($value) > 50) {
            throw new InvalidArgumentException('Alphanumeric parameter too long (max 50 characters)');
        }

        return $value;
    }

    private static function sanitizeEmailParameter(string $value): string
    {
        $filtered = filter_var($value, FILTER_VALIDATE_EMAIL);
        if ($filtered === false) {
            throw new InvalidArgumentException("Invalid email parameter: {$value}");
        }

        if (strlen($filtered) > 254) {
            throw new InvalidArgumentException('Email parameter too long (max 254 characters)');
        }

        return $filtered;
    }

    private static function sanitizeUrlParameter(string $value): string
    {
        $filtered = filter_var($value, FILTER_VALIDATE_URL);
        if ($filtered === false) {
            throw new InvalidArgumentException("Invalid URL parameter: {$value}");
        }

        // Prüfe erlaubte Schemas
        $allowedSchemes = ['http', 'https'];
        $scheme = parse_url($filtered, PHP_URL_SCHEME);
        if (!in_array($scheme, $allowedSchemes, true)) {
            throw new InvalidArgumentException("URL scheme not allowed: {$scheme}");
        }

        return $filtered;
    }

    private static function sanitizeDefaultParameter(string $value): string
    {
        // Entferne gefährliche HTML-Zeichen
        $sanitized = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Entferne Script-Tags und ähnliches
        $sanitized = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '', $sanitized);

        return trim($sanitized);
    }

    /**
     * Umfassende Request-Validierung
     */
    public static function validateRequest(Request $request): void
    {
        // Path validieren
        self::sanitizePath($request->path);

        // URI-Länge prüfen
        if (strlen($request->uri) > 8192) {
            throw new InvalidArgumentException('Request URI too long');
        }

        // Query-Parameter begrenzen
        if (count($request->query) > 100) {
            throw new InvalidArgumentException('Too many query parameters');
        }

        // Body-Größe prüfen
        if (strlen($request->raw()) > 10485760) { // 10MB
            throw new InvalidArgumentException('Request body too large');
        }

        // Headers validieren
        foreach ($request->headers()->all() as $name => $value) {
            self::sanitizeHeaderName($name);
            self::sanitizeHeaderValue($value);
        }
    }

    /**
     * Sichere Path-Sanitization mit erweiterten Sicherheitschecks
     */
    public static function sanitizePath(string $path): string
    {
        if (empty($path)) {
            return '/';
        }

        // Mehrfache Bereinigung bis keine Änderungen mehr
        $iterations = 0;
        do {
            $before = $path;
            $path = str_ireplace(self::DANGEROUS_PATH_PATTERNS, '', $path);
            $path = urldecode($path);

            // Verhindere unendliche Schleifen
            if (++$iterations > 10) {
                throw new InvalidArgumentException('Path contains too many dangerous patterns');
            }
        } while ($before !== $path);

        // Erweiterte Sicherheitsvalidierung
        self::validatePathSecurity($path);

        // Normalisierung
        $cleaned = str_replace('\\', '/', $path);
        if (!str_starts_with($cleaned, '/')) {
            $cleaned = '/' . $cleaned;
        }
        $cleaned = preg_replace('#/+#', '/', $cleaned);

        // Längenvalidierung
        if (strlen($cleaned) > self::MAX_PATH_LENGTH) {
            throw new InvalidArgumentException('Path too long (max ' . self::MAX_PATH_LENGTH . ' characters)');
        }

        return $cleaned;
    }

    /**
     * Erweiterte Path-Sicherheitsvalidierung
     */
    private static function validatePathSecurity(string $path): void
    {
        // Non-printable characters
        if (preg_match('/[^\x20-\x7E]/', $path)) {
            throw new InvalidArgumentException('Path contains non-printable characters');
        }

        // Absolute file paths (Windows)
        if (preg_match('/^[A-Z]:[\\\\\/]/', $path)) {
            throw new InvalidArgumentException('Absolute file paths not allowed in URL');
        }

        // Unix absolute paths mit gefährlichen Bereichen
        if (preg_match('#^/(etc|proc|sys|dev|root|bin|sbin)/#i', $path)) {
            throw new InvalidArgumentException('Access to system directories not allowed');
        }

        // Script injection attempts
        if (preg_match('/<script|javascript:|vbscript:|data:/i', $path)) {
            throw new InvalidArgumentException('Script injection detected in path');
        }

        // Null byte injection
        if (str_contains($path, "\x00")) {
            throw new InvalidArgumentException('Null byte injection detected');
        }

        // Unicode homograph attacks (basic check)
        if (preg_match('/[\x{2000}-\x{206F}\x{FFF0}-\x{FFFF}]/u', $path)) {
            throw new InvalidArgumentException('Suspicious Unicode characters detected');
        }
    }

    /**
     * Validiere Header-Namen
     */
    public static function sanitizeHeaderName(string $name): string
    {
        // Entferne CRLF und andere gefährliche Zeichen
        $sanitized = preg_replace('/[\r\n\t\0]/', '', $name);

        if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $sanitized)) {
            throw new InvalidArgumentException("Invalid header name: {$name}");
        }

        if (strlen($sanitized) > 100) {
            throw new InvalidArgumentException('Header name too long');
        }

        return $sanitized;
    }

    /**
     * Validiere Request-Header
     */
    public static function sanitizeHeaderValue(string $value): string
    {
        // Entferne CRLF Injection Versuche
        $sanitized = preg_replace('/[\r\n\t\0]/', '', $value);

        // Längencheck
        if (strlen($sanitized) > 8192) {
            throw new InvalidArgumentException('Header value too long');
        }

        return $sanitized;
    }

    /**
     * Debug-Informationen für Entwicklung
     */
    public static function getSecurityReport(string $input, string $type = 'path'): array
    {
        $report = [
            'original' => $input,
            'type' => $type,
            'length' => strlen($input),
            'issues' => [],
            'sanitized' => null
        ];

        try {
            $report['sanitized'] = match ($type) {
                'path' => self::sanitizePath($input),
                'parameter' => self::sanitizeParameter($input),
                'header_name' => self::sanitizeHeaderName($input),
                'header_value' => self::sanitizeHeaderValue($input),
                default => $input
            };
        } catch (InvalidArgumentException $e) {
            $report['issues'][] = $e->getMessage();
        }

        // Zusätzliche Checks
        if (str_contains($input, '..')) {
            $report['issues'][] = 'Contains path traversal patterns';
        }

        if (preg_match('/[<>"\'\x00-\x1f\x7f-\x9f]/', $input)) {
            $report['issues'][] = 'Contains dangerous characters';
        }

        $report['is_safe'] = empty($report['issues']);

        return $report;
    }
}