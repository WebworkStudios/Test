<?php

declare(strict_types=1);

namespace Framework\Routing;

/**
 * Complete High-Performance Route Cache with PHP 8.4 optimizations and security
 */
final class RouteCache
{
    private const string CACHE_FILE = 'framework_routes.cache';
    private const int CACHE_TTL = 3600; // 1 hour
    private const int MAX_CACHE_SIZE = 52428800; // 50MB
    private const string CACHE_VERSION = '2.0';
    private const string INTEGRITY_SUFFIX = '.integrity';
    private const string METADATA_SUFFIX = '.meta';

    // PHP 8.4 Property Hooks for better API
    public bool $isValid {
        get => $this->checkCacheValidity();
    }

    public int $cacheSize {
        get => $this->getCacheFileSize();
    }

    public float $hitRatio {
        get => $this->totalRequests > 0 ? ($this->cacheHits / $this->totalRequests) * 100 : 0.0;
    }

    public array $stats {
        get => $this->getStats();
    }

    public bool $compressionEnabled {
        get => $this->useCompression;
    }

    public bool $encryptionEnabled {
        get => extension_loaded('openssl');
    }

    private readonly string $cacheDir;
    private readonly bool $integrityCheck;
    private readonly string $encryptionKey;
    private readonly bool $useCompression;
    private readonly bool $useAPCu;
    private readonly int $compressionLevel;
    private readonly bool $enableMetrics;

    // Performance tracking
    private int $cacheHits = 0;
    private int $cacheMisses = 0;
    private int $totalRequests = 0;
    private int $storeOperations = 0;
    private int $loadOperations = 0;
    private float $totalStoreTime = 0.0;
    private float $totalLoadTime = 0.0;

    // Advanced features
    private array $compressionStats = [];
    private array $encryptionStats = [];

    public function __construct(
        string $cacheDir = '',
        array $config = []
    ) {
        $this->cacheDir = $this->validateAndSetCacheDir($cacheDir);
        $this->integrityCheck = $config['integrity_check'] ?? true;
        $this->encryptionKey = $config['encryption_key'] ?? $this->generateEncryptionKey();
        $this->useCompression = $config['use_compression'] ?? extension_loaded('zlib');
        $this->compressionLevel = $config['compression_level'] ?? 6;
        $this->useAPCu = $config['use_apcu'] ?? (extension_loaded('apcu') && apcu_enabled());
        $this->enableMetrics = $config['enable_metrics'] ?? true;

        $this->ensureSecureCacheDirectory();
        $this->initializeMetrics();
    }

    /**
     * Store routes with advanced optimizations and metrics
     */
    public function store(array $routes): void
    {
        $startTime = hrtime(true);

        try {
            $this->validateRoutesForStorage($routes);

            $cacheFile = $this->getCacheFile();
            $this->ensureDirectoryExists(dirname($cacheFile));

            $cacheData = $this->prepareCacheData($routes);
            $serialized = $this->optimizedSerialize($cacheData);

            // Size validation
            if (strlen($serialized) > self::MAX_CACHE_SIZE) {
                throw new \RuntimeException('Cache data exceeds maximum size limit: ' . $this->formatBytes(strlen($serialized)));
            }

            // Compression with metrics
            if ($this->useCompression) {
                $originalSize = strlen($serialized);
                $serialized = $this->compressData($serialized);
                $compressedSize = strlen($serialized);

                $this->compressionStats = [
                    'original_size' => $originalSize,
                    'compressed_size' => $compressedSize,
                    'compression_ratio' => $originalSize > 0 ? round(($compressedSize / $originalSize) * 100, 2) : 0,
                    'space_saved' => $originalSize - $compressedSize
                ];
            }

            // Encryption with metrics
            $encrypted = $this->encryptData($serialized);

            // Atomic write with optimized I/O
            $this->optimizedAtomicWrite($cacheFile, $encrypted);

            // Store in APCu for ultra-fast access
            if ($this->useAPCu) {
                $this->storeInAPCu($routes);
            }

            // Store integrity and metadata files
            if ($this->integrityCheck) {
                $this->storeIntegrityFile($cacheFile, $encrypted);
            }

            $this->storeMetadataFile($cacheFile, $routes);

            $this->storeOperations++;
            $this->totalStoreTime += (hrtime(true) - $startTime) / 1_000_000;

        } catch (\Throwable $e) {
            error_log("Failed to store route cache: " . $e->getMessage());
            if ($this->enableMetrics) {
                $this->recordError('store', $e->getMessage());
            }
        }
    }

