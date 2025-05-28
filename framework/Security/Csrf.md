# CSRF Protection API Documentation

## Overview

The CSRF Protection class provides comprehensive Cross-Site Request Forgery protection for web applications. It supports action-specific tokens, automatic cleanup, and multiple token sources (forms, headers, meta tags).

## Table of Contents

- [Installation](#installation)
- [Basic Usage](#basic-usage)
- [Token Management](#token-management)
- [Request Validation](#request-validation)
- [HTML Generation](#html-generation)
- [Property Hooks (PHP 8.4)](#property-hooks-php-84)
- [Advanced Features](#advanced-features)
- [Configuration](#configuration)
- [Security Best Practices](#security-best-practices)

## Installation

```php
use Framework\Security\CsrfProtection;
use Framework\Http\Session;

$session = new Session();
$csrf = new CsrfProtection($session);
```

## Basic Usage

### Generate a Token

```php
// Generate default token
$token = $csrf->generateToken();

// Generate action-specific token
$loginToken = $csrf->generateToken('login');
$deleteToken = $csrf->generateToken('delete');
```

### Validate a Token

```php
// Validate default token
$isValid = $csrf->validateToken($token);

// Validate action-specific token
$isLoginValid = $csrf->validateToken($loginToken, 'login');

// Validate and consume token in one operation
$isValidAndConsumed = $csrf->validateAndConsume($token);
```

### Validate from HTTP Request

```php
// Validates token from $_POST['_token'], X-CSRF-Token header, or X-XSRF-Token header
$isValid = $csrf->validateFromRequest($request);

// Action-specific validation
$isValid = $csrf->validateFromRequest($request, 'login');

// Validate without consuming token
$isValid = $csrf->validateFromRequest($request, 'default', false);
```

## Token Management

### Get or Generate Token

```php
// Get existing token or generate new one
$token = $csrf->token(); // default action
$token = $csrf->token('login'); // specific action
```

### Get Existing Token

```php
// Returns null if token doesn't exist
$existingToken = $csrf->getToken('login');
```

### Token Consumption

```php
// Mark token as used (for one-time tokens)
$csrf->consumeToken('login');

// Check if token is valid before consuming
if ($csrf->validateToken($token, 'login')) {
    // Process form...
    $csrf->consumeToken('login'); // Mark as used
}
```

### Token Invalidation

```php
// Invalidate specific token
$csrf->invalidateToken('login');

// Clear all tokens
$csrf->clearTokens();
```

## Request Validation

### Form Validation Example

```php
// HTML form with CSRF token
echo $csrf->field('login'); // Generates hidden input

// Validate form submission
if ($request->isPost() && $csrf->validateFromRequest($request, 'login')) {
    // Process login...
} else {
    // Invalid or missing CSRF token
}
```

### AJAX Validation Example

```php
// Include meta tag in HTML head
echo $csrf->metaTag('api');

// JavaScript: Include token in AJAX headers
// X-CSRF-Token: [token_value]

// Validate AJAX request
if ($csrf->validateFromRequest($request, 'api')) {
    // Process AJAX request...
}
```

### Multiple Token Sources

The validator automatically checks multiple sources:

1. `$_POST['_token']` (form field)
2. `X-CSRF-Token` header
3. `X-XSRF-Token` header

```php
// All these will be validated automatically
$isValid = $csrf->validateFromRequest($request);
```

## HTML Generation

### Hidden Form Field

```php
// Default field name '_token'
echo $csrf->field();
// Output: <input type="hidden" name="_token" value="abc123...">

// Custom field name
echo $csrf->field('login', 'csrf_token');
// Output: <input type="hidden" name="csrf_token" value="def456...">

// Action-specific token
echo $csrf->field('delete');
// Output: <input type="hidden" name="_token" value="ghi789...">
```

### Meta Tag for AJAX

```php
// Default action
echo $csrf->metaTag();
// Output: <meta name="csrf-token" content="abc123...">

// Action-specific
echo $csrf->metaTag('api');
// Output: <meta name="csrf-token" content="jkl012...">
```

### Complete Form Example

```html
<form method="POST" action="/login">
    <?php echo $csrf->field('login'); ?>
    <input type="email" name="email" required>
    <input type="password" name="password" required>
    <button type="submit">Login</button>
</form>
```

## Property Hooks (PHP 8.4)

### Quick Access Properties

```php
// Get default token
$defaultToken = $csrf->defaultToken;

// Get all stored tokens
$allTokens = $csrf->allTokens;

// Check if any tokens exist
$hasTokens = $csrf->hasTokens;
```

## Advanced Features

### Token Expiration

```php
// Clean expired tokens (default: 1 hour)
$csrf->cleanExpiredTokens();

// Custom expiration time (30 minutes)
$csrf->cleanExpiredTokens(1800);

// Automatic cleanup happens during token generation
```

### Token Limits

- Maximum 10 tokens stored simultaneously
- Automatic cleanup of oldest tokens when limit exceeded
- 32-byte token length (64 hex characters)

### One-Time vs Reusable Tokens

```php
// One-time token (default behavior)
$isValid = $csrf->validateToken($token, 'default', true);

// Reusable token
$isValid = $csrf->validateToken($token, 'default', false);
```

### Debug Information

```php
// Get all stored tokens with metadata
$tokens = $csrf->getStoredTokens();

foreach ($tokens as $action => $data) {
    echo "Action: {$action}\n";
    echo "Token: {$data['token']}\n";
    echo "Created: " . date('Y-m-d H:i:s', $data['created_at']) . "\n";
    echo "Used: " . ($data['used'] ? 'Yes' : 'No') . "\n\n";
}
```

## Configuration

### Session Configuration

```php
// Custom session configuration
$session = new Session([
    'name' => 'my_app_session',
    'lifetime' => 7200, // 2 hours
    'secure' => true,
    'samesite' => 'Strict'
]);

$csrf = new CsrfProtection($session);
```

### Token Constants

```php
// These are built into the class:
// TOKEN_LENGTH = 32 bytes
// MAX_TOKENS = 10 tokens
// TOKEN_LIFETIME = 3600 seconds (1 hour)
// SESSION_KEY = '_csrf_tokens'
```

## Security Best Practices

### 1. Use Action-Specific Tokens

```php
// Good: Different tokens for different actions
echo $csrf->field('login');
echo $csrf->field('delete_user');
echo $csrf->field('change_password');

// Avoid: Using same token for everything
```

### 2. Validate on State-Changing Operations

```php
// Always validate CSRF for:
// - POST, PUT, PATCH, DELETE requests
// - Any operation that changes data
// - Sensitive operations (login, logout, password change)

if ($request->isPost() && $csrf->validateFromRequest($request)) {
    // Safe to process
}
```

### 3. Use HTTPS

```php
// Configure session for HTTPS
$session = new Session(['secure' => true]);
```

### 4. Proper Error Handling

```php
if (!$csrf->validateFromRequest($request, 'login')) {
    // Log the attempt
    error_log('CSRF validation failed for IP: ' . $request->ip());
    
    // Return error response
    return Response::forbidden('Invalid security token');
}
```

### 5. Token Rotation

```php
// Regenerate tokens after successful operations
if ($csrf->validateFromRequest($request, 'login') && $user->login()) {
    $csrf->invalidateToken('login'); // Force new token generation
    // Redirect to prevent token reuse
}
```

### 6. AJAX Implementation

```javascript
// JavaScript example for AJAX requests
const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

fetch('/api/endpoint', {
    method: 'POST',
    headers: {
        'X-CSRF-Token': token,
        'Content-Type': 'application/json'
    },
    body: JSON.stringify(data)
});
```

## Error Handling

### Common Validation Failures

```php
try {
    if (!$csrf->validateFromRequest($request, 'delete_user')) {
        throw new SecurityException('CSRF token validation failed');
    }
} catch (SecurityException $e) {
    // Log security incident
    $logger->warning('CSRF attack attempt', [
        'ip' => $request->ip(),
        'user_agent' => $request->userAgent(),
        'action' => 'delete_user'
    ]);
    
    return Response::forbidden('Security token invalid');
}
```

### Token Generation Errors

```php
// Check if tokens exist before validation
if (!$csrf->hasTokens) {
    // No tokens available, redirect to form
    return Response::redirect('/form');
}
```

## Integration Examples

### Controller Integration

```php
class UserController
{
    public function __construct(
        private CsrfProtection $csrf,
        private UserService $userService
    ) {}
    
    public function delete(Request $request): Response
    {
        if (!$csrf->validateFromRequest($request, 'delete_user')) {
            return Response::forbidden('Invalid security token');
        }
        
        $userId = $request->int('user_id');
        $this->userService->delete($userId);
        
        return Response::json(['message' => 'User deleted successfully']);
    }
    
    public function showDeleteForm(int $userId): Response
    {
        $html = "
            <form method='POST' action='/users/{$userId}/delete'>
                {$this->csrf->field('delete_user')}
                <p>Are you sure you want to delete this user?</p>
                <button type='submit'>Delete User</button>
            </form>
        ";
        
        return Response::html($html);
    }
}
```

### Middleware Integration

```php
class CsrfMiddleware
{
    public function __construct(private CsrfProtection $csrf) {}
    
    public function handle(Request $request, callable $next): Response
    {
        // Skip CSRF for GET requests
        if ($request->isGet()) {
            return $next($request);
        }
        
        // Validate CSRF token
        if (!$this->csrf->validateFromRequest($request)) {
            return Response::forbidden('CSRF token mismatch');
        }
        
        return $next($request);
    }
}
```

## Testing

### Unit Test Example

```php
public function testCsrfValidation(): void
{
    $session = new MockSession();
    $csrf = new CsrfProtection($session);
    
    // Generate token
    $token = $csrf->generateToken('test');
    
    // Create request with token
    $request = Request::create('POST', '/test', ['_token' => $token]);
    
    // Validate
    $this->assertTrue($csrf->validateFromRequest($request, 'test'));
}
```

This documentation covers all major features of the CSRF Protection system. The implementation provides robust security while maintaining ease of use and flexibility for different application needs.