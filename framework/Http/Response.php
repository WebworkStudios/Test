<?php

declare(strict_types=1);

namespace Framework\Http;

use InvalidArgumentException;

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

    /**
     * @param string $body Response body
     * @param int $status HTTP status code
     * @param array<string, string> $headers HTTP headers
     */
    public function __construct(
        private string $body = '',
        private int $status = self::STATUS_OK,
        private array $headers = []
    ) {
        $this->validateStatus($status);
    }

    /**
     * Create JSON response with flexible data types and size limit
     */
    public static function json(array|object|string $data, int $status = self::STATUS_OK, int $maxSize = 1048576): self
    {
        $jsonData = match(true) {
            is_string($data) => $data,
            default => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
        };

        if (strlen($jsonData) > $maxSize) {
            throw new InvalidArgumentException("JSON response too large: " . strlen($jsonData) . " bytes (max: {$maxSize})");
        }

        return new self(
            $jsonData,
            $status,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    /**
     * Create HTML response
     */
    public static function html(string $html, int $status = self::STATUS_OK): self
    {
        return new self(
            $html,
            $status,
            ['Content-Type' => 'text/html; charset=utf-8']
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
            ['Content-Type' => 'text/plain; charset=utf-8']
        );
    }

    /**
     * Create successful creation response
     */
    public static function created(array|object|string|null $data = null): self
    {
        return match($data) {
            null => new self('', self::STATUS_CREATED),
            default => self::json($data, self::STATUS_CREATED)
        };
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
            ['Location' => $url]
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
     * Set response header with fluent interface and sanitization
     */
    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $this->sanitizeHeaderValue($value);
        return $this;
    }

    /**
     * Set multiple headers at once with sanitization
     */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->headers[$name] = $this->sanitizeHeaderValue($value);
        }
        return $this;
    }

    /**
     * Set status code with validation
     */
    public function withStatus(int $status): self
    {
        $this->validateStatus($status);
        $this->status = $status;
        return $this;
    }

    /**
     * Set response body
     */
    public function withBody(string $body): self
    {
        $this->body = $body;
        return $this;
    }

    /**
     * Add cookie to response with validation
     */
    public function withCookie(
        string $name,
        string $value,
        int $expire = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httponly = true,
        string $samesite = 'Lax'
    ): self {
        // Validate cookie name and value
        $this->validateCookieName($name);
        $this->validateCookieValue($value);
        
        $cookieHeader = $this->buildCookieHeader($name, $value, $expire, $path, $domain, $secure, $httponly, $samesite);
        
        // Handle multiple Set-Cookie headers
        if (isset($this->headers['Set-Cookie'])) {
            $existing = is_array($this->headers['Set-Cookie']) 
                ? $this->headers['Set-Cookie'] 
                : [$this->headers['Set-Cookie']];
            $this->headers['Set-Cookie'] = [...$existing, $cookieHeader];
        } else {
            $this->headers['Set-Cookie'] = $cookieHeader;
        }

        return $this;
    }

    /**
     * Send response to client
     */
    public function send(): void
    {
        // Prevent output before headers
        if (headers_sent()) {
            throw new \RuntimeException('Headers already sent');
        }

        // Set status code
        http_response_code($this->status);
        
        // Send headers
        foreach ($this->headers as $name => $value) {
            if (is_array($value)) {
                // Handle multiple values (like Set-Cookie)
                foreach ($value as $val) {
                    header("{$name}: {$val}", false);
                }
            } else {
                header("{$name}: {$value}");
            }
        }
        
        // Send body
        echo $this->body;
        
        // Ensure output is sent immediately
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            flush();
        }
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
        return $this->headers;
    }

    /**
     * Get specific header
     */
    public function getHeader(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }

    /**
     * Check if response has specific header
     */
    public function hasHeader(string $name): bool
    {
        return isset($this->headers[$name]);
    }

    /**
     * Get content type
     */
    public function getContentType(): string
    {
        return $this->getHeader('Content-Type') ?? 'text/html';
    }

    /**
     * Check if response is JSON
     */
    public function isJson(): bool
    {
        return str_contains($this->getContentType(), 'application/json');
    }

    /**
     * Check if response is successful (2xx)
     */
    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Check if response is redirect (3xx)
     */
    public function isRedirect(): bool
    {
        return $this->status >= 300 && $this->status < 400;
    }

    /**
     * Check if response is client error (4xx)
     */
    public function isClientError(): bool
    {
        return $this->status >= 400 && $this->status < 500;
    }

    /**
     * Check if response is server error (5xx)
     */
    public function isServerError(): bool
    {
        return $this->status >= 500 && $this->status < 600;
    }

    /**
     * Validate HTTP status code using match expression
     */
    private function validateStatus(int $status): void
    {
        match(true) {
            $status >= 100 && $status < 600 => null,
            default => throw new InvalidArgumentException("Invalid HTTP status code: {$status}")
        };
    }

    /**
     * Build cookie header string
     */
    private function buildCookieHeader(
        string $name,
        string $value,
        int $expire,
        string $path,
        string $domain,
        bool $secure,
        bool $httponly,
        string $samesite
    ): string {
        $cookie = "{$name}=" . urlencode($value);
        
        if ($expire > 0) {
            $cookie .= '; Expires=' . gmdate('D, d M Y H:i:s T', $expire);
            $cookie .= '; Max-Age=' . ($expire - time());
        }
        
        if ($path !== '') {
            $cookie .= "; Path={$path}";
        }
        
        if ($domain !== '') {
            $cookie .= "; Domain={$domain}";
        }
        
        if ($secure) {
            $cookie .= '; Secure';
        }
        
        if ($httponly) {
            $cookie .= '; HttpOnly';
        }
        
        if ($samesite !== '') {
            $cookie .= "; SameSite={$samesite}";
        }
        
        return $cookie;
    }
}