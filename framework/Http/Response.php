<?php

declare(strict_types=1);

namespace Framework\Http;

use DateTimeInterface;
use framework\Http\Cache\Cookie;
use InvalidArgumentException;
use RuntimeException;

/**
 * HTTP Response representation with PHP 8.4 enhancements
 */
final class Response
{
    // HTTP Status Code Constants
    public const int STATUS_OK = 200;
    public const int STATUS_CREATED = 201;
    public const int STATUS_ACCEPTED = 202;
    public const int STATUS_NO_CONTENT = 204;
    public const int STATUS_MOVED_PERMANENTLY = 301;
    public const int STATUS_FOUND = 302;
    public const int STATUS_NOT_MODIFIED = 304;
    public const int STATUS_BAD_REQUEST = 400;
    public const int STATUS_UNAUTHORIZED = 401;
    public const int STATUS_FORBIDDEN = 403;
    public const int STATUS_NOT_FOUND = 404;
    public const int STATUS_METHOD_NOT_ALLOWED = 405;
    public const int STATUS_CONFLICT = 409;
    public const int STATUS_UNPROCESSABLE_ENTITY = 422;
    public const int STATUS_INTERNAL_SERVER_ERROR = 500;
    public const int STATUS_NOT_IMPLEMENTED = 501;
    public const int STATUS_BAD_GATEWAY = 502;
    public const int STATUS_SERVICE_UNAVAILABLE = 503;

    // Property Hooks for better API
    public private(set) string $body {
        set(string $value) {
            $this->body = $value;
        }
    }

    public private(set) int $status {
        set(int $value) {
            $this->validateStatus($value);
            $this->status = $value;
        }
    }

    private array $cookies = [];

    /**
     * @param string $body Response body
     * @param int $status HTTP status code
     * @param Headers $headers HTTP headers
     */
    public function __construct(
        string          $body = '',
        int             $status = self::STATUS_OK,
        private Headers $headers = new Headers()
    )
    {
        $this->validateStatus($status);
        $this->body = $body;
        $this->status = $status;
    }

    /**
     * Validate HTTP status code using match expression
     */
    private function validateStatus(int $status): void
    {
        match (true) {
            $status >= 100 && $status < 600 => null,
            default => throw new InvalidArgumentException("Invalid HTTP status code: {$status}")
        };
    }

    /**
     * Create HTML response
     */
    public static function html(string $html, int $status = self::STATUS_OK): self
    {
        return new self(
            $html,
            $status,
            Headers::fromArray(['Content-Type' => 'text/html; charset=utf-8'])
        );
    }

    /**
     * Create plain text response
     */
    public static function text(string $text, int $status = self::STATUS_OK): self
    {
        return new self(
            $text,
            $status,
            Headers::fromArray(['Content-Type' => 'text/plain; charset=utf-8'])
        );
    }

    /**
     * Create successful creation response
     */
    public static function created(array|object|string|null $data = null): self
    {
        return match ($data) {
            null => new self('', self::STATUS_CREATED),
            default => self::json($data, self::STATUS_CREATED)
        };
    }

    /**
     * Create JSON response with flexible data types and size limit
     */
    public static function json(array|object|string $data, int $status = self::STATUS_OK, int $maxSize = 1048576): self
    {
        $jsonData = match (true) {
            is_string($data) => $data,
            default => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
        };

        if (strlen($jsonData) > $maxSize) {
            throw new InvalidArgumentException("JSON response too large: " . strlen($jsonData) . " bytes (max: {$maxSize})");
        }

        return new self(
            $jsonData,
            $status,
            Headers::fromArray(['Content-Type' => 'application/json; charset=utf-8'])
        );
    }

    /**
     * Create not found response
     */
    public static function notFound(string $message = 'Not Found'): self
    {
        return self::json(
            ['error' => $message],
            self::STATUS_NOT_FOUND
        );
    }

    /**
     * Create redirect response
     */
    public static function redirect(string $url, int $status = self::STATUS_FOUND): self
    {
        // Validate redirect status codes
        if (!in_array($status, [301, 302, 303, 307, 308], true)) {
            throw new InvalidArgumentException("Invalid redirect status code: {$status}");
        }

        return new self(
            '',
            $status,
            Headers::fromArray(['Location' => $url])
        );
    }

    /**
     * Create no content response
     */
    public static function noContent(): self
    {
        return new self('', self::STATUS_NO_CONTENT);
    }

    /**
     * Create not modified response (for caching)
     */
    public static function notModified(): self
    {
        return new self('', self::STATUS_NOT_MODIFIED);
    }

    /**
     * Create bad request response
     */
    public static function badRequest(string $message = 'Bad Request'): self
    {
        return self::json(
            ['error' => $message],
            self::STATUS_BAD_REQUEST
        );
    }

