<?php


declare(strict_types=1);

namespace framework\Http\Cache;

use Framework\Http\Headers;

/**
 * HTTP Caching utilities
 */
final readonly class CacheHeaders
{
    public function __construct(
        private Headers $headers
    )
    {
    }

    /**
     * Generate cache headers for response
     */
    public static function forResponse(
        ?string             $etag = null,
        ?\DateTimeImmutable $lastModified = null,
        int                 $maxAge = 0,
        bool                $public = true,
        bool                $mustRevalidate = false
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

    /**
     * Check if resource is fresh (not modified)
     */
    public function isFresh(string $etag, \DateTimeImmutable $lastModified): bool
    {
        // Check ETag first (stronger validator)
        $clientEtag = $this->getIfNoneMatch();
        if ($clientEtag && $clientEtag === $etag) {
            return true;
        }

        // Check Last-Modified
        $clientLastModified = $this->getIfModifiedSince();
        if ($clientLastModified && $clientLastModified >= $lastModified) {
            return true;
        }

        return false;
    }

    /**
     * Check if request has If-None-Match header (ETag validation)
     */
    public function getIfNoneMatch(): ?string
    {
        return $this->headers->get('if-none-match');
    }

    /**
     * Check if request has If-Modified-Since header
     */
    public function getIfModifiedSince(): ?\DateTimeImmutable
    {
        $header = $this->headers->get('if-modified-since');
        if (!$header) {
            return null;
        }

        try {
            return new \DateTimeImmutable($header);
        } catch (\DateMalformedStringException) {
            return null;
        }
    }
}