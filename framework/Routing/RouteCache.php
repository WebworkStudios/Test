<?php

declare(strict_types=1);

namespace Framework\Routing;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * ✅ OPTIMIZED: Lightweight Route Cache with faster operations
 */
final class RouteCache
{
    private const string CACHE_FILE = 'routes.cache';
    private const int CACHE_TTL = 3600; // 1 hour
    private const int MAX_CACHE_SIZE = 5242880; // 5MB (reduced from 10MB)
    private const string CACHE_VERSION = '2.1'; // Updated version
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

    private readonly string $cacheDir;

    // ✅ OPTIMIZED: Reduced tracking overhead
    private int $cacheHits = 0;
    private int $cacheMisses = 0;
    private int $totalRequests = 0;

    public function __construct(
        string                $cacheDir = '',
        private readonly bool $useCompression = true,
        private readonly int  $compressionLevel = 6,
        private readonly bool $integrityCheck = true,
        private readonly bool $strictMode = false // ✅ Default false
    )
    {
        $this->cacheDir = $this->validateAndSetCacheDir($cacheDir);
        $this->ensureSecureCacheDirectory();
    }

    /**
     * ✅ OPTIMIZED: Streamlined cache directory setup
     */
    private function validateAndSetCacheDir(string $cacheDir): string
    {
        if ($cacheDir === '') {
            $cacheDir = sys_get_temp_dir() . '/framework_routes';
        }

        // Create directory if it doesn't exist
        if (!is_dir($cacheDir)) {
            if (!mkdir($cacheDir, 0755, true)) {
                throw new InvalidArgumentException("Cannot create cache directory: {$cacheDir}");
            }
        }

        $realPath = realpath($cacheDir);
        if ($realPath === false) {
            throw new InvalidArgumentException("Invalid cache directory: {$cacheDir}");
        }

        if (!is_writable($realPath)) {
            throw new InvalidArgumentException("Cache directory not writable: {$realPath}");
        }

        return $realPath;
    }

    /**
     * ✅ OPTIMIZED: Minimal security setup
     */
    private function ensureSecureCacheDirectory(): void
    {
        // Create .htaccess for web security (only if needed)
        $htaccessFile = $this->cacheDir . DIRECTORY_SEPARATOR . '.htaccess';
        if (!file_exists($htaccessFile)) {
            file_put_contents($htaccessFile, "Deny from all\n", LOCK_EX);
        }
    }

    /**
     * ✅ OPTIMIZED: Essential statistics only
     */
    public function getStats(): array
    {
        return [
            'cache_hits' => $this->cacheHits,
            'cache_misses' => $this->cacheMisses,
            'hit_ratio_percent' => round($this->hitRatio, 1),
            'cache_size_bytes' => $this->cacheSize,
            'cache_size_formatted' => $this->cacheSizeFormatted,
            'is_valid' => $this->isValid,
            'compression_enabled' => $this->compressionEnabled,
        ];
    }

