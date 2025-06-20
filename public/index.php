<?php

declare(strict_types=1);

use Framework\Application;

// Bootstrap
require_once __DIR__ . '/../vendor/autoload.php';

// ✅ Load .env file
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        // ✅ SICHERHEITSVALIDIERUNG für ENV-Keys
        if (!preg_match('/^[A-Z_][A-Z0-9_]*$/', $key)) {
            continue; // Überspringe ungültige Keys
        }

        if (strlen($value) > 1000) {
            continue; // Überspringe zu lange Werte
        }

        // ✅ Entferne Quotes falls vorhanden
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }

        $_ENV[$key] = $value;
    }
}

// ✅ Helper function for env values
function env(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? $default;

    // Convert string booleans
    return match (strtolower((string)$value)) {
        'true', '1', 'yes', 'on' => true,
        'false', '0', 'no', 'off' => false,
        default => $value
    };
}

// ✅ Create necessary directories
$directories = [
    __DIR__ . '/../app/Actions',
    __DIR__ . '/../app/Controllers',
    __DIR__ . '/../storage/cache/routes',
    __DIR__ . '/../storage/sessions',
    __DIR__ . '/../storage/logs'
];

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// ✅ Configuration using absolute paths and .env values
$config = [
    'app' => [
        'env' => env('APP_ENV', 'development'), // ✅ Auf development setzen
        'debug' => env('APP_DEBUG', true),
        'url' => env('APP_URL', 'http://localhost'),
    ],
    'session' => [
        'name' => 'framework_session',
        'lifetime' => (int)env('SESSION_LIFETIME', 7200),
        'secure' => env('SESSION_SECURE', false),
        'httponly' => env('SESSION_HTTPONLY', true),
        'samesite' => env('SESSION_SAMESITE', 'Lax'),
        'validate_ip' => env('SESSION_VALIDATE_IP', false), // ✅ IP-Validierung optional
        'environment' => env('APP_ENV', 'development') // ✅ Environment Info
    ],
    'routing' => [
        'debug' => env('ROUTE_DEBUG', true),
        'auto_discover' => true,
        'discovery_paths' => [
            __DIR__ . '/../app/Actions',
            __DIR__ . '/../app/Controllers'
        ],
        'cache_dir' => __DIR__ . '/../storage/cache/routes',
        'cache' => env('ROUTE_CACHE', false),
        'strict' => false
    ],
    'security' => [
        'csrf_protection' => env('CSRF_PROTECTION', true),
    ],
    'logging' => [
        'level' => env('LOG_LEVEL', 'info'),
    ]
];

// ✅ Create and run application with error handling
try {
    $app = Application::create($config);
    $app->run();
} catch (Throwable $e) {
    // Log the error
    error_log("Application Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());

    if (env('APP_DEBUG', false)) {
        // Debug mode - show detailed error
        echo "<!DOCTYPE html><html lang=de><head><title>Application Error</title></head><body>";
        echo "<h1>🐛 Application Error</h1>";
        echo "<div style='background:#f8f9fa;padding:20px;border-radius:8px;margin:20px 0;'>";
        echo "<strong>Message:</strong> " . htmlspecialchars($e->getMessage()) . "<br>";
        echo "<strong>File:</strong> " . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "<br>";
        echo "<strong>Trace:</strong><br><pre style='background:#e9ecef;padding:15px;border-radius:4px;overflow:auto;'>";
        echo htmlspecialchars($e->getTraceAsString());
        echo "</pre></div></body></html>";
    } else {
        // Production mode - generic error
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Internal server error',
            'timestamp' => date('c')
        ]);
    }
}