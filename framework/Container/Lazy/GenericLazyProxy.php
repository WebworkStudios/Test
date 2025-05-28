<?php

declare(strict_types=1);

namespace Framework\Container\Lazy;

/**
 * Generischer Lazy Proxy für PHP 8.4 Native Lazy Objects Fallback
 *
 * Wird verwendet wenn die Zielklasse nicht bestimmt werden kann
 */
final class GenericLazyProxy
{
    private mixed $instance = null;
    private bool $initialized = false;

    public function __construct(
        private readonly \Closure $initializer
    ) {}

    private function initialize(): void
    {
        if (!$this->initialized) {
            $this->instance = ($this->initializer)();
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
        return $this->instance(...$arguments);
    }

    public function __serialize(): array
    {
        $this->initialize();
        return ['instance' => $this->instance];
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
            'initialized' => $this->initialized,
            'instance_type' => $this->initialized ? get_class($this->instance) : 'not initialized'
        ];
    }
}