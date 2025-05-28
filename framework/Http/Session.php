<?php

declare(strict_types=1);

namespace Framework\Http;

/**
 * Secure Session Management with PHP 8.4 enhancements and optimized lazy loading
 */
class Session implements SessionInterface
{
    // Security constants
    private const int MAX_LIFETIME = 7200; // 2 hours
    private const int REGENERATE_INTERVAL = 300; // 5 minutes
    private const int VALIDATION_INTERVAL = 60; // 1 minute
    private const int MAX_DATA_SIZE = 1048576; // 1MB
    private const string SESSION_PREFIX = '_framework_';

    // Lazy-loaded session data
    private ?array $sessionData = null;
    private ?array $flashData = null;
    private ?array $userData = null;

    // Optimized sync system
    private array $pendingWrites = [];
    private ?int $lastValidation = null;

    // Property Hooks for better API
    public private(set) bool $started = false;
    public private(set) ?string $sessionId = null;

    // Lazy-loaded properties with Property Hooks
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

        // Security validation
        $this->validateIfNeeded();
        $this->regenerateIfNeeded();
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
     * Set session value with validation and optimized sync
     */
    public function set(string $key, mixed $value): void
    {
        $this->ensureStarted();

        // Validate size
        $serialized = serialize($value);
        if (strlen($serialized) > self::MAX_DATA_SIZE) {
            throw new \InvalidArgumentException("Session data too large for key: {$key}");
        }

        // Update lazy cache
        $this->sessionData ??= $this->loadSessionData();
        $this->sessionData[$key] = $value;

        // Mark for sync
        $this->markForSync('session', $key, $value);
    }

    /**
     * Check if session has key (lazy)
     */
    public function has(string $key): bool
    {
        $this->ensureStarted();
        $data = $this->getSessionDataLazy();
        return isset($data[$key]);
    }

    /**
     * Remove session key with optimized sync
     */
    public function remove(string $key): void
    {
        $this->ensureStarted();

        $this->sessionData ??= $this->loadSessionData();
        unset($this->sessionData[$key]);

        $this->markForSync('session', $key, null);
    }

    /**
     * Get all session data (lazy)
     */
    public function all(): array
    {
        $this->ensureStarted();
        return $this->getSessionDataLazy();
    }

    /**
     * Clear all session data
     */
    public function clear(): void
    {
        $this->ensureStarted();

        $this->sessionData = [];
        $this->pendingWrites['session'] = ['_clear' => true];
        $this->syncPending();
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
        $this->setUserDataItem('_regenerated_at', time());
    }

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
        $this->flashData = [];
        $this->userData = [];
        $this->pendingWrites = [];

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
     * Flash message storage with optimized handling
     */
    public function flash(string $key, mixed $value = null): mixed
    {
        $this->ensureStarted();

        if ($value === null) {
            // Get and remove flash message
            $flash = $this->getFlashDataLazy();
            $flashValue = $flash[$key] ?? null;

            if (isset($this->flashData[$key])) {
                unset($this->flashData[$key]);
                $this->markForSync('flash', $key, null);
            }

            return $flashValue;
        }

        // Set flash message
        $this->flashData ??= $this->loadFlashData();
        $this->flashData[$key] = $value;
        $this->markForSync('flash', $key, $value);

        return true;
    }

    /**
     * Store user authentication
     */
    public function login(string|int $userId, array $userData = []): void
    {
        $this->ensureStarted();

        // Regenerate session on login to prevent fixation
        $this->regenerate();

        $this->userData ??= $this->loadUserData();
        $this->userData['_user_id'] = $userId;
        $this->userData['_user_data'] = $userData;
        $this->userData['_login_time'] = time();
        $this->userData['_last_activity'] = time();

        $this->markForSync('user', '_batch', $this->userData);
    }

    /**
     * Remove user authentication
     */
    public function logout(): void
    {
        $this->ensureStarted();

        $this->userData = [];
        $this->pendingWrites['user'] = ['_clear' => true];
        $this->syncPending();

        // Regenerate session after logout
        $this->regenerate();
    }

    /**
     * Update last activity timestamp
     */
    public function touch(): void
    {
        $this->ensureStarted();
        $this->setUserDataItem('_last_activity', time());
    }

    // === Optimized Lazy Loading & Sync System ===

    /**
     * Mark data for sync (batched writes)
     */
    private function markForSync(string $type, string $key, mixed $value): void
    {
        $this->pendingWrites[$type][$key] = $value;

        // Auto-sync on significant changes
        if (count($this->pendingWrites) > 10) {
            $this->syncPending();
        }
    }

