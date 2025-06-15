public function __invoke(Request $request, array $params): Response
{
$environment = $this->container->getConfig('app.env') ?? 'production';
$debugMode = $this->container->getConfig('app.debug') ?? false;
$appUrl = $this->container->getConfig('app.url') ?? 'unknown';
$csrfEnabled = $this->container->getConfig('security.csrf_protection') ?? false;
$routeCache = $this->container->getConfig('routing.cache') ?? false;

return Response::html(
'<!DOCTYPE html>
<html lang="en">
<head>
    <title>PHP 8.4 Framework</title>
    <meta charset="utf-8">
    <style>
        body { font-family: -apple-system, sans-serif; margin: 40px; line-height: 1.6; }
        .status { padding: 15px; border-radius: 8px; margin: 15px 0; }
        .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .config { background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 15px 0; }
        .config h3 { margin-top: 0; color: #495057; }
        .config-item { margin: 8px 0; }
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.875em;
            font-weight: bold;
        }
        .badge-success { background: #28a745; color: white; }
        .badge-info { background: #17a2b8; color: white; }
    </style>
</head>
<body>
<h1>🚀 PHP 8.4 Framework</h1>

<div class="status success">
    <strong>✅ Framework running successfully with .env configuration!</strong>
</div>

<div class="config">
    <h3>📋 Configuration Status</h3>
    <div class="config-item">
        <strong>Environment:</strong>
        <span class="badge badge-info">' . htmlspecialchars($environment) . '</span>
    </div>
    <div class="config-item">
        <strong>Debug Mode:</strong>
        <span class="badge ' . ($debugMode ? 'badge-warning' : 'badge-success') . '">' .
                    ($debugMode ? 'enabled' : 'disabled') . '</span>
    </div>
    <div class="config-item">
        <strong>Application URL:</strong> ' . htmlspecialchars($appUrl) . '
    </div>
    <div class="config-item">
        <strong>CSRF Protection:</strong>
        <span class="badge ' . ($csrfEnabled ? 'badge-success' : 'badge-warning') . '">' .
                    ($csrfEnabled ? 'enabled' : 'disabled') . '</span>
    </div>
    <div class="config-item">
        <strong>Route Caching:</strong>
        <span class="badge ' . ($routeCache ? 'badge-success' : 'badge-info') . '">' .
                    ($routeCache ? 'enabled' : 'disabled') . '</span>
    </div>
    <div class="config-item">
        <strong>PHP Version:</strong> ' . PHP_VERSION . '
    </div>
    <div class="config-item">
        <strong>Route Discovery:</strong>
        <span class="badge badge-success">active</span>
    </div>
    <div class="config-item">
        <strong>Auto-Wiring:</strong>
        <span class="badge badge-success">enabled</span>
    </div>
</div>
</body>
</html>'
);
}