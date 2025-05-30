<?php

declare(strict_types=1);

namespace Framework\Routing\Attributes;

use Attribute;

/**
 * Route attribute for marking actions with HTTP routing information
 *
 * @example
 * #[Route(method: 'GET', path: '/user/{id}')]
 * #[Route('POST', '/api/users', subdomain: 'api')]
 * #[Route('GET', '/admin/dashboard', subdomain: 'admin')]
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Route
{
    /**
     * @param string $method HTTP method (GET, POST, PUT, DELETE, etc.)
     * @param string $path URL path with optional parameters like /user/{id}
     * @param array<string> $middleware Optional middleware for this route
     * @param string|null $name Optional route name for URL generation
     * @param string|null $subdomain Optional subdomain constraint (e.g., 'api', 'admin')
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $middleware = [],
        public ?string $name = null,
        public ?string $subdomain = null
    ) {
        // Sicherheitsvalidierung für Subdomain
        if ($this->subdomain !== null) {
            $this->validateSubdomain($this->subdomain);
        }

        // Zusätzliche Validierung für bestehende Parameter
        $this->validateMethod($this->method);
        $this->validatePath($this->path);
        $this->validateName($this->name);
    }

    /**
     * Validate subdomain for security
     */
    private function validateSubdomain(string $subdomain): void
    {
        // Längen-Begrenzung
        if (strlen($subdomain) > 63) {
            throw new \InvalidArgumentException('Subdomain too long (max 63 characters)');
        }

        // Leere Subdomain nicht erlaubt
        if (trim($subdomain) === '') {
            throw new \InvalidArgumentException('Subdomain cannot be empty');
        }

        // RFC 1123 hostname validation
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?$/', $subdomain)) {
            throw new \InvalidArgumentException('Invalid subdomain format');
        }

        // Gefährliche Subdomains blocken
        $dangerous = ['admin', 'root', 'www', 'mail', 'ftp', 'localhost', 'test', 'staging'];
        if (in_array(strtolower($subdomain), $dangerous, true)) {
            throw new \InvalidArgumentException('Subdomain not allowed for security reasons');
        }

        // SQL Injection und XSS Schutz
        if (preg_match('/[<>"\']/', $subdomain)) {
            throw new \InvalidArgumentException('Subdomain contains invalid characters');
        }
    }

    /**
     * Validate HTTP method
     */
    private function validateMethod(string $method): void
    {
        $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'];

        if (!in_array(strtoupper($method), $allowedMethods, true)) {
            throw new \InvalidArgumentException("Invalid HTTP method: {$method}");
        }
    }

    /**
     * Validate path for security
     */
    private function validatePath(string $path): void
    {
        // Path muss mit / beginnen
        if (!str_starts_with($path, '/')) {
            throw new \InvalidArgumentException('Path must start with /');
        }

        // Directory traversal prevention
        if (str_contains($path, '..') || str_contains($path, '\0')) {
            throw new \InvalidArgumentException('Path contains invalid sequences');
        }

        // Längen-Begrenzung
        if (strlen($path) > 2048) {
            throw new \InvalidArgumentException('Path too long (max 2048 characters)');
        }

        // Validiere Parameter-Format
        if (preg_match('/\{([^}]*)}/', $path, $matches)) {
            foreach ($matches as $param) {
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', trim($param, '{}'))) {
                    throw new \InvalidArgumentException("Invalid parameter name: {$param}");
                }
            }
        }
    }

    /**
     * Validate route name
     */
    private function validateName(?string $name): void
    {
        if ($name === null) {
            return;
        }

        // Längen-Begrenzung
        if (strlen($name) > 255) {
            throw new \InvalidArgumentException('Route name too long (max 255 characters)');
        }

        // Format-Validierung
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $name)) {
            throw new \InvalidArgumentException('Route name contains invalid characters');
        }
    }
}