    /**
     * Load routes with tiered caching strategy and comprehensive metrics
     */
    public function load(): ?array
    {
        $startTime = hrtime(true);
        $this->totalRequests++;
        $this->loadOperations++;

        try {
            // Tier 1: APCu cache (fastest)
            if ($this->useAPCu) {
                $apcuData = $this->loadFromAPCu();
                if ($apcuData !== null) {
                    $this->cacheHits++;
                    $this->recordLoadTime($startTime);
                    return $apcuData;
                }
            }

            // Tier 2: File cache with validation
            $cacheFile = $this->getCacheFile();

            if (!$this->isValidCacheFile($cacheFile)) {
                $this->cacheMisses++;
                $this->recordLoadTime($startTime);
                return null;
            }

            if ($this->isCacheExpired($cacheFile)) {
                $this->clear();
                $this->cacheMisses++;
                $this->recordLoadTime($startTime);
                return null;
            }

            // Integrity check
            if ($this->integrityCheck && !$this->verifyIntegrity($cacheFile)) {
                error_log("Cache integrity check failed, clearing cache");
                $this->clear();
                $this->cacheMisses++;
                $this->recordLoadTime($startTime);
                return null;
            }

            // Load and decrypt data
            $encrypted = file_get_contents($cacheFile);
            if ($encrypted === false) {
                $this->cacheMisses++;
                $this->recordLoadTime($startTime);
                return null;
            }

            $serialized = $this->decryptData($encrypted);
            if ($serialized === false) {
                error_log("Failed to decrypt cache data");
                $this->clear();
                $this->cacheMisses++;
                $this->recordLoadTime($startTime);
                return null;
            }

            // Decompress if needed
            if ($this->useCompression) {
                $serialized = $this->decompressData($serialized);
                if ($serialized === false) {
                    error_log("Failed to decompress cache data");
                    $this->clear();
                    $this->cacheMisses++;
                    $this->recordLoadTime($startTime);
                    return null;
                }
            }

            $cacheData = $this->optimizedUnserialize($serialized);
            if (!$this->isValidCacheData($cacheData)) {
                error_log("Invalid cache data structure");
                $this->clear();
                $this->cacheMisses++;
                $this->recordLoadTime($startTime);
                return null;
            }

            // Version and checksum verification
            if (!$this->verifyCacheData($cacheData)) {
                $this->clear();
                $this->cacheMisses++;
                $this->recordLoadTime($startTime);
                return null;
            }

            $routes = $cacheData['routes'];

            // Store in APCu for next request
            if ($this->useAPCu && $this->validateCachedRoutes($routes)) {
                $this->storeInAPCu($routes);
            }

            $this->cacheHits++;
            $this->recordLoadTime($startTime);
            return $routes;

        } catch (\Throwable $e) {
            error_log("Failed to load route cache: " . $e->getMessage());
            $this->clear();
            $this->cacheMisses++;
            $this->recordLoadTime($startTime);
            if ($this->enableMetrics) {
                $this->recordError('load', $e->getMessage());
            }
            return null;
        }
    }

    /**
     * Clear all cache layers with comprehensive cleanup
     */
    public function clear(): void
    {
        try {
            // Clear APCu cache
            if ($this->useAPCu) {
                $this->clearAPCu();
            }

            // Clear file cache
            $cacheFile = $this->getCacheFile();
            $integrityFile = $this->getIntegrityFile($cacheFile);
            $metadataFile = $this->getMetadataFile($cacheFile);

            $files = [$cacheFile, $integrityFile, $metadataFile];

            foreach ($files as $file) {
                if (file_exists($file)) {
                    $this->secureDelete($file);
                }
            }

            // Reset metrics
            if ($this->enableMetrics) {
                $this->resetMetrics();
            }

        } catch (\Throwable $e) {
            error_log("Failed to clear route cache: " . $e->getMessage());
        }
    }

