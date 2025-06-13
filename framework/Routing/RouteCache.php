<?php

declare(strict_types=1);

namespace Framework\Routing;

/**
 * Optimized Route Cache with PHP 8.4 features
 */
final class RouteCache
{
    private const string CACHE_FILE = 'routes.cache';
    private const int CACHE_TTL = 3600; // 1 hour
    private const int MAX_CACHE_SIZE = 10485760; // 10MB
    private const string CACHE_VERSION = '2.0';
    private const string INTEGRITY_SUFFIX = '.integrity';

    // PHP 8.4 Property Hooks for computed properties
    public bool $isValid {
        get => $this->checkCacheValidity();
    }

    public int $cacheSize {
        get => $this->getCacheFileSize();
    }

    public string $cacheSizeFormatted {
        get => $this->formatBytes($this->cacheSize);
    }

    public float $hitRatio {
        get => $this->totalRequests > 0 ? ($this->cacheHits / $this->totalRequests) * 100 : 0.0;
    }

    public bool $compressionEnabled {
        get => $this->useCompression && extension_loaded('zlib');
    }

    public bool $integrityCheckEnabled {
        get => $this->integrityCheck;
    }

    // Performance tracking
    private int $cacheHits = 0;
    private int $cacheMisses = 0;
    private int $totalRequests = 0;
    private float $totalStoreTime = 0.0;
    private float $totalLoadTime = 0.0;
    private int $storeOperations = 0;
    private int $loadOperations = 0;

    public function __construct(
        private readonly string $cacheDir = '',
        private readonly bool $useCompression = true,
        private readonly int $compressionLevel = 6,
        private readonly bool $integrityCheck = true,
        private readonly bool $strictMode = true
    ) {
        $this->cacheDir = $this->validateAndSetCacheDir($cacheDir);
        $this->ensureSecureCacheDirectory();
    }

    /**
     * Store routes in cache
     */
    public function store(array $routes): void
    {
        $startTime = hrtime(true);

        try {
            $this->validateRoutesForStorage($routes);

            $cacheFile = $this->getCacheFile();
            $this->ensureDirectoryExists(dirname($cacheFile));

            $cacheData = $this->prepareCacheData($routes);
            $serialized = serialize($cacheData);

            // Size validation
            if (strlen($serialized) > self::MAX_CACHE_SIZE) {
                throw new \RuntimeException('Cache data exceeds maximum size limit');
            }

            // Compression
            if ($this->compressionEnabled) {
                $compressed = gzcompress($serialized, $this->compressionLevel);
                if ($compressed === false) {
                    throw new \RuntimeException('Failed to compress cache data');
                }
                $serialized = $compressed;
            }

            // Atomic write
            $this->atomicWrite($cacheFile, $serialized);

            // Store integrity file
            if ($this->integrityCheck) {
                $this->storeIntegrityFile($cacheFile, $serialized);
            }

            $this->storeOperations++;
            $this->totalStoreTime += (hrtime(true) - $startTime) / 1_000_000;

        } catch (\Throwable $e) {
            if ($this->strictMode) {
                throw $e;
            }
            error_log("Failed to store route cache: " . $e->getMessage());
        }
    }

    /**
     * Load routes from cache
     */
    public function load(): ?array
    {
        $startTime = hrtime(true);
        $this->totalRequests++;
        $this->loadOperations++;

        try {
            $cacheFile = $this->getCacheFile();

            if (!$this->isValidCacheFile($cacheFile)) {
                $this->cacheMisses++;
                return null;
            }

            if ($this->isCacheExpired($cacheFile)) {
                $this->clear();
                $this->cacheMisses++;
                return null;
            }

            // Integrity check
            if ($this->integrityCheck && !$this->verifyIntegrity($cacheFile)) {
                if ($this->strictMode) {
                    error_log("Cache integrity check failed, clearing cache");
                }
                $this->clear();
                $this->cacheMisses++;
                return null;
            }

            // Load data
            $data = file_get_contents($cacheFile);
            if ($data === false) {
                $this->cacheMisses++;
                return null;
            }

            // Decompress if needed
            if ($this->compressionEnabled) {
                $decompressed = gzuncompress($data);
                if ($decompressed === false) {
                    if ($this->strictMode) {
                        error_log("Failed to decompress cache data");
                    }
                    $this->clear();
                    $this->cacheMisses++;
                    return null;
                }
                $data = $decompressed;
            }

            $cacheData = unserialize($data);
            if (!$this->isValidCacheData($cacheData)) {
                if ($this->strictMode) {
                    error_log("Invalid cache data structure");
                }
                $this->clear();
                $this->cacheMisses++;
                return null;
            }

            // Version and checksum verification
            if (!$this->verifyCacheData($cacheData)) {
                $this->clear();
                $this->cacheMisses++;
                return null;
            }

            $this->cacheHits++;
            return $cacheData['routes'];

        } catch (\Throwable $e) {
            if ($this->strictMode) {
                error_log("Failed to load route cache: " . $e->getMessage());
            }
            $this->clear();
            $this->cacheMisses++;
            return null;
        } finally {
            $this->totalLoadTime += (hrtime(true) - $startTime) / 1_000_000;
        }
    }

