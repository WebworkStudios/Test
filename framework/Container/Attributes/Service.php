<?php

declare(strict_types=1);

namespace Framework\Container\Attributes;

use Attribute;

/**
 * Mark a class as a service to be auto-registered in the container
 * 
 * This attribute enables automatic service discovery and registration,
 * eliminating the need for manual service provider configurations.
 * 
 * @example
 * #[Service(id: 'my.service', singleton: true, tags: ['cache', 'storage'])]
 * class RedisCache implements CacheInterface {}
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Service
{
    /**
     * @param string|null $id Custom service ID, defaults to class name
     * @param bool $singleton Whether to register as singleton (default: true)
     * @param array<string> $tags Tags for service discovery and grouping
     * @param int $priority Priority for ordered resolution (higher = first)
     */
    public function __construct(
        public ?string $id = null,
        public bool $singleton = true,
        public array $tags = [],
        public int $priority = 0
    ) {}
}