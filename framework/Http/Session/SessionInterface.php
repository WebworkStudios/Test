<?php

declare(strict_types=1);

namespace Framework\Http\Session;

interface SessionInterface
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    public function has(string $key): bool;

    public function clear(): void;

    public function regenerate(bool $deleteOld = true): void;

    public function destroy(): void;

    public function flash(): FlashInterface;

    public function auth(): AuthInterface;
}