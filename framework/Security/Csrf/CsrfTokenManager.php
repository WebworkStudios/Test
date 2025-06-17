<?php


declare(strict_types=1);

namespace Framework\Security\Csrf;

use framework\Http\Session\SessionInterface;
use RuntimeException;

/**
 * Separate Token-Verwaltung für bessere Kapselung
 */
final class CsrfTokenManager
{
    private const int TOKEN_LENGTH = 32;
    private const int MAX_TOKENS = 10;
    private const int TOKEN_LIFETIME = 3600;
    private const string SESSION_KEY = '_csrf_tokens';

    public function __construct(
        private readonly SessionInterface $session
    )
    {
    }

    /**
     * Generate new token
     */
    public function generate(string $action): string
    {
        // ✅ RATE LIMITING für Token-Generierung
        $this->enforceRateLimit($action);

        $this->cleanup();

        $token = bin2hex(random_bytes(self::TOKEN_LENGTH));
        $tokens = $this->getAll();

        $tokens[$action] = [
            'token' => $token,
            'created_at' => time(),
            'used' => false,
            'generation_count' => ($tokens[$action]['generation_count'] ?? 0) + 1  // ✅ Counter
        ];

        $this->limitTokens($tokens);
        $this->store($tokens);

        return $token;
    }

// ✅ NEUE METHODE: Rate Limiting
    private function enforceRateLimit(string $action): void
    {
        $tokens = $this->getAll();

        if (isset($tokens[$action])) {
            $lastGeneration = $tokens[$action]['created_at'] ?? 0;
            $generationCount = $tokens[$action]['generation_count'] ?? 0;

            // Max 5 Token-Generierungen pro Minute
            if (time() - $lastGeneration < 60 && $generationCount >= 5) {
                throw new RuntimeException('CSRF token generation rate limit exceeded');
            }
        }
    }

    /**
     * Get all tokens
     */
    public function getAll(): array
    {
        return $this->session->get(self::SESSION_KEY, []);
    }

    /**
     * Get existing token or null
     */
    public function get(string $action): ?string
    {
        $tokens = $this->getAll();
        return $tokens[$action]['token'] ?? null;
    }

    /**
     * Clean expired tokens
     */
    public function cleanup(): void
    {
        $tokens = $this->getAll();
        $cleaned = false;

        foreach ($tokens as $action => $data) {
            if ($this->isExpired($data['created_at'])) {
                unset($tokens[$action]);
                $cleaned = true;
            }
        }

        if ($cleaned) {
            $this->store($tokens);
        }
    }

    /**
     * Check if timestamp is expired
     */
    private function isExpired(int $timestamp): bool
    {
        return (time() - $timestamp) > self::TOKEN_LIFETIME;
    }

    /**
     * Store tokens in session
     */
    private function store(array $tokens): void
    {
        $this->session->set(self::SESSION_KEY, $tokens);
    }

    /**
     * Limit number of stored tokens
     */
    private function limitTokens(array &$tokens): void
    {
        if (count($tokens) > self::MAX_TOKENS) {
            $tokens = array_slice($tokens, -self::MAX_TOKENS, preserve_keys: true);
        }
    }

    /**
     * Check if token exists and is valid
     */
    public function exists(string $action): bool
    {
        $tokens = $this->getAll();

        if (!isset($tokens[$action])) {
            return false;
        }

        return !$this->isExpired($tokens[$action]['created_at']);
    }

    /**
     * Mark token as used
     */
    public function markUsed(string $action): void
    {
        $tokens = $this->getAll();

        if (isset($tokens[$action])) {
            $tokens[$action]['used'] = true;
            $this->store($tokens);
        }
    }

    /**
     * Check if token was already used
     */
    public function isUsed(string $action): bool
    {
        $tokens = $this->getAll();
        return $tokens[$action]['used'] ?? false;
    }

    /**
     * Clear all tokens
     */
    public function clear(): void
    {
        $this->session->remove(self::SESSION_KEY);
    }

    /**
     * Remove specific token
     */
    public function remove(string $action): void
    {
        $tokens = $this->getAll();
        unset($tokens[$action]);
        $this->store($tokens);
    }
}