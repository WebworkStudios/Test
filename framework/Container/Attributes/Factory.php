<?php

declare(strict_types=1);

namespace Framework\Container\Attributes;

use Attribute;

/**
 * Mark a method as a factory for creating services
 * 
 * Factory methods provide custom instantiation logic for complex services
 * that require special setup or configuration beyond simple constructor injection.
 * 
 * @example
 * #[Factory(creates: DatabaseConnection::class, singleton: true)]
 * public static function createConnection(Container $container): DatabaseConnection
 * {
 *     return new DatabaseConnection($container->get('config.database'));
 * }
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Factory
{
    /**
     * @param string $creates Class/interface this factory creates
     * @param bool $singleton Whether created service should be singleton
     * @param array<string> $tags Tags to assign to the created service
     */
    public function __construct(
        public string $creates,
        public bool $singleton = true,
        public array $tags = []
    ) {}
}