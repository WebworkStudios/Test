<?php


declare(strict_types=1);

namespace Framework\Security;

use Framework\Http\Session;

/**
 * CSRF Protection with token management and validation
 */
final readonly class CsrfProtection
{
    private const int TOKEN_LENGTH = 32;
    private const int MAX_TOKENS = 10; // Limit stored tokens
    private const string SESSION_KEY = '_csrf_tokens';

    public function __construct(
        private Session $session
    )
    {
    }

    /**
     * Generate new CSRF token
     */
    public function generateToken(?string $action = 'default'): string
    {
        $token = bin2hex(random_bytes(self::TOKEN_LENGTH));
        $timestamp = time();

        // Store token with metadata
        $tokens = $this->getStoredTokens();
        $tokens[$action] = [
            'token' => $token,
            'created_at' => $timestamp,
            'used' => false
        ];

        // Limit number of stored tokens
        if (count($tokens) > self::MAX_TOKENS) {
            $tokens = array_slice($tokens, -self::MAX_TOKENS, preserve_keys: true);
        }

        $this->session->set(self::SESSION_KEY, $tokens);

        return $token;
    }

    /**
     * Validate CSRF token
     */
    public function validateToken(string $token, ?string $action = 'default', bool $oneTime = true): bool
    {
        $tokens = $this->getStoredTokens();

        if (!isset($tokens[$action])) {
            return false;
        }

        $storedData = $tokens[$action];

        // Check if token matches using timing-safe comparison
        if (!hash_equals($storedData['token'], $token)) {
            return false;
        }

        // Check if already used (for one-time tokens)
        if ($oneTime && $storedData['used']) {
            return false;
        }

        // Mark as used for one-time tokens
        if ($oneTime) {
            $tokens[$action]['used'] = true;
            $this->session->set(self::SESSION_KEY, $tokens);
        }

        return true;
    }

    /**
     * Get current token for action
     */
    public function getToken(?string $action = 'default'): ?string
    {
        $tokens = $this->getStoredTokens();
        return $tokens[$action]['token'] ?? null;
    }

    /**
     * Get or generate token for action
     */
    public function token(?string $action = 'default'): string
    {
        $existingToken = $this->getToken($action);

        if ($existingToken !== null && !$this->isTokenExpired($action)) {
            return $existingToken;
        }

        return $this->generateToken($action);
    }

    /**
     * Generate HTML hidden input field
     */
    public function field(?string $action = 'default', string $name = '_token'): string
    {
        $token = $this->token($action);
        $escapedName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $escapedToken = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');

        return "<input type=\"hidden\" name=\"{$escapedName}\" value=\"{$escapedToken}\">";
    }

    /**
     * Generate meta tag for AJAX requests
     */
    public function metaTag(?string $action = 'default'): string
    {
        $token = $this->token($action);
        $escapedToken = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');

        return "<meta name=\"csrf-token\" content=\"{$escapedToken}\">";
    }

    /**
     * Validate token from request
     */
    public function validateFromRequest(
        \Framework\Http\Request $request,
        ?string                 $action = 'default',
        bool                    $oneTime = true
    ): bool
    {
        // Try different token sources
        $token = $request->input('_token')
            ?? $request->header('x-csrf-token')
            ?? $request->header('x-xsrf-token');

        if ($token === null) {
            return false;
        }

        return $this->validateToken($token, $action, $oneTime);
    }

    /**
     * Invalidate specific token
     */
    public function invalidateToken(?string $action = 'default'): void
    {
        $tokens = $this->getStoredTokens();
        unset($tokens[$action]);
        $this->session->set(self::SESSION_KEY, $tokens);
    }

    /**
     * Clear all tokens
     */
    public function clearTokens(): void
    {
        $this->session->remove(self::SESSION_KEY);
    }

    /**
     * Clean expired tokens
     */
    public function cleanExpiredTokens(int $maxAge = 3600): void
    {
        $tokens = $this->getStoredTokens();
        $now = time();

        foreach ($tokens as $action => $data) {
            if ($now - $data['created_at'] > $maxAge) {
                unset($tokens[$action]);
            }
        }

        $this->session->set(self::SESSION_KEY, $tokens);
    }

    /**
     * Get all stored tokens (for debugging)
     */
    public function getStoredTokens(): array
    {
        return $this->session->get(self::SESSION_KEY, []);
    }

    /**
     * Check if token is expired
     */
    private function isTokenExpired(?string $action = 'default', int $maxAge = 3600): bool
    {
        $tokens = $this->getStoredTokens();

        if (!isset($tokens[$action])) {
            return true;
        }

        return (time() - $tokens[$action]['created_at']) > $maxAge;
    }
}