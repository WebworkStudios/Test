<?php

declare(strict_types=1);

namespace Framework\Http;

/**
 * HTTP Request with Lazy Loading for better performance
 */
final class Request
{
    // Lazy-loaded data (computed only when needed)
    private ?array $parsedBody = null;
    private ?array $parsedHeaders = null;
    private ?array $parsedFiles = null;
    private ?string $clientIp = null;
    private ?string $fullUrl = null;

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
        public readonly string $method,
        public readonly string $uri,
        public readonly string $path,
        public readonly array $query,
        public readonly array $cookies,
        public readonly array $server,
        private readonly string $rawBody,
        public readonly string $contentType,
        public readonly int $contentLength
    ) {}

    /**
     * Create Request from PHP globals
     */
    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        
        // Only parse small/fast data eagerly
        return new self(
            method: $method,
            uri: $uri,
            path: $path,
            query: $_GET, // Small, parse eagerly
            cookies: $_COOKIE, // Small, parse eagerly
            server: $_SERVER,
            rawBody: file_get_contents('php://input') ?: '', // Store raw, parse later
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
        array $query = [],
        array $body = [],
        array $headers = [],
        array $server = []
    ): self {
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';
        $contentType = $headers['content-type'] ?? '';
        $rawBody = is_array($body) ? json_encode($body) : (string) $body;
        
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

    public function isGet(): bool { return $this->isMethod('GET'); }
    public function isPost(): bool { return $this->isMethod('POST'); }
    public function isPut(): bool { return $this->isMethod('PUT'); }
    public function isDelete(): bool { return $this->isMethod('DELETE'); }
    public function isPatch(): bool { return $this->isMethod('PATCH'); }

    // === Content Type Detection ===

    public function isJson(): bool
    {
        return str_contains($this->contentType, 'application/json');
    }

    public function expectsJson(): bool
    {
        return str_contains($this->header('accept') ?? '', 'application/json');
    }

    public function isAjax(): bool
    {
        return strtolower($this->header('x-requested-with') ?? '') === 'xmlhttprequest';
    }

    // === Security & Network Info ===

    public function isSecure(): bool
    {
        return ($this->server['HTTPS'] ?? '') === 'on' ||
               ($this->server['SERVER_PORT'] ?? '') === '443' ||
               strtolower($this->header('x-forwarded-proto') ?? '') === 'https';
    }

    public function ip(): string
    {
        // Lazy computation - only calculate once
        if ($this->clientIp === null) {
            // Check for forwarded IP first
            $forwardedFor = $this->header('x-forwarded-for');
            if ($forwardedFor) {
                $ips = explode(',', $forwardedFor);
                $this->clientIp = trim($ips[0]);
            } else {
                $this->clientIp = $this->server['REMOTE_ADDR'] ?? 'unknown';
            }
        }
        
        return $this->clientIp;
    }

    public function userAgent(): string
    {
        return $this->header('user-agent') ?? '';
    }

    public function host(): string
    {
        return $this->header('host') ?? $this->server['SERVER_NAME'] ?? 'localhost';
    }

    public function scheme(): string
    {
        return $this->isSecure() ? 'https' : 'http';
    }

    public function url(): string
    {
        // Lazy computation - only build once
        if ($this->fullUrl === null) {
            $this->fullUrl = $this->scheme() . '://' . $this->host() . $this->uri;
        }
        
        return $this->fullUrl;
    }

    // === Headers (Lazy Parsed) ===

    public function header(string $name): ?string
    {
        $headers = $this->headers();
        return $headers[strtolower($name)] ?? null;
    }

    public function headers(): array
    {
        // Lazy parsing - only parse headers when first accessed
        if ($this->parsedHeaders === null) {
            $this->parsedHeaders = $this->parseHeaders();
        }
        
        return $this->parsedHeaders;
    }

    // === Query Parameters (Eager) ===

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function queryAll(): array
    {
        return $this->query;
    }

    // === Body Parameters (Lazy Parsed) ===

    public function input(string $key, mixed $default = null): mixed
    {
        $body = $this->body();
        return $body[$key] ?? $default;
    }

    public function inputAll(): array
    {
        return $this->body();
    }

    public function body(): array
    {
        // Lazy parsing - only parse body when first accessed
        if ($this->parsedBody === null) {
            $this->parsedBody = $this->parseBody();
        }
        
        return $this->parsedBody;
    }

    // === Universal Parameter Access ===

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $this->input($key, $default);
    }

    public function has(string $key): bool
    {
        return isset($this->query[$key]) || isset($this->body()[$key]);
    }

    // === Input Size Limits ===
    private const MAX_STRING_LENGTH = 1000000;    // 1MB for strings
    private const MAX_BODY_SIZE = 10485760;       // 10MB for request body
    private const MAX_JSON_SIZE = 1048576;        // 1MB for JSON
    private const MAX_ARRAY_ITEMS = 1000;         // Max array elements

    // === Type-Safe Parameter Access ===

    /**
     * Get parameter as string with optional default and size limit
     */
    public function string(string $key, string $default = '', int $maxLength = self::MAX_STRING_LENGTH): string
    {
        $value = $this->get($key);
        
        if ($value === null) {
            return $default;
        }
        
        $stringValue = (string) $value;
        
        // Check size limit
        if (strlen($stringValue) > $maxLength) {
            return $default; // Return default for oversized input
        }
        
        return $stringValue;
    }

    /**
     * Get parameter as integer with optional default
     */
    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);
        
        if ($value === null || $value === '') {
            return $default;
        }
        
        // Handle string representations
        $filtered = filter_var($value, FILTER_VALIDATE_INT);
        return $filtered !== false ? $filtered : $default;
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
        
        if ($value === null) {
            return $default;
        }
        
        // Handle common truthy values
        if (is_string($value)) {
            $lower = strtolower(trim($value));
            return match ($lower) {
                'true', '1', 'on', 'yes', 'y' => true,
                'false', '0', 'off', 'no', 'n', '' => false,
                default => (bool) $value
            };
        }
        
        return (bool) $value;
    }

    /**
     * Get parameter as array with optional default and size limits
     */
    public function array(string $key, array $default = [], int $maxItems = self::MAX_ARRAY_ITEMS): array
    {
        $value = $this->get($key);
        
        if ($value === null) {
            return $default;
        }
        
        if (is_array($value)) {
            // Check size limit
            if (count($value) > $maxItems) {
                return $default;
            }
            return $value;
        }
        
        // Try to decode JSON string
        if (is_string($value)) {
            // Check JSON size limit
            if (strlen($value) > self::MAX_JSON_SIZE) {
                return $default;
            }
            
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                // Check decoded array size
                if (count($decoded) > $maxItems) {
                    return $default;
                }
                return $decoded;
            }
            
            // Handle comma-separated values
            if (str_contains($value, ',')) {
                $items = array_map('trim', explode(',', $value));
                // Check size limit
                if (count($items) > $maxItems) {
                    return $default;
                }
                return $items;
            }
        }
        
        // Wrap single value in array
        return [$value];
    }

    /**
     * Get parameter as date with optional default
     */
    public function date(string $key, ?\DateTimeImmutable $default = null): ?\DateTimeImmutable
    {
        $value = $this->string($key);
        
        if ($value === '') {
            return $default;
        }
        
        try {
            return new \DateTimeImmutable($value);
        } catch (\DateMalformedStringException) {
            return $default;
        }
    }

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
    public function url(string $key, string $default = ''): string
    {
        $value = $this->string($key);
        
        if ($value === '') {
            return $default;
        }
        
        $filtered = filter_var($value, FILTER_VALIDATE_URL);
        return $filtered !== false ? $filtered : $default;
    }

    // === Input Sanitization & Security ===

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

    /**
     * Get parameter with only alphanumeric characters
     */
    public function alphaNum(string $key, string $default = ''): string
    {
        $value = $this->string($key);
        
        if ($value === '') {
            return $default;
        }
        
        // Keep only letters, numbers, and underscores
        $filtered = preg_replace('/[^a-zA-Z0-9_]/', '', $value);
        return $filtered !== '' ? $filtered : $default;
    }

    /**
     * Get parameter with only alphabetic characters
     */
    public function alpha(string $key, string $default = ''): string
    {
        $value = $this->string($key);
        
        if ($value === '') {
            return $default;
        }
        
        // Keep only letters and spaces
        $filtered = preg_replace('/[^a-zA-Z\s]/', '', $value);
        return trim($filtered) !== '' ? trim($filtered) : $default;
    }

    /**
     * Get parameter with only numeric characters
     */
    public function numeric(string $key, string $default = ''): string
    {
        $value = $this->string($key);
        
        if ($value === '') {
            return $default;
        }
        
        // Keep only numbers, dots, and minus for decimals
        $filtered = preg_replace('/[^0-9.\-]/', '', $value);
        return $filtered !== '' ? $filtered : $default;
    }

    /**
     * Get parameter as phone number (cleaned)
     */
    public function phone(string $key, string $default = ''): string
    {
        $value = $this->string($key);
        
        if ($value === '') {
            return $default;
        }
        
        // Keep only numbers, +, -, (, ), and spaces
        $filtered = preg_replace('/[^0-9+\-\(\)\s]/', '', $value);
        return trim($filtered) !== '' ? trim($filtered) : $default;
    }

    /**
     * Get parameter as safe file path (prevent directory traversal)
     */
    public function safePath(string $key, string $default = ''): string
    {
        $value = $this->string($key);
        
        if ($value === '') {
            return $default;
        }
        
        // Remove directory traversal attempts and dangerous characters
        $safe = str_replace(['../', '.\\', '..\\'], '', $value);
        $safe = preg_replace('/[^a-zA-Z0-9._\-]/', '', $safe);
        
        return $safe !== '' ? $safe : $default;
    }

    /**
     * Get parameter as UUID (validated format)
     */
    public function uuid(string $key, string $default = ''): string
    {
        $value = $this->string($key);
        
        if ($value === '') {
            return $default;
        }
        
        // Validate UUID format (8-4-4-4-12 hexadecimal digits)
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $value)) {
            return strtolower($value);
        }
        
        return $default;
    }

    /**
     * Get parameter with only whitelisted characters
     */
    public function whitelist(string $key, string $allowedChars, string $default = ''): string
    {
        $value = $this->string($key);
        
        if ($value === '') {
            return $default;
        }
        
        // Create regex pattern from allowed characters
        $pattern = '/[^' . preg_quote($allowedChars, '/') . ']/';
        $filtered = preg_replace($pattern, '', $value);
        
        return $filtered !== '' ? $filtered : $default;
    }

    /**
     * Get parameter with blacklisted characters removed
     */
    public function blacklist(string $key, array $forbiddenChars, string $default = ''): string
    {
        $value = $this->string($key);
        
        if ($value === '') {
            return $default;
        }
        
        // Remove each forbidden character
        foreach ($forbiddenChars as $char) {
            $value = str_replace($char, '', $value);
        }
        
        return trim($value) !== '' ? trim($value) : $default;
    }

    /**
     * Get parameter from enum of allowed values
     */
    public function enum(string $key, array $allowedValues, mixed $default = null): mixed
    {
        $value = $this->get($key);
        
        if (in_array($value, $allowedValues, true)) {
            return $value;
        }
        
        return $default;
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
        
        $decoded = json_decode($value, true);
        
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            // Check decoded array size
            if (count($decoded) > self::MAX_ARRAY_ITEMS) {
                return $default;
            }
            return $decoded;
        }
        
        return $default;
    }

    /**
     * Get parameter as slug (URL-friendly string)
     */
    public function slug(string $key, string $default = ''): string
    {
        $value = $this->string($key);
        
        if ($value === '') {
            return $default;
        }
        
        // Convert to lowercase and replace non-alphanumeric characters
        $slug = strtolower(trim($value));
        
        // Replace umlauts and special characters
        $slug = str_replace(
            ['ä', 'ö', 'ü', 'ß', 'á', 'à', 'é', 'è', 'í', 'ì', 'ó', 'ò', 'ú', 'ù'],
            ['ae', 'oe', 'ue', 'ss', 'a', 'a', 'e', 'e', 'i', 'i', 'o', 'o', 'u', 'u'],
            $slug
        );
        
        // Replace non-alphanumeric characters with hyphens
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        
        // Remove multiple consecutive hyphens
        $slug = preg_replace('/-+/', '-', $slug);
        
        // Remove leading/trailing hyphens
        $slug = trim($slug, '-');
        
        return $slug !== '' ? $slug : $default;
    }

    /**
     * Sanitize multiple parameters at once
     */
    public function sanitizeAll(array $keys): array
    {
        $sanitized = [];
        
        foreach ($keys as $key) {
            $sanitized[$key] = $this->sanitized($key);
        }
        
        return $sanitized;
    }

    /**
     * Check if parameter value is safe (no suspicious patterns)
     */
    public function isSafe(string $key): bool
    {
        $value = $this->string($key);
        
        if ($value === '') {
            return true;
        }
        
        // Check for common malicious patterns
        $dangerousPatterns = [
            '/<script[^>]*>.*?<\/script>/i',  // Script tags
            '/javascript:/i',                 // JavaScript URLs
            '/on\w+\s*=/i',                  // Event handlers
            '/\.\./i',                       // Directory traversal
            '/union\s+select/i',             // SQL injection
            '/drop\s+table/i',               // SQL injection
            '/<iframe[^>]*>/i',              // Iframe injection
        ];
        
        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return false;
            }
        }
        
        return true;
    }

    // === File Uploads (Lazy Parsed) ===

    public function file(string $key): ?array
    {
        $files = $this->files();
        return $files[$key] ?? null;
    }

    public function hasFile(string $key): bool
    {
        $file = $this->file($key);
        return $file && $file['error'] === UPLOAD_ERR_OK;
    }

    public function files(): array
    {
        // Lazy parsing - only process files when first accessed
        if ($this->parsedFiles === null) {
            $this->parsedFiles = $_FILES;
        }
        
        return $this->parsedFiles;
    }

    // === Cookies (Eager) ===

    public function cookie(string $name, mixed $default = null): mixed
    {
        return $this->cookies[$name] ?? $default;
    }

    // === Raw Access ===

    public function raw(): string
    {
        return $this->rawBody;
    }

    // === Private Parsing Methods (Called Lazily) ===

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
            $decoded = json_decode($this->rawBody, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                // Check decoded array size
                if (count($decoded) > self::MAX_ARRAY_ITEMS) {
                    return [];
                }
                return $decoded;
            }
            return [];
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

    /**
     * Parse HTTP headers from $_SERVER
     */
    private function parseHeaders(): array
    {
        $headers = [];
        
        foreach ($this->server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerName = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$headerName] = $value;
            }
        }

        // Add important non-HTTP_ headers
        if (isset($this->server['CONTENT_TYPE'])) {
            $headers['content-type'] = $this->server['CONTENT_TYPE'];
        }
        
        if (isset($this->server['CONTENT_LENGTH'])) {
            $headers['content-length'] = $this->server['CONTENT_LENGTH'];
        }

        if (isset($this->server['PHP_AUTH_USER'])) {
            $user = $this->server['PHP_AUTH_USER'];
            $pass = $this->server['PHP_AUTH_PW'] ?? '';
            $headers['authorization'] = 'Basic ' . base64_encode($user . ':' . $pass);
        }

        return $headers;
    }
}