<?php

declare(strict_types=1);

namespace Framework\Http;

use InvalidArgumentException;
use JsonException;

/**
 * HTTP Request mit vollständiger PHP 8.4 Integration
 * Nutzt Property Hooks, Asymmetric Visibility und neue Array-Funktionen
 */
final class Request
{
    private const int MAX_STRING_LENGTH = 1000000;
    private const int MAX_BODY_SIZE = 10485760;
    private const int MAX_JSON_SIZE = 1048576;
    private const int MAX_ARRAY_ITEMS = 1000;

    // ✅ PHP 8.4 Property Hooks für Lazy Loading
    public array $body {
        get => $this->parsedBody ??= $this->parseBody();
    }

    public Headers $headers {
        get => $this->headersObject ??= Headers::fromServer($this->server);
    }

    public array $files {
        get => $this->parsedFiles ??= $_FILES;
    }

    // ✅ PHP 8.4 Asymmetric Visibility - Public read, Private write
    public private(set) string $clientIp {
        get => $this->clientIp ??= $this->calculateClientIp();
    }

    public private(set) string $fullUrl {
        get => $this->fullUrl ??= $this->buildFullUrl();
    }

    // ✅ Virtual Properties (nur get hook, kein backing value)
    public bool $isJson {
        get => str_contains($this->contentType, 'application/json');
    }

    public bool $isAjax {
        get => strtolower($this->headers->get('x-requested-with') ?? '') === 'xmlhttprequest';
    }

    public bool $expectsJson {
        get => $this->headers->expectsJson();
    }

    public bool $isSecure {
        get => ($this->server['HTTPS'] ?? '') === 'on' ||
            ($this->server['SERVER_PORT'] ?? '') === '443' ||
            strtolower($this->header('x-forwarded-proto') ?? '') === 'https';
    }

    public string $scheme {
        get => $this->isSecure ? 'https' : 'http';
    }

    public string $host {
        get => $this->header('host') ?? $this->server['SERVER_NAME'] ?? 'localhost';
    }

    public string $userAgent {
        get => $this->header('user-agent') ?? '';
    }

    // ✅ Virtual Property - berechnet bei jedem Zugriff
    public array $cacheHeaders {
        get => [
            'etag' => $this->header('etag'),
            'last_modified' => $this->header('last-modified'),
            'cache_control' => $this->header('cache-control'),
            'expires' => $this->header('expires'),
            'if_none_match' => $this->header('if-none-match'),
            'if_modified_since' => $this->header('if-modified-since'),
        ];
    }

