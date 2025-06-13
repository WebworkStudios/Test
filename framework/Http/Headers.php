<?php

declare(strict_types=1);

namespace Framework\Http;

/**
 * HTTP Headers collection with case-insensitive access
 */
final readonly class Headers
{
    /**
     * @param array<string, string> $headers Normalized headers (lowercase keys)
     */
    public function __construct(
        private array $headers = []
    )
    {
    }

    /**
     * Create from $_SERVER superglobal
     */
    public static function fromServer(array $server): self
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerName = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$headerName] = $value;
            }
        }

        // Add important non-HTTP_ headers
        if (isset($server['CONTENT_TYPE'])) {
            $headers['content-type'] = $server['CONTENT_TYPE'];
        }

        if (isset($server['CONTENT_LENGTH'])) {
            $headers['content-length'] = $server['CONTENT_LENGTH'];
        }

        if (isset($server['PHP_AUTH_USER'])) {
            $user = $server['PHP_AUTH_USER'];
            $pass = $server['PHP_AUTH_PW'] ?? '';
            $headers['authorization'] = 'Basic ' . base64_encode($user . ':' . $pass);
        }

        return new self($headers);
    }

    /**
     * Create from array with normalization
     */
    public static function fromArray(array $headers): self
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = self::sanitizeHeaderValue((string)$value);
        }

        return new self($normalized);
    }

    public function get(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    public function all(): array
    {
        return $this->headers;
    }

    /**
     * Check if client expects JSON response
     */
    public function expectsJson(): bool
    {
        $accept = $this->get('accept') ?? '';
        return str_contains($accept, 'application/json');
    }

    /**
     * Check if request is AJAX
     */
    public function isAjax(): bool
    {
        return strtolower($this->get('x-requested-with') ?? '') === 'xmlhttprequest';
    }

    /**
     * Get forwarded IP addresses
     */
    public function forwardedFor(): array
    {
        $header = $this->get('x-forwarded-for');
        if (!$header) {
            return [];
        }

        return array_map('trim', explode(',', $header));
    }

    private static function sanitizeHeaderValue(string $value): string
    {
        // Remove line breaks and control characters
        return preg_replace('/[\r\n\t]/', '', $value);
    }
}