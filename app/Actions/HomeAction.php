<?php

declare(strict_types=1);

namespace App\Actions;

use Framework\Container\Container;
use Framework\Http\{Request, Response};
use Framework\Routing\Attributes\Route;

/**
 * Home page action with comprehensive system status
 */
#[Route('GET', '/', name: 'home')]
#[Route('GET', '/home', name: 'home.alt')]
final class HomeAction
{
    public function __construct(
        private readonly Container $container
    )
    {
    }

    public function __invoke(Request $request, array $params): Response
    {
        $environment = $this->container->getConfig('app.env') ?? 'production';
        $debugMode = $this->container->getConfig('app.debug') ?? false;
        $appUrl = $this->container->getConfig('app.url') ?? 'unknown';
        $csrfEnabled = $this->container->getConfig('security.csrf_protection') ?? false;
        $routeCache = $this->container->getConfig('routing.cache') ?? false;
        $logLevel = $this->container->getConfig('logging.level') ?? 'info';

        return Response::html(
            '<!DOCTYPE html>
            <html lang="en">
            <head>
                <title>PHP 8.4 Framework</title>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <style>
                    body { 
                        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; 
                        margin: 0; 
                        padding: 40px; 
                        line-height: 1.6; 
                        background: #f8f9fa;
                        color: #333;
                    }
                    .container { max-width: 800px; margin: 0 auto; }
                    .header { text-align: center; margin-bottom: 40px; }
                    .header h1 { 
                        font-size: 2.5rem; 
                        margin: 0; 
                        color: #2c3e50;
                        text-shadow: 0 2px 4px rgba(0,0,0,0.1);
                    }
                    .status { 
                        padding: 20px; 
                        border-radius: 12px; 
                        margin: 20px 0; 
                        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                    }
                    .success { 
                        background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%); 
                        border: 1px solid #c3e6cb; 
                        color: #155724; 
                    }
                    .config { 
                        background: white; 
                        padding: 25px; 
                        border-radius: 12px; 
                        margin: 20px 0; 
                        box-shadow: 0 2px 15px rgba(0,0,0,0.1);
                    }
                    .config h3 { 
                        margin: 0 0 20px 0; 
                        color: #2c3e50; 
                        font-size: 1.4rem;
                        border-bottom: 2px solid #ecf0f1;
                        padding-bottom: 10px;
                    }
                    .config-grid {
                        display: grid;
                        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                        gap: 15px;
                    }
                    .config-item { 
                        padding: 12px;
                        border-radius: 8px;
                        background: #f8f9fa;
                        border-left: 4px solid #007bff;
                    }
                    .config-item strong {
                        display: block;
                        margin-bottom: 5px;
                        color: #495057;
                        font-size: 0.9rem;
                        text-transform: uppercase;
                        letter-spacing: 0.5px;
                    }
                    .badge { 
                        display: inline-block; 
                        padding: 6px 12px; 
                        border-radius: 20px; 
                        font-size: 0.85rem; 
                        font-weight: 600;
                        text-transform: uppercase;
                        letter-spacing: 0.5px;
                    }
                    .badge-success { background: #28a745; color: white; }
                    .badge-warning { background: #ffc107; color: #212529; }
                    .badge-info { background: #17a2b8; color: white; }
                    .badge-primary { background: #007bff; color: white; }
                    .php-info {
                        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                        color: white;
                        text-align: center;
                        padding: 20px;
                        border-radius: 12px;
                        margin: 20px 0;
                    }
                    .features {
                        background: white;
                        padding: 25px;
                        border-radius: 12px;
                        margin: 20px 0;
                        box-shadow: 0 2px 15px rgba(0,0,0,0.1);
                    }
                    .feature-list {
                        display: grid;
                        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                        gap: 10px;
                        margin-top: 15px;
                    }
                    .feature-item {
                        display: flex;
                        align-items: center;
                        padding: 8px 0;
                    }
                    .feature-item::before {
                        content: "✅";
                        margin-right: 10px;
                        font-size: 1.1rem;
                    }
                    @media (max-width: 600px) {
                        body { padding: 20px; }
                        .header h1 { font-size: 2rem; }
                        .config-grid { grid-template-columns: 1fr; }
                    }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h1>🚀 PHP 8.4 Framework</h1>
                    </div>
                    
                    <div class="status success">
                        <strong>✅ Framework running successfully with .env configuration!</strong>
                        <p style="margin: 10px 0 0 0; opacity: 0.8;">
                            All systems operational and ready for development.
                        </p>
                    </div>
                    
                    <div class="php-info">
                        <h3 style="margin: 0 0 10px 0;">🐘 PHP Environment</h3>
                        <p style="margin: 0; font-size: 1.2rem; font-weight: bold;">
                            PHP ' . PHP_VERSION . ' with modern features enabled
                        </p>
                    </div>
                    
                    <div class="config">
                        <h3>📋 Configuration Status</h3>
                        <div class="config-grid">
                            <div class="config-item">
                                <strong>Environment</strong>
                                <span class="badge badge-info">' . htmlspecialchars($environment) . '</span>
                            </div>
                            <div class="config-item">
                                <strong>Debug Mode</strong>
                                <span class="badge ' . ($debugMode ? 'badge-warning' : 'badge-success') . '">' .
            ($debugMode ? 'enabled' : 'disabled') . '</span>
                            </div>
                            <div class="config-item">
                                <strong>Application URL</strong>
                                <div style="margin-top: 5px; word-break: break-all;">
                                    ' . htmlspecialchars($appUrl) . '
                                </div>
                            </div>
                            <div class="config-item">
                                <strong>CSRF Protection</strong>
                                <span class="badge ' . ($csrfEnabled ? 'badge-success' : 'badge-warning') . '">' .
            ($csrfEnabled ? 'enabled' : 'disabled') . '</span>
                            </div>
                            <div class="config-item">
                                <strong>Route Caching</strong>
                                <span class="badge ' . ($routeCache ? 'badge-success' : 'badge-info') . '">' .
            ($routeCache ? 'enabled' : 'disabled') . '</span>
                            </div>
                            <div class="config-item">
                                <strong>Log Level</strong>
                                <span class="badge badge-primary">' . htmlspecialchars($logLevel) . '</span>
                            </div>
                            <div class="config-item">
                                <strong>Route Discovery</strong>
                                <span class="badge badge-success">active</span>
                            </div>
                            <div class="config-item">
                                <strong>Container Auto-Wiring</strong>
                                <span class="badge badge-success">enabled</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="features">
                        <h3>🎯 Framework Features</h3>
                        <div class="feature-list">
                            <div class="feature-item">Property Hooks (PHP 8.4)</div>
                            <div class="feature-item">Asymmetric Visibility</div>
                            <div class="feature-item">Attribute-based Routing</div>
                            <div class="feature-item">Dependency Injection</div>
                            <div class="feature-item">Auto Route Discovery</div>
                            <div class="feature-item">Security Validation</div>
                            <div class="feature-item">Session Management</div>
                            <div class="feature-item">CSRF Protection</div>
                            <div class="feature-item">Request/Response API</div>
                            <div class="feature-item">Container Auto-Wiring</div>
                            <div class="feature-item">Route Caching</div>
                            <div class="feature-item">Middleware Support</div>
                        </div>
                    </div>
                </div>
            </body>
            </html>'
        );
    }
}