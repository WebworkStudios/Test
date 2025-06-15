<?php


declare(strict_types=1);

namespace App\Actions;

use Framework\Routing\Attributes\Route;
use Framework\Http\{Request, Response};

/**
 * Example home page action
 */
#[Route('GET', '/')]
#[Route('GET', '/home', name: 'home')]
final class HomeAction
{
    public function __invoke(Request $request, array $params): Response
    {
        return Response::html(
            '<!DOCTYPE html>
            <html lang="">
            <head>
                <title>Framework Home</title>
                <meta charset="utf-8">
            </head>
            <body>
                <h1>Welcome to PHP 8.4 Framework</h1>
                <p>Your application is running successfully!</p>
                <p>Environment: ' . ($_ENV['APP_ENV'] ?? 'production') . '</p>
                <p>Debug mode: ' . (($_ENV['APP_DEBUG'] ?? false) ? 'enabled' : 'disabled') . '</p>
            </body>
            </html>'
        );
    }
}