    /**
     * ✅ OPTIMIZED: Fast warm-up
     */
    public function warmUp(array $routes): bool
    {
        try {
            $this->store($routes);
            return $this->load() !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * ✅ OPTIMIZED: Streamlined store operation
     */
    public function store(array $routes): void
    {
        try {
            $this->validateRoutesForStorage($routes);

            $cacheData = [
                'version' => self::CACHE_VERSION,
                'timestamp' => time(),
                'routes' => $routes,
                'route_count' => $this->countRoutes($routes),
            ];

            $serialized = serialize($cacheData);

            // Size check
            if (strlen($serialized) > self::MAX_CACHE_SIZE) {
                throw new RuntimeException('Cache data too large');
            }

            // Compression
            if ($this->compressionEnabled) {
                $compressed = gzcompress($serialized, $this->compressionLevel);
                if ($compressed !== false) {
                    $serialized = $compressed;
                }
            }

            // Atomic write
            $this->atomicWrite($this->getCacheFile(), $serialized);

            // Store integrity if enabled
            if ($this->integrityCheck) {
                $this->storeIntegrityFile($serialized);
            }

        } catch (Throwable $e) {
            if ($this->strictMode) {
                throw $e;
            }
            error_log("Route cache store failed: " . $e->getMessage());
        }
    }

    /**
     * ✅ OPTIMIZED: Basic route validation
     */
    private function validateRoutesForStorage(array $routes): void
    {
        if (empty($routes)) {
            throw new InvalidArgumentException("Cannot store empty routes");
        }

        $routeCount = $this->countRoutes($routes);
        if ($routeCount > 5000) { // Reasonable limit
            throw new InvalidArgumentException("Too many routes: {$routeCount}");
        }
    }

    /**
     * Count total routes
     */
    private function countRoutes(array $routes): int
    {
        return array_sum(array_map('count', $routes));
    }

    /**
     * ✅ OPTIMIZED: Atomic file writing
     */
    private function atomicWrite(string $filename, string $data): void
    {
        $tempFile = $filename . '.' . uniqid() . '.tmp';

        if (file_put_contents($tempFile, $data, LOCK_EX) === false) {
            throw new RuntimeException("Failed to write cache");
        }

        if (!rename($tempFile, $filename)) {
            unlink($tempFile);
            throw new RuntimeException("Failed to finalize cache");
        }

        chmod($filename, 0644);
    }

    /**
     * ✅ OPTIMIZED: Simple integrity storage
     */
    private function storeIntegrityFile(string $data): void
    {
        $integrityFile = $this->getCacheFile() . self::INTEGRITY_SUFFIX;
        $hash = hash('sha256', $data);

        file_put_contents($integrityFile, json_encode([
            'hash' => $hash,
            'timestamp' => time(),
            'size' => strlen($data)
        ]), LOCK_EX);
    }

    /**
     * ✅ OPTIMIZED: Fast load operation
     */
    public function load(): ?array
    {
        $this->totalRequests++;

        try {
            $cacheFile = $this->getCacheFile();

            if (!$this->isValidCacheFile($cacheFile)) {
                $this->cacheMisses++;
                return null;
            }

            // Integrity check if enabled
            if ($this->integrityCheck && !$this->verifyIntegrity($cacheFile)) {
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
                if ($decompressed !== false) {
                    $data = $decompressed;
                }
            }

            $cacheData = unserialize($data);
            if (!$this->isValidCacheData($cacheData)) {
                $this->clear();
                $this->cacheMisses++;
                return null;
            }

            $this->cacheHits++;
            return $cacheData['routes'];

        } catch (Throwable $e) {
            if ($this->strictMode) {
                error_log("Route cache load failed: " . $e->getMessage());
            }
            $this->cacheMisses++;
            return null;
        }
    }

    /**
     * ✅ OPTIMIZED: Fast cache file validation
     */
    private function isValidCacheFile(string $cacheFile): bool
    {
        if (!file_exists($cacheFile) || !is_readable($cacheFile)) {
            return false;
        }

        // Check age
        $mtime = filemtime($cacheFile);
        if ($mtime === false || (time() - $mtime) > self::CACHE_TTL) {
            return false;
        }

        // Check size
        $size = filesize($cacheFile);
        return $size !== false && $size > 0 && $size <= self::MAX_CACHE_SIZE;
    }

    /**
     * ✅ OPTIMIZED: Basic integrity verification
     */
    private function verifyIntegrity(string $cacheFile): bool
    {
        $integrityFile = $cacheFile . self::INTEGRITY_SUFFIX;

        if (!file_exists($integrityFile)) {
            return false;
        }

        try {
            $integrity = json_decode(file_get_contents($integrityFile), true);
            $cacheData = file_get_contents($cacheFile);

            if (!$integrity || !$cacheData) {
                return false;
            }

            return hash_equals($integrity['hash'], hash('sha256', $cacheData));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * ✅ OPTIMIZED: Basic cache data validation
     */
    private function isValidCacheData(mixed $cacheData): bool
    {
        return is_array($cacheData) &&
            isset($cacheData['version'], $cacheData['routes']) &&
            $cacheData['version'] === self::CACHE_VERSION &&
            is_array($cacheData['routes']);
    }

    /**
     * Clear cache files
     */
    public function clear(): void
    {
        try {
            $cacheFile = $this->getCacheFile();
            $integrityFile = $cacheFile . self::INTEGRITY_SUFFIX;

            foreach ([$cacheFile, $integrityFile] as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }

            $this->resetMetrics();
        } catch (Throwable $e) {
            if ($this->strictMode) {
                error_log("Cache clear failed: " . $e->getMessage());
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
    }

    /**
     * ✅ OPTIMIZED: Simple health check
     */
    public function healthCheck(): array
    {
        $health = [
            'status' => 'healthy',
            'issues' => []
        ];

        $cacheFile = $this->getCacheFile();

        if (!is_writable($this->cacheDir)) {
            $health['status'] = 'error';
            $health['issues'][] = 'Cache directory not writable';
        }

        if (file_exists($cacheFile)) {
            if (!$this->isValidCacheFile($cacheFile)) {
                $health['issues'][] = 'Cache file invalid or expired';
            }

            if ($this->cacheSize > self::MAX_CACHE_SIZE * 0.9) {
                $health['issues'][] = 'Cache size approaching limit';
            }
        }

        return $health;
    }

    /**
     * Get cache file path
     */
    private function getCacheFile(): string
    {
        return $this->cacheDir . DIRECTORY_SEPARATOR . self::CACHE_FILE;
    }

    /**
     * Check cache validity
     */
    private function checkCacheValidity(): bool
    {
        try {
            return $this->isValidCacheFile($this->getCacheFile());
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Get cache file size
     */
    private function getCacheFileSize(): int
    {
        $cacheFile = $this->getCacheFile();
        return file_exists($cacheFile) ? (filesize($cacheFile) ?: 0) : 0;
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
     * Debug information
     */
    public function __debugInfo(): array
    {
        return [
            'cache_dir' => $this->cacheDir,
            'cache_size' => $this->cacheSizeFormatted,
            'is_valid' => $this->isValid,
            'hit_ratio' => round($this->hitRatio, 1) . '%',
            'compression' => $this->compressionEnabled,
            'integrity_check' => $this->integrityCheck
        ];
    }
}