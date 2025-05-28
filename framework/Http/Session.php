<?php

declare(strict_types=1);

namespace Framework\Http;

/**
 * Secure Session Management with PHP 8.4 enhancements and lazy loading
 */
final class Session
{
    // Security constants
    private const int MAX_LIFETIME = 7200; // 2 hours
    private const int REGENERATE_INTERVAL = 300; // 5 minutes
    private const int MAX_DATA_SIZE = 1048576; // 1MB
    private const string SESSION_PREFIX = '_framework_';

    // Lazy-loaded session data
    private ?array $sessionData = null;
    private ?array $flashData = null;
    private ?array $userData = null;

    // Property Hooks für bessere API
    public private(set) bool $started = false;
    public private(set) ?string $sessionId = null;

    // Lazy-loaded properties mit Property Hooks
    public string|int|null $userId {
        get {
            $this->ensureStarted();
            return $this->getUserDataLazy()['_user_id'] ?? null;
        }
    }

    public bool $isLoggedIn {
        get {
            $this->ensureStarted();
            return isset($this->getUserDataLazy()['_user_id']);
        }
    }

    public array $userInfo {
        get {
            $this->ensureStarted();
            return $this->getUserDataLazy()['_user_data'] ?? [];
        }
    }

    public function __construct(
        private readonly array $config = []
    ) {
        $this->configure();
    }

    /**
     * Start session with security hardening
     */
    public function start(): bool
    {
        if ($this->started) {
            return true;
        }

        try {
            // Prevent session fixation attacks
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            if (!session_start()) {
                return false;
            }

            $this->started = true;
            $this->sessionId = session_id();

            // Security checks and maintenance
            $this->validateSessionLazy();
            $this->regenerateIfNeeded();

            return true;
        } catch (\Throwable $e) {
            $this->handleSessionError('Failed to start session', $e);
            return false;
        }
    }

    /**
     * Get session value with lazy loading
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->ensureStarted()) {
            return $default;
        }

        $data = $this->getSessionDataLazy();
        return $data[$key] ?? $default;
    }

    /**
     * Set session value with size validation and lazy updates
     */
    public function set(string $key, mixed $value): bool
    {
        if (!$this->ensureStarted()) {
            return false;
        }

        try {
            // Prevent oversized session data
            $serialized = serialize($value);
            if (strlen($serialized) > self::MAX_DATA_SIZE) {
                $this->handleSessionError('Session data too large for key: ' . $key);
                return false;
            }

            // Lazy update - modify abstract data, sync later
            $this->sessionData ??= $this->loadSessionData();
            $this->sessionData[$key] = $value;
            $this->syncSessionData();

            return true;
        } catch (\Throwable $e) {
            $this->handleSessionError('Failed to set session data', $e);
            return false;
        }
    }

    /**
     * Check if session has key (lazy)
     */
    public function has(string $key): bool
    {
        if (!$this->ensureStarted()) {
            return false;
        }

        $data = $this->getSessionDataLazy();
        return isset($data[$key]);
    }

    /**
     * Remove session key (lazy)
     */
    public function remove(string $key): bool
    {
        if (!$this->ensureStarted()) {
            return false;
        }

        try {
            $this->sessionData ??= $this->loadSessionData();
            unset($this->sessionData[$key]);
            $this->syncSessionData();
            return true;
        } catch (\Throwable $e) {
            $this->handleSessionError('Failed to remove session key', $e);
            return false;
        }
    }

    /**
     * Get all session data (lazy)
     */
    public function all(): array
    {
        if (!$this->ensureStarted()) {
            return [];
        }

        return $this->getSessionDataLazy();
    }

    /**
     * Clear all session data
     */
    public function clear(): bool
    {
        if (!$this->ensureStarted()) {
            return false;
        }

        try {
            $this->sessionData = [];
            $this->syncSessionData();
            return true;
        } catch (\Throwable $e) {
            $this->handleSessionError('Failed to clear session', $e);
            return false;
        }
    }

