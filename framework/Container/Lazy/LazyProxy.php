<?php

declare(strict_types=1);

namespace Framework\Container\Lazy;

use Framework\Container\Container;

/**
 * Lazy Proxy für verzögerte Service-Instanziierung
 *
 * Implementiert alle Magic Methods für transparente Proxy-Funktionalität
 */
final class LazyProxy
{
    private mixed $instance = null;
    private bool $initialized = false;

    public function __construct(
        private readonly \Closure $factory,
        private readonly Container $container,
        private readonly string $id
    ) {}

    private function initialize(): void
    {
        if (!$this->initialized) {
            $this->instance = ($this->factory)($this->container);
            if (is_object($this->instance) && method_exists($this->container, 'trackInstance')) {
                $this->container->trackInstance($this->id, $this->instance);
            }
            $this->initialized = true;
        }
    }

    public function __call(string $name, array $arguments): null
    {
        $this->initialize();
        return $this->instance?->$name(...$arguments);
    }

    public function __get(string $name): null
    {
        $this->initialize();
        return $this->instance?->$name;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->initialize();
        if ($this->instance !== null) {
            $this->instance->$name = $value;
        }
    }

    public function __isset(string $name): bool
    {
        $this->initialize();
        return isset($this->instance?->$name);
    }

    public function __unset(string $name): void
    {
        $this->initialize();
        if ($this->instance !== null) {
            unset($this->instance->$name);
        }
    }

    public function __toString(): string
    {
        $this->initialize();
        return (string)$this->instance;
    }

    public function __invoke(...$arguments): mixed
    {
        $this->initialize();
        if ($this->instance === null || !is_callable($this->instance)) {
            throw new \BadMethodCallException('Proxied instance is not callable');
        }
        return $this->instance(...$arguments);
    }

    public function __serialize(): array
    {
        $this->initialize();
        return $this->instance instanceof \Serializable
            ? $this->instance->__serialize()
            : ['instance' => $this->instance];
    }

    public function __unserialize(array $data): void
    {
        $this->instance = $data['instance'];
        $this->initialized = true;
    }

    public function __clone(): void
    {
        $this->initialize();
        if ($this->instance !== null) {
            $this->instance = clone $this->instance;
        }
    }

    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'initialized' => $this->initialized,
            'instance_type' => $this->initialized ? get_class($this->instance) : 'not initialized'
        ];
    }
}