    /**
     * Clear cache
     */
    public function clear(): void
    {
        try {
            $cacheFile = $this->getCacheFile();
            $integrityFile = $this->getIntegrityFile($cacheFile);

            $files = [$cacheFile, $integrityFile];
            foreach ($files as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }

            $this->resetMetrics();

        } catch (\Throwable $e) {
            if ($this->strictMode) {
                error_log("Failed to clear route cache: " . $e->getMessage());
            }
        }
    }

    /**
     * Check if cache is valid
     */
    private function checkCacheValidity(): bool
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

        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get cache file size
     */
    private function getCacheFileSize(): int
    {
        $cacheFile = $this->getCacheFile();
        return file_exists($cacheFile) ? filesize($cacheFile) : 0;
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
            'compression' => $this->compressionEnabled,
            'route_count' => $this->countRoutes($routes),
        ];
    }

    /**
     * Atomic file write
     */
    private function atomicWrite(string $filename, string $data): void
    {
        $tempFile = $filename . '.tmp.' . uniqid() . '.' . getmypid();

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

        $integrityData = json_encode([
            'hash' => $hash,
            'timestamp' => time(),
            'algorithm' => 'sha256',
            'file_size' => strlen($data)
        ]);

        file_put_contents($integrityFile, $integrityData, LOCK_EX);
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
     * Verify cache data structure
     */
    private function verifyCacheData(array $cacheData): bool
    {
        // Version check
        if (($cacheData['version'] ?? '') !== self::CACHE_VERSION) {
            return false;
        }

        // Checksum verification
        if (!$this->verifyChecksum($cacheData)) {
            return false;
        }

        return true;
    }

    /**
     * Calculate checksum for routes
     */
    private function calculateChecksum(array $routes): string
    {
        return hash('sha256', serialize($routes));
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
     * Count total routes
     */
    private function countRoutes(array $routes): int
    {
        return array_sum(array_map('count', $routes));
    }

    /**
     * Check if cache file is valid
     */
    private function isValidCacheFile(string $cacheFile): bool
    {
        if (!file_exists($cacheFile) || !is_readable($cacheFile)) {
            return false;
        }

        $size = filesize($cacheFile);
        return $size !== false && $size <= self::MAX_CACHE_SIZE && $size > 0;
    }

    /**
     * Check if cache is expired
     */
    private function isCacheExpired(string $cacheFile): bool
    {
        $mtime = filemtime($cacheFile);
        return $mtime === false || (time() - $mtime) > self::CACHE_TTL;
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

        return is_array($cacheData['routes']);
    }

    /**
     * Validate routes for storage
     */
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

        if ($routeCount > 10000) {
            throw new \InvalidArgumentException("Too many routes for efficient caching: {$routeCount}");
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

    /**
     * Validate secure path
     */
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

    /**
     * Ensure secure cache directory
     */
    private function ensureSecureCacheDirectory(): void
    {
        // Check permissions
        $perms = fileperms($this->cacheDir);
        if ($perms !== false && ($perms & 0002) !== 0) {
            if ($this->strictMode) {
                error_log("Warning: Cache directory is world-writable: {$this->cacheDir}");
            }
        }

        // Create .htaccess file for web security
        $htaccessFile = $this->cacheDir . DIRECTORY_SEPARATOR . '.htaccess';
        if (!file_exists($htaccessFile)) {
            $htaccessContent = "Order deny,allow\nDeny from all\n";
            file_put_contents($htaccessFile, $htaccessContent, LOCK_EX);
        }
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
     * Reset performance metrics
     */
    private function resetMetrics(): void
    {
        $this->cacheHits = 0;
        $this->cacheMisses = 0;
        $this->totalRequests = 0;
        $this->storeOperations = 0;
        $this->loadOperations = 0;
        $this->totalStoreTime = 0.0;
        $this->totalLoadTime = 0.0;
    }

    /**
     * Format bytes to human readable
     */
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

    /**
     * Get comprehensive statistics
     */
    public function getStats(): array
    {
        return [
            'cache_hits' => $this->cacheHits,
            'cache_misses' => $this->cacheMisses,
            'total_requests' => $this->totalRequests,
            'hit_ratio_percent' => $this->hitRatio,
            'cache_size_bytes' => $this->cacheSize,
            'cache_size_formatted' => $this->cacheSizeFormatted,
            'cache_file_exists' => file_exists($this->getCacheFile()),
            'is_valid' => $this->isValid,
            'store_operations' => $this->storeOperations,
            'load_operations' => $this->loadOperations,
            'average_store_time_ms' => $this->storeOperations > 0
                ? $this->totalStoreTime / $this->storeOperations
                : 0,
            'average_load_time_ms' => $this->loadOperations > 0
                ? $this->totalLoadTime / $this->loadOperations
                : 0,
            'features' => [
                'compression_enabled' => $this->compressionEnabled,
                'integrity_check_enabled' => $this->integrityCheckEnabled,
                'strict_mode' => $this->strictMode,
            ],
        ];
    }

    /**
     * Get performance metrics
     */
    public function getPerformanceMetrics(): array
    {
        return [
            'hit_ratio' => $this->hitRatio,
            'total_requests' => $this->totalRequests,
            'cache_hits' => $this->cacheHits,
            'cache_misses' => $this->cacheMisses,
            'average_store_time_ms' => $this->storeOperations > 0
                ? round($this->totalStoreTime / $this->storeOperations, 3)
                : 0,
            'average_load_time_ms' => $this->loadOperations > 0
                ? round($this->totalLoadTime / $this->loadOperations, 3)
                : 0,
        ];
    }

    /**
     * Warm up cache with routes
     */
    public function warmUp(array $routes): bool
    {
        try {
            $this->store($routes);
            $loaded = $this->load();
            return $loaded !== null && $this->countRoutes($loaded) === $this->countRoutes($routes);
        } catch (\Throwable $e) {
            if ($this->strictMode) {
                error_log("Cache warm-up failed: " . $e->getMessage());
            }
            return false;
        }
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
     * Health check
     */
    public function healthCheck(): array
    {
        $health = [
            'status' => 'healthy',
            'issues' => [],
            'recommendations' => [],
        ];

        $cacheFile = $this->getCacheFile();

        // Check cache file
        if (file_exists($cacheFile)) {
            if ($this->isCacheExpired($cacheFile)) {
                $health['issues'][] = 'Cache file is expired';
                $health['recommendations'][] = 'Clear cache to force regeneration';
            }

            if ($this->cacheSize > self::MAX_CACHE_SIZE * 0.9) {
                $health['issues'][] = 'Cache file size approaching limit';
                $health['recommendations'][] = 'Consider optimizing cache';
            }

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
        if (!is_writable($this->cacheDir)) {
            $health['status'] = 'error';
            $health['issues'][] = 'Cache directory is not writable';
            $health['recommendations'][] = 'Fix directory permissions';
        }

        return $health;
    }

    /**
     * Magic method for debugging
     */
    public function __debugInfo(): array
    {
        return [
            'cache_dir' => $this->cacheDir,
            'cache_size' => $this->cacheSizeFormatted,
            'is_valid' => $this->isValid,
            'hit_ratio' => round($this->hitRatio, 2) . '%',
            'features' => [
                'compression' => $this->compressionEnabled,
                'integrity_check' => $this->integrityCheckEnabled,
                'strict_mode' => $this->strictMode,
            ],
            'performance' => $this->getPerformanceMetrics(),
        ];
    }
}