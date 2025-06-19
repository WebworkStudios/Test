<?php

declare(strict_types=1);

namespace framework\Http\Cache;

use DateTimeImmutable;

/**
 * HTTP Caching utilities - Static helper class
 */
final class CacheHeaders
{
    /**
     * Private constructor - this is a static utility class
     */
    private function __construct()
    {
    }

    /**
     * Generate cache headers for response
     */
    public static function forResponse(
        ?string            $etag = null,
        ?DateTimeImmutable $lastModified = null,
        int                $maxAge = 0,
        bool               $public = true,
        bool               $mustRevalidate = false
    ): array
    {
        $headers = [];

        if ($etag) {
            $headers['ETag'] = $etag;
        }

        if ($lastModified) {
            $headers['Last-Modified'] = $lastModified->format('D, d M Y H:i:s T');
        }

        $cacheControl = [];
        $cacheControl[] = $public ? 'public' : 'private';

        if ($maxAge > 0) {
            $cacheControl[] = "max-age={$maxAge}";
        } else {
            $cacheControl[] = 'no-cache';
        }

        if ($mustRevalidate) {
            $cacheControl[] = 'must-revalidate';
        }

        $headers['Cache-Control'] = implode(', ', $cacheControl);

        return $headers;
    }
}