    /**
     * Check cache validity with multiple validation layers
     */
    private function checkCacheValidity(): bool
    {
        try {
            // Check APCu first
            if ($this->useAPCu && $this->hasAPCuData()) {
                return true;
            }

            // Check file cache
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
     * Get comprehensive cache statistics
     */
    public function getStats(): array
    {
        $stats = [
            'cache_hits' => $this->cacheHits,
            'cache_misses' => $this->cacheMisses,
            'total_requests' => $this->totalRequests,
            'hit_ratio_percent' => $this->hitRatio,
            'cache_size_bytes' => $this->cacheSize,
            'cache_size_formatted' => $this->formatBytes($this->cacheSize),
            'cache_file_exists' => file_exists($this->getCacheFile()),
            'store_operations' => $this->storeOperations,
            'load_operations' => $this->loadOperations,
            'average_store_time_ms' => $this->storeOperations > 0 ? $this->totalStoreTime / $this->storeOperations : 0,
            'average_load_time_ms' => $this->loadOperations > 0 ? $this->totalLoadTime / $this->loadOperations : 0,
            'features' => [
                'apcu_enabled' => $this->useAPCu,
                'compression_enabled' => $this->useCompression,
                'encryption_enabled' => $this->encryptionEnabled,
                'integrity_check_enabled' => $this->integrityCheck,
                'metrics_enabled' => $this->enableMetrics,
            ],
        ];

        if ($this->useCompression && !empty($this->compressionStats)) {
            $stats['compression'] = $this->compressionStats;
        }

        if (!empty($this->encryptionStats)) {
            $stats['encryption'] = $this->encryptionStats;
        }

        return $stats;
    }

    /**
     * Prepare cache data with metadata
     */
    private function prepareCacheData(array $routes): array
    {
        return [
            'version' => self::CACHE_VERSION,
            'timestamp' => time(),
            'routes' => $routes,
            'checksum' => $this->calculateChecksum($routes),
            'php_version' => PHP_VERSION,
            'compression' => $this->useCompression,
            'route_count' => $this->countRoutes($routes),
            'metadata' => [
                'created_by' => 'Framework\\Routing\\RouteCache',
                'cache_dir' => $this->cacheDir,
                'features' => [
                    'compression' => $this->useCompression,
                    'encryption' => $this->encryptionEnabled,
                    'integrity_check' => $this->integrityCheck,
                ]
            ]
        ];
    }

    /**
     * APCu operations
     */
    private function storeInAPCu(array $routes): void
    {
        if (!$this->useAPCu) {
            return;
        }

        $key = $this->getAPCuKey();
        $success = apcu_store($key, $routes, self::CACHE_TTL);

        if (!$success && function_exists('apcu_enabled') && apcu_enabled()) {
            error_log("Failed to store routes in APCu cache");
        }
    }

    private function loadFromAPCu(): ?array
    {
        if (!$this->useAPCu) {
            return null;
        }

        $key = $this->getAPCuKey();
        $data = apcu_fetch($key);

        if ($data !== false && $this->validateCachedRoutes($data)) {
            return $data;
        }

        return null;
    }

    private function clearAPCu(): void
    {
        if (!$this->useAPCu) {
            return;
        }

        $key = $this->getAPCuKey();
        apcu_delete($key);
    }

    private function hasAPCuData(): bool
    {
        if (!$this->useAPCu) {
            return false;
        }

        return apcu_exists($this->getAPCuKey());
    }

    private function getAPCuKey(): string
    {
        return 'framework_routes_' . hash('xxh3', $this->cacheDir . self::CACHE_VERSION);
    }

    /**
     * Compression operations
     */
    private function compressData(string $data): string
    {
        if (!$this->useCompression) {
            return $data;
        }

        $compressed = gzcompress($data, $this->compressionLevel);
        if ($compressed === false) {
            throw new \RuntimeException('Failed to compress cache data');
        }

        return $compressed;
    }

    private function decompressData(string $data): string|false
    {
        if (!$this->useCompression) {
            return $data;
        }

        return gzuncompress($data);
    }

    /**
     * Encryption operations with enhanced security
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
            throw new \RuntimeException("Failed to encrypt cache data: " . openssl_error_string());
        }

        // Store encryption stats
        $this->encryptionStats = [
            'cipher' => $cipher,
            'iv_length' => strlen($iv),
            'tag_length' => strlen($tag),
            'encrypted_size' => strlen($encrypted),
            'total_size' => strlen($iv) + strlen($tag) + strlen($encrypted)
        ];

        return base64_encode($iv . $tag . $encrypted);
    }

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

        $decrypted = openssl_decrypt($encrypted, $cipher, $this->encryptionKey, OPENSSL_RAW_DATA, $iv, $tag);

        if ($decrypted === false) {
            error_log("Decryption failed: " . openssl_error_string());
        }

        return $decrypted;
    }

    /**
     * Generate or load encryption key
     */
    private function generateEncryptionKey(): string
    {
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
     * Optimized serialization using igbinary if available
     */
    private function optimizedSerialize(array $data): string
    {
        if (extension_loaded('igbinary')) {
            $result = igbinary_serialize($data);
            if ($result !== false) {
                return $result;
            }
        }

        return serialize($data);
    }

    private function optimizedUnserialize(string $data): mixed
    {
        if (extension_loaded('igbinary')) {
            // Try igbinary first
            $result = @igbinary_unserialize($data);
            if ($result !== false) {
                return $result;
            }
        }

        return unserialize($data);
    }

    /**
     * Optimized atomic write with better I/O handling
     */
    private function optimizedAtomicWrite(string $filename, string $data): void
    {
        $tempFile = $filename . '.tmp.' . uniqid() . '.' . getmypid();

        // Use file_put_contents with LOCK_EX for better performance
        $bytesWritten = file_put_contents($tempFile, $data, LOCK_EX);
        if ($bytesWritten === false) {
            throw new \RuntimeException("Failed to write cache data to temporary file");
        }

        if ($bytesWritten !== strlen($data)) {
            unlink($tempFile);
            throw new \RuntimeException("Incomplete write to cache file");
        }

        // Ensure data is written to disk
        if (function_exists('fsync')) {
            $handle = fopen($tempFile, 'r');
            if ($handle !== false) {
                fsync($handle);
                fclose($handle);
            }
        }

        if (!rename($tempFile, $filename)) {
            unlink($tempFile);
            throw new \RuntimeException("Failed to rename temporary cache file");
        }

        // Set restrictive permissions
        chmod($filename, 0600);
    }

    /**
     * Store integrity file with enhanced verification
     */
    private function storeIntegrityFile(string $cacheFile, string $data): void
    {
        $integrityFile = $this->getIntegrityFile($cacheFile);
        $hash = hash('sha256', $data);
        $timestamp = time();

        $integrityData = json_encode([
            'hash' => $hash,
            'timestamp' => $timestamp,
            'algorithm' => 'sha256',
            'file_size' => strlen($data)
        ]);

        file_put_contents($integrityFile, $integrityData, LOCK_EX);
        chmod($integrityFile, 0600);
    }

    /**
     * Store metadata file with cache information
     */
    private function storeMetadataFile(string $cacheFile, array $routes): void
    {
        $metadataFile = $this->getMetadataFile($cacheFile);

        $metadata = [
            'created_at' => time(),
            'route_count' => $this->countRoutes($routes),
            'php_version' => PHP_VERSION,
            'cache_version' => self::CACHE_VERSION,
            'features' => [
                'compression' => $this->useCompression,
                'encryption' => $this->encryptionEnabled,
                'integrity_check' => $this->integrityCheck,
            ],
            'statistics' => $this->getStats()
        ];

        file_put_contents($metadataFile, json_encode($metadata, JSON_PRETTY_PRINT), LOCK_EX);
        chmod($metadataFile, 0600);
    }

    /**
     * Verify cache integrity with enhanced checks
     */
    private function verifyIntegrity(string $cacheFile): bool
    {
        $integrityFile = $this->getIntegrityFile($cacheFile);

        if (!file_exists($integrityFile)) {
            return false;
        }

        $integrityData = file_get_contents($integrityFile);
        if ($integrityData === false) {
            return false;
        }

        $integrity = json_decode($integrityData, true);
        if ($integrity === null) {
            return false;
        }

        $cacheData = file_get_contents($cacheFile);
        if ($cacheData === false) {
            return false;
        }

        // Verify hash
        $currentHash = hash('sha256', $cacheData);
        if (!hash_equals($integrity['hash'], $currentHash)) {
            return false;
        }

        // Verify file size
        if (($integrity['file_size'] ?? 0) !== strlen($cacheData)) {
            return false;
        }

        return true;
    }

    /**
     * Verify cache data structure and content
     */
    private function verifyCacheData(array $cacheData): bool
    {
        // Version check
        if (($cacheData['version'] ?? '') !== self::CACHE_VERSION) {
            error_log("Cache version mismatch: expected " . self::CACHE_VERSION . ", got " . ($cacheData['version'] ?? 'unknown'));
            return false;
        }

        // Checksum verification
        if (!$this->verifyChecksum($cacheData)) {
            error_log("Cache checksum verification failed");
            return false;
        }

        // Route count verification
        $expectedCount = $this->countRoutes($cacheData['routes']);
        $storedCount = $cacheData['route_count'] ?? 0;
        if ($expectedCount !== $storedCount) {
            error_log("Route count mismatch: expected {$expectedCount}, got {$storedCount}");
            return false;
        }

        return true;
    }

    /**
     * Utility methods
     */
    private function countRoutes(array $routes): int
    {
        return array_sum(array_map('count', $routes));
    }

    private function getCacheFileSize(): int
    {
        $cacheFile = $this->getCacheFile();
        return file_exists($cacheFile) ? filesize($cacheFile) : 0;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }

    private function recordLoadTime(int $startTime): void
    {
        $this->totalLoadTime += (hrtime(true) - $startTime) / 1_000_000;
    }

    private function initializeMetrics(): void
    {
        if (!$this->enableMetrics) {
            return;
        }

        $this->compressionStats = [];
        $this->encryptionStats = [];
    }

    private function resetMetrics(): void
    {
        $this->cacheHits = 0;
        $this->cacheMisses = 0;
        $this->totalRequests = 0;
        $this->storeOperations = 0;
        $this->loadOperations = 0;
        $this->totalStoreTime = 0.0;
        $this->totalLoadTime = 0.0;
        $this->compressionStats = [];
        $this->encryptionStats = [];
    }

    private function recordError(string $operation, string $error): void
    {
        // Log error for debugging
        error_log("Cache {$operation} error: {$error}");
    }

    /**
     * File path methods
     */
    private function getCacheFile(): string
    {
        return $this->cacheDir . DIRECTORY_SEPARATOR . self::CACHE_FILE;
    }

    private function getIntegrityFile(string $cacheFile): string
    {
        return $cacheFile . self::INTEGRITY_SUFFIX;
    }

    private function getMetadataFile(string $cacheFile): string
    {
        return $cacheFile . self::METADATA_SUFFIX;
    }

    // Include all other required methods from previous implementation...
    // (validateAndSetCacheDir, ensureSecureCacheDirectory, validateRoutesForStorage, etc.)

    /**
     * Validate and set cache directory
     */
    private function validateAndSetCacheDir(string $cacheDir): string
    {
        if ($cacheDir === '') {
            $cacheDir = sys_get_temp_dir();
        }

        $realPath = realpath($cacheDir);
        if ($realPath === false) {
            throw new \InvalidArgumentException("Cache directory does not exist: {$cacheDir}");
        }

        $this->validateSecurePath($realPath);

        if (!is_writable($realPath)) {
            throw new \InvalidArgumentException("Cache directory is not writable: {$realPath}");
        }

        return $realPath;
    }

    private function validateSecurePath(string $path): void
    {
        if (str_contains($path, '..')) {
            throw new \InvalidArgumentException("Directory traversal detected in path: {$path}");
        }

        $dangerousPaths = ['/etc/', '/bin/', '/sbin/', '/root/', '/proc/', '/sys/'];
        foreach ($dangerousPaths as $dangerous) {
            if (str_starts_with($path, $dangerous)) {
                throw new \InvalidArgumentException("Cache directory in dangerous location: {$path}");
            }
        }
    }

    private function ensureSecureCacheDirectory(): void
    {
        $perms = fileperms($this->cacheDir);
        if ($perms !== false && ($perms & 0002) !== 0) {
            error_log("Warning: Cache directory is world-writable: {$this->cacheDir}");
        }

        $htaccessFile = $this->cacheDir . DIRECTORY_SEPARATOR . '.htaccess';
        if (!file_exists($htaccessFile)) {
            $htaccessContent = "Order deny,allow\nDeny from all\n";
            file_put_contents($htaccessFile, $htaccessContent, LOCK_EX);
        }
    }

    private function validateRoutesForStorage(array $routes): void
    {
        if (empty($routes)) {
            throw new \InvalidArgumentException("Cannot store empty routes array");
        }

        $routeCount = 0;
        foreach ($routes as $method => $methodRoutes) {
            if (!is_string($method) || !is_array($methodRoutes)) {
                throw new \InvalidArgumentException("Invalid routes structure");
            }

            foreach ($methodRoutes as $route) {
                if (!($route instanceof RouteInfo)) {
                    throw new \InvalidArgumentException("Invalid route object in cache data");
                }
                $routeCount++;
            }
        }

        if ($routeCount > 50000) {
            throw new \InvalidArgumentException("Too many routes for efficient caching: {$routeCount}");
        }
    }

    private function calculateChecksum(array $routes): string
    {
        return hash('sha256', serialize($routes) . $this->encryptionKey);
    }

    private function verifyChecksum(array $cacheData): bool
    {
        if (!isset($cacheData['checksum']) || !isset($cacheData['routes'])) {
            return false;
        }

        $expectedChecksum = $this->calculateChecksum($cacheData['routes']);
        return hash_equals($expectedChecksum, $cacheData['checksum']);
    }

    private function isValidCacheFile(string $cacheFile): bool
    {
        if (!file_exists($cacheFile) || !is_readable($cacheFile)) {
            return false;
        }

        $size = filesize($cacheFile);
        return $size !== false && $size <= self::MAX_CACHE_SIZE && $size > 0;
    }

    private function isCacheExpired(string $cacheFile): bool
    {
        $mtime = filemtime($cacheFile);
        return $mtime === false || (time() - $mtime) > self::CACHE_TTL;
    }

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

        return is_array($cacheData['routes']);
    }

    private function validateCachedRoutes(array $routes): bool
    {
        if (!is_array($routes)) {
            return false;
        }

        foreach ($routes as $method => $methodRoutes) {
            if (!is_string($method) || !is_array($methodRoutes)) {
                return false;
            }

            foreach ($methodRoutes as $route) {
                if (!($route instanceof RouteInfo)) {
                    return false;
                }

                // Basic route validation
                if (!class_exists($route->actionClass)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function secureDelete(string $filename): void
    {
        if (!file_exists($filename)) {
            return;
        }

        // Overwrite file with random data before deletion for security
        $size = filesize($filename);
        if ($size !== false && $size > 0) {
            $handle = fopen($filename, 'r+b');
            if ($handle !== false) {
                // Overwrite with random data multiple times
                for ($i = 0; $i < 3; $i++) {
                    fseek($handle, 0);
                    fwrite($handle, random_bytes($size));
                    fflush($handle);
                }
                fclose($handle);
            }
        }

        unlink($filename);
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true)) {
                throw new \RuntimeException("Failed to create cache directory: {$directory}");
            }
        }
    }

    /**
     * Advanced cache management methods
     */

    /**
     * Optimize cache by removing expired or invalid entries
     */
    public function optimize(): bool
    {
        try {
            $cacheFile = $this->getCacheFile();

            if (!file_exists($cacheFile)) {
                return true; // Nothing to optimize
            }

            // Load current cache
            $routes = $this->load();
            if ($routes === null) {
                return false;
            }

            // Validate all routes and remove invalid ones
            $validRoutes = [];
            $removedCount = 0;

            foreach ($routes as $method => $methodRoutes) {
                $validRoutes[$method] = [];

                foreach ($methodRoutes as $route) {
                    if ($this->isValidRouteForCache($route)) {
                        $validRoutes[$method][] = $route;
                    } else {
                        $removedCount++;
                    }
                }

                // Remove empty method arrays
                if (empty($validRoutes[$method])) {
                    unset($validRoutes[$method]);
                }
            }

            // Re-store optimized cache if changes were made
            if ($removedCount > 0) {
                $this->store($validRoutes);

                if ($this->enableMetrics) {
                    error_log("Cache optimization completed: removed {$removedCount} invalid routes");
                }
            }

            return true;

        } catch (\Throwable $e) {
            error_log("Cache optimization failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Validate individual route for cache storage
     */
    private function isValidRouteForCache(RouteInfo $route): bool
    {
        // Check if action class still exists
        if (!class_exists($route->actionClass)) {
            return false;
        }

        // Check if pattern is valid
        if (strlen($route->pattern) > 1000 || str_contains($route->pattern, '(?')) {
            return false;
        }

        // Check parameter count
        if (count($route->paramNames) > 20) {
            return false;
        }

        // Check subdomain validity
        if ($route->subdomain !== null) {
            if (strlen($route->subdomain) > 63 ||
                !preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?$/', $route->subdomain)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get cache metadata information
     */
    public function getMetadata(): ?array
    {
        $cacheFile = $this->getCacheFile();
        $metadataFile = $this->getMetadataFile($cacheFile);

        if (!file_exists($metadataFile)) {
            return null;
        }

        $metadata = file_get_contents($metadataFile);
        if ($metadata === false) {
            return null;
        }

        $decoded = json_decode($metadata, true);
        return $decoded ?: null;
    }

    /**
     * Check cache health and return diagnostic information
     */
    public function healthCheck(): array
    {
        $health = [
            'status' => 'healthy',
            'issues' => [],
            'recommendations' => [],
            'cache_file' => [
                'exists' => false,
                'readable' => false,
                'writable' => false,
                'size' => 0,
                'age_seconds' => 0,
            ],
            'directory' => [
                'exists' => is_dir($this->cacheDir),
                'readable' => is_readable($this->cacheDir),
                'writable' => is_writable($this->cacheDir),
                'permissions' => decoct(fileperms($this->cacheDir) & 0777),
            ],
            'features' => [
                'apcu_available' => extension_loaded('apcu') && apcu_enabled(),
                'compression_available' => extension_loaded('zlib'),
                'encryption_available' => extension_loaded('openssl'),
                'igbinary_available' => extension_loaded('igbinary'),
            ],
        ];

        $cacheFile = $this->getCacheFile();

        if (file_exists($cacheFile)) {
            $health['cache_file']['exists'] = true;
            $health['cache_file']['readable'] = is_readable($cacheFile);
            $health['cache_file']['writable'] = is_writable($cacheFile);
            $health['cache_file']['size'] = filesize($cacheFile);
            $health['cache_file']['age_seconds'] = time() - filemtime($cacheFile);

            // Check if cache is expired
            if ($this->isCacheExpired($cacheFile)) {
                $health['issues'][] = 'Cache file is expired';
                $health['recommendations'][] = 'Clear cache to force regeneration';
            }

            // Check file size
            if ($health['cache_file']['size'] > self::MAX_CACHE_SIZE * 0.9) {
                $health['issues'][] = 'Cache file size approaching limit';
                $health['recommendations'][] = 'Consider optimizing cache or increasing size limit';
            }

            // Check integrity
            if ($this->integrityCheck && !$this->verifyIntegrity($cacheFile)) {
                $health['status'] = 'corrupted';
                $health['issues'][] = 'Cache integrity check failed';
                $health['recommendations'][] = 'Clear and regenerate cache';
            }
        } else {
            $health['issues'][] = 'Cache file does not exist';
            $health['recommendations'][] = 'Cache will be generated on first use';
        }

        // Check directory permissions
        if (!$health['directory']['writable']) {
            $health['status'] = 'error';
            $health['issues'][] = 'Cache directory is not writable';
            $health['recommendations'][] = 'Fix directory permissions';
        }

        // Check for security issues
        $perms = fileperms($this->cacheDir);
        if ($perms !== false && ($perms & 0002) !== 0) {
            $health['issues'][] = 'Cache directory is world-writable (security risk)';
            $health['recommendations'][] = 'Restrict directory permissions';
        }

        // Performance recommendations
        if (!$health['features']['apcu_available'] && $this->useAPCu) {
            $health['recommendations'][] = 'Install APCu extension for better performance';
        }

        if (!$health['features']['compression_available'] && $this->useCompression) {
            $health['recommendations'][] = 'Install zlib extension for compression support';
        }

        if (!$health['features']['igbinary_available']) {
            $health['recommendations'][] = 'Install igbinary extension for faster serialization';
        }

        return $health;
    }

    /**
     * Warm up cache with predefined routes
     */
    public function warmUp(array $routes): bool
    {
        try {
            if (empty($routes)) {
                return false;
            }

            // Validate routes before storing
            $this->validateRoutesForStorage($routes);

            // Store routes
            $this->store($routes);

            // Verify storage was successful
            $loaded = $this->load();

            return $loaded !== null && $this->countRoutes($loaded) === $this->countRoutes($routes);

        } catch (\Throwable $e) {
            error_log("Cache warm-up failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Export cache data for backup or migration
     */
    public function export(): ?array
    {
        try {
            $routes = $this->load();
            if ($routes === null) {
                return null;
            }

            return [
                'version' => self::CACHE_VERSION,
                'exported_at' => time(),
                'php_version' => PHP_VERSION,
                'route_count' => $this->countRoutes($routes),
                'routes' => $routes,
                'metadata' => $this->getMetadata(),
                'statistics' => $this->getStats(),
            ];

        } catch (\Throwable $e) {
            error_log("Cache export failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Import cache data from backup
     */
    public function import(array $data): bool
    {
        try {
            // Validate import data structure
            if (!isset($data['routes']) || !is_array($data['routes'])) {
                throw new \InvalidArgumentException('Invalid import data: missing routes');
            }

            // Version compatibility check
            if (isset($data['version']) && $data['version'] !== self::CACHE_VERSION) {
                error_log("Warning: Importing cache from different version: {$data['version']} -> " . self::CACHE_VERSION);
            }

            // Validate and store routes
            $this->validateRoutesForStorage($data['routes']);
            $this->store($data['routes']);

            return true;

        } catch (\Throwable $e) {
            error_log("Cache import failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get cache size in human-readable format
     */
    public function getCacheSizeFormatted(): string
    {
        return $this->formatBytes($this->cacheSize);
    }

    /**
     * Check if cache needs optimization
     */
    public function needsOptimization(): bool
    {
        // Check if cache file is too large
        if ($this->cacheSize > self::MAX_CACHE_SIZE * 0.8) {
            return true;
        }

        // Check if hit ratio is too low
        if ($this->totalRequests > 100 && $this->hitRatio < 50.0) {
            return true;
        }

        // Check if cache is older than 24 hours
        $cacheFile = $this->getCacheFile();
        if (file_exists($cacheFile)) {
            $age = time() - filemtime($cacheFile);
            if ($age > 86400) { // 24 hours
                return true;
            }
        }

        return false;
    }

    /**
     * Get cache performance metrics
     */
    public function getPerformanceMetrics(): array
    {
        $avgStoreTime = $this->storeOperations > 0 ? $this->totalStoreTime / $this->storeOperations : 0;
        $avgLoadTime = $this->loadOperations > 0 ? $this->totalLoadTime / $this->loadOperations : 0;

        return [
            'hit_ratio' => $this->hitRatio,
            'total_requests' => $this->totalRequests,
            'cache_hits' => $this->cacheHits,
            'cache_misses' => $this->cacheMisses,
            'store_operations' => $this->storeOperations,
            'load_operations' => $this->loadOperations,
            'average_store_time_ms' => round($avgStoreTime, 3),
            'average_load_time_ms' => round($avgLoadTime, 3),
            'cache_efficiency' => $this->calculateCacheEfficiency(),
        ];
    }

    /**
     * Calculate cache efficiency score
     */
    private function calculateCacheEfficiency(): float
    {
        if ($this->totalRequests === 0) {
            return 0.0;
        }

        $hitRatioScore = $this->hitRatio / 100;

        // Performance score based on load times
        $avgLoadTime = $this->loadOperations > 0 ? $this->totalLoadTime / $this->loadOperations : 0;
        $performanceScore = $avgLoadTime < 1.0 ? 1.0 : (1.0 / $avgLoadTime);

        // Size efficiency (smaller is better, up to a point)
        $sizeScore = $this->cacheSize > 0 ? min(1.0, 1048576 / $this->cacheSize) : 1.0;

        // Weighted average
        return round(($hitRatioScore * 0.5 + $performanceScore * 0.3 + $sizeScore * 0.2) * 100, 2);
    }

    /**
     * Reset all performance counters
     */
    public function resetCounters(): void
    {
        $this->resetMetrics();
    }

    /**
     * Magic method for debugging
     */
    public function __debugInfo(): array
    {
        return [
            'cache_dir' => $this->cacheDir,
            'cache_size' => $this->getCacheSizeFormatted(),
            'is_valid' => $this->isValid,
            'hit_ratio' => $this->hitRatio . '%',
            'features' => [
                'compression' => $this->compressionEnabled,
                'encryption' => $this->encryptionEnabled,
                'apcu' => $this->useAPCu,
                'integrity_check' => $this->integrityCheck,
            ],
            'performance' => $this->getPerformanceMetrics(),
        ];
    }
}