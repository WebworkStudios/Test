<?php

declare(strict_types=1);

namespace Framework\Http;

use DateMalformedStringException;
use DateTimeImmutable;
use framework\Http\Cache\CacheHeaders;
use InvalidArgumentException;
use JsonException;

/**
 * HTTP Request with Lazy Loading and optimized structure
 */
final class Request
{
    // Lazy-loaded data (computed only when needed)
    private const int MAX_STRING_LENGTH = 1000000;
    private const int MAX_BODY_SIZE = 10485760;
    private const int MAX_JSON_SIZE = 1048576;
    private const int MAX_ARRAY_ITEMS = 1000;
    private ?array $parsedBody = null;
    private ?Headers $headersObject = null;

    // Input size limits
    private ?CacheHeaders $cacheHeaders = null;    // 1MB for strings
    private ?array $parsedFiles = null;       // 10MB for request body
    private ?string $clientIp = null;        // 1MB for JSON
    private ?string $fullUrl = null;         // Max array elements

    /**
     * @param string $method HTTP method
     * @param string $uri Request URI
     * @param string $path Path component of URI
     * @param array<string, mixed> $query Query parameters (eager - usually small)
     * @param array<string, mixed> $cookies Cookie data (eager - usually small)
     * @param array<string, mixed> $server Server information
     * @param string $rawBody Raw request body (lazy parsing)
     * @param string $contentType Content type
     * @param int $contentLength Content length
     */
    public function __construct(
        public readonly string  $method,
        public readonly string  $uri,
        public readonly string  $path,
        public readonly array   $query,
        public readonly array   $cookies,
        public readonly array   $server,
        private readonly string $rawBody,
        public readonly string  $contentType,
        public readonly int     $contentLength
    )
    {
    }

    /**
     * Create Request from PHP globals
     */
    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        // Validate URI before processing
        if (preg_match('/^[A-Z]:/i', $uri)) {
            throw new InvalidArgumentException('Invalid request URI: contains absolute path');
        }

        $path = parse_url($uri, PHP_URL_PATH) ?? '/';