    /**
     * Sync all pending writes at once
     */
    private function syncPending(): void
    {
        foreach ($this->pendingWrites as $type => $data) {
            match($type) {
                'session' => $this->syncSessionData($data),
                'flash' => $this->syncFlashData($data),
                'user' => $this->syncUserData($data)
            };
        }
        $this->pendingWrites = [];
    }

    /**
     * Sync session data to $_SESSION
     */
    private function syncSessionData(array $changes): void
    {
        if (isset($changes['_clear'])) {
            // Clear all non-framework keys
            foreach ($_SESSION as $key => $value) {
                if (!str_starts_with($key, self::SESSION_PREFIX)) {
                    unset($_SESSION[$key]);
                }
            }
            return;
        }

        foreach ($changes as $key => $value) {
            if ($value === null) {
                unset($_SESSION[$key]);
            } else {
                $_SESSION[$key] = $value;
            }
        }
    }

    /**
     * Sync flash data to $_SESSION
     */
    private function syncFlashData(array $changes): void
    {
        $flashKey = self::SESSION_PREFIX . 'flash';

        if (isset($changes['_clear'])) {
            unset($_SESSION[$flashKey]);
            return;
        }

        $current = $_SESSION[$flashKey] ?? [];

        foreach ($changes as $key => $value) {
            if ($value === null) {
                unset($current[$key]);
            } else {
                $current[$key] = $value;
            }
        }

        if (empty($current)) {
            unset($_SESSION[$flashKey]);
        } else {
            $_SESSION[$flashKey] = $current;
        }
    }

    /**
     * Sync user data to $_SESSION
     */
    private function syncUserData(array $changes): void
    {
        $userKey = self::SESSION_PREFIX . 'user';

        if (isset($changes['_clear'])) {
            unset($_SESSION[$userKey]);
            return;
        }

        if (isset($changes['_batch'])) {
            $_SESSION[$userKey] = $changes['_batch'];
            return;
        }

        $current = $_SESSION[$userKey] ?? [];

        foreach ($changes as $key => $value) {
            if ($value === null) {
                unset($current[$key]);
            } else {
                $current[$key] = $value;
            }
        }

        if (empty($current)) {
            unset($_SESSION[$userKey]);
        } else {
            $_SESSION[$userKey] = $current;
        }
    }

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
     * Set user data item with optimized sync
     */
    private function setUserDataItem(string $key, mixed $value): void
    {
        $this->userData ??= $this->loadUserData();
        $this->userData[$key] = $value;
        $this->markForSync('user', $key, $value);
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
     * Optimized validation - only when needed
     */
    private function validateIfNeeded(): void
    {
        $now = time();
        if ($this->lastValidation === null || $now - $this->lastValidation > self::VALIDATION_INTERVAL) {
            $this->validateSession();
            $this->lastValidation = $now;
        }
    }

    /**
     * Validate session security
     */
    private function validateSession(): void
    {
        $now = time();
        $userData = $this->getUserDataLazy();

        // Check session timeout
        $lastActivity = $userData['_last_activity'] ?? $now;
        if ($now - $lastActivity > self::MAX_LIFETIME) {
            $this->destroy();
            throw new \RuntimeException('Session expired');
        }

        // Update last activity
        $this->setUserDataItem('_last_activity', $now);

        // Validate user agent (basic fingerprinting)
        $currentUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $sessionUserAgent = $userData['_user_agent'] ?? '';

        if ($sessionUserAgent === '') {
            $this->setUserDataItem('_user_agent', $currentUserAgent);
        } elseif ($sessionUserAgent !== $currentUserAgent) {
            $this->destroy();
            throw new \RuntimeException('Session hijacking detected');
        }

        // Validate IP address (optional)
        if ($this->config['validate_ip'] ?? false) {
            $currentIp = $_SERVER['REMOTE_ADDR'] ?? '';
            $sessionIp = $userData['_ip_address'] ?? '';

            if ($sessionIp === '') {
                $this->setUserDataItem('_ip_address', $currentIp);
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
        $userData = $this->getUserDataLazy();
        $lastRegeneration = $userData['_regenerated_at'] ?? 0;

        if (time() - $lastRegeneration > self::REGENERATE_INTERVAL) {
            $this->regenerate();
        }
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
     * Destructor: Ensure pending writes are synced
     */
    public function __destruct()
    {
        if (!empty($this->pendingWrites)) {
            $this->syncPending();
        }
    }
}