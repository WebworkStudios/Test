<?php

declare(strict_types=1);

namespace Framework\Container;


/**
 * Contextual Binding Needs Builder
 *
 * Zweiter Teil des Fluent Interface für contextual bindings.
 */
final readonly class ContextualBindingNeedsBuilder
{
    public function __construct(
        private Container $container,
        private string    $context,
        private string    $abstract
    ) {}

    /**
     * Specify the implementation for the contextual binding
     */
    public function give(mixed $implementation): void
    {
        $this->container->addContextualBinding($this->context, $this->abstract, $implementation);
    }

    /**
     * Specify a tagged service for the contextual binding
     */
    public function giveTagged(string $tag): void
    {
        $this->give(function(Container $container) use ($tag) {
            $services = $container->tagged($tag);

            if (empty($services)) {
                throw ContainerNotFoundException::tagNotFound($tag);
            }

            return $services[0]; // Return first tagged service
        });
    }

    /**
     * Specify a factory closure for the contextual binding
     */
    public function giveFactory(callable $factory): void
    {
        $this->give($factory);
    }
}