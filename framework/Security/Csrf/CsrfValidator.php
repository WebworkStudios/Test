<?php


declare(strict_types=1);

namespace Framework\Security\Csrf;

use Framework\Http\Request;

/**
 * Separate CSRF Validierung für bessere Testbarkeit
 */
final readonly class CsrfValidator
{
    public function __construct(
        private CsrfTokenManager $tokenManager
    )
    {
    }

    /**
     * Validate and consume token in one operation
     */
    public function validateAndConsume(string $token, string $action): bool
    {
        if ($this->validate($token, $action, true)) {
            $this->tokenManager->markUsed($action);
            return true;
        }

        return false;
    }

    /**
     * Validate token with timing-safe comparison
     */
    public function validate(string $token, string $action, bool $oneTime = true): bool
    {
        $storedToken = $this->tokenManager->get($action);

        if ($storedToken === null) {
            return false;
        }

        if (!hash_equals($storedToken, $token)) {
            return false;
        }

        if (strlen($token) !== strlen($storedToken) || strlen($token) < 32) {
            return false;
        }

        if ($oneTime && $this->tokenManager->isUsed($action)) {
            return false;
        }

        return true;
    }

    /**
     * Validate token from HTTP request
     */
    public function validateFromRequest(
        Request $request,
        string  $action = 'default',
        bool    $consume = true
    ): bool
    {
        $token = $this->extractTokenFromRequest($request);

        if ($token === null) {
            return false;
        }

        if ($this->validate($token, $action, $consume)) {
            if ($consume) {
                $this->tokenManager->markUsed($action);
            }
            return true;
        }

        return false;
    }

    /**
     * Extract token from various request sources
     */
    private function extractTokenFromRequest(Request $request): ?string
    {
        return $request->input('_token')
            ?? $request->header('x-csrf-token')
            ?? $request->header('x-xsrf-token');
    }
}