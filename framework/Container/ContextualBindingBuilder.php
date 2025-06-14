<?php

declare(strict_types=1);

namespace Framework\Container;

/**
 * Contextual Binding Builder mit PHP 8.4 Features
 *
 * Ermöglicht das Binden von Services abhängig vom Kontext.
 * Inspiriert von Laravel's contextual binding, aber optimiert für unser Framework.
 */
final readonly class ContextualBindingBuilder
{
    public function __construct(
        private Container $container,
        private string    $context
    )
    {
        // Validate context
        if (empty($this->context) || str_contains($this->context, '..')) {
            throw new \InvalidArgumentException('Invalid context format');
        }
    }

    /**
     * Multiple contextual bindings at once
     */
    public function needsMany(array $bindings): void
    {
        foreach ($bindings as $abstract => $implementation) {
            if (!is_string($abstract)) {
                continue;
            }

            try {
                $this->needs($abstract)->give($implementation);
            } catch (\Throwable $e) {
                error_log("Failed to register contextual binding for '{$abstract}': " . $e->getMessage());
            }
        }
    }

    /**
     * Specify the abstract the contextual binding is for
     */
    public function needs(string $abstract): ContextualBindingNeedsBuilder
    {
        if (empty($abstract) || str_contains($abstract, '..')) {
            throw new \InvalidArgumentException('Invalid abstract format');
        }

        return new ContextualBindingNeedsBuilder($this->container, $this->context, $abstract);
    }

    /**
     * Get context name
     */
    public function getContext(): string
    {
        return $this->context;
    }
}