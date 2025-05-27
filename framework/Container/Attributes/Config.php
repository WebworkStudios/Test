<?php

declare(strict_types=1);

namespace Framework\Container\Attributes;

use Attribute;

/**
 * Mark a parameter to be injected with a configuration value
 * 
 * Enables direct injection of configuration values using dot notation
 * for nested array access, eliminating the need for manual config retrieval.
 * 
 * @example
 * public function __construct(
 *     #[Config('database.host', 'localhost')] string $host,
 *     #[Config('database.port', 3306)] int $port,
 *     #[Config('app.debug', false)] bool $debug
 * ) {}
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Config
{
    /**
     * @param string $key Configuration key (dot notation supported for nested arrays)
     * @param mixed $default Default value if configuration key not found
     */
    public function __construct(
        public string $key,
        public mixed $default = null
    ) {}
}