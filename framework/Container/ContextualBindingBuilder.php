<?php

use Framework\Container\Container;
use Framework\Container\ContextualBindingNeedsBuilder;

/**
 * Contextual Binding Builder
 *
 * Ermöglicht das Binden von Services abhängig vom Kontext.
 * Inspiriert von Laravel's contextual binding, aber optimiert für unser Framework.
 */
final readonly class ContextualBindingBuilder
{
    public function __construct(
        private Container $container,
        private string    $context
    ) {}

    /**
     * Specify the abstract the contextual binding is for
     */
    public function needs(string $abstract): ContextualBindingNeedsBuilder
    {
        return new ContextualBindingNeedsBuilder($this->container, $this->context, $abstract);
    }
}