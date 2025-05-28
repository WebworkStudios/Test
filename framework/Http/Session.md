# Session API Documentation

## Overview

The `Session` class provides secure session management with PHP 8.4 features, optimized lazy loading, and advanced security features.

## Class Constants

```php
private const int MAX_LIFETIME = 7200;        // 2 hours session timeout
private const int REGENERATE_INTERVAL = 300;  // 5 minutes regeneration interval
private const int VALIDATION_INTERVAL = 60;   // 1 minute validation interval
private const int MAX_DATA_SIZE = 1048576;    // 1MB maximum data size
private const string SESSION_PREFIX = '_framework_'; // Prefix for framework data
```

## Property Hooks (PHP 8.4)

### Read-only Properties
```php
public private(set) bool $started = false;      // Session status
public private(set) ?string $sessionId = null;  // Current session ID
```

### Lazy-loaded Properties
```php
public string|int|null $userId {
    get => $this->getUserDataLazy()['_user_id'] ?? null;
}

public bool $isLoggedIn {
    get => isset($this->getUserDataLazy()['_user_id']);
}

public array $userInfo {
    get => $this->getUserDataLazy()['_user_data'] ?? [];
}
```

## Constructor

```php
public function __construct(array $config = [])
```

**Parameters:**
- `$config` - Optional configuration options:
    - `secure`: bool - HTTPS cookie flag
    - `samesite`: string - SameSite attribute ('Lax', 'Strict', 'None')
    - `lifetime`: int - Session lifetime in seconds
    - `name`: string - Session name
    - `save_path`: string - Session save path
    - `validate_ip`: bool - Enable IP address validation

## Session Management

### start()
```php
public function start(): void
```
Starts the session with security hardening and anti-session-fixation protection.

**Throws:** `RuntimeException` - If session start fails

### regenerate()
```php
public function regenerate(bool $deleteOld = true): void
```
Regenerates session ID to prevent session fixation attacks.

**Parameters:**
- `$deleteOld` - Delete old session data (default: true)

**Throws:** `RuntimeException` - On regeneration errors

### destroy()
```php
public function destroy(): void
```
Completely destroys the session, clearing all data and session cookies.

## Data Management

### get()
```php
public function get(string $key, mixed $default = null): mixed
```
Retrieves session value with lazy loading.

**Parameters:**
- `$key` - Session variable key
- `$default` - Default value if not present

**Returns:** Stored value or default value

### set()
```php
public function set(string $key, mixed $value): void
```
Sets session value with size validation and optimized synchronization.

**Parameters:**
- `$key` - Session variable key
- `$value` - Value to store

**Throws:** `InvalidArgumentException` - For oversized data (>1MB)

### has()
```php
public function has(string $key): bool
```
Checks if session key exists (lazy loading).

**Parameters:**
- `$key` - Key to check

**Returns:** true if present

### remove()
```php
public function remove(string $key): void
```
Removes session variable with optimized synchronization.

**Parameters:**
- `$key` - Key to remove

### all()
```php
public function all(): array
```
Returns all session data (lazy loading).

**Returns:** Array of all session data

### clear()
```php
public function clear(): void
```
Clears all session data.

## User Authentication

### login()
```php
public function login(string|int $userId, array $userData = []): void
```
Stores user authentication with automatic session regeneration.

**Parameters:**
- `$userId` - Unique user ID
- `$userData` - Additional user data (optional)

**Internal Data:**
- `_user_id`: User ID
- `_user_data`: User data
- `_login_time`: Login timestamp
- `_last_activity`: Last activity timestamp

### logout()
```php
public function logout(): void
```
Removes user authentication and regenerates session.

### touch()
```php
public function touch(): void
```
Updates the last activity timestamp.

## Flash Messages

### flash()
```php
public function flash(string $key, mixed $value = null): mixed
```
Flash message management with optimized handling.

**Parameters:**
- `$key` - Flash message key
- `$value` - Value (null = read and remove message)

**Returns:**
- When reading: Stored message or null
- When setting: true

**Example:**
```php
// Set flash message
$session->flash('success', 'Data saved successfully!');

// Read flash message (automatically removed)
$message = $session->flash('success');
```

## Security Features

### Automatic Validation
- **Session Timeout**: Automatic maximum lifetime checking
- **User Agent Validation**: Protection against session hijacking
- **IP Address Validation**: Optionally configurable
- **Periodic Regeneration**: Automatic session ID renewal

### Session Fingerprinting
The class automatically stores:
- `_user_agent`: Browser fingerprint
- `_ip_address`: Client IP (optional)
- `_regenerated_at`: Last regeneration
- `_last_activity`: Last activity

### Configured Security Settings
```php
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', $isHttps);
ini_set('session.cookie_samesite', 'Lax');
```

## Lazy Loading System

### Optimizations
- **Session Data**: Only loaded when needed
- **Flash Data**: Separate lazy loading
- **User Data**: Own lazy loading
- **Batch Synchronization**: Grouped write operations

### Synchronization
```php
private function markForSync(string $type, string $key, mixed $value): void
private function syncPending(): void
```

Automatic synchronization occurs:
- When more than 10 pending changes exist
- On destructor call
- On explicit clear() call

## Error Handling

### Exceptions
- `RuntimeException`: Session start errors, validation errors
- `InvalidArgumentException`: Data size exceeded

### Automatic Recovery
- Session restart on timeout
- Automatic cleanup on hijacking suspicion

## Usage Example

```php
// Create and start session
$session = new Session([
    'secure' => true,
    'samesite' => 'Strict',
    'validate_ip' => true
]);

// Store data
$session->set('username', 'john_doe');
$session->set('preferences', ['theme' => 'dark']);

// Login user
$session->login('user123', [
    'name' => 'John Doe',
    'role' => 'admin'
]);

// Use property hooks
if ($session->isLoggedIn) {
    echo "Welcome, " . $session->userInfo['name'];
}

// Flash messages
$session->flash('success', 'Successfully logged in!');
$message = $session->flash('success'); // Read and remove

// End session
$session->logout();
$session->destroy();
```

## Performance Notes

### Lazy Loading
- Session data loaded only when needed
- Minimal performance impact for unused features
- Batch synchronization reduces I/O operations

### Memory Optimization
- Maximum data size: 1MB per key
- Automatic cleanup of consumed flash messages
- Efficient array operations

### Security Optimization
- Validation only every 60 seconds
- Regeneration only every 5 minutes
- Intelligent fingerprint checking