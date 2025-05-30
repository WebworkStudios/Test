<?php

declare(strict_types=1);

namespace Framework\Routing;

/**
 * Secure route caching for improved performance with comprehensive security measures
 */
final class RouteCache
{
    private const string CACHE_FILE = 'framework_routes.cache';
    private const int CACHE_TTL = 3600; // 1 hour
    private const int MAX_CACHE_SIZE = 10485760; // 10MB
    private const string CACHE_VERSION = '1.0';
    private const string INTEGRITY_SUFFIX = '.integrity';

    private readonly string $cacheDir;
    private readonly bool $integrityCheck;
    private readonly string $encryptionKey;

    public function __construct(
        string $cacheDir = '',
        array $config = []
    ) {
        $this->cacheDir = $this->validateAndSetCacheDir($cacheDir);
        $this->integrityCheck = $config['integrity_check'] ?? true;
        $this->encryptionKey = $config['encryption_key'] ?? $this->generateEncryptionKey();

        $this->ensureSecureCacheDirectory();
    }

    /**
     * Store compiled routes in cache with security measures
     */
    public function store(array $routes): void
    {
        try {
            $this->validateRoutesForStorage($routes);

            $cacheFile = $this->getCacheFile();
            $this->ensureDirectoryExists(dirname($cacheFile));

            $cacheData = [
                'version' => self::CACHE_VERSION,
                'timestamp' => time(),
                'routes' => $routes,
                'checksum' => $this->calculateChecksum($routes)
            ];

            $serialized = serialize($cacheData);

            // Size validation
            if (strlen($serialized) > self::MAX_CACHE_SIZE) {
                throw new \RuntimeException('Cache data exceeds maximum size limit');
            }

            // Encrypt cache data
            $encrypted = $this->encryptData($serialized);

            // Atomic write with integrity protection
            $this->atomicWrite($cacheFile, $encrypted);

            // Store integrity file
            if ($this->integrityCheck) {
                $this->storeIntegrityFile($cacheFile, $encrypted);
            }

        } catch (\Throwable $e) {
            error_log("Failed to store route cache: " . $e->getMessage());
            // Don't throw - caching is optional
        }
    }

    /**
     * Load cached routes with security validation
     */
    public function load(): ?array
    {
        try {
            $cacheFile = $this->getCacheFile();

            if (!$this->isValidCacheFile($cacheFile)) {
                return null;
            }

            // Check if cache is expired
            if ($this->isCacheExpired($cacheFile)) {
                $this->clear();
                return null;
            }

            // Integrity check
            if ($this->integrityCheck && !$this->verifyIntegrity($cacheFile)) {
                error_log("Cache integrity check failed, clearing cache");
                $this->clear();
                return null;
            }

            $encrypted = file_get_contents($cacheFile);
            if ($encrypted === false) {
                return null;
            }

            // Decrypt data
            $serialized = $this->decryptData($encrypted);
            if ($serialized === false) {
                error_log("Failed to decrypt cache data");
                $this->clear();
                return null;
            }

            $cacheData = unserialize($serialized);
            if (!$this->isValidCacheData($cacheData)) {
                error_log("Invalid cache data structure");
                $this->clear();
                return null;
            }

            // Verify checksum
            if (!$this->verifyChecksum($cacheData)) {
                error_log("Cache checksum verification failed");
                $this->clear();
                return null;
            }

            // Version check
            if (($cacheData['version'] ?? '') !== self::CACHE_VERSION) {
                error_log("Cache version mismatch, clearing cache");
                $this->clear();
                return null;
            }

            return $cacheData['routes'];

        } catch (\Throwable $e) {
            error_log("Failed to load route cache: " . $e->getMessage());
            $this->clear();
            return null;
        }
    }

    /**
     * Clear cache securely
     */
    public function clear(): void
    {
        try {
            $cacheFile = $this->getCacheFile();
            $integrityFile = $this->getIntegrityFile($cacheFile);

            // Secure deletion
            if (file_exists($cacheFile)) {
                $this->secureDelete($cacheFile);
            }

            if (file_exists($integrityFile)) {
                $this->secureDelete($integrityFile);
            }

        } catch (\Throwable $e) {
            error_log("Failed to clear route cache: " . $e->getMessage());
        }
    }

