<?php

declare(strict_types=1);

namespace Framework\Routing;

/**
 * Internal route information storage with enhanced security
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
     * @param string|null $subdomain Subdomain constraint
     */
    public function __construct(
        public string $method,
        public string $pattern,
        public string $originalPath,
        public array $paramNames,
        public string $actionClass,
        public array $middleware = [],
        public ?string $name = null,
        public ?string $subdomain = null
    ) {
        // Sicherheitsvalidierung bei Erstellung
        $this->validateSecureConstruction();
    }

    /**
     * Create RouteInfo from path pattern with security validation
     */
    public static function fromPath(
        string $method,
        string $path,
        string $actionClass,
        array $middleware = [],
        ?string $name = null,
        ?string $subdomain = null
    ): self {
        // Sichere Parameter-Extraktion
        $paramNames = [];

        // Verbesserte und sichere Regex für Parameter
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)}/',
            function ($matches) use (&$paramNames) {
                $paramName = $matches[1];

                // Parameter-Name Sicherheitsvalidierung
                if (strlen($paramName) > 50) {
                    throw new \InvalidArgumentException("Parameter name too long: {$paramName}");
                }

                if (in_array($paramName, ['__construct', '__destruct', '__call', '__get', '__set'], true)) {
                    throw new \InvalidArgumentException("Reserved parameter name: {$paramName}");
                }

                $paramNames[] = $paramName;
                return '([^/\0]+)'; // Null-Byte ausschließen
            },
            $path
        );

        if ($pattern === null) {
            throw new \InvalidArgumentException("Invalid path pattern: {$path}");
        }

        // KORREKTUR: Sichere Pattern-Erstellung OHNE doppeltes Escaping
        $escapedPattern = '#^' . str_replace('/', '\/', $pattern) . '$#D';

        return new self(
            $method,
            $escapedPattern,
            $path,
            $paramNames,
            $actionClass,
            $middleware,
            $name,
            $subdomain
        );
    }

    /**
     * Check if route matches request with secure subdomain validation
     */
    public function matches(string $method, string $path, ?string $requestSubdomain = null): bool
    {
        // Early exit für falsche HTTP-Methode
        if ($this->method !== $method) {
            return false;
        }

        // Sichere Input-Validierung (bereits vorhanden)
        if (strlen($path) > 2048 || str_contains($path, "\0") || str_contains($path, '..')) {
            return false;
        }

        // Subdomain-Check VOR Pattern-Match für bessere Performance
        if (!$this->matchesSubdomain($requestSubdomain)) {
            return false;
        }

        // Pattern-Match als letzter (teuerster) Check
        return preg_match($this->pattern, $path) === 1;
    }

    /**
     * Extract parameters from matched path with comprehensive security validation
     */
    public function extractParams(string $path): array
    {
        // Input-Validierung
        if (strlen($path) > 2048) {
            throw new \InvalidArgumentException('Path too long');
        }

        if (str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Path contains null byte');
        }

        if (!preg_match($this->pattern, $path, $matches)) {
            return [];
        }

        array_shift($matches); // Entferne Full-Match

        if (count($matches) !== count($this->paramNames)) {
            throw new \InvalidArgumentException('Parameter count mismatch');
        }

        $params = [];

        for ($i = 0; $i < count($this->paramNames); $i++) {
            $name = $this->paramNames[$i];
            $value = $matches[$i] ?? '';

            // Umfassende Sicherheitsvalidierung für jeden Parameter
            $sanitizedValue = $this->sanitizeParameter($name, $value);
            $params[$name] = $sanitizedValue;
        }

        return $params;
    }

    /**
     * Secure subdomain matching
     */
    private function matchesSubdomain(?string $requestSubdomain): bool
    {
        // Wenn Route eine Subdomain erwartet
        if ($this->subdomain !== null) {
            // Sichere Subdomain-Validierung
            if ($requestSubdomain === null) {
                return false;
            }

            // Sicherheitsvalidierung der Request-Subdomain
            if (!$this->isValidSubdomain($requestSubdomain)) {
                return false;
            }

            return $this->subdomain === $requestSubdomain;
        }

        // Route erwartet keine Subdomain - nur matchen wenn keine vorhanden
        // ODER wenn es eine erlaubte Standard-Subdomain ist (www)
        return $requestSubdomain === null || $requestSubdomain === 'www';
    }

    /**
     * Validate subdomain for security
     */
    private function isValidSubdomain(string $subdomain): bool
    {
        // Längen-Check
        if (strlen($subdomain) > 63) {
            return false;
        }

        // RFC 1123 hostname validation
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?$/', $subdomain)) {
            return false;
        }

        // Gefährliche Zeichen
        if (preg_match('/[<>"\'\0]/', $subdomain)) {
            return false;
        }

        return true;
    }

    /**
     * Sanitize and validate route parameter
     */
    private function sanitizeParameter(string $name, string $value): string
    {
        // Längen-Begrenzung pro Parameter
        if (strlen($value) > 255) {
            throw new \InvalidArgumentException("Parameter '{$name}' too long (max 255 characters)");
        }

        // Directory Traversal Prevention
        if (str_contains($value, '..') || str_contains($value, '\0') || str_contains($value, '\\')) {
            throw new \InvalidArgumentException("Parameter '{$name}' contains invalid characters");
        }

        // Control Characters blocken
        if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new \InvalidArgumentException("Parameter '{$name}' contains control characters");
        }

        // URL-decode sicher durchführen
        $decoded = urldecode($value);

        // Nochmalige Validierung nach URL-Decode
        if (str_contains($decoded, '..') || str_contains($decoded, '\0')) {
            throw new \InvalidArgumentException("Parameter '{$name}' contains invalid sequences after decoding");
        }

        // Spezielle Parameter-Validierung basierend auf Namen
        $this->validateParameterByName($name, $decoded);

        return $decoded;
    }

    /**
     * Parameter-spezifische Validierung
     */
    private function validateParameterByName(string $name, string $value): void
    {
        match (strtolower($name)) {
            'id', 'userid', 'postid' => $this->validateId($value, $name),
            'slug', 'username' => $this->validateSlug($value, $name),
            'email' => $this->validateEmail($value, $name),
            'token' => $this->validateToken($value, $name),
            default => null // Keine spezielle Validierung
        };
    }

    /**
     * Validiere ID-Parameter
     */
    private function validateId(string $value, string $name): void
    {
        if (!preg_match('/^\d+$/', $value) || (int)$value <= 0) {
            throw new \InvalidArgumentException("Parameter '{$name}' must be a positive integer");
        }

        if ((int)$value > PHP_INT_MAX) {
            throw new \InvalidArgumentException("Parameter '{$name}' too large");
        }
    }

    /**
     * Validiere Slug-Parameter
     */
    private function validateSlug(string $value, string $name): void
    {
        if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $value)) {
            throw new \InvalidArgumentException("Parameter '{$name}' contains invalid characters for slug");
        }
    }

    /**
     * Validiere Email-Parameter
     */
    private function validateEmail(string $value, string $name): void
    {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Parameter '{$name}' is not a valid email");
        }
    }

    /**
     * Validiere Token-Parameter
     */
    private function validateToken(string $value, string $name): void
    {
        if (!preg_match('/^[a-zA-Z0-9]+$/', $value) || strlen($value) < 8) {
            throw new \InvalidArgumentException("Parameter '{$name}' is not a valid token format");
        }
    }

    /**
     * Validate secure construction
     */
    private function validateSecureConstruction(): void
    {
        // Validiere Pattern ist sicher
        if (str_contains($this->pattern, '(?')) {
            throw new \InvalidArgumentException('Complex regex patterns not allowed for security');
        }

        // Middleware Array Validierung
        foreach ($this->middleware as $middleware) {
            if (!is_string($middleware) || strlen($middleware) > 100) {
                throw new \InvalidArgumentException('Invalid middleware specification');
            }
        }

        // Action Class Sicherheitsvalidierung
        if (strlen($this->actionClass) > 255) {
            throw new \InvalidArgumentException('Action class name too long');
        }
    }
}