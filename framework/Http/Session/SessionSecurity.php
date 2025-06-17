<?php


declare(strict_types=1);

namespace Framework\Http\Session;

use RuntimeException;

/**
 * Zentrale Session-Sicherheitsvalidierung
 */
final class SessionSecurity
{
    private const int MAX_LIFETIME = 7200; // 2 hours
    private const int REGENERATE_INTERVAL = 300; // 5 minutes
    private const string SESSION_PREFIX = '_framework_';

    public function __construct(
        private readonly array $config = []
    )
    {
    }

    /**
     * Validiere Session-Sicherheit
     */
    public function validateSession(): void
    {
        $now = time();

        // Neue Session erkennen und initialisieren
        if (!isset($_SESSION[self::SESSION_PREFIX . 'last_activity'])) {
            $this->initializeNewSession($now);
            return;
        }

        $this->validateExistingSession($now);
    }

    /**
     * Initialisiere neue Session
     */
    private function initializeNewSession(int $now): void
    {
        $_SESSION[self::SESSION_PREFIX . 'last_activity'] = $now;
        $_SESSION[self::SESSION_PREFIX . 'created_at'] = $now;
        $_SESSION[self::SESSION_PREFIX . 'regenerated_at'] = $now;

        // Sichere Fingerprint-Erstellung
        $_SESSION[self::SESSION_PREFIX . 'fingerprint'] = $this->createFingerprint();

        // IP nur in Production tracken
        if ($this->shouldValidateIp()) {
            $_SESSION[self::SESSION_PREFIX . 'ip_address'] = $this->getCurrentIp();
        }
    }

    /**
     * Erstelle sicheren Session-Fingerprint
     */
    private function createFingerprint(): string
    {
        $components = [
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
            session_id()
        ];

        return hash('sha256', implode('|', $components));
    }

    /**
     * Prüfe ob IP-Validierung aktiviert werden soll
     */
    private function shouldValidateIp(): bool
    {
        return ($this->config['validate_ip'] ?? false) === true;
    }

    /**
     * Hole aktuelle IP-Adresse sicher
     */
    private function getCurrentIp(): string
    {
        // Prüfe Proxy-Headers in sicherer Reihenfolge
        $ipSources = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,     // Cloudflare
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,      // Standard Proxy
            $_SERVER['HTTP_X_REAL_IP'] ?? null,            // Nginx
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'           // Direct connection
        ];

        foreach ($ipSources as $ip) {
            if ($ip && $this->isValidIp($ip)) {
                return $ip;
            }
        }

        return 'unknown';
    }

    /**
     * Validiere IP-Adresse Format
     */
    private function isValidIp(string $ip): bool
    {
        // Bei Forwarded-IPs nur erste nehmen
        if (str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip)[0]);
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * Validiere bestehende Session
     */
    private function validateExistingSession(int $now): void
    {
        $lastActivity = $_SESSION[self::SESSION_PREFIX . 'last_activity'];

        // Session-Expiry prüfen
        if ($now - $lastActivity > self::MAX_LIFETIME) {
            throw new RuntimeException('Session expired');
        }

        // Update last activity
        $_SESSION[self::SESSION_PREFIX . 'last_activity'] = $now;

        // Security-Validierungen
        $this->validateFingerprint();

        if ($this->shouldValidateIp()) {
            $this->validateIpAddress();
        }
    }

    /**
     * Validiere User-Agent Fingerprint
     */
    private function validateFingerprint(): void
    {
        $currentFingerprint = $this->createFingerprint();
        $sessionFingerprint = $_SESSION[self::SESSION_PREFIX . 'fingerprint'] ?? '';

        if ($sessionFingerprint === '') {
            $_SESSION[self::SESSION_PREFIX . 'fingerprint'] = $currentFingerprint;
            return;
        }

        // Nur bei komplett anderem Fingerprint Session zerstören
        if (!hash_equals($sessionFingerprint, $currentFingerprint)) {
            throw new RuntimeException('Session fingerprint mismatch');
        }
    }

    /**
     * Validiere IP-Adresse (nur in Production)
     */
    private function validateIpAddress(): void
    {
        $currentIp = $this->getCurrentIp();
        $sessionIp = $_SESSION[self::SESSION_PREFIX . 'ip_address'] ?? '';

        if ($sessionIp === '') {
            $_SESSION[self::SESSION_PREFIX . 'ip_address'] = $currentIp;
            return;
        }

        if ($sessionIp !== $currentIp) {
            $environment = $this->config['environment'] ?? 'development';

            if ($environment === 'production') {
                throw new RuntimeException('IP address mismatch');
            } else {
                // In Development: Update IP statt destroy
                $_SESSION[self::SESSION_PREFIX . 'ip_address'] = $currentIp;
            }
        }
    }

    /**
     * Prüfe ob Session regeneriert werden muss
     */
    public function needsRegeneration(): bool
    {
        $lastRegeneration = $_SESSION[self::SESSION_PREFIX . 'regenerated_at'] ?? 0;
        return (time() - $lastRegeneration) > self::REGENERATE_INTERVAL;
    }

    /**
     * Markiere Session als regeneriert
     */
    public function markRegenerated(): void
    {
        $_SESSION[self::SESSION_PREFIX . 'regenerated_at'] = time();
    }

    /**
     * Session-Cleanup beim Logout
     */
    public function cleanup(): void
    {
        // Entferne nur Framework-spezifische Keys
        foreach ($_SESSION as $key => $value) {
            if (str_starts_with($key, self::SESSION_PREFIX)) {
                unset($_SESSION[$key]);
            }
        }
    }

    /**
     * Hole Session-Metadata
     */
    public function getMetadata(): array
    {
        return [
            'created_at' => $_SESSION[self::SESSION_PREFIX . 'created_at'] ?? null,
            'last_activity' => $_SESSION[self::SESSION_PREFIX . 'last_activity'] ?? null,
            'regenerated_at' => $_SESSION[self::SESSION_PREFIX . 'regenerated_at'] ?? null,
            'ip_address' => $_SESSION[self::SESSION_PREFIX . 'ip_address'] ?? null,
            'has_fingerprint' => isset($_SESSION[self::SESSION_PREFIX . 'fingerprint'])
        ];
    }
}