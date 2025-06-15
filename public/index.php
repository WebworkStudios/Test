<?php

declare(strict_types=1);

// FORCE DEBUG MODE
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

use Framework\Application;

// Bootstrap
require_once __DIR__ . '/../vendor/autoload.php';

// Configuration
$config = [
    'app' => [
        'env' => 'development',
        'debug' => true,
    ],
    'session' => [
        'name' => 'framework_session',
        'lifetime' => 7200,
        'secure' => false,  // ← Für XAMPP auf false
        'httponly' => true,
        'samesite' => 'Lax'
    ],
    'routing' => [
        'debug' => true,
        'auto_discover' => false,  // ← Erstmal deaktivieren
        'discovery_paths' => [
            'app/Actions',
            'app/Controllers'
        ],
        'cache_dir' => __DIR__ . '/../storage/cache/routes'
    ]
];

// Create and run application
$app = Application::create($config);

// MANUAL ROUTE TEST
$router = $app->getContainer()->get(\Framework\Routing\Router::class);
$router->addRoute('GET', '/', function($request, $params) {
    return \Framework\Http\Response::html('<h1>✅ Framework Works!</h1><p>Direct route successful</p>');
});

// DON'T catch here - let errors bubble up
$app->run();