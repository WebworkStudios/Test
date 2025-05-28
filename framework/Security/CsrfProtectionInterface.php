<?php


declare(strict_types=1);

namespace Framework\Security;

interface CsrfProtectionInterface
{
    public function generateToken(?string $action = 'default'): string;

    public function validateToken(string $token, ?string $action = 'default', bool $oneTime = true): bool;

    public function consumeToken(?string $action = 'default'): void;

    public function validateAndConsume(string $token, ?string $action = 'default'): bool;

    public function validateFromRequest(\Framework\Http\Request $request, ?string $action = 'default', bool $consume = true): bool;

    public function token(?string $action = 'default'): string;

    public function field(?string $action = 'default', string $name = '_token'): string;

    public function metaTag(?string $action = 'default'): string;

    public function invalidateToken(?string $action = 'default'): void;

    public function clearTokens(): void;

    public function getToken(?string $action = 'default'): ?string;

    public function getStoredTokens(): array;

    public function cleanExpiredTokens(int $maxAge = 3600): void;
}