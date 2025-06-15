# PHP 8.4 Router Framework

A high-performance, feature-rich HTTP routing system built specifically for PHP 8.4, leveraging the latest language
features including Property Hooks, Asymmetric Visibility, and modern attribute-based route definitions.

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Basic Usage](#basic-usage)
- [Advanced Features](#advanced-features)
- [Route Discovery](#route-discovery)
- [Caching](#caching)
- [Middleware](#middleware)
- [Subdomains](#subdomains)
- [Performance](#performance)
- [Configuration](#configuration)
- [Examples](#examples)
- [Debugging](#debugging)
- [Best Practices](#best-practices)
- [API Reference](#api-reference)

## Features

### Core Features

- **PHP 8.4 Property Hooks** - Computed properties for real-time metrics
- **Attribute-based routing** - Modern, declarative route definitions
- **High-performance caching** - Multi-layer caching with integrity checks
- **Auto-discovery** - Automatic route scanning and registration
- **Subdomain routing** - Multi-tenant application support
- **Type-safe parameters** - Built-in parameter validation and constraints
- **Middleware support** - Flexible request/response filtering
- **Named routes** - URL generation and reverse routing

### Performance Features

- **Static route optimization** - O(1) lookup for static routes
- **Dynamic route compilation** - Optimized pattern matching
- **Memory-efficient caching** - LRU cache with size limits
- **Batch processing** - Efficient file scanning
- **Compression support** - Gzip compression for cache files
- **Integrity checking** - SHA-256 verification for cache files

### Security Features

- **Path traversal protection** - Secure file operations
- **Input validation** - Comprehensive parameter sanitization
- **XSS prevention** - Safe parameter handling
- **Dangerous code detection** - Security scanning during discovery
- **Strict mode** - Enhanced security validations

## Requirements

- PHP 8.4 or higher
- Composer for dependency management
- Optional: Zlib extension for compression

## Installation

```bash
composer require your-namespace/router-framework
```

## Quick Start

### 1. Create a Simple Action

```php
<?php

use Framework\Routing\Attributes\Route;

#[Route('GET', '/hello/{name}')]
final class HelloAction
{
    public function __invoke(Request $request, array $params): Response
    {
        $name = htmlspecialchars($params['name']);
        return Response::html("Hello, {$name}!");
    }
}
```

### 2. Set Up the Router

```php
<?php

use Framework\Routing\Router;
use Framework\Container\Container;

$container = new Container();
$router = Router::create($container, [
    'debug' => true,
    'cache_dir' => '/path/to/cache'
]);

// Auto-discover routes
$router->autoDiscoverRoutes(['app/Actions']);

// Handle request
$request = Request::fromGlobals();
$response = $router->dispatch($request);
$response->send();
```

## Basic Usage

### Manual Route Registration

```php
$router->addRoute(
    method: 'GET',
    path: '/users/{id}',
    actionClass: UserShowAction::class,
    middleware: ['auth', 'rate-limit'],
    name: 'user.show'
);
```

### Attribute-based Routes

```php
#[Route('POST', '/api/users', middleware: ['auth', 'json'], name: 'api.users.create')]
final class CreateUserAction
{
    public function __invoke(Request $request, array $params): Response
    {
        // Handle user creation
        return Response::json(['status' => 'created']);
    }
}
```

### Parameter Constraints

```php
#[Route('GET', '/users/{id:int}/posts/{slug:slug}')]
final class UserPostAction
{
    public function __invoke(Request $request, array $params): Response
    {
        $userId = (int) $params['id'];      // Guaranteed to be integer
        $slug = $params['slug'];            // Guaranteed to be slug format
        
        return Response::json(['user_id' => $userId, 'slug' => $slug]);
    }
}
```

### Available Constraints

- `{id:int}` - Integer values only
- `{uuid:uuid}` - UUID format (8-4-4-4-12)
- `{slug:slug}` - URL-friendly slugs (a-z0-9-)
- `{name:alpha}` - Alphabetic characters only
- `{code:alnum}` - Alphanumeric characters only

### Multiple HTTP Methods

```php
#[Route('GET', '/api/users/{id}', name: 'api.users.show')]
#[Route('PUT', '/api/users/{id}', name: 'api.users.update')]
#[Route('DELETE', '/api/users/{id}', name: 'api.users.delete')]
final class UserApiAction
{
    public function __invoke(Request $request, array $params): Response
    {
        return match($request->method) {
            'GET' => $this->show($params['id']),
            'PUT' => $this->update($params['id'], $request->body()),
            'DELETE' => $this->delete($params['id']),
        };
    }
}
```

## Advanced Features

### Route Options

```php
#[Route(
    'GET',
    '/admin/dashboard',
    middleware: ['auth', 'admin'],
    name: 'admin.dashboard',
    options: [
        'cache' => 300,           // Cache for 5 minutes
        'priority' => 90,         // High priority route
        'auth_required' => true,  // Explicit auth requirement
        'rate_limit' => 100,      // Requests per minute
        'deprecated' => false,    // Not deprecated
        'description' => 'Admin dashboard overview'
    ]
)]
final class AdminDashboardAction
{
    public function __invoke(Request $request, array $params): Response
    {
        return Response::html('<h1>Admin Dashboard</h1>');
    }
}
```

### URL Generation

```php
// Simple named route
$url = $router->url('user.show', ['id' => 123]);
// Result: /users/123

// With subdomain
$url = $router->url('api.users.show', ['id' => 123], 'api');
// Result: //api.example.com/users/123

// Check if route exists
if ($router->hasRoute('GET', '/users/123')) {
    // Route exists
}
```

### Route Groups (via Discovery)

Organize routes by namespace and directory structure:

```
app/
├── Actions/
│   ├── Web/
│   │   ├── HomeAction.php
│   │   └── AboutAction.php
│   ├── Api/
│   │   ├── V1/
│   │   │   ├── UsersAction.php
│   │   │   └── PostsAction.php
│   │   └── V2/
│   │       └── UsersAction.php
│   └── Admin/
│       ├── DashboardAction.php
│       └── UsersAction.php
```

## Route Discovery

### Automatic Discovery

```php
$router = Router::create($container, [
    'discovery' => [
        'max_depth' => 5,
        'strict_mode' => true,
        'max_file_size' => 2097152  // 2MB
    ]
]);

// Discover in multiple directories
$router->autoDiscoverRoutes([
    'app/Actions',
    'app/Controllers',
    'modules/*/Actions'
]);
```

### Manual Discovery

```php
use Framework\Routing\RouteDiscovery;

$discovery = RouteDiscovery::create($router);

// Discover with pattern
$discovery->discoverWithPattern('app', '**/*{Action,Controller}.php');

// Discover specific files
$discovery->discoverInFiles([
    'app/Actions/HomeAction.php',
    'app/Actions/UserAction.php'
]);

// Get discovery statistics
$stats = $discovery->getStats();
echo "Discovered {$stats['discovered_routes']} routes in {$stats['processed_files']} files";
```

### File Scanner Configuration

```php
use Framework\Routing\RouteFileScanner;

$scanner = new RouteFileScanner([
    'max_file_size' => 1048576,  // 1MB
    'strict_mode' => true
]);

// Scan single file
$classes = $scanner->scanFile('app/Actions/UserAction.php');

// Check if file has routes
if ($scanner->fileHasRoutes('app/Actions/UserAction.php')) {
    // File contains route attributes
}

// Get scanner statistics
$stats = $scanner->getStats();
echo "Cache hit ratio: {$stats['cache_hit_ratio']}%";
```

## Caching

### Cache Configuration

```php
use Framework\Routing\RouteCache;

$cache = new RouteCache(
    cacheDir: '/path/to/cache',
    useCompression: true,
    compressionLevel: 6,
    integrityCheck: true,
    strictMode: true
);

$router = new Router($container, cache: $cache);
```

### Cache Management

```php
// Store routes manually
$cache->store($router->getRoutes());

// Load cached routes
$routes = $cache->load();

// Clear cache
$cache->clear();

// Check cache validity
if ($cache->isValid) {
    echo "Cache is valid and up-to-date";
}

// Get cache statistics
$stats = $cache->getStats();
echo "Cache hit ratio: {$stats['hit_ratio_percent']}%";
echo "Cache size: {$stats['cache_size_formatted']}";

// Health check
$health = $cache->healthCheck();
if ($health['status'] !== 'healthy') {
    foreach ($health['issues'] as $issue) {
        echo "Issue: {$issue}\n";
    }
}
```

### Cache Warm-up

```php
// Warm up cache with current routes
$success = $cache->warmUp($router->getRoutes());

// Warm up router caches
$router->warmUp();
```

## Middleware

### Defining Middleware

```php
#[Route('GET', '/protected', middleware: ['auth', 'rate-limit:100'])]
final class ProtectedAction
{
    public function __invoke(Request $request, array $params): Response
    {
        return Response::json(['message' => 'Access granted']);
    }
}
```

### Multiple Middleware

```php
#[Route(
    'POST',
    '/api/admin/users',
    middleware: ['auth', 'admin', 'json', 'rate-limit:10', 'log']
)]
final class AdminCreateUserAction
{
    public function __invoke(Request $request, array $params): Response
    {
        // Middleware will be executed in order:
        // 1. auth - Authentication check
        // 2. admin - Admin role verification
        // 3. json - JSON request validation
        // 4. rate-limit:10 - Rate limiting (10 req/min)
        // 5. log - Request logging
        
        return Response::json(['status' => 'created']);
    }
}
```

## Subdomains

### Subdomain Routes

```php
#[Route('GET', '/dashboard', subdomain: 'admin')]
final class AdminDashboardAction
{
    // Only accessible via admin.example.com/dashboard
    public function __invoke(Request $request, array $params): Response
    {
        return Response::html('<h1>Admin Dashboard</h1>');
    }
}

#[Route('GET', '/users', subdomain: 'api')]
final class ApiUsersAction
{
    // Only accessible via api.example.com/users
    public function __invoke(Request $request, array $params): Response
    {
        return Response::json(['users' => []]);
    }
}
```

### Configuration

```php
$router = Router::create($container, [
    'allowed_subdomains' => ['api', 'admin', 'app', 'mobile'],
    'base_domain' => 'example.com',
    'strict' => true
]);
```

### URL Generation with Subdomains

```php
// Generate subdomain URLs
$url = $router->url('admin.dashboard', [], 'admin');
// Result: //admin.example.com/dashboard

$url = $router->url('api.users.show', ['id' => 123], 'api');
// Result: //api.example.com/users/123
```

## Performance

### Router Statistics

```php
$stats = $router->getStats();

echo "Routes: {$stats['route_count']}\n";
echo "Static routes: {$stats['static_routes']}\n";
echo "Dynamic routes: {$stats['dynamic_routes']}\n";
echo "Cache hit ratio: {$stats['cache_hit_ratio']}%\n";
echo "Average dispatch time: {$stats['average_dispatch_time_ms']}ms\n";
```

### Performance Monitoring

```php
// Monitor route performance
$performanceData = $router->getStats();

if ($performanceData['cache_hit_ratio'] < 80) {
    // Consider cache optimization
    $router->clearCaches();
    $router->warmUp();
}

if ($performanceData['average_dispatch_time_ms'] > 10) {
    // Consider route optimization
    // Check for too many dynamic routes
    echo "Dynamic routes ratio: " . 
         ($performanceData['dynamic_routes'] / $performanceData['route_count'] * 100) . "%\n";
}
```

### Optimization Tips

1. **Use static routes when possible**
   ```php
   // Preferred - static route
   #[Route('GET', '/about')]
   
   // Avoid unless necessary - dynamic route
   #[Route('GET', '/page/{slug}')]
   ```

2. **Optimize parameter constraints**
   ```php
   // More efficient with constraints
   #[Route('GET', '/users/{id:int}')]
   
   // Less efficient without constraints
   #[Route('GET', '/users/{id}')]
   ```

3. **Enable caching in production**
   ```php
   $router = Router::create($container, [
       'debug' => false,  // Disable debug mode
       'cache_dir' => '/path/to/cache',
       'cache' => [
           'useCompression' => true,
           'integrityCheck' => true
       ]
   ]);
   ```

## Configuration

### Complete Configuration Example

```php
$config = [
    // Debug mode
    'debug' => $_ENV['APP_DEBUG'] ?? false,
    
    // Strict mode for enhanced security
    'strict' => true,
    
    // Cache configuration
    'cache_dir' => $_ENV['CACHE_DIR'] ?? sys_get_temp_dir(),
    'cache' => [
        'useCompression' => true,
        'compressionLevel' => 6,
        'integrityCheck' => true,
        'strictMode' => true
    ],
    
    // Route discovery
    'discovery' => [
        'max_depth' => 10,
        'strict_mode' => true,
        'max_file_size' => 2097152  // 2MB
    ],
    
    // Subdomain settings
    'allowed_subdomains' => ['api', 'admin', 'app', 'mobile'],
    'base_domain' => $_ENV['APP_DOMAIN'] ?? 'localhost'
];

$router = Router::create($container, $config);
```

### Environment Variables

```bash
# .env file
APP_DEBUG=false
APP_DOMAIN=example.com
CACHE_DIR=/var/cache/routes
ROUTER_STRICT_MODE=true
```

## Examples

### REST API Example

```php
#[Route('GET', '/api/users', name: 'api.users.index')]
#[Route('POST', '/api/users', name: 'api.users.store')]
final class UsersApiAction
{
    public function __invoke(Request $request, array $params): Response
    {
        return match($request->method) {
            'GET' => $this->index($request),
            'POST' => $this->store($request),
        };
    }
    
    private function index(Request $request): Response
    {
        // List users with pagination
        $page = (int) ($request->query('page') ?? 1);
        $limit = min((int) ($request->query('limit') ?? 20), 100);
        
        return Response::json([
            'users' => [],
            'page' => $page,
            'limit' => $limit
        ]);
    }
    
    private function store(Request $request): Response
    {
        // Create new user
        $data = $request->json();
        
        return Response::json(['id' => 123], 201);
    }
}

#[Route('GET', '/api/users/{id:int}', name: 'api.users.show')]
#[Route('PUT', '/api/users/{id:int}', name: 'api.users.update')]
#[Route('DELETE', '/api/users/{id:int}', name: 'api.users.destroy')]
final class UserApiAction
{
    public function __invoke(Request $request, array $params): Response
    {
        $userId = (int) $params['id'];
        
        return match($request->method) {
            'GET' => $this->show($userId),
            'PUT' => $this->update($userId, $request),
            'DELETE' => $this->destroy($userId),
        };
    }
    
    private function show(int $id): Response
    {
        return Response::json(['id' => $id, 'name' => 'John Doe']);
    }
    
    private function update(int $id, Request $request): Response
    {
        $data = $request->json();
        return Response::json(['id' => $id, 'updated' => true]);
    }
    
    private function destroy(int $id): Response
    {
        return Response::json(null, 204);
    }
}
```

### Web Application Example

```php
#[Route('GET', '/', name: 'home')]
final class HomeAction
{
    public function __invoke(Request $request, array $params): Response
    {
        return Response::html('<h1>Welcome Home</h1>');
    }
}

#[Route('GET', '/blog/{slug:slug}', name: 'blog.show')]
final class BlogPostAction
{
    public function __invoke(Request $request, array $params): Response
    {
        $slug = $params['slug'];
        
        return Response::html("<h1>Blog Post: {$slug}</h1>");
    }
}

#[Route('GET', '/users/{id:int}/profile', middleware: ['auth'], name: 'user.profile')]
final class UserProfileAction
{
    public function __invoke(Request $request, array $params): Response
    {
        $userId = (int) $params['id'];
        
        return Response::html("<h1>User Profile: {$userId}</h1>");
    }
}
```

### Multi-tenant Application Example

```php
// Tenant-specific routes
#[Route('GET', '/dashboard', subdomain: 'tenant1')]
final class Tenant1DashboardAction
{
    public function __invoke(Request $request, array $params): Response
    {
        return Response::html('<h1>Tenant 1 Dashboard</h1>');
    }
}

#[Route('GET', '/dashboard', subdomain: 'tenant2')]
final class Tenant2DashboardAction
{
    public function __invoke(Request $request, array $params): Response
    {
        return Response::html('<h1>Tenant 2 Dashboard</h1>');
    }
}

// API routes
#[Route('GET', '/api/stats', subdomain: 'api')]
final class ApiStatsAction
{
    public function __invoke(Request $request, array $params): Response
    {
        return Response::json(['status' => 'ok', 'version' => '1.0']);
    }
}
```

## Debugging

### Debug Mode

```php
$router = Router::create($container, ['debug' => true]);

// Debug specific route
$debug = $router->debugRoute('GET', '/users/123');
print_r($debug);
```

Debug output:

```php
[
    'method' => 'GET',
    'path' => '/users/123',
    'subdomain' => null,
    'static_key' => '/users/123',
    'cache_key' => 'hash_value',
    'has_static_match' => false,
    'dynamic_candidates' => [
        [
            'class' => 'App\\Actions\\UserShowAction',
            'pattern' => '#^/users/(\d+)$#',
            'original_path' => '/users/{id:int}',
            'param_names' => ['id'],
            'subdomain' => null,
            'matches' => true,
            'extracted_params' => ['id' => '123']
        ]
    ],
    'matched_route' => [
        'type' => 'dynamic',
        'class' => 'App\\Actions\\UserShowAction',
        'path' => '/users/{id:int}',
        'params' => ['id' => '123']
    ]
]
```

### Error Handling

```php
try {
    $response = $router->dispatch($request);
} catch (RouteNotFoundException $e) {
    // Handle 404
    $response = Response::html('Page not found', 404);
} catch (MethodNotAllowedException $e) {
    // Handle 405
    $allowedMethods = $e->getAllowedMethods();
    $response = Response::html(
        'Method not allowed. Allowed: ' . implode(', ', $allowedMethods),
        405
    );
}
```

### Statistics and Monitoring

```php
// Route statistics
$stats = $router->getStats();
$discoveryStats = $discovery->getStats();
$cacheStats = $cache->getStats();

// Performance monitoring
echo "Router Performance:\n";
echo "- Total routes: {$stats['route_count']}\n";
echo "- Dispatch count: {$stats['dispatch_count']}\n";
echo "- Cache hit ratio: {$stats['cache_hit_ratio']}%\n";
echo "- Avg dispatch time: {$stats['average_dispatch_time_ms']}ms\n";

echo "\nDiscovery Statistics:\n";
echo "- Files processed: {$discoveryStats['processed_files']}\n";
echo "- Routes discovered: {$discoveryStats['discovered_routes']}\n";
echo "- Cache hit ratio: {$discoveryStats['scanner_stats']['cache_hit_ratio']}%\n";

echo "\nCache Statistics:\n";
echo "- Cache size: {$cacheStats['cache_size_formatted']}\n";
echo "- Hit ratio: {$cacheStats['hit_ratio_percent']}%\n";
echo "- Compression: " . ($cacheStats['features']['compression_enabled'] ? 'enabled' : 'disabled') . "\n";
```

## Best Practices

### 1. Route Organization

```php
// ✅ Good - Organized by feature
app/Actions/
├── Auth/
│   ├── LoginAction.php
│   ├── LogoutAction.php
│   └── RegisterAction.php
├── User/
│   ├── ShowAction.php
│   ├── EditAction.php
│   └── DeleteAction.php
└── Blog/
    ├── IndexAction.php
    ├── ShowAction.php
    └── CreateAction.php

// ❌ Bad - All in one directory
app/Actions/
├── LoginAction.php
├── LogoutAction.php
├── UserShowAction.php
├── UserEditAction.php
├── BlogIndexAction.php
└── BlogShowAction.php
```

### 2. Naming Conventions

```php
// ✅ Good - Clear, descriptive names
#[Route('GET', '/users/{id:int}', name: 'users.show')]
#[Route('GET', '/api/v1/users/{id:int}', name: 'api.v1.users.show')]

// ❌ Bad - Unclear names
#[Route('GET', '/users/{id:int}', name: 'user')]
#[Route('GET', '/api/v1/users/{id:int}', name: 'u')]
```

### 3. Parameter Constraints

```php
// ✅ Good - Use appropriate constraints
#[Route('GET', '/users/{id:int}/posts/{slug:slug}')]
#[Route('GET', '/files/{uuid:uuid}')]

// ❌ Bad - No constraints
#[Route('GET', '/users/{id}/posts/{slug}')]
#[Route('GET', '/files/{uuid}')]
```

### 4. Middleware Usage

```php
// ✅ Good - Logical middleware order
#[Route('POST', '/api/admin/users', middleware: ['auth', 'admin', 'json', 'rate-limit'])]

// ❌ Bad - Illogical order
#[Route('POST', '/api/admin/users', middleware: ['rate-limit', 'json', 'admin', 'auth'])]
```

### 5. Error Handling

```php
// ✅ Good - Proper error handling
final class UserShowAction
{
    public function __invoke(Request $request, array $params): Response
    {
        try {
            $userId = (int) $params['id'];
            $user = $this->userRepository->find($userId);
            
            if (!$user) {
                return Response::json(['error' => 'User not found'], 404);
            }
            
            return Response::json($user->toArray());
            
        } catch (\Exception $e) {
            return Response::json(['error' => 'Internal server error'], 500);
        }
    }
}
```

### 6. Performance Optimization

```php
// ✅ Good - Enable caching in production
if (!$debugMode) {
    $router = Router::create($container, [
        'cache_dir' => '/var/cache/routes',
        'cache' => [
            'useCompression' => true,
            'integrityCheck' => true
        ]
    ]);
}

// ✅ Good - Use static routes when possible
#[Route('GET', '/about')]          // Static - fast
#[Route('GET', '/contact')]        // Static - fast
#[Route('GET', '/users/{id:int}')] // Dynamic - slower but necessary
```

## API Reference

### Router Class

#### Methods

-

`addRoute(string $method, string $path, string $actionClass, array $middleware = [], ?string $name = null, ?string $subdomain = null): void`

- `dispatch(Request $request): Response`
- `url(string $name, array $params = [], ?string $subdomain = null): string`
- `hasRoute(string $method, string $path, ?string $subdomain = null): bool`
- `getRoutes(): array`
- `getNamedRoutes(): array`
- `autoDiscoverRoutes(array $directories = []): void`
- `getStats(): array`
- `clearCaches(): void`
- `warmUp(): void`
- `debugRoute(string $method, string $path, ?string $subdomain = null): array`

#### Property Hooks (PHP 8.4)

- `$routeCount` - Total number of registered routes
- `$isCompiled` - Whether routes have been compiled
- `$supportedMethods` - Array of supported HTTP methods
- `$staticRouteCount` - Number of static routes
- `$dynamicRouteCount` - Number of dynamic routes

### Route Attribute

#### Constructor Parameters

- `string $method` - HTTP method
- `string $path` - URL path pattern
- `array $middleware = []` - Middleware stack
- `?string $name = null` - Route name
- `?string $subdomain = null` - Subdomain constraint
- `array $options = []` - Additional options
- `array $schemes = ['http', 'https']` - Allowed schemes
- `array $methods = []` - Additional HTTP methods

#### Property Hooks (PHP 8.4)

- `$normalizedMethod` - Uppercase HTTP method
- `$hasParameters` - Whether route has parameters
- `$allMethods` - All HTTP methods for this route

### RouteInfo Class

#### Property Hooks (PHP 8.4)

- `$isStatic` - Whether route is static
- `$hasParameters` - Whether route has parameters
- `$parameterCount` - Number of parameters
- `$cacheKey` - Unique cache key

### RouteCache Class

#### Property Hooks (PHP 8.4)

- `$isValid` - Whether cache is valid
- `$cacheSize` - Cache file size in bytes
- `$cacheSizeFormatted` - Human-readable cache size
- `$hitRatio` - Cache hit ratio percentage
- `$compressionEnabled` - Whether compression is enabled
- `$integrityCheckEnabled` - Whether integrity checking is enabled

---

**Note**: This framework requires PHP 8.4 and makes extensive use of the latest language features. For older PHP
versions, consider using alternative routing solutions.

For more examples and advanced usage, please refer to the `/examples` directory in the repository.