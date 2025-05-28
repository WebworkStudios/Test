<?php


declare(strict_types=1);

namespace Framework\Http;

interface SessionInterface
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    public function has(string $key): bool;
}