    /**
     * Check if cache exists and is valid
     */
    public function isValid(): bool
    {
        try {
            $cacheFile = $this->getCacheFile();

            if (!$this->isValidCacheFile($cacheFile)) {
                return false;
            }

            if ($this->isCacheExpired($cacheFile)) {
                return false;
            }

            if ($this->integrityCheck && !$this->verifyIntegrity($cacheFile)) {
                return false;
            }

            return true;

        } catch (\Throwable $e) {
            error_log("Cache validation failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Validate and set cache directory
     */
    private function validateAndSetCacheDir(string $cacheDir): string
    {
        if ($cacheDir === '') {
            $cacheDir = sys_get_temp_dir();
        }

        // Security validation
        $realPath = realpath($cacheDir);
        if ($realPath === false) {
            throw new \InvalidArgumentException("Cache directory does not exist: {$cacheDir}");
        }

        // Check for dangerous paths
        $this->validateSecurePath($realPath);

        // Check permissions
        if (!is_writable($realPath)) {
            throw new \InvalidArgumentException("Cache directory is not writable: {$realPath}");
        }

        return $realPath;
    }

    /**
     * Validate secure path
     */
    private function validateSecurePath(string $path): void
    {
        // Check for directory traversal
        if (str_contains($path, '..')) {
            throw new \InvalidArgumentException("Directory traversal detected in path: {$path}");
        }

        // Check for system directories
        $dangerousPaths = ['/etc/', '/bin/', '/sbin/', '/root/', '/proc/', '/sys/'];

        foreach ($dangerousPaths as $dangerous) {
            if (str_starts_with($path, $dangerous)) {
                throw new \InvalidArgumentException("Cache directory in dangerous location: {$path}");
            }
        }

        // Windows system directories
        if (DIRECTORY_SEPARATOR === '\\') {
            $windowsDangerous = ['C:\\Windows\\', 'C:\\Program Files\\', 'C:\\ProgramData\\'];

            foreach ($windowsDangerous as $dangerous) {
                if (str_starts_with($path, $dangerous)) {
                    throw new \InvalidArgumentException("Cache directory in dangerous Windows location: {$path}");
                }
            }
        }
    }

    /**
     * Ensure cache directory is secure
     */
    private function ensureSecureCacheDirectory(): void
    {
        // Check directory permissions
        $perms = fileperms($this->cacheDir);
        if ($perms === false) {
            throw new \RuntimeException("Cannot get cache directory permissions");
        }

        // Ensure directory is not world-writable
        if (($perms & 0002) !== 0) {
            error_log("Warning: Cache directory is world-writable: {$this->cacheDir}");
        }

        // Create .htaccess for Apache protection
        $htaccessFile = $this->cacheDir . DIRECTORY_SEPARATOR . '.htaccess';
        if (!file_exists($htaccessFile)) {
            $htaccessContent = "Order deny,allow\nDeny from all\n";
            file_put_contents($htaccessFile, $htaccessContent, LOCK_EX);
        }
    }

    /**
     * Validate routes for storage
     */
    private function validateRoutesForStorage(array $routes): void
    {
        if (empty($routes)) {
            throw new \InvalidArgumentException("Cannot store empty routes array");
        }

        // Validate structure
        foreach ($routes as $method => $methodRoutes) {
            if (!is_string($method) || !is_array($methodRoutes)) {
                throw new \InvalidArgumentException("Invalid routes structure");
            }

            foreach ($methodRoutes as $route) {
                if (!($route instanceof RouteInfo)) {
                    throw new \InvalidArgumentException("Invalid route object in cache data");
                }

                $this->validateRouteInfo($route);
            }
        }
    }

    /**
     * Validate RouteInfo object
     */
    private function validateRouteInfo(RouteInfo $route): void
    {
        // Validate action class exists
        if (!class_exists($route->actionClass)) {
            throw new \InvalidArgumentException("Action class does not exist: {$route->actionClass}");
        }

        // Validate pattern length
        if (strlen($route->pattern) > 1000) {
            throw new \InvalidArgumentException("Route pattern too long");
        }

        // Validate parameter count
        if (count($route->paramNames) > 20) {
            throw new \InvalidArgumentException("Too many route parameters");
        }

        // Validate subdomain if present
        if ($route->subdomain !== null) {
            if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?$/', $route->subdomain)) {
                throw new \InvalidArgumentException("Invalid subdomain in route: {$route->subdomain}");
            }
        }
    }

    /**
     * Calculate checksum for data integrity
     */
    private function calculateChecksum(array $routes): string
    {
        return hash('sha256', serialize($routes) . $this->encryptionKey);
    }

    /**
     * Verify checksum
     */
    private function verifyChecksum(array $cacheData): bool
    {
        if (!isset($cacheData['checksum']) || !isset($cacheData['routes'])) {
            return false;
        }

        $expectedChecksum = $this->calculateChecksum($cacheData['routes']);
        return hash_equals($expectedChecksum, $cacheData['checksum']);
    }

    /**
     * Encrypt cache data
     */
    private function encryptData(string $data): string
    {
        if (!extension_loaded('openssl')) {
            // Fallback to base64 encoding if OpenSSL not available
            return base64_encode($data);
        }

        $cipher = 'AES-256-GCM';
        $iv = random_bytes(16);
        $tag = '';

        $encrypted = openssl_encrypt($data, $cipher, $this->encryptionKey, OPENSSL_RAW_DATA, $iv, $tag);
        if ($encrypted === false) {
            throw new \RuntimeException("Failed to encrypt cache data");
        }

        return base64_encode($iv . $tag . $encrypted);
    }

    /**
     * Decrypt cache data
     */
    private function decryptData(string $encryptedData): string|false
    {
        if (!extension_loaded('openssl')) {
            // Fallback from base64 encoding
            return base64_decode($encryptedData);
        }

        $data = base64_decode($encryptedData);
        if ($data === false || strlen($data) < 32) {
            return false;
        }

        $cipher = 'AES-256-GCM';
        $iv = substr($data, 0, 16);
        $tag = substr($data, 16, 16);
        $encrypted = substr($data, 32);

        return openssl_decrypt($encrypted, $cipher, $this->encryptionKey, OPENSSL_RAW_DATA, $iv, $tag);
    }

    /**
     * Generate encryption key
     */
    private function generateEncryptionKey(): string
    {
        // Try to use a stable key based on installation
        $keyFile = $this->cacheDir . DIRECTORY_SEPARATOR . '.cache_key';

        if (file_exists($keyFile)) {
            $key = file_get_contents($keyFile);
            if ($key !== false && strlen($key) === 32) {
                return $key;
            }
        }

        // Generate new key
        $key = random_bytes(32);
        file_put_contents($keyFile, $key, LOCK_EX);
        chmod($keyFile, 0600); // Owner read/write only

        return $key;
    }

    /**
     * Atomic write operation
     */
    private function atomicWrite(string $filename, string $data): void
    {
        $tempFile = $filename . '.tmp.' . uniqid();

        $bytesWritten = file_put_contents($tempFile, $data, LOCK_EX);
        if ($bytesWritten === false) {
            throw new \RuntimeException("Failed to write cache data to temporary file");
        }

        if ($bytesWritten !== strlen($data)) {
            unlink($tempFile);
            throw new \RuntimeException("Incomplete write to cache file");
        }

        if (!rename($tempFile, $filename)) {
            unlink($tempFile);
            throw new \RuntimeException("Failed to rename temporary cache file");
        }

        // Set restrictive permissions
        chmod($filename, 0600);
    }

    /**
     * Store integrity file
     */
    private function storeIntegrityFile(string $cacheFile, string $data): void
    {
        $integrityFile = $this->getIntegrityFile($cacheFile);
        $hash = hash('sha256', $data);

        file_put_contents($integrityFile, $hash, LOCK_EX);
        chmod($integrityFile, 0600);
    }

    /**
     * Verify cache integrity
     */
    private function verifyIntegrity(string $cacheFile): bool
    {
        $integrityFile = $this->getIntegrityFile($cacheFile);

        if (!file_exists($integrityFile)) {
            return false;
        }

        $storedHash = file_get_contents($integrityFile);
        if ($storedHash === false) {
            return false;
        }

        $cacheData = file_get_contents($cacheFile);
        if ($cacheData === false) {
            return false;
        }

        $currentHash = hash('sha256', $cacheData);
        return hash_equals(trim($storedHash), $currentHash);
    }

    /**
     * Check if cache file is valid
     */
    private function isValidCacheFile(string $cacheFile): bool
    {
        if (!file_exists($cacheFile)) {
            return false;
        }

        if (!is_readable($cacheFile)) {
            return false;
        }

        // Size check
        $size = filesize($cacheFile);
        if ($size === false || $size > self::MAX_CACHE_SIZE) {
            return false;
        }

        return true;
    }

    /**
     * Check if cache is expired
     */
    private function isCacheExpired(string $cacheFile): bool
    {
        $mtime = filemtime($cacheFile);
        if ($mtime === false) {
            return true;
        }

        return (time() - $mtime) > self::CACHE_TTL;
    }

    /**
     * Validate cache data structure
     */
    private function isValidCacheData(mixed $cacheData): bool
    {
        if (!is_array($cacheData)) {
            return false;
        }

        $requiredKeys = ['version', 'timestamp', 'routes', 'checksum'];
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $cacheData)) {
                return false;
            }
        }

        if (!is_array($cacheData['routes'])) {
            return false;
        }

        return true;
    }

    /**
     * Secure file deletion
     */
    private function secureDelete(string $filename): void
    {
        if (!file_exists($filename)) {
            return;
        }

        // Overwrite file with random data before deletion
        $size = filesize($filename);
        if ($size !== false && $size > 0) {
            $handle = fopen($filename, 'r+b');
            if ($handle !== false) {
                fwrite($handle, random_bytes($size));
                fflush($handle);
                fclose($handle);
            }
        }

        unlink($filename);
    }

    /**
     * Ensure directory exists
     */
    private function ensureDirectoryExists(string $directory): void
    {
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true)) {
                throw new \RuntimeException("Failed to create cache directory: {$directory}");
            }
        }
    }

    /**
     * Get cache file path
     */
    private function getCacheFile(): string
    {
        return $this->cacheDir . DIRECTORY_SEPARATOR . self::CACHE_FILE;
    }

    /**
     * Get integrity file path
     */
    private function getIntegrityFile(string $cacheFile): string
    {
        return $cacheFile . self::INTEGRITY_SUFFIX;
    }
}