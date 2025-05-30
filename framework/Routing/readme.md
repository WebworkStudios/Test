# Framework Routing System - API Documentation

## Table of Contents

- [Overview](#overview)
- [Core Components](#core-components)
- [Quick Start](#quick-start)
- [Route Attributes](#route-attributes)
- [Router API](#router-api)
- [Route Discovery](#route-discovery)
- [Route Caching](#route-caching)
- [Action Classes](#action-classes)
- [Error Handling](#error-handling)
- [Advanced Features](#advanced-features)
- [Performance Optimization](#performance-optimization)
- [Best Practices](#best-practices)
- [Examples](#examples)

## Overview

The Framework Routing System provides a modern, attribute-based HTTP routing solution with the following features:

- **Attribute-based route definitions** using PHP 8.4+ attributes
- **Automatic route discovery** from directory scanning
- **Performance optimization** with route compilation and caching
- **Type-safe parameter extraction** with validation
- **Dependency injection** integration via container
- **Named routes** for URL generation
- **Middleware support** at route level
- **Security-first approach** with input validation

## Core Components

### 1. Route Attribute (`Framework\Routing\Attributes\Route`)

Defines HTTP routing information for action classes.

### 2. Router (`Framework\Routing\Router`)

Core routing engine that handles route registration, compilation, and dispatching.

### 3. RouteInfo (`Framework\Routing\RouteInfo`)

Internal route information storage with parameter extraction and validation.

### 4. RouteDiscovery (`Framework\Routing\RouteDiscovery`)

Automatic route discovery engine that scans directories for route attributes.

### 5. RouteCache (`Framework\Routing\RouteCache`)

Performance optimization through route compilation caching.

## Quick Start

### 1. Create an Action Class

```php
<?php

namespace App\Actions;

use Framework\Routing\Attributes\Route;
use Framework\Http\{Request, Response};

#[Route('GET', '/users/{id}', name: 'user.show')]
final class ShowUserAction
{
    public function __invoke(Request $request, array $params): Response
    {
        $userId = $params['id'];
        
        // Your logic here
        $user = $this->userService->find($userId);
        
        return Response::json($user);
    }
}
```

### 2. Setup Router

```php
<?php

use Framework\Routing\{Router, RouteDiscovery, RouteCache};
use Framework\Container\Container;

$container = new Container();
$cache = new RouteCache('/path/to/cache');
$router = new Router($container, $cache);

// Automatic route discovery
$discovery = new RouteDiscovery($router);
$discovery->discover([
    'app/Actions',
    'app/Controllers'
]);

// Manual route registration (alternative)
$router->addRoute('POST', '/api/users', CreateUserAction::class, [], 'user.create');
```

### 3. Dispatch Requests

```php
<?php

use Framework\Http\Request;

$request = Request::fromGlobals();

try {
    $response = $router->dispatch($request);
    $response->send();
} catch (RouteNotFoundException $e) {
    // Handle 404
} catch (MethodNotAllowedException $e) {
    // Handle 405
}
```

## Route Attributes

### Basic Usage

```php
#[Route(method: 'GET', path: '/users')]
#[Route('POST', '/users')]  // Shorthand syntax
```

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `method` | `string` | Yes | HTTP method (GET, POST, PUT, DELETE, etc.) |
| `path` | `string` | Yes | URL path with optional parameters |
| `middleware` | `array<string>` | No | Route-specific middleware |
| `name` | `string\|null` | No | Route name for URL generation |

### Path Parameters

```php
// Single parameter
#[Route('GET', '/users/{id}')]

// Multiple parameters
#[Route('GET', '/users/{userId}/posts/{postId}')]

// Optional parameters (via default values in action method)
#[Route('GET', '/posts/{category}/{page}')]
public function __invoke(Request $request, array $params): Response
{
    $category = $params['category'];
    $page = $params['page'] ?? 1; // Handle optional parameter
}
```

### Multiple Routes per Action

```php
#[Route('GET', '/users/{id}')]
#[Route('GET', '/profile/{id}')]
final class ShowUserAction
{
    // Single action handling multiple routes
}
```

### Middleware Integration

```php
#[Route('POST', '/admin/users', middleware: ['auth', 'admin'])]
#[Route('DELETE', '/api/users/{id}', middleware: ['auth', 'rate_limit'])]
```

### Named Routes

```php
#[Route('GET', '/users/{id}', name: 'user.show')]
#[Route('POST', '/users', name: 'user.create')]

// Generate URLs
$url = $router->url('user.show', ['id' => 123]);
// Result: /users/123
```

## Router API

### Constructor

```php
public function __construct(
    ContainerInterface $container,
    ?RouteCache $cache = null
)
```

### Adding Routes

#### addRoute()

```php
public function addRoute(
    string $method,
    string $path,
    string $actionClass,
    array $middleware = [],
    ?string $name = null
): void
```

**Example:**
```php
$router->addRoute('GET', '/api/users/{id}', ShowUserAction::class, ['auth'], 'api.user.show');
```

### Dispatching

#### dispatch()

```php
public function dispatch(Request $request): Response
```

Dispatches request to matching route and returns response.

**Throws:**
- `RouteNotFoundException` - No matching route found
- `MethodNotAllowedException` - Route exists but method not allowed

### Route Checking

#### hasRoute()

```php
public function hasRoute(string $method, string $path): bool
```

Check if route exists for given method and path.

**Example:**
```php
if ($router->hasRoute('GET', '/users/123')) {
    // Route exists
}
```

### URL Generation

#### url()

```php
public function url(string $name, array $params = []): string
```

Generate URL for named route with parameters.

**Example:**
```php
$url = $router->url('user.posts', [
    'userId' => 123,
    'category' => 'tech'
]);
```

**Throws:**
- `RouteNotFoundException` - Named route not found
- `InvalidArgumentException` - Missing required parameters

### Route Inspection

#### getRoutes()

```php
public function getRoutes(): array
```

Get all registered routes for debugging/inspection.

## Route Discovery

### Constructor

```php
public function __construct(
    Router $router,
    array $ignoredDirectories = ['vendor', 'node_modules', '.git', 'storage', 'cache', 'tests']
)
```

### Discovery Methods

#### discover()

```php
public function discover(array $directories): void
```

Scan directories for route attributes and register them.

**Example:**
```php
$discovery = new RouteDiscovery($router);
$discovery->discover([
    'app/Actions',
    'app/Http/Controllers',
    'modules/*/Actions'
]);
```

#### registerClass()

```php
public function registerClass(string $className): void
```

Register specific class if it has route attributes.

**Example:**
```php
$discovery->registerClass(ShowUserAction::class);
```

### Performance Methods

#### getStats()

```php
public function getStats(): array
```

Get discovery statistics.

**Returns:**
```php
[
    'cached_files' => 42,
    'ignored_directories' => ['vendor', 'node_modules', ...]
]
```

#### clearCache()

```php
public function clearCache(): void
```

Clear discovery cache for testing or forced re-discovery.

## Route Caching

### Constructor

```php
public function __construct(string $cacheDir = '')
```

### Cache Methods

#### store()

```php
public function store(array $routes): void
```

Store compiled routes in cache.

#### load()

```php
public function load(): ?array
```

Load cached routes if valid.

#### clear()

```php
public function clear(): void
```

Clear route cache.

#### isValid()

```php
public function isValid(): bool
```

Check if cache exists and is valid (not expired).

### Cache Configuration

```php
private const string CACHE_FILE = 'framework_routes.cache';
private const int CACHE_TTL = 3600; // 1 hour
```

## Action Classes

### Requirements

Action classes must:
1. Be invokable (have `__invoke` method)
2. Accept `Request` and `array $params` parameters
3. Return a `Response` object or compatible type

### Basic Action Structure

```php
<?php

namespace App\Actions;

use Framework\Routing\Attributes\Route;
use Framework\Http\{Request, Response};

#[Route('GET', '/users/{id}')]
final class ShowUserAction
{
    public function __construct(
        private readonly UserService $userService
    ) {}

    public function __invoke(Request $request, array $params): Response
    {
        $userId = (int) $params['id'];
        
        // Input validation
        if ($userId <= 0) {
            return Response::badRequest('Invalid user ID');
        }
        
        try {
            $user = $this->userService->find($userId);
            return Response::json($user);
        } catch (UserNotFoundException $e) {
            return Response::notFound('User not found');
        }
    }
}
```

### Parameter Access

Route parameters are available in the `$params` array:

```php
public function __invoke(Request $request, array $params): Response
{
    // URL: /users/123/posts/456
    $userId = $params['userId'];    // "123"
    $postId = $params['postId'];    // "456"
    
    // Type conversion with validation
    $userId = $request->int('userId', 0);
    $postId = $request->int('postId', 0);
}
```

### Response Types

Actions can return various response types:

```php
// Explicit Response object
return Response::json(['status' => 'success']);

// Array (automatically converted to JSON)
return ['users' => $users];

// String (automatically converted to HTML)
return '<h1>Welcome</h1>';

// Object (automatically converted to JSON)
return $userDto;
```

### Dependency Injection

Actions are resolved through the container, enabling dependency injection:

```php
#[Route('POST', '/users')]
final class CreateUserAction
{
    public function __construct(
        private readonly UserService $userService,
        private readonly Validator $validator,
        private readonly EventDispatcher $events
    ) {}
    
    public function __invoke(Request $request, array $params): Response
    {
        // Dependencies are automatically injected
    }
}
```

## Error Handling

### Route Exceptions

#### RouteNotFoundException

Thrown when no matching route is found.

```php
try {
    $response = $router->dispatch($request);
} catch (RouteNotFoundException $e) {
    return Response::notFound('Page not found');
}
```

#### MethodNotAllowedException

Thrown when route exists but HTTP method is not allowed.

```php
try {
    $response = $router->dispatch($request);
} catch (MethodNotAllowedException $e) {
    $allowedMethods = $e->getAllowedMethods();
    return Response::json([
        'error' => 'Method not allowed',
        'allowed_methods' => $allowedMethods
    ], 405)->withHeader('Allow', implode(', ', $allowedMethods));
}
```

### Parameter Validation

Route parameters are automatically validated for security:

```php
// These will throw InvalidArgumentException:
// - Directory traversal: ../etc/passwd
// - Null bytes: file\0.txt
// - Oversized parameters: > 255 characters
```

### Action Validation

Action classes are validated for security:

```php
// Validation checks:
// 1. Class exists
// 2. Class is invokable
// 3. Not in dangerous namespace patterns
// 4. Allowed namespace prefixes
```

## Advanced Features

### Custom Route Compilation

Routes are automatically compiled for optimal performance:

```php
// Original: /users/{id}/posts/{slug}
// Compiled: #^\/users\/([^/]+)\/posts\/([^/]+)$#

// Parameter extraction with security validation
$params = $routeInfo->extractParams('/users/123/posts/my-post');
// Result: ['id' => '123', 'slug' => 'my-post']
```

### Route Sorting

Routes are automatically sorted for optimal matching:

1. Static routes first (e.g., `/users/profile`)
2. Parametric routes second (e.g., `/users/{id}`)
3. Shorter paths before longer paths
4. Within each category, exact matches prioritized

### Security Features

#### Parameter Validation

```php
// Automatic security checks:
// - Directory traversal prevention
// - Null byte filtering
// - Length limits (255 chars)
// - URL decoding
```

#### Action Class Security

```php
// Validation of action classes:
// - Existence check
// - Invokability verification
// - Dangerous pattern detection
// - Namespace whitelist checking
```

#### Input Sanitization

```php
// URL parameters are automatically:
// - URL decoded
// - Validated for security
// - Length limited
// - Filtered for dangerous patterns
```

## Performance Optimization

### Route Compilation

Routes are compiled once and cached for optimal performance:

```php
// Before compilation (slow)
foreach ($routes as $route) {
    if (preg_match($route->pattern, $path)) {
        // Match found
    }
}

// After compilation (fast)
$compiled = $this->compiledRoutes[$method];
foreach ($compiled as $route) {
    // Optimized matching with early returns
}
```

### Caching Strategy

1. **Route Discovery Cache**: File-based caching of discovered classes
2. **Route Compilation Cache**: Serialized compiled routes
3. **Automatic Invalidation**: TTL-based cache expiration (1 hour)
4. **Atomic Writes**: Corruption-safe cache updates

### Memory Optimization

- Lazy route compilation (only when needed)
- Optimized route sorting
- Minimal memory footprint
- Efficient regular expression patterns

### Benchmarks

Typical performance improvements:

- Route compilation: 5-10x faster than dynamic routing
- Route caching: 2-3x faster cold starts
- Discovery caching: 10-50x faster repeated scans

## Best Practices

### 1. Action Organization

```php
// Good: Organize by feature
app/
├── Actions/
│   ├── User/
│   │   ├── ShowUserAction.php
│   │   ├── CreateUserAction.php
│   │   └── UpdateUserAction.php
│   └── Post/
│       ├── ListPostsAction.php
│       └── ShowPostAction.php

// Bad: Single directory with many files
app/Actions/
├── ShowUserAction.php
├── CreateUserAction.php
├── ShowPostAction.php
└── ... (100+ files)
```

### 2. Route Naming

```php
// Good: Consistent naming convention
#[Route('GET', '/users', name: 'user.index')]
#[Route('GET', '/users/{id}', name: 'user.show')]
#[Route('POST', '/users', name: 'user.create')]
#[Route('PUT', '/users/{id}', name: 'user.update')]
#[Route('DELETE', '/users/{id}', name: 'user.delete')]

// Bad: Inconsistent or missing names
#[Route('GET', '/users')]  // No name
#[Route('GET', '/users/{id}', name: 'show_user')]  // Inconsistent format
```

### 3. Parameter Validation

```php
// Good: Validate parameters early
public function __invoke(Request $request, array $params): Response
{
    $userId = filter_var($params['id'], FILTER_VALIDATE_INT);
    if ($userId === false || $userId <= 0) {
        return Response::badRequest('Invalid user ID');
    }
    
    // Continue with business logic
}

// Bad: Use parameters without validation
public function __invoke(Request $request, array $params): Response
{
    $user = $this->userService->find($params['id']); // Potential security issue
}
```

### 4. Response Consistency

```php
// Good: Consistent response format
return Response::json([
    'status' => 'success',
    'data' => $user,
    'meta' => ['timestamp' => time()]
]);

// Bad: Inconsistent responses
return $user;  // Sometimes object
return ['user' => $user];  // Sometimes array
return Response::html($user->name);  // Sometimes HTML
```

### 5. Error Handling

```php
// Good: Specific error handling
try {
    $user = $this->userService->find($userId);
    return Response::json($user);
} catch (UserNotFoundException $e) {
    return Response::notFound('User not found');
} catch (DatabaseException $e) {
    error_log($e->getMessage());
    return Response::serverError('Database error occurred');
}

// Bad: Generic error handling
try {
    $user = $this->userService->find($userId);
    return Response::json($user);
} catch (Exception $e) {
    return Response::serverError('Something went wrong');
}
```

## Examples

### REST API Example

```php
<?php

namespace App\Actions\User;

use Framework\Routing\Attributes\Route;
use Framework\Http\{Request, Response};

// List users
#[Route('GET', '/api/users', name: 'api.user.index')]
final class ListUsersAction
{
    public function __invoke(Request $request, array $params): Response
    {
        $page = $request->int('page', 1);
        $limit = $request->int('limit', 20);
        
        $users = $this->userService->paginate($page, $limit);
        
        return Response::json([
            'data' => $users,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $this->userService->count()
            ]
        ]);
    }
}

// Show user
#[Route('GET', '/api/users/{id}', name: 'api.user.show')]
final class ShowUserAction
{
    public function __invoke(Request $request, array $params): Response
    {
        $userId = $request->int('id');
        
        try {
            $user = $this->userService->find($userId);
            return Response::json(['data' => $user]);
        } catch (UserNotFoundException $e) {
            return Response::notFound(['error' => 'User not found']);
        }
    }
}

// Create user
#[Route('POST', '/api/users', name: 'api.user.create')]
final class CreateUserAction
{
    public function __invoke(Request $request, array $params): Response
    {
        $data = $request->json();
        
        try {
            $user = $this->userService->create($data);
            return Response::created(['data' => $user]);
        } catch (ValidationException $e) {
            return Response::badRequest(['errors' => $e->getErrors()]);
        }
    }
}
```

### Web Application Example

```php
<?php

namespace App\Actions\Web;

use Framework\Routing\Attributes\Route;
use Framework\Http\{Request, Response};

// Homepage
#[Route('GET', '/', name: 'home')]
final class HomeAction
{
    public function __invoke(Request $request, array $params): Response
    {
        $html = $this->templateEngine->render('home.html.twig', [
            'user' => $request->user(),
            'posts' => $this->postService->getRecent(5)
        ]);
        
        return Response::html($html);
    }
}

// User profile
#[Route('GET', '/profile/{username}', name: 'profile')]
final class ProfileAction
{
    public function __invoke(Request $request, array $params): Response
    {
        $username = $request->sanitized('username');
        
        try {
            $user = $this->userService->findByUsername($username);
            $posts = $this->postService->getByUser($user->id);
            
            $html = $this->templateEngine->render('profile.html.twig', [
                'profile_user' => $user,
                'posts' => $posts,
                'can_edit' => $request->user()?->id === $user->id
            ]);
            
            return Response::html($html);
        } catch (UserNotFoundException $e) {
            return Response::notFound('Profile not found');
        }
    }
}
```

### File Upload Example

```php
<?php

namespace App\Actions\Media;

use Framework\Routing\Attributes\Route;
use Framework\Http\{Request, Response};

#[Route('POST', '/api/upload', middleware: ['auth'], name: 'api.upload')]
final class UploadFileAction
{
    public function __invoke(Request $request, array $params): Response
    {
        if (!$request->hasFile('file')) {
            return Response::badRequest(['error' => 'No file uploaded']);
        }
        
        $file = $request->file('file');
        
        // Validate file
        if (!$this->fileValidator->isValid($file)) {
            return Response::badRequest(['error' => 'Invalid file type']);
        }
        
        try {
            $uploadedFile = $this->fileService->store($file);
            
            return Response::created([
                'data' => [
                    'id' => $uploadedFile->id,
                    'url' => $uploadedFile->url,
                    'filename' => $uploadedFile->filename
                ]
            ]);
        } catch (FileUploadException $e) {
            return Response::serverError(['error' => 'Upload failed']);
        }
    }
}
```

### Middleware Integration Example

```php
<?php

namespace App\Actions\Admin;

use Framework\Routing\Attributes\Route;
use Framework\Http\{Request, Response};

#[Route('GET', '/admin/dashboard', middleware: ['auth', 'admin'], name: 'admin.dashboard')]
final class DashboardAction
{
    public function __invoke(Request $request, array $params): Response
    {
        // Only authenticated admin users reach this point
        $stats = $this->adminService->getDashboardStats();
        
        return Response::json([
            'stats' => $stats,
            'user' => $request->user(),
            'permissions' => $request->user()->permissions
        ]);
    }
}

#[Route('DELETE', '/admin/users/{id}', middleware: ['auth', 'admin', 'csrf'], name: 'admin.user.delete')]
final class DeleteUserAction
{
    public function __invoke(Request $request, array $params): Response
    {
        $userId = $request->int('id');
        $currentUser = $request->user();
        
        // Prevent self-deletion
        if ($userId === $currentUser->id) {
            return Response::forbidden(['error' => 'Cannot delete yourself']);
        }
        
        try {
            $this->userService->delete($userId);
            return Response::noContent();
        } catch (UserNotFoundException $e) {
            return Response::notFound(['error' => 'User not found']);
        }
    }
}
```

This documentation covers all aspects of the Framework Routing System, from basic usage to advanced features and best practices. The system provides a powerful, secure, and performant routing solution for modern PHP applications.