<?php

declare(strict_types=1);

namespace Framework\Http\Session;

use InvalidArgumentException;
use RuntimeException;

/**
 * Optimized Session Management with clean separation of concerns
 */
class Session implements SessionInterface
{
    // Security constants
    private const int MAX_LIFETIME = 7200; // 2 hours
    private const int REGENERATE_INTERVAL = 300; // 5 minutes
    private const int MAX_DATA_SIZE = 1048576; // 1MB
    private const string SESSION_PREFIX = '_framework_';

    // Lazy-loaded session data
    public private(set) bool $started = false;

    // Property Hooks for better API
    public private(set) ?string $sessionId = null;
    private ?array $sessionData = null;

    // Lazy-loaded components
    private ?FlashManager $flashManager = null;
    private ?AuthManager $authManager = null;

    private readonly SessionSecurity $security;

    public function __construct(
        private readonly array $config = []
    )
    {
        $this->configure();
        $this->security = new SessionSecurity($this->config);
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

        if (isset($this->config['name'])) {
            session_name($this->config['name']);
        }

        if (isset($this->config['save_path'])) {
            session_save_path($this->config['save_path']);
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
            throw new RuntimeException('Failed to start session');
        }

        $this->started = true;
        $this->sessionId = session_id();

        // Delegiere Security-Validierung
        try {
            $this->security->validateSession();
            $this->regenerateIfNeeded();
        } catch (RuntimeException $e) {
            $this->destroy();
            throw $e;
        }
    }

    /**
     * Regenerate session ID periodically
     */
    private function regenerateIfNeeded(): void
    {
        if ($this->security->needsRegeneration()) {
            $this->regenerate();
        }
    }

    /**
     * Regenerate session ID (prevents session fixation)
     */
    public function regenerate(bool $deleteOld = true): void
    {
        $this->ensureStarted();

        if (!session_regenerate_id($deleteOld)) {
            throw new RuntimeException('Failed to regenerate session ID');
        }

        $this->sessionId = session_id();
        $this->security->markRegenerated();
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
    // === Private Helper Methods ===

    /**
     * Destroy session completely
     */
    public function destroy(): void
    {
        if (!$this->started) {
            return;
        }

        // Clear all caches
        $this->sessionData = [];
        $this->flashManager = null;
        $this->authManager = null;

        // Security cleanup
        $this->security->cleanup();

        // Clear PHP session
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
     * Get session value with lazy loading
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureStarted();
        $data = $this->getSessionDataLazy();
        return $data[$key] ?? $default;
    }

    /**
     * Get session data with lazy loading
     */
    private function getSessionDataLazy(): array
    {
        return $this->sessionData ??= $this->loadSessionData();
    }

    /**
     * Load session data from $_SESSION
     */
    private function loadSessionData(): array
    {
        $data = [];
        foreach ($_SESSION as $key => $value) {
            if (!str_starts_with($key, self::SESSION_PREFIX)) {
                $data[$key] = $value;
            }
        }
        return $data;
    }

    /**
     * Set session value with validation
     */
    public function set(string $key, mixed $value): void
    {
        $this->ensureStarted();

        // Validate size
        $serialized = serialize($value);
        if (strlen($serialized) > self::MAX_DATA_SIZE) {
            throw new InvalidArgumentException("Session data too large for key: {$key}");
        }

        // Update cache and session
        $this->sessionData ??= $this->loadSessionData();
        $this->sessionData[$key] = $value;
        $_SESSION[$key] = $value;
    }

    /**
     * Check if session has key
     */
    public function has(string $key): bool
    {
        $this->ensureStarted();
        $data = $this->getSessionDataLazy();
        return isset($data[$key]);
    }

    /**
     * Remove session key
     */
    public function remove(string $key): void
    {
        $this->ensureStarted();

        $this->sessionData ??= $this->loadSessionData();
        unset($this->sessionData[$key], $_SESSION[$key]);
    }

    /**
     * Clear all session data
     */
    public function clear(): void
    {
        $this->ensureStarted();

        // Clear all non-framework keys
        foreach ($_SESSION as $key => $value) {
            if (!str_starts_with($key, self::SESSION_PREFIX)) {
                unset($_SESSION[$key]);
            }
        }

        $this->sessionData = [];
    }

    /**
     * Get flash manager for flash messages
     */
    public function flash(): FlashInterface
    {
        $this->ensureStarted();
        return $this->flashManager ??= new FlashManager();
    }

    /**
     * Get auth manager for authentication
     */
    public function auth(): AuthInterface
    {
        $this->ensureStarted();
        return $this->authManager ??= new AuthManager($this);
    }
}