<?php

declare(strict_types=1);

namespace Framework\Security\Csrf;

use Framework\Http\Request;
use framework\Http\Session\SessionInterface;

/**
 * Refactored CSRF Protection using Facade Pattern
 */
final class CsrfProtection implements CsrfProtectionInterface
{
    public string $defaultToken {
        get => $this->token('default');
    }
    public bool $hasTokens {
        get => !empty($this->tokenManager->getAll());
    }
    private readonly CsrfTokenManager $tokenManager;
    private readonly CsrfValidator $validator;

    // Property Hooks
    private readonly CsrfHtmlGenerator $htmlGenerator;

    public function __construct(SessionInterface $session)
    {
        $this->tokenManager = new CsrfTokenManager($session);
        $this->validator = new CsrfValidator($this->tokenManager);
        $this->htmlGenerator = new CsrfHtmlGenerator($this->tokenManager);
    }

    // === Token Management ===

    public function generateToken(?string $action = 'default'): string
    {
        return $this->tokenManager->generate($action ?? 'default');
    }

    public function token(?string $action = 'default'): string
    {
        $action = $action ?? 'default';

        if ($this->tokenManager->exists($action)) {
            return $this->tokenManager->get($action);
        }

        return $this->tokenManager->generate($action);
    }

    // === Validation ===

    public function validateToken(string $token, ?string $action = 'default', bool $oneTime = true): bool
    {
        return $this->validator->validate($token, $action ?? 'default', $oneTime);
    }

    public function validateAndConsume(string $token, ?string $action = 'default'): bool
    {
        return $this->validator->validateAndConsume($token, $action ?? 'default');
    }

    public function validateFromRequest(
        Request $request,
        ?string $action = 'default',
        bool    $consume = true
    ): bool
    {
        return $this->validator->validateFromRequest($request, $action ?? 'default', $consume);
    }

    // === Token Lifecycle ===

    public function consumeToken(?string $action = 'default'): void
    {
        $this->tokenManager->markUsed($action ?? 'default');
    }

    public function invalidateToken(?string $action = 'default'): void
    {
        $this->tokenManager->remove($action ?? 'default');
    }

    public function clearTokens(): void
    {
        $this->tokenManager->clear();
    }

    // === HTML Generation ===

    public function field(?string $action = 'default', string $name = '_token'): string
    {
        return $this->htmlGenerator->field($action ?? 'default', $name);
    }

    public function metaTag(?string $action = 'default'): string
    {
        return $this->htmlGenerator->metaTag($action ?? 'default');
    }

    // === Debug/Admin ===

    public function getStoredTokens(): array
    {
        return $this->tokenManager->getAll();
    }

    public function cleanExpiredTokens(int $maxAge = 3600): void
    {
        $this->tokenManager->cleanup();
    }

    // === Deprecated/Removed Methods ===

    /**
     * @deprecated Use token() instead
     */
    public function getToken(?string $action = 'default'): ?string
    {
        return $this->tokenManager->get($action ?? 'default');
    }
}