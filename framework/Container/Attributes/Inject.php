<?php

declare(strict_types=1);

namespace Framework\Container\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Inject a specific service by ID or tag mit PHP 8.4 Features
 *
 * Vereinfachte Inject-Attribut-Klasse mit optimierter Validierung
 * und verbesserter Developer Experience.
 *
 * @example
 * public function __construct(
 *     #[Inject(id: 'logger.file')] LoggerInterface $logger,
 *     #[Inject(tag: 'cache')] CacheInterface $cache,
 *     #[Inject(id: 'mailer', optional: true)] ?MailerInterface $mailer = null
 * ) {}
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final  class Inject
{
    // Property Hooks für computed properties
    public string $injectionType {
        get => match (true) {
            $this->id !== null => 'id',
            $this->tag !== null => 'tag',
            default => 'unknown'
        };
    }

    public bool $isValid {
        get => $this->isValidId() && $this->isValidTag();
    }

    public bool $hasConstraints {
        get => $this->id !== null || $this->tag !== null;
    }

    public bool $isHighPriority {
        get => $this->priority > 50;
    }

    /**
     * @param string|null $id Service ID to inject
     * @param string|null $tag Inject service by tag (first match)
     * @param bool $optional Allow null if service not found
     * @param int $priority Priority when multiple services match tag (higher = first)
     */
    public function __construct(
        public ?string $id = null,
        public ?string $tag = null,
        public bool    $optional = false,
        public int     $priority = 0
    )
    {
        $this->validateConstruction();
    }

    /**
     * Validate all constructor parameters
     */
    private function validateConstruction(): void
    {
        $this->validateExclusivity();
        $this->validateId();
        $this->validateTag();
        $this->validatePriority();
    }

    /**
     * Validate that only one injection type is specified
     */
    private function validateExclusivity(): void
    {
        match (true) {
            $this->id !== null && $this->tag !== null =>
            throw new InvalidArgumentException('Cannot specify both id and tag'),
            $this->id === null && $this->tag === null =>
            throw new InvalidArgumentException('Must specify either id or tag'),
            default => null
        };
    }

    /**
     * Validate service ID if provided
     */
    private function validateId(): void
    {
        if ($this->id === null) {
            return;
        }

        match (true) {
            empty($this->id) =>
            throw new InvalidArgumentException('Service ID cannot be empty'),
            strlen($this->id) > 255 =>
            throw new InvalidArgumentException('Service ID too long (max 255 characters)'),
            str_contains($this->id, '..') =>
            throw new InvalidArgumentException('Service ID cannot contain ".."'),
            str_contains($this->id, '/') =>
            throw new InvalidArgumentException('Service ID cannot contain "/"'),
            !preg_match('/^[a-zA-Z_\\\\][a-zA-Z0-9_\\\\.]*$/', $this->id) =>
            throw new InvalidArgumentException('Invalid service ID format'),
            default => null
        };
    }

    /**
     * Validate tag if provided
     */
    private function validateTag(): void
    {
        if ($this->tag === null) {
            return;
        }

        match (true) {
            empty($this->tag) =>
            throw new InvalidArgumentException('Tag cannot be empty'),
            strlen($this->tag) > 100 =>
            throw new InvalidArgumentException('Tag too long (max 100 characters)'),
            str_contains($this->tag, '..') =>
            throw new InvalidArgumentException('Tag cannot contain ".."'),
            str_contains($this->tag, '/') =>
            throw new InvalidArgumentException('Tag cannot contain "/"'),
            !preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $this->tag) =>
            throw new InvalidArgumentException('Invalid tag format'),
            default => null
        };
    }

    /**
     * Validate priority value
     */
    private function validatePriority(): void
    {
        match (true) {
            $this->priority < 0 =>
            throw new InvalidArgumentException('Priority cannot be negative'),
            $this->priority > 1000 =>
            throw new InvalidArgumentException('Priority too high (max 1000)'),
            default => null
        };
    }

    /**
     * Create from array (for cache/serialization)
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? null,
            tag: $data['tag'] ?? null,
            optional: $data['optional'] ?? false,
            priority: $data['priority'] ?? 0
        );
    }

    /**
     * Create inject by ID
     */
    public static function byId(string $id, bool $optional = false, int $priority = 0): self
    {
        return new self(id: $id, optional: $optional, priority: $priority);
    }

    /**
     * Create inject by tag
     */
    public static function byTag(string $tag, bool $optional = false, int $priority = 0): self
    {
        return new self(tag: $tag, optional: $optional, priority: $priority);
    }

    /**
     * Create optional inject by ID
     */
    public static function optionalId(string $id, int $priority = 0): self
    {
        return new self(id: $id, optional: true, priority: $priority);
    }

    /**
     * Create optional inject by tag
     */
    public static function optionalTag(string $tag, int $priority = 0): self
    {
        return new self(tag: $tag, optional: true, priority: $priority);
    }

    /**
     * Validate service ID format (public method)
     */
    public function isValidId(): bool
    {
        if ($this->id === null) {
            return true; // No ID is valid if tag is specified
        }

        return !empty($this->id) &&
            strlen($this->id) <= 255 &&
            !str_contains($this->id, '..') &&
            !str_contains($this->id, '/') &&
            preg_match('/^[a-zA-Z_\\\\][a-zA-Z0-9_\\\\.]*$/', $this->id) === 1;
    }

    /**
     * Validate tag format (public method)
     */
    public function isValidTag(): bool
    {
        if ($this->tag === null) {
            return true; // No tag is valid if ID is specified
        }

        return !empty($this->tag) &&
            strlen($this->tag) <= 100 &&
            !str_contains($this->tag, '..') &&
            !str_contains($this->tag, '/') &&
            preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $this->tag) === 1;
    }

    /**
     * Check if injection should fail silently when not found
     */
    public function shouldFailSilently(): bool
    {
        return $this->optional;
    }

    /**
     * Get injection configuration for container
     */
    public function getInjectionConfig(): array
    {
        return [
            'type' => $this->injectionType,
            'identifier' => $this->getIdentifier(),
            'optional' => $this->optional,
            'priority' => $this->priority
        ];
    }

    /**
     * Get the injection identifier (ID or tag)
     */
    public function getIdentifier(): string
    {
        return $this->id ?? $this->tag ?? '';
    }

    /**
     * Convert to array for serialization
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tag' => $this->tag,
            'optional' => $this->optional,
            'priority' => $this->priority,
            'injection_type' => $this->injectionType,
            'is_valid' => $this->isValid,
            'identifier' => $this->getIdentifier()
        ];
    }

    /**
     * Clone inject as optional
     */
    public function asOptional(): self
    {
        return new self(
            $this->id,
            $this->tag,
            true, // optional = true
            $this->priority
        );
    }

    /**
     * Clone inject with different priority
     */
    public function withPriority(int $priority): self
    {
        return new self(
            $this->id,
            $this->tag,
            $this->optional,
            $priority
        );
    }

    /**
     * Clone inject with different ID
     */
    public function withId(string $id): self
    {
        return new self(
            $id,
            null, // Clear tag when setting ID
            $this->optional,
            $this->priority
        );
    }

    /**
     * Clone inject with different tag
     */
    public function withTag(string $tag): self
    {
        return new self(
            null, // Clear ID when setting tag
            $tag,
            $this->optional,
            $this->priority
        );
    }

    /**
     * Check if inject targets a specific service
     */
    public function targets(string $identifier): bool
    {
        return $this->getIdentifier() === $identifier;
    }

    /**
     * Check if inject is compatible with another inject
     */
    public function isCompatibleWith(self $other): bool
    {
        return $this->injectionType === $other->injectionType &&
            $this->getIdentifier() === $other->getIdentifier();
    }

    /**
     * Get validation errors as formatted string
     */
    public function getValidationSummary(): string
    {
        $errors = $this->validate();

        if (empty($errors)) {
            return "Valid injection: {$this->injectionType}={$this->getIdentifier()}";
        }

        return "Invalid injection: " . implode(', ', $errors);
    }

    /**
     * Check if injection configuration is valid
     */
    public function validate(): array
    {
        $errors = [];

        try {
            $this->validateExclusivity();
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }

        try {
            $this->validateId();
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }

        try {
            $this->validateTag();
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }

        try {
            $this->validatePriority();
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }

        return $errors;
    }

    /**
     * Magic method for debugging
     */
    public function __debugInfo(): array
    {
        return [
            'injection_type' => $this->injectionType,
            'identifier' => $this->getIdentifier(),
            'optional' => $this->optional,
            'priority' => $this->priority,
            'is_valid' => $this->isValid,
            'is_high_priority' => $this->isHighPriority,
            'validation_errors' => $this->validate()
        ];
    }

    /**
     * String representation
     */
    public function __toString(): string
    {
        $parts = ["{$this->injectionType}:{$this->getIdentifier()}"];

        if ($this->optional) {
            $parts[] = "optional";
        }

        if ($this->priority > 0) {
            $parts[] = "priority:{$this->priority}";
        }

        return 'Inject(' . implode(' ', $parts) . ')';
    }
}