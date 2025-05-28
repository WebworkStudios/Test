<?php

declare(strict_types=1);

namespace Framework\Container\Attributes;

use Attribute;

/**
 * Inject a specific service by ID or tag mit PHP 8.4 Features
 *
 * Provides fine-grained control over dependency injection when type-based
 * auto-wiring is not sufficient or when multiple implementations exist.
 *
 * @example
 * public function __construct(
 *     #[Inject(id: 'logger.file')] LoggerInterface $logger,
 *     #[Inject(tag: 'cache')] CacheInterface $cache,
 *     #[Inject(id: 'mailer', optional: true)] ?MailerInterface $mailer = null
 * ) {}
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Inject
{
    /**
     * @param string|null $id Service ID to inject
     * @param string|null $tag Inject service by tag (first match)
     * @param bool $optional Allow null if service not found
     * @param int $priority Priority when multiple services match tag (higher = first)
     */
    public function __construct(
        public ?string $id = null,
        public ?string $tag = null,
        public bool $optional = false,
        public int $priority = 0
    ) {
        // Validation mit PHP 8.4 match
        match (true) {
            $this->id !== null && $this->tag !== null =>
            throw new \InvalidArgumentException('Cannot specify both id and tag'),
            $this->id === null && $this->tag === null =>
            throw new \InvalidArgumentException('Must specify either id or tag'),
            default => null
        };
    }

    /**
     * Validate service ID format
     */
    public function isValidId(): bool
    {
        return $this->id === null || (
                !empty($this->id) &&
                !str_contains($this->id, '..') &&
                preg_match('/^[a-zA-Z_\\\\][a-zA-Z0-9_\\\\.]*$/', $this->id) === 1
            );
    }

    /**
     * Validate tag format
     */
    public function isValidTag(): bool
    {
        return $this->tag === null || (
                !empty($this->tag) &&
                !str_contains($this->tag, '..') &&
                preg_match('/^[a-zA-Z_][a-zA-Z0-9_\\.]*$/', $this->tag) === 1
            );
    }

    /**
     * Get the injection type
     */
    public function getType(): string
    {
        return match (true) {
            $this->id !== null => 'id',
            $this->tag !== null => 'tag',
            default => 'unknown'
        };
    }
}