    /**
     * Regenerate session ID (prevents session fixation)
     */
    public function regenerate(bool $deleteOld = true): bool
    {
        if (!$this->ensureStarted()) {
            return false;
        }

        try {
            if (!session_regenerate_id($deleteOld)) {
                return false;
            }

            $this->sessionId = session_id();
            $this->setUserDataLazy('_regenerated_at', time());
            return true;
        } catch (\Throwable $e) {
            $this->handleSessionError('Failed to regenerate session ID', $e);
            return false;
        }
    }

    /**
     * Destroy session completely
     */
    public function destroy(): bool
    {
        if (!$this->started) {
            return true;
        }

        try {
            // Clear abstracted data
            $this->sessionData = [];
            $this->flashData = [];
            $this->userData = [];

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

            return true;
        } catch (\Throwable $e) {
            $this->handleSessionError('Failed to destroy session', $e);
            return false;
        }
    }

    /**
     * Flash message storage (lazy)
     */
    public function flash(string $key, mixed $value = null): mixed
    {
        if (!$this->ensureStarted()) {
            return $value === null ? null : false;
        }

        try {
            if ($value === null) {
                // Get and remove flash message
                $flash = $this->getFlashDataLazy();
                $flashValue = $flash[$key] ?? null;

                $this->flashData[$key] = null;
                unset($this->flashData[$key]);
                $this->syncFlashData();

                return $flashValue;
            }

            // Set flash message
            $this->flashData ??= $this->loadFlashData();
            $this->flashData[$key] = $value;
            $this->syncFlashData();

            return true;
        } catch (\Throwable $e) {
            $this->handleSessionError('Flash operation failed', $e);
            return $value === null ? null : false;
        }
    }

    /**
     * Store user authentication
     */
    public function login(string|int $userId, array $userData = []): bool
    {
        if (!$this->ensureStarted()) {
            return false;
        }

        try {
            // Regenerate session on login to prevent fixation
            if (!$this->regenerate()) {
                return false;
            }

            $this->userData ??= $this->loadUserData();
            $this->userData['_user_id'] = $userId;
            $this->userData['_user_data'] = $userData;
            $this->userData['_login_time'] = time();
            $this->userData['_last_activity'] = time();

            $this->syncUserData();
            return true;
        } catch (\Throwable $e) {
            $this->handleSessionError('Login failed', $e);
            return false;
        }
    }

    /**
     * Remove user authentication
     */
    public function logout(): bool
    {
        if (!$this->ensureStarted()) {
            return false;
        }

        try {
            $this->userData = [];
            $this->syncUserData();

            // Regenerate session after logout
            return $this->regenerate();
        } catch (\Throwable $e) {
            $this->handleSessionError('Logout failed', $e);
            return false;
        }
    }

    /**
     * Update last activity timestamp
     */
    public function touch(): bool
    {
        if (!$this->ensureStarted()) {
            return false;
        }

        try {
            $this->setUserDataLazy('_last_activity', time());
            return true;
        } catch (\Throwable $e) {
            $this->handleSessionError('Touch failed', $e);
            return false;
        }
    }

    // === Private Lazy Loading Methods ===

    /**
     * Load session data from $_SESSION (lazy)
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
     * Get session data with lazy loading
     */
    private function getSessionDataLazy(): array
    {
        return $this->sessionData ??= $this->loadSessionData();
    }

    /**
     * Sync abstracted data back to $_SESSION
     */
    private function syncSessionData(): void
    {
        if ($this->sessionData === null) {
            return;
        }

        // Clear non-framework keys
        foreach ($_SESSION as $key => $value) {
            if (!str_starts_with($key, self::SESSION_PREFIX)) {
                unset($_SESSION[$key]);
            }
        }

        // Set current data
        foreach ($this->sessionData as $key => $value) {
            $_SESSION[$key] = $value;
        }
    }

    /**
     * Load flash data (lazy)
     */
    private function loadFlashData(): array
    {
        return $_SESSION[self::SESSION_PREFIX . 'flash'] ?? [];
    }

    /**
     * Get flash data with lazy loading
     */
    private function getFlashDataLazy(): array
    {
        return $this->flashData ??= $this->loadFlashData();
    }

