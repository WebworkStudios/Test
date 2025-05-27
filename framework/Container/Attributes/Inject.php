<?php

declare(strict_types=1);

namespace Framework\Container\Attributes;

use Attribute;

/**
 * Inject a specific service by ID or tag
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
     */
    public function __construct(
        public ?string $id = null,
        public ?string $tag = null,
        public bool $optional = false
    ) {}
}