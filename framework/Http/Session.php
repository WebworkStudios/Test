<?php


declare(strict_types=1);

namespace Framework\Http;

/**
 * Secure Session Management with PHP 8.4 enhancements
 */
final class Session
{
    private bool $started = false;
    private ?string $sessionId = null;

    // Security constants
    private const int MAX_LIFETIME = 7200; // 2 hours
    private const int REGENERATE_INTERVAL = 300; // 5 minutes
    private const int MAX_DATA_SIZE = 1048576; // 1MB

    public function __construct(
        private readonly array $config = []
    )
    {
        $this->configure();
    }

    /**
     * Start session with security hardening
     */
    public function start(): void
    {
        if ($this->started) {
            return;
        }

        // Prevent session fixation attacks
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        if (!session_start()) {
            throw new \RuntimeException('Failed to start session');
        }

        $this->started = true;
        $this->sessionId = session_id();

        // Security checks and maintenance
        $this->validateSession();
        $this->regenerateIfNeeded();
    }

    /**
     * Get session value with type safety
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureStarted();
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Set session value with size validation
     */
    public function set(string $key, mixed $value): void
    {
        $this->ensureStarted();

        // Prevent oversized session data
        $serialized = serialize($value);
        if (strlen($serialized) > self::MAX_DATA_SIZE) {
            throw new \InvalidArgumentException('Session data too large');
        }

        $_SESSION[$key] = $value;
    }

    /**
     * Check if session has key
     */
    public function has(string $key): bool
    {
        $this->ensureStarted();
        return isset($_SESSION[$key]);
    }

    /**
     * Remove session key
     */
    public function remove(string $key): void
    {
        $this->ensureStarted();
        unset($_SESSION[$key]);
    }

    /**
     * Get all session data
     */
    public function all(): array
    {
        $this->ensureStarted();
        return $_SESSION;
    }

    /**
     * Clear all session data
     */
    public function clear(): void
    {
        $this->ensureStarted();
        $_SESSION = [];
    }

    /**
     * Regenerate session ID (prevents session fixation)
     */
    public function regenerate(bool $deleteOld = true): void
    {
        $this->ensureStarted();

        if (!session_regenerate_id($deleteOld)) {
            throw new \RuntimeException('Failed to regenerate session ID');
        }

        $this->sessionId = session_id();
        $_SESSION['_regenerated_at'] = time();
    }

    /**
     * Destroy session completely
     */
    public function destroy(): void
    {
        if (!$this->started) {
            return;
        }

        $_SESSION = [];

        // Delete session cookie
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
        $this->started = false;
        $this->sessionId = null;
    }

    /**
     * Get session ID
     */
    public function id(): ?string
    {
        return $this->sessionId;
    }

    /**
     * Check if session is started
     */
    public function isStarted(): bool
    {
        return $this->started;
    }

    /**
     * Flash message storage
     */
    public function flash(string $key, mixed $value = null): mixed
    {
        $this->ensureStarted();

        if ($value === null) {
            // Get and remove flash message
            $flashValue = $_SESSION['_flash'][$key] ?? null;
            unset($_SESSION['_flash'][$key]);
            return $flashValue;
        }

        // Set flash message
        $_SESSION['_flash'][$key] = $value;
        return null;
    }

    /**
     * Check if flash message exists
     */
    public function hasFlash(string $key): bool
    {
        $this->ensureStarted();
        return isset($_SESSION['_flash'][$key]);
    }

    /**
     * Get all flash messages and clear them
     */
    public function getFlashBag(): array
    {
        $this->ensureStarted();

        $flash = $_SESSION['_flash'] ?? [];
        $_SESSION['_flash'] = [];

        return $flash;
    }

    /**
     * Store user authentication
     */
    public function login(string|int $userId, array $userData = []): void
    {
        $this->ensureStarted();

        // Regenerate session on login to prevent fixation
        $this->regenerate();

        $_SESSION['_user_id'] = $userId;
        $_SESSION['_user_data'] = $userData;
        $_SESSION['_login_time'] = time();
        $_SESSION['_last_activity'] = time();
    }

    /**
     * Remove user authentication
     */
    public function logout(): void
    {
        $this->ensureStarted();

        unset(
            $_SESSION['_user_id'],
            $_SESSION['_user_data'],
            $_SESSION['_login_time'],
            $_SESSION['_last_activity']
        );

        // Regenerate session after logout
        $this->regenerate();
    }

    /**
     * Check if user is logged in
     */
    public function isLoggedIn(): bool
    {
        $this->ensureStarted();
        return isset($_SESSION['_user_id']);
    }

    /**
     * Get logged in user ID
     */
    public function getUserId(): string|int|null
    {
        $this->ensureStarted();
        return $_SESSION['_user_id'] ?? null;
    }

    /**
     * Get user data
     */
    public function getUserData(): array
    {
        $this->ensureStarted();
        return $_SESSION['_user_data'] ?? [];
    }

    /**
     * Update last activity timestamp
     */
    public function touch(): void
    {
        $this->ensureStarted();
        $_SESSION['_last_activity'] = time();
    }

    /**
     * Configure session security settings
     */
    private function configure(): void
    {
        // Secure session configuration
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_cookies', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', (string)($this->config['secure'] ?? $this->isHttps()));
        ini_set('session.cookie_samesite', $this->config['samesite'] ?? 'Lax');
        ini_set('session.gc_maxlifetime', (string)($this->config['lifetime'] ?? self::MAX_LIFETIME));
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');

        // Set session name if provided
        if (isset($this->config['name'])) {
            session_name($this->config['name']);
        }

        // Set session save path if provided
        if (isset($this->config['save_path'])) {
            session_save_path($this->config['save_path']);
        }
    }

    /**
     * Ensure session is started
     */
    private function ensureStarted(): void
    {
        if (!$this->started) {
            $this->start();
        }
    }

    /**
     * Validate session security
     */
    private function validateSession(): void
    {
        $now = time();

        // Check session timeout
        $lastActivity = $_SESSION['_last_activity'] ?? $now;
        if ($now - $lastActivity > self::MAX_LIFETIME) {
            $this->destroy();
            throw new \RuntimeException('Session expired');
        }

        // Update last activity
        $_SESSION['_last_activity'] = $now;

        // Validate user agent (basic fingerprinting)
        $currentUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $sessionUserAgent = $_SESSION['_user_agent'] ?? '';

        if ($sessionUserAgent === '') {
            $_SESSION['_user_agent'] = $currentUserAgent;
        } elseif ($sessionUserAgent !== $currentUserAgent) {
            $this->destroy();
            throw new \RuntimeException('Session hijacking detected');
        }

        // Validate IP address (optional, can be disabled for dynamic IPs)
        if ($this->config['validate_ip'] ?? false) {
            $currentIp = $_SERVER['REMOTE_ADDR'] ?? '';
            $sessionIp = $_SESSION['_ip_address'] ?? '';

            if ($sessionIp === '') {
                $_SESSION['_ip_address'] = $currentIp;
            } elseif ($sessionIp !== $currentIp) {
                $this->destroy();
                throw new \RuntimeException('IP address mismatch');
            }
        }
    }

    /**
     * Regenerate session ID periodically
     */
    private function regenerateIfNeeded(): void
    {
        $lastRegeneration = $_SESSION['_regenerated_at'] ?? 0;

        if (time() - $lastRegeneration > self::REGENERATE_INTERVAL) {
            $this->regenerate();
        }
    }

    /**
     * Check if connection is HTTPS
     */
    private function isHttps(): bool
    {
        return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ||
            !empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' ||
            !empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on';
    }
}