        // Additional path validation
        if (str_contains($path, '\\') || str_contains($path, "\0")) {
            throw new InvalidArgumentException('Invalid characters in request path');
        }

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);

        return new self(
            method: $method,
            uri: $uri,
            path: $path,
            query: $_GET,
            cookies: $_COOKIE,
            server: $_SERVER,
            rawBody: file_get_contents('php://input') ?: '',
            contentType: $contentType,
            contentLength: $contentLength
        );
    }

    /**
     * Create Request from parameters (for testing)
     */
    public static function create(
        string $method,
        string $uri,
        array  $query = [],
        array  $body = [],
        array  $headers = [],
        array  $server = []
    ): self
    {
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';
        $contentType = $headers['content-type'] ?? '';
        $rawBody = is_array($body) ? json_encode($body) : (string)$body;

        return new self(
            method: strtoupper($method),
            uri: $uri,
            path: $path,
            query: $query,
            cookies: [],
            server: $server,
            rawBody: $rawBody,
            contentType: $contentType,
            contentLength: strlen($rawBody)
        );
    }

    // === HTTP Method Checks ===

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    // === Content Type Detection ===

    public function expectsJson(): bool
    {
        return $this->headers()->expectsJson();
    }

    public function headers(): Headers
    {
        // Lazy parsing - only parse headers when first accessed
        if ($this->headersObject === null) {
            $this->headersObject = Headers::fromServer($this->server);
        }

        return $this->headersObject;
    }

    public function isAjax(): bool
    {
        return $this->headers()->isAjax();
    }

    // === Security & Network Info ===

    public function ip(): string
    {
        // Lazy computation - only calculate once
        if ($this->clientIp === null) {
            $forwardedIps = $this->headers()->forwardedFor();
            $this->clientIp = $forwardedIps[0] ?? $this->server['REMOTE_ADDR'] ?? 'unknown';
        }

        return $this->clientIp;
    }

    public function userAgent(): string
    {
        return $this->header('user-agent') ?? '';
    }

    public function header(string $name): ?string
    {
        return $this->headers()->get($name);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $this->input($key, $default);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $body = $this->body();
        return $body[$key] ?? $default;
    }

    public function body(): array
    {
        // Lazy parsing - only parse body when first accessed
        if ($this->parsedBody === null) {
            $this->parsedBody = $this->parseBody();
        }

        return $this->parsedBody;
    }

    // === Headers (via Headers class) ===

    /**
     * Parse request body based on content type and method
     */
    private function parseBody(): array
    {
        if (!in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            return [];
        }

        // Empty body
        if (empty($this->rawBody)) {
            // Fall back to $_POST for POST requests
            return $this->method === 'POST' ? $_POST : [];
        }

        // Check body size limit
        if (strlen($this->rawBody) > self::MAX_BODY_SIZE) {
            return []; // Reject oversized bodies
        }

        // JSON content
        if ($this->isJson()) {
            return $this->parseJsonString($this->rawBody, []);
        }

        // Form data - try to parse raw body
        if (str_contains($this->contentType, 'application/x-www-form-urlencoded')) {
            parse_str($this->rawBody, $parsed);
            return is_array($parsed) ? $parsed : [];
        }

        // Multipart form data - use $_POST (already parsed by PHP)
        if (str_contains($this->contentType, 'multipart/form-data')) {
            return $_POST;
        }

        // Default: try to parse as form data
        if ($this->method === 'POST' && !empty($_POST)) {
            return $_POST;
        }

        // Last resort: try to parse raw body as query string
        parse_str($this->rawBody, $parsed);
        return is_array($parsed) ? $parsed : [];
    }

    public function isJson(): bool
    {
        return str_contains($this->contentType, 'application/json');
    }

    // === Caching ===

    /**
     * Parse JSON string safely
     */
    private function parseJsonString(string $jsonString, array $default): array
    {
        // Check JSON size limit
        if (strlen($jsonString) > self::MAX_JSON_SIZE) {
            return $default;
        }

        try {
            $decoded = json_decode($jsonString, true, flags: JSON_THROW_ON_ERROR);

            if (is_array($decoded)) {
                // Check decoded array size
                if (count($decoded) > self::MAX_ARRAY_ITEMS) {
                    return $default;
                }
                return $decoded;
            }

            return $default;
        } catch (JsonException) {
            return $default;
        }
    }

    // === Query Parameters (Eager) ===

    public function url(): string
    {
        // Lazy computation - only build once
        if ($this->fullUrl === null) {
            $this->fullUrl = $this->scheme() . '://' . $this->host() . $this->uri;
        }

        return $this->fullUrl;
    }

    public function scheme(): string
    {
        return $this->isSecure() ? 'https' : 'http';
    }

    // === Body Parameters (Lazy Parsed) ===

    public function isSecure(): bool
    {
        return ($this->server['HTTPS'] ?? '') === 'on' ||
            ($this->server['SERVER_PORT'] ?? '') === '443' ||
            strtolower($this->header('x-forwarded-proto') ?? '') === 'https';
    }

    public function host(): string
    {
        return $this->header('host') ?? $this->server['SERVER_NAME'] ?? 'localhost';
    }

    public function cache(): CacheHeaders
    {
        if ($this->cacheHeaders === null) {
            $this->cacheHeaders = new CacheHeaders($this->headers());
        }
        return $this->cacheHeaders;
    }

    // === Universal Parameter Access ===

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function queryAll(): array
    {
        return $this->query;
    }

    // === Type-Safe Parameter Access ===

    public function inputAll(): array
    {
        return $this->body();
    }

    public function has(string $key): bool
    {
        return isset($this->query[$key]) || isset($this->body()[$key]);
    }

    /**
     * Get parameter as integer with optional default
     */
    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return match (true) {
            $value === null || $value === '' => $default,
            is_int($value) => $value,
            default => filter_var($value, FILTER_VALIDATE_INT) ?: $default
        };
    }

    /**
     * Get parameter as float with optional default
     */
    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->get($key);

        if ($value === null || $value === '') {
            return $default;
        }

        // Handle German/European decimal separator
        if (is_string($value)) {
            $value = str_replace(',', '.', $value);
        }

        $filtered = filter_var($value, FILTER_VALIDATE_FLOAT);
        return $filtered !== false ? $filtered : $default;
    }

    /**
     * Get parameter as boolean
     * Handles checkbox values, string representations, etc.
     */
    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        return match (true) {
            $value === null => $default,
            is_bool($value) => $value,
            is_string($value) => match (strtolower(trim($value))) {
                'true', '1', 'on', 'yes', 'y' => true,
                'false', '0', 'off', 'no', 'n', '' => false,
                default => $default
            },
            default => (bool)$value
        };
    }

    /**
     * Get parameter as array with optional default and size limits
     */
    public function array(string $key, array $default = []): array
    {
        $value = $this->get($key);

        return match (true) {
            $value === null => $default,
            is_array($value) => count($value) > self::MAX_ARRAY_ITEMS ? $default : $value,
            is_string($value) && str_contains($value, ',') =>
            array_map('trim', explode(',', $value)),
            is_string($value) && $this->isJsonString($value) => $this->parseJsonString($value, $default),
            default => [$value]
        };
    }

    /**
     * Check if string contains valid JSON
     */
    private function isJsonString(string $string): bool
    {
        return str_starts_with(trim($string), '{') || str_starts_with(trim($string), '[');
    }

    /**
     * Get parameter as date with optional default
     */
    public function date(string $key, ?DateTimeImmutable $default = null): ?DateTimeImmutable
    {
        $value = $this->string($key);

        if ($value === '') {
            return $default;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (DateMalformedStringException) {
            return $default;
        }
    }

    /**
     * Get parameter as string with optional default and size limit
     */
    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key);

        if ($value === null || $value === '') {
            return $default;
        }

        $stringValue = (string)$value;

        // Check size limit
        if (strlen($stringValue) > self::MAX_STRING_LENGTH) {
            return $default; // Return default for oversized input
        }

        return $stringValue;
    }

    // === Input Sanitization ===

    /**
     * Get parameter as email (validated) with optional default
     */
    public function email(string $key, string $default = ''): string
    {
        $value = $this->string($key);

        if ($value === '') {
            return $default;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_EMAIL);
        return $filtered !== false ? $filtered : $default;
    }

    /**
     * Get parameter as URL (validated) with optional default
     */
    public function urlParam(string $key, string $default = ''): string
    {
        $value = $this->string($key);

        if ($value === '') {
            return $default;
        }

        $filtered = filter_var($value, FILTER_VALIDATE_URL);
        return $filtered !== false ? $filtered : $default;
    }

    /**
     * Get parameter as JSON (validated and decoded)
     */
    public function json(string $key, array $default = []): array
    {
        $value = $this->string($key, '', self::MAX_JSON_SIZE);

        if ($value === '') {
            return $default;
        }

        return $this->parseJsonString($value, $default);
    }

    // === File Uploads (Lazy Parsed) ===

    /**
     * Get sanitized parameter (HTML entities, trimmed)
     */
    public function sanitized(string $key, string $default = ''): string
    {
        $value = $this->string($key);

        if ($value === '') {
            return $default;
        }

        return htmlspecialchars(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Get parameter with HTML tags stripped
     */
    public function stripped(string $key, string $default = ''): string
    {
        $value = $this->string($key);

        if ($value === '') {
            return $default;
        }

        return trim(strip_tags($value));
    }

    /**
     * Get parameter with only allowed HTML tags
     */
    public function cleaned(string $key, array $allowedTags = [], string $default = ''): string
    {
        $value = $this->string($key);

        if ($value === '') {
            return $default;
        }

        // Convert array to string format for strip_tags
        $allowedTagsStr = empty($allowedTags) ? '' : '<' . implode('><', $allowedTags) . '>';

        return trim(strip_tags($value, $allowedTagsStr));
    }

    // === Cookies (Eager) ===

    public function hasFile(string $key): bool
    {
        $file = $this->file($key);
        return $file && $file['error'] === UPLOAD_ERR_OK;
    }

    // === Raw Access ===

    public function file(string $key): ?array
    {
        $files = $this->files();
        return $files[$key] ?? null;
    }

    // === Private Helper Methods ===

    public function files(): array
    {
        // Lazy parsing - only process files when first accessed
        if ($this->parsedFiles === null) {
            $this->parsedFiles = $_FILES;
        }

        return $this->parsedFiles;
    }

    public function cookie(string $name, mixed $default = null): mixed
    {
        return $this->cookies[$name] ?? $default;
    }

    public function raw(): string
    {
        return $this->rawBody;
    }
}