    // Private backing properties für Lazy Loading
    private ?array $parsedBody = null;
    private ?Headers $headersObject = null;
    private ?array $parsedFiles = null;

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
    ) {
    }

    /**
     * Create Request from PHP globals mit PHP 8.4 Optimierungen
     */
    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        // ✅ Erweiterte URI-Validierung
        if (preg_match('/^[A-Z]:/i', $uri)) {
            throw new InvalidArgumentException('Invalid request URI: contains absolute path');
        }

        $path = parse_url($uri, PHP_URL_PATH) ?? '/';

        // ✅ Security validation
        if (str_contains($path, '\\') || str_contains($path, "\0")) {
            throw new InvalidArgumentException('Invalid characters in request path');
        }

        $request = new self(
            method: $method,
            uri: $uri,
            path: $path,
            query: $_GET,
            cookies: $_COOKIE,
            server: $_SERVER,
            rawBody: file_get_contents('php://input') ?: '',
            contentType: $_SERVER['CONTENT_TYPE'] ?? '',
            contentLength: (int)($_SERVER['CONTENT_LENGTH'] ?? 0)
        );

        // ✅ RequestSanitizer validation
        RequestSanitizer::validateRequest($request);

        return $request;
    }

    /**
     * Create Request for testing
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

    // === URL Building ===

    private function buildFullUrl(): string
    {
        return $this->scheme . '://' . $this->host . $this->uri;
    }

    public function url(): string
    {
        return $this->fullUrl;
    }

    // === Client IP Calculation ===

    private function calculateClientIp(): string
    {
        $forwardedIps = $this->headers->forwardedFor();
        return $forwardedIps[0] ?? $this->server['REMOTE_ADDR'] ?? 'unknown';
    }

    public function ip(): string
    {
        return $this->clientIp;
    }

    // === Header Access ===

    public function header(string $name): ?string
    {
        return $this->headers->get($name);
    }

    // === Parameter Access ===

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $this->input($key, $default);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function queryAll(): array
    {
        return $this->query;
    }

    public function inputAll(): array
    {
        return $this->body;
    }

    public function has(string $key): bool
    {
        return isset($this->query[$key]) || isset($this->body[$key]);
    }

    // === Type-Safe Parameter Access ===

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return match (true) {
            $value === null || $value === '' => $default,
            is_int($value) => $value,
            default => filter_var($value, FILTER_VALIDATE_INT) ?: $default
        };
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->get($key);

        if ($value === null || $value === '') {
            return $default;
        }

        if (is_string($value)) {
            $value = str_replace(',', '.', $value);
        }

        $filtered = filter_var($value, FILTER_VALIDATE_FLOAT);
        return $filtered !== false ? $filtered : $default;
    }

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

    public function array(string $key, array $default = []): array
    {
        $value = $this->get($key);

        return match (true) {
            $value === null => $default,
            is_array($value) => count($value) > self::MAX_ARRAY_ITEMS ? $default : $value,
            is_string($value) && str_contains($value, ',') =>
            array_map('trim', explode(',', $value)),
            is_string($value) && $this->isJsonString($value) =>
            $this->parseJsonString($value, $default),
            default => [$value]
        };
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key);

        if ($value === null || $value === '') {
            return $default;
        }

        $stringValue = (string)$value;

        if (strlen($stringValue) > self::MAX_STRING_LENGTH) {
            return $default;
        }

        return $stringValue;
    }

    // === Input Sanitization ===

    public function sanitized(string $key, string $default = ''): string
    {
        $value = $this->string($key);

        if ($value === '') {
            return $default;
        }

        try {
            return RequestSanitizer::sanitizeParameter($value, 'default');
        } catch (InvalidArgumentException) {
            return $default;
        }
    }

    public function cleaned(string $key, array $allowedTags = [], string $default = ''): string
    {
        $value = $this->string($key);

        if ($value === '') {
            return $default;
        }

        try {
            return RequestSanitizer::sanitizeParameter($value, 'html', ['allowed_tags' => $allowedTags]);
        } catch (InvalidArgumentException) {
            return $default;
        }
    }

    public function email(string $key, string $default = ''): string
    {
        $value = $this->string($key);

        if ($value === '') {
            return $default;
        }

        try {
            return RequestSanitizer::sanitizeParameter($value, 'email');
        } catch (InvalidArgumentException) {
            return $default;
        }
    }

    public function urlParam(string $key, string $default = ''): string
    {
        $value = $this->string($key);

        if ($value === '') {
            return $default;
        }

        try {
            return RequestSanitizer::sanitizeParameter($value, 'url');
        } catch (InvalidArgumentException) {
            return $default;
        }
    }

    public function json(string $key, array $default = []): array
    {
        $value = $this->string($key);

        if ($value === '') {
            return $default;
        }

        return $this->parseJsonString($value, $default);
    }

    // === File Uploads ===

    public function hasFile(string $key): bool
    {
        $file = $this->file($key);
        return $file && $file['error'] === UPLOAD_ERR_OK;
    }

    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;

        if ($file === null) {
            return null;
        }

        return $this->validateUploadedFile($file);
    }

    // === Cookie Access ===

    public function cookie(string $name, mixed $default = null): mixed
    {
        return $this->cookies[$name] ?? $default;
    }

    // === Raw Body ===

    public function raw(): string
    {
        return $this->rawBody;
    }

    // === Cache Helper ===

    public function cache(): array
    {
        return $this->cacheHeaders;
    }

    // === Private Helper Methods ===

    /**
     * Parse request body based on content type and method
     */
    private function parseBody(): array
    {
        if (!in_array($this->method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            return [];
        }

        if (empty($this->rawBody)) {
            return $this->method === 'POST' ? $_POST : [];
        }

        if (strlen($this->rawBody) > self::MAX_BODY_SIZE) {
            return [];
        }

        // ✅ Match expression für Content-Type
        return match (true) {
            $this->isJson => $this->parseJsonString($this->rawBody, []),
            str_contains($this->contentType, 'application/x-www-form-urlencoded') => $this->parseFormData(),
            str_contains($this->contentType, 'multipart/form-data') => $_POST,
            $this->method === 'POST' && !empty($_POST) => $_POST,
            default => $this->parseFormData()
        };
    }

    private function parseFormData(): array
    {
        parse_str($this->rawBody, $parsed);
        return is_array($parsed) ? $parsed : [];
    }

    private function parseJsonString(string $jsonString, array $default): array
    {
        if (strlen($jsonString) > self::MAX_JSON_SIZE) {
            return $default;
        }

        try {
            $decoded = json_decode($jsonString, true, flags: JSON_THROW_ON_ERROR);

            if (is_array($decoded)) {
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

    private function isJsonString(string $string): bool
    {
        return str_starts_with(trim($string), '{') || str_starts_with(trim($string), '[');
    }

    private function validateUploadedFile(array $file): ?array
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        if ($file['size'] > 10485760) { // 10MB
            throw new InvalidArgumentException('File too large');
        }

        $allowedMimeTypes = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'application/pdf', 'text/plain', 'text/csv'
        ];

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedMimeTypes, true)) {
            throw new InvalidArgumentException('File type not allowed');
        }

        $filename = basename($file['name']);
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $filename)) {
            throw new InvalidArgumentException('Invalid filename');
        }

        return $file;
    }
}