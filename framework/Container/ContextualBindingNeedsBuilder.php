<?php

declare(strict_types=1);

namespace Framework\Container;

/**
 * Contextual Binding Needs Builder mit PHP 8.4 Features
 *
 * Zweiter Teil des Fluent Interface für contextual bindings.
 */
final readonly class ContextualBindingNeedsBuilder
{
    public function __construct(
        private Container $container,
        private string $context,
        private string $abstract
    ) {}

    /**
     * Specify the implementation for the contextual binding
     */
    public function give(mixed $implementation): void
    {
        // Validate implementation
        $this->validateImplementation($implementation);

        $this->container->addContextualBinding($this->context, $this->abstract, $implementation);
    }

    /**
     * Specify a tagged service for the contextual binding
     */
    public function giveTagged(string $tag): void
    {
        if (empty($tag) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_\.]*$/', $tag)) {
            throw new \InvalidArgumentException("Invalid tag format: {$tag}");
        }

        $this->give(function(Container $container) use ($tag) {
            $services = $container->tagged($tag);

            if (empty($services)) {
                throw ContainerNotFoundException::tagNotFound($tag);
            }

            return $services[0]; // Return first tagged service
        });
    }

    /**
     * Specify multiple tagged services for the contextual binding
     */
    public function giveAllTagged(string $tag): void
    {
        if (empty($tag) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_\.]*$/', $tag)) {
            throw new \InvalidArgumentException("Invalid tag format: {$tag}");
        }

        $this->give(function(Container $container) use ($tag) {
            $services = $container->tagged($tag);

            if (empty($services)) {
                throw ContainerNotFoundException::tagNotFound($tag);
            }

            return $services; // Return all tagged services
        });
    }

    /**
     * Specify a factory closure for the contextual binding
     */
    public function giveFactory(callable $factory): void
    {
        if (!is_callable($factory)) {
            throw new \InvalidArgumentException('Factory must be callable');
        }

        $this->give($factory);
    }

    /**
     * Give a specific instance
     */
    public function giveInstance(object $instance): void
    {
        $this->give($instance);
    }

    /**
     * Give with configuration
     */
    public function giveWithConfig(string $className, array $config = []): void
    {
        if (!class_exists($className)) {
            throw new \InvalidArgumentException("Class does not exist: {$className}");
        }

        $this->give(function(Container $container) use ($className, $config) {
            // Create instance with config
            $reflection = new \ReflectionClass($className);

            if (!$reflection->isInstantiable()) {
                throw ContainerException::cannotResolve($className, 'Class is not instantiable');
            }

            $constructor = $reflection->getConstructor();

            if ($constructor === null) {
                return new $className();
            }

            // Resolve parameters with config override
            $parameters = [];
            foreach ($constructor->getParameters() as $parameter) {
                $paramName = $parameter->getName();

                if (array_key_exists($paramName, $config)) {
                    $parameters[] = $config[$paramName];
                } elseif ($parameter->isDefaultValueAvailable()) {
                    $parameters[] = $parameter->getDefaultValue();
                } else {
                    // Try to resolve from container
                    $type = $parameter->getType();
                    if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                        $parameters[] = $container->resolve($type->getName());
                    } else {
                        throw ContainerException::cannotResolve(
                            $className,
                            "Cannot resolve parameter '{$paramName}'"
                        );
                    }
                }
            }

            return $reflection->newInstanceArgs($parameters);
        });
    }

    /**
     * Conditional contextual binding
     */
    public function giveWhen(callable $condition, mixed $implementation): void
    {
        $this->give(function(Container $container) use ($condition, $implementation) {
            if ($condition($container)) {
                return match (true) {
                    is_callable($implementation) => $implementation($container),
                    is_string($implementation) && $container->isRegistered($implementation) =>
                    $container->resolve($implementation),
                    is_string($implementation) && class_exists($implementation) =>
                    $container->resolve($implementation),
                    is_object($implementation) => $implementation,
                    default => $implementation
                };
            }

            throw ContainerException::cannotResolve(
                $this->abstract,
                'Condition not met for contextual binding'
            );
        });
    }

    /**
     * Validate implementation
     */
    private function validateImplementation(mixed $implementation): void
    {
        match (true) {
            is_null($implementation) =>
            throw new \InvalidArgumentException('Implementation cannot be null'),
            is_string($implementation) && empty($implementation) =>
            throw new \InvalidArgumentException('Implementation string cannot be empty'),
            is_string($implementation) && str_contains($implementation, '..') =>
            throw new \InvalidArgumentException('Implementation cannot contain ".."'),
            default => null
        };
    }

    /**
     * Get binding information
     */
    public function getBindingInfo(): array
    {
        return [
            'context' => $this->context,
            'abstract' => $this->abstract
        ];
    }
}