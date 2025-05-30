<?php


declare(strict_types=1);

namespace Framework\Routing;

/**
 * Route caching for improved performance
 */
final class RouteCache
{
    private const string CACHE_FILE = 'framework_routes.cache';
    private const int CACHE_TTL = 3600; // 1 hour

    public function __construct(
        private readonly string $cacheDir = ''
    )
    {
    }

    /**
     * Store compiled routes in cache
     */
    public function store(array $routes): void
    {
        $cacheFile = $this->getCacheFile();
        $cacheData = [
            'timestamp' => time(),
            'routes' => $routes
        ];

        $serialized = serialize($cacheData);

        // Atomic write to prevent corruption
        $tempFile = $cacheFile . '.tmp';
        if (file_put_contents($tempFile, $serialized, LOCK_EX) !== false) {
            rename($tempFile, $cacheFile);
        }
    }

    /**
     * Load cached routes if valid
     */
    public function load(): ?array
    {
        $cacheFile = $this->getCacheFile();

        if (!file_exists($cacheFile)) {
            return null;
        }

        // Check if cache is expired
        if (time() - filemtime($cacheFile) > self::CACHE_TTL) {
            $this->clear();
            return null;
        }

        $content = file_get_contents($cacheFile);
        if ($content === false) {
            return null;
        }

        $cacheData = unserialize($content);
        if (!is_array($cacheData) || !isset($cacheData['routes'])) {
            return null;
        }

        return $cacheData['routes'];
    }

    /**
     * Clear cache
     */
    public function clear(): void
    {
        $cacheFile = $this->getCacheFile();
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
    }

    /**
     * Check if cache exists and is valid
     */
    public function isValid(): bool
    {
        $cacheFile = $this->getCacheFile();

        if (!file_exists($cacheFile)) {
            return false;
        }

        return time() - filemtime($cacheFile) <= self::CACHE_TTL;
    }

    /**
     * Get cache file path
     */
    private function getCacheFile(): string
    {
        $cacheDir = $this->cacheDir ?: sys_get_temp_dir();
        return $cacheDir . DIRECTORY_SEPARATOR . self::CACHE_FILE;
    }
}