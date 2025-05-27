<?php

declare(strict_types=1);

namespace Framework\Routing\Attributes;

use Attribute;

/**
 * Route attribute for marking actions with HTTP routing information
 * 
 * @example
 * #[Route(method: 'GET', path: '/user/{id}')]
 * #[Route('POST', '/api/users')]
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Route
{
    /**
     * @param string $method HTTP method (GET, POST, PUT, DELETE, etc.)
     * @param string $path URL path with optional parameters like /user/{id}
     * @param array<string> $middleware Optional middleware for this route
     * @param string|null $name Optional route name for URL generation
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $middleware = [],
        public ?string $name = null
    ) {}
}