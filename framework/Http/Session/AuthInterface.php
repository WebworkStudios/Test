<?php

declare(strict_types=1);

namespace framework\Http\Session;

interface AuthInterface
{
    public function login(string|int $userId, array $userData = []): void;

    public function logout(): void;

    public function check(): bool;

    public function id(): string|int|null;

    public function user(): array;

    public function touch(): void;
}