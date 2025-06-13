<?php

declare(strict_types=1);

namespace framework\Http\Session;

interface FlashInterface
{
    public function set(string $key, mixed $value): void;
    public function get(string $key, mixed $default = null): mixed;
    public function has(string $key): bool;
    public function all(): array;
}