    /**
     * Sync flash data
     */
    private function syncFlashData(): void
    {
        if ($this->flashData === null) {
            return;
        }

        if (empty($this->flashData)) {
            unset($_SESSION[self::SESSION_PREFIX . 'flash']);
        } else {
            $_SESSION[self::SESSION_PREFIX . 'flash'] = $this->flashData;
        }
    }

    /**
     * Load user data (lazy)
     */
    private function loadUserData(): array
    {
        return $_SESSION[self::SESSION_PREFIX . 'user'] ?? [];
    }

    /**
     * Get user data with lazy loading
     */
    private function getUserDataLazy(): array
    {
        return $this->userData ??= $this->loadUserData();
    }

    /**
     * Set user data item (lazy)
     */
    private function setUserDataLazy(string $key, mixed $value): void
    {
        $this->userData ??= $this->loadUserData();
        $this->userData[$key] = $value;
        $this->syncUserData();
    }

    /**
     * Sync user data
     */
    private function syncUserData(): void
    {
        if ($this->userData === null) {
            return;
        }

        if (empty($this->userData)) {
            unset($_SESSION[self::SESSION_PREFIX . 'user']);
        } else {
            $_SESSION[self::SESSION_PREFIX . 'user'] = $this->userData;
        }
    }

    /**
     * Ensure session is started with error handling
     */
    private function ensureStarted(): bool
    {
        if (!$this->started) {
            return $this->start();
        }
        return true;
    }

    /**
     * Validate session security (lazy)
     */
    private function validateSessionLazy(): bool
    {
        try {
            $now = time();
            $userData = $this->getUserDataLazy();

            // Check session timeout
            $lastActivity = $userData['_last_activity'] ?? $now;
            if ($now - $lastActivity > self::MAX_LIFETIME) {
                $this->destroy();
                throw new \RuntimeException('Session expired');
            }

            // Update last activity
            $this->setUserDataLazy('_last_activity', $now);

            // Validate user agent (basic fingerprinting)
            $currentUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $sessionUserAgent = $userData['_user_agent'] ?? '';

            if ($sessionUserAgent === '') {
                $this->setUserDataLazy('_user_agent', $currentUserAgent);
            } elseif ($sessionUserAgent !== $currentUserAgent) {
                $this->destroy();
                throw new \RuntimeException('Session hijacking detected');
            }

            // Validate IP address (optional)
            if ($this->config['validate_ip'] ?? false) {
                $currentIp = $_SERVER['REMOTE_ADDR'] ?? '';
                $sessionIp = $userData['_ip_address'] ?? '';

                if ($sessionIp === '') {
                    $this->setUserDataLazy('_ip_address', $currentIp);
                } elseif ($sessionIp !== $currentIp) {
                    $this->destroy();
                    throw new \RuntimeException('IP address mismatch');
                }
            }

            return true;
        } catch (\Throwable $e) {
            $this->handleSessionError('Session validation failed', $e);
            return false;
        }
    }

    /**
     * Regenerate session ID periodically
     */
    private function regenerateIfNeeded(): bool
    {
        try {
            $userData = $this->getUserDataLazy();
            $lastRegeneration = $userData['_regenerated_at'] ?? 0;

            if (time() - $lastRegeneration > self::REGENERATE_INTERVAL) {
                return $this->regenerate();
            }

            return true;
        } catch (\Throwable $e) {
            $this->handleSessionError('Auto-regeneration failed', $e);
            return false;
        }
    }

    /**
     * Consistent error handling
     */
    private function handleSessionError(string $message, ?\Throwable $previous = null): void
    {
        // Log error (in real implementation, use proper logger)
        error_log("Session Error: {$message}" . ($previous ? " - " . $previous->getMessage() : ""));

        // Option: Throw exception or handle gracefully based on config
        if ($this->config['strict_errors'] ?? false) {
            throw new \RuntimeException($message, 0, $previous);
        }
    }

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

    private function isHttps(): bool
    {
        return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ||
            !empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' ||
            !empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on';
    }
}