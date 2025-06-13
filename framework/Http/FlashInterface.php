<?php

declare(strict_types=1);

namespace Framework\Http;

interface FlashInterface
{
    public function set(string $key, mixed $value): void;
    public function get(string $key, mixed $default = null): mixed;
    public function has(string $key): bool;
    public function all(): array;
}