    /**
     * Create unauthorized response
     */
    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return self::json(
            ['error' => $message],
            self::STATUS_UNAUTHORIZED
        );
    }

    /**
     * Create forbidden response
     */
    public static function forbidden(string $message = 'Forbidden'): self
    {
        return self::json(
            ['error' => $message],
            self::STATUS_FORBIDDEN
        );
    }

    /**
     * Create internal server error response
     */
    public static function serverError(string $message = 'Internal Server Error'): self
    {
        return self::json(
            ['error' => $message],
            self::STATUS_INTERNAL_SERVER_ERROR
        );
    }

    /**
     * Set status code with validation
     */
    public function withStatus(int $status): self
    {
        $response = new self($this->body, $status, $this->headers);
        $response->cookies = $this->cookies;
        return $response;
    }

    /**
     * Set response body
     */
    public function withBody(string $body): self
    {
        $response = new self($body, $this->status, $this->headers);
        $response->cookies = $this->cookies;
        return $response;
    }

    /**
     * Add multiple cookies to response
     */
    public function withCookies(array $cookies): self
    {
        $response = new self($this->body, $this->status, $this->headers);
        $response->cookies = [...$this->cookies, ...$cookies];
        return $response;
    }

    /**
     * Delete cookie by creating expired cookie
     */
    public function withoutCookie(string $name, string $path = '/', string $domain = ''): self
    {
        return $this->withCookie(Cookie::delete($name, $path, $domain));
    }

    /**
     * Add cookie to response
     */
    public function withCookie(Cookie $cookie): self
    {
        $response = new self($this->body, $this->status, $this->headers);
        $response->cookies = [...$this->cookies, $cookie];
        return $response;
    }

    /**
     * Set Cache-Control header
     */
    public function withCacheControl(string $cacheControl): self
    {
        return $this->withHeader('Cache-Control', $cacheControl);
    }

    /**
     * Set response header with fluent interface
     */
    public function withHeader(string $name, string $value): self
    {
        // ✅ HEADER INJECTION SCHUTZ
        $sanitizedName = RequestSanitizer::sanitizeHeaderName($name);
        $sanitizedValue = RequestSanitizer::sanitizeHeaderValue($value);

        $newHeaders = Headers::fromArray(
            [...$this->headers->all(), strtolower($sanitizedName) => $sanitizedValue]
        );

        $response = new self($this->body, $this->status, $newHeaders);
        $response->cookies = $this->cookies;
        return $response;
    }

    /**
     * Set ETag header
     */
    public function withETag(string $etag): self
    {
        return $this->withHeader('ETag', $etag);
    }

    /**
     * Set Last-Modified header
     */
    public function withLastModified(DateTimeInterface $lastModified): self
    {
        return $this->withHeader('Last-Modified', $lastModified->format('D, d M Y H:i:s T'));
    }

    /**
     * Set Expires header
     */
    public function withExpires(DateTimeInterface $expires): self
    {
        return $this->withHeader('Expires', $expires->format('D, d M Y H:i:s T'));
    }

    /**
     * Disable caching
     */
    public function withoutCache(): self
    {
        return $this->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT'
        ]);
    }

    /**
     * Set multiple headers at once
     */
    public function withHeaders(array $headers): self
    {
        $newHeaders = Headers::fromArray(
            [...$this->headers->all(), ...array_change_key_case($headers, CASE_LOWER)]
        );

        $response = new self($this->body, $this->status, $newHeaders);
        $response->cookies = $this->cookies;
        return $response;
    }

    /**
     * Set CORS headers
     */
    public function withCors(
        string $origin = '*',
        array  $methods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        array  $headers = ['Content-Type', 'Authorization'],
        int    $maxAge = 86400
    ): self
    {
        return $this->withHeaders([
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Methods' => implode(', ', $methods),
            'Access-Control-Allow-Headers' => implode(', ', $headers),
            'Access-Control-Max-Age' => (string)$maxAge
        ]);
    }

    /**
     * Set security headers
     */
    public function withSecurityHeaders(): self
    {
        return $this->withHeaders([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'X-XSS-Protection' => '1; mode=block',
            'Referrer-Policy' => 'strict-origin-when-cross-origin'
        ]);
    }

    /**
     * Check if response has specific status(es)
     */
    public function hasStatus(int ...$statuses): bool
    {
        return in_array($this->status, $statuses, true);
    }

    /**
     * Send response to client
     */
    public function send(): void
    {
        error_log("=== Response::send() START ===");
        error_log("Status: " . $this->status);
        error_log("Content-Type: " . ($this->getHeader('content-type') ?? 'not set'));
        error_log("Body length: " . strlen($this->body));
        error_log("Body preview: " . substr($this->body, 0, 100) . "...");

        // Prevent output before headers
        if (headers_sent($file, $line)) {
            error_log("❌ Headers already sent in {$file}:{$line}");
            throw new RuntimeException('Headers already sent');
        }

        // Set status code
        http_response_code($this->status);
        error_log("HTTP status set to: " . $this->status);

        // Send headers
        foreach ($this->headers->all() as $name => $value) {
            header("{$name}: {$value}");
            error_log("Header sent: {$name}: {$value}");
        }

        // Send cookies
        foreach ($this->cookies as $cookie) {
            header('Set-Cookie: ' . $cookie->toHeaderValue(), false);
            error_log("Cookie sent: " . $cookie->toHeaderValue());
        }

        error_log("=== About to echo body ===");

        // Send body
        echo $this->body;

        error_log("=== Body echoed ===");

        // Ensure output is sent immediately
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            error_log("fastcgi_finish_request() called");
        } else {
            flush();
            error_log("flush() called");
        }

        error_log("=== Response::send() COMPLETE ===");
    }

    /**
     * Get specific header
     */
    public function getHeader(string $name): ?string
    {
        return $this->headers->get($name);
    }

    /**
     * Get response body
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Get status code
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * Get headers
     */
    public function getHeaders(): array
    {
        return $this->headers->all();
    }

    /**
     * Check if response has specific header
     */
    public function hasHeader(string $name): bool
    {
        return $this->headers->has($name);
    }

    /**
     * Get cookies
     */
    public function getCookies(): array
    {
        return $this->cookies;
    }

    /**
     * Check if response is JSON
     */
    public function isJson(): bool
    {
        return str_contains($this->getContentType(), 'application/json');
    }

    /**
     * Get content type
     */
    public function getContentType(): string
    {
        return $this->headers->get('content-type') ?? 'text/html';
    }
}