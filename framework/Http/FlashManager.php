<?php

declare(strict_types=1);

namespace Framework\Http;

final class FlashManager implements FlashInterface
{
    private const string FLASH_KEY = '_framework_flash';

    public function __construct() {}

    public function set(string $key, mixed $value): void
    {
        $flash = $_SESSION[self::FLASH_KEY] ?? [];
        $flash[$key] = $value;
        $_SESSION[self::FLASH_KEY] = $flash;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $flash = $_SESSION[self::FLASH_KEY] ?? [];
        $value = $flash[$key] ?? $default;

        // Nach dem Lesen entfernen
        if (isset($flash[$key])) {
            unset($flash[$key]);
            $_SESSION[self::FLASH_KEY] = empty($flash) ? null : $flash;
        }

        return $value;
    }

    public function has(string $key): bool
    {
        return isset($_SESSION[self::FLASH_KEY][$key]);
    }

    public function all(): array
    {
        $flash = $_SESSION[self::FLASH_KEY] ?? [];
        unset($_SESSION[self::FLASH_KEY]);
        return $flash;
    }
}