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
        $_ENV[trim($key)] = trim($value);
    }
}

// ✅ Helper function for env values
function env(string $key, mixed $default = null): mixed {
    $value = $_ENV[$key] ?? $default;

    // Convert string booleans
    return match (strtolower((string)$value)) {
        'true', '1', 'yes', 'on' => true,
        'false', '0', 'no', 'off' => false,
        default => $value
    };
}

// ✅ Configuration using .env values
$config = [
    'app' => [
        'env' => env('APP_ENV', 'production'),
        'debug' => env('APP_DEBUG', false),
        'url' => env('APP_URL', 'http://localhost'),
    ],
    'session' => [
        'name' => 'framework_session',
        'lifetime' => (int) env('SESSION_LIFETIME', 7200),
        'secure' => env('SESSION_SECURE', false),
        'httponly' => env('SESSION_HTTPONLY', true),
        'samesite' => env('SESSION_SAMESITE', 'Lax')
    ],
    'routing' => [
        'debug' => env('ROUTE_DEBUG', false),
        'auto_discover' => true,
        'discovery_paths' => [
            'app/Actions',
            'app/Controllers'
        ],
        'cache_dir' => __DIR__ . '/../storage/cache/routes',
        'cache' => env('ROUTE_CACHE', true),
        'strict' => false
    ],
    'security' => [
        'csrf_protection' => env('CSRF_PROTECTION', true),
    ],
    'logging' => [
        'level' => env('LOG_LEVEL', 'info'),
    ]
];

// ✅ Create and run application
$app = Application::create($config);
$app->run();