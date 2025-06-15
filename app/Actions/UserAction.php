<?php

declare(strict_types=1);

namespace App\Actions;

use Framework\Routing\Attributes\Route;
use Framework\Http\{Request, Response};

/**
 * Test Action für verschiedene Parameter-Typen
 */
#[Route('GET', '/user/{id}', name: 'user.show')]
#[Route('GET', '/user/{id:int}', name: 'user.show.typed')]
#[Route('GET', '/user/{id:int}/profile', name: 'user.profile')]
#[Route('GET', '/user/{id:int}/posts/{slug:slug}', name: 'user.posts.show')]
#[Route('GET', '/api/user/{uuid:uuid}', name: 'api.user.show')]
#[Route('GET', '/blog/{year:int}/{month:int}/{slug:slug}', name: 'blog.post')]
final class UserAction
{
    public function __invoke(Request $request, array $params): Response
    {
        // Bestimme welche Route getroffen wurde basierend auf den Parametern
        $routeInfo = $this->determineRoute($request, $params);

        return Response::html('
            <!DOCTYPE html>
            <html lang="de">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>Parameter Test - PHP 8.4 Framework</title>
                <style>
                    body { 
                        font-family: system-ui, sans-serif; 
                        margin: 0; 
                        padding: 40px; 
                        background: linear-gradient(135deg, #2196F3 0%, #21CBF3 100%);
                        color: white;
                        min-height: 100vh;
                    }
                    .container { 
                        max-width: 800px; 
                        margin: 0 auto;
                        background: rgba(255,255,255,0.1);
                        padding: 40px;
                        border-radius: 15px;
                        backdrop-filter: blur(10px);
                        box-shadow: 0 8px 32px rgba(0,0,0,0.3);
                    }
                    h1 { 
                        margin: 0 0 30px 0;
                        text-align: center;
                        text-shadow: 0 2px 4px rgba(0,0,0,0.3);
                    }
                    .info-box {
                        background: rgba(255,255,255,0.15);
                        padding: 20px;
                        border-radius: 10px;
                        margin: 20px 0;
                        border-left: 4px solid #fff;
                    }
                    .param-grid {
                        display: grid;
                        grid-template-columns: auto 1fr;
                        gap: 10px 20px;
                        margin: 15px 0;
                    }
                    .param-label {
                        font-weight: bold;
                        opacity: 0.8;
                    }
                    .param-value {
                        font-family: monospace;
                        background: rgba(0,0,0,0.2);
                        padding: 5px 10px;
                        border-radius: 5px;
                    }
                    .test-links {
                        background: rgba(255,255,255,0.1);
                        padding: 25px;
                        border-radius: 10px;
                        margin-top: 30px;
                    }
                    .test-links h3 {
                        margin: 0 0 15px 0;
                    }
                    .test-links a {
                        display: inline-block;
                        color: white;
                        text-decoration: none;
                        background: rgba(255,255,255,0.2);
                        padding: 8px 15px;
                        border-radius: 20px;
                        margin: 5px 10px 5px 0;
                        transition: all 0.2s;
                    }
                    .test-links a:hover {
                        background: rgba(255,255,255,0.3);
                        transform: translateY(-2px);
                    }
                    .route-info {
                        background: rgba(0,255,0,0.2);
                        padding: 15px;
                        border-radius: 8px;
                        margin: 20px 0;
                    }
                </style>
            </head>
            <body>
                <div class="container">
                    <h1>🎯 Parameter Routing Test</h1>
                    
                    <div class="route-info">
                        <strong>Aktuelle Route:</strong> ' . htmlspecialchars($routeInfo['pattern']) . '<br>
                        <strong>Route Name:</strong> ' . htmlspecialchars($routeInfo['name']) . '<br>
                        <strong>HTTP Method:</strong> ' . htmlspecialchars($request->method) . '<br>
                        <strong>Full URL:</strong> ' . htmlspecialchars($request->url()) . '
                    </div>
                    
                    <div class="info-box">
                        <h3>📋 Gefundene Parameter:</h3>
                        <div class="param-grid">
                            ' . $this->renderParameters($params) . '
                        </div>
                    </div>
                    
                    <div class="info-box">
                        <h3>🔍 Request Details:</h3>
                        <div class="param-grid">
                            <span class="param-label">Path:</span>
                            <span class="param-value">' . htmlspecialchars($request->path) . '</span>
                            
                            <span class="param-label">Query String:</span>
                            <span class="param-value">' . htmlspecialchars($request->uri) . '</span>
                            
                            <span class="param-label">User Agent:</span>
                            <span class="param-value">' . htmlspecialchars($request->userAgent()) . '</span>
                        </div>
                    </div>
                    
                    <div class="test-links">
                        <h3>🧪 Test verschiedene Parameter-Typen:</h3>
                        
                        <h4>Basis Parameter:</h4>
                        <a href="/user/123">user/123</a>
                        <a href="/user/abc">user/abc</a>
                        <a href="/user/john">user/john</a>
                        
                        <h4>Typisierte Parameter:</h4>
                        <a href="/user/456/profile">user/456/profile</a>
                        <a href="/user/789/posts/mein-blog-post">user/789/posts/mein-blog-post</a>
                        
                        <h4>UUID Parameter:</h4>
                        <a href="/api/user/550e8400-e29b-41d4-a716-446655440000">api/user/[uuid]</a>
                        
                        <h4>Multiple Parameter:</h4>
                        <a href="/blog/2024/12/frohe-weihnachten">blog/2024/12/frohe-weihnachten</a>
                        <a href="/blog/2025/01/neues-jahr">blog/2025/01/neues-jahr</a>
                        
                        <h4>Zurück:</h4>
                        <a href="/">🏠 Home</a>
                    </div>
                </div>
            </body>
            </html>
        ');
    }

    private function determineRoute(Request $request, array $params): array
    {
        $path = $request->path;

        return match (true) {
            str_contains($path, '/api/user/') => [
                'pattern' => '/api/user/{uuid:uuid}',
                'name' => 'api.user.show',
                'type' => 'UUID Parameter'
            ],
            str_contains($path, '/blog/') => [
                'pattern' => '/blog/{year:int}/{month:int}/{slug:slug}',
                'name' => 'blog.post',
                'type' => 'Multiple typed parameters'
            ],
            str_contains($path, '/posts/') => [
                'pattern' => '/user/{id:int}/posts/{slug:slug}',
                'name' => 'user.posts.show',
                'type' => 'Nested parameters'
            ],
            str_contains($path, '/profile') => [
                'pattern' => '/user/{id:int}/profile',
                'name' => 'user.profile',
                'type' => 'Typed integer parameter'
            ],
            preg_match('/\/user\/\d+$/', $path) => [
                'pattern' => '/user/{id:int}',
                'name' => 'user.show.typed',
                'type' => 'Typed parameter'
            ],
            default => [
                'pattern' => '/user/{id}',
                'name' => 'user.show',
                'type' => 'Basic parameter'
            ]
        };
    }

    private function renderParameters(array $params): string
    {
        if (empty($params)) {
            return '<span class="param-label">Keine Parameter</span><span class="param-value">-</span>';
        }

        $html = '';
        foreach ($params as $key => $value) {
            $html .= '<span class="param-label">' . htmlspecialchars($key) . ':</span>';
            $html .= '<span class="param-value">' . htmlspecialchars($value) . ' (' . gettype($value) . ')</span>';
        }

        return $html;
    }
}