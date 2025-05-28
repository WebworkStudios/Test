# Framework Container - API Documentation

## Table of Contents

- [Overview](#overview)
- [Quick Start](#quick-start)
- [Core Classes](#core-classes)
- [Attributes](#attributes)
- [Service Providers](#service-providers)
- [Examples](#examples)
- [Best Practices](#best-practices)
- [Migration Guide](#migration-guide)

## Overview

The Framework Container is a high-performance, security-first dependency injection container for PHP 8.4+. It features:

- ⚡ **High Performance**: WeakMap caching, compilation, optimized resolution
- 🔒 **Security First**: Built-in validation, path protection, content scanning
- 🏷️ **Attribute-based**: Modern PHP 8+ attribute configuration
- 🔄 **Lazy Loading**: PHP 8.4 native lazy objects with fallback
- 🎯 **Auto-Discovery**: Automatic service registration via attributes
- 📦 **Zero Dependencies**: Standalone, framework-agnostic

## Quick Start

### Installation

```bash
composer require framework/container
```

### Basic Usage

```php
<?php
use Framework\Container\Container;
use Framework\Container\Attributes\Service;

// Create container
$container = new Container();

// Register a service
$container->bind('logger', MyLogger::class);

// Resolve service
$logger = $container->resolve('logger');

// Auto-registration with attributes
#[Service(singleton: true)]
class DatabaseService
{
    public function __construct(
        #[Config('database.host')] string $host
    ) {}
}

// Auto-discover services
$container->autodiscover(['src/Services']);
```

## Core Classes

### Container

The main container class for dependency injection.

#### Constructor

```php
public function __construct(array $config = [], array $allowedPaths = [])
```

**Parameters:**
- `$config` (array): Configuration array for the application
- `$allowedPaths` (array): Allowed file system paths for security

**Example:**
```php
$container = new Container([
    'database' => [
        'host' => 'localhost',
        'port' => 3306
    ]
], ['/app/src', '/app/config']);
```

#### Methods

##### Service Registration

###### bind()

```php
public function bind(string $id, mixed $concrete = null, bool $singleton = false): self
```

Register a service binding.

**Parameters:**
- `$id` (string): Service identifier
- `$concrete` (mixed): Service implementation (class name, callable, or instance)
- `$singleton` (bool): Whether to register as singleton

**Returns:** Container instance for method chaining

**Example:**
```php
$container->bind('logger', FileLogger::class);
$container->bind('cache', fn() => new RedisCache('localhost'));
$container->bind('config', $configArray, true); // singleton
```

###### singleton()

```php
public function singleton(string $id, mixed $concrete = null): self
```

Register a singleton service.

**Parameters:**
- `$id` (string): Service identifier
- `$concrete` (mixed): Service implementation

**Example:**
```php
$container->singleton('database', DatabaseConnection::class);
```

###### instance()

```php
public function instance(string $id, object $instance): self
```

Register an existing instance as a service.

**Parameters:**
- `$id` (string): Service identifier
- `$instance` (object): Existing object instance

**Example:**
```php
$logger = new FileLogger('/var/log/app.log');
$container->instance('logger', $logger);
```

###### lazy()

```php
public function lazy(string $id, callable $factory, bool $singleton = true): self
```

Register a lazy-loaded service.

**Parameters:**
- `$id` (string): Service identifier
- `$factory` (callable): Factory function that creates the service
- `$singleton` (bool): Whether the service should be singleton

**Example:**
```php
$container->lazy('heavy-service', function(Container $container) {
    return new ExpensiveService($container->resolve('config'));
});
```

##### Service Resolution

###### resolve()

```php
public function resolve(string $id, ?string $context = null): mixed
```

Resolve a service from the container.

**Parameters:**
- `$id` (string): Service identifier
- `$context` (string|null): Resolution context for contextual bindings

**Returns:** Resolved service instance

**Throws:**
- `ContainerNotFoundException`: When service is not found
- `ContainerException`: When resolution fails

**Example:**
```php
$logger = $container->resolve('logger');
$apiLogger = $container->resolve('logger', ApiController::class);
```

##### Service Discovery

###### tag()

```php
public function tag(string $id, string $tag): self
```

Add a tag to a service.

**Parameters:**
- `$id` (string): Service identifier
- `$tag` (string): Tag name

**Example:**
```php
$container->bind('file-logger', FileLogger::class);
$container->tag('file-logger', 'logger');
```

###### tagged()

```php
public function tagged(string $tag): array
```

Get all services with a specific tag.

**Parameters:**
- `$tag` (string): Tag name

**Returns:** Array of resolved services

**Example:**
```php
$loggers = $container->tagged('logger');
foreach ($loggers as $logger) {
    $logger->log('Message');
}
```

##### Contextual Bindings

###### when()

```php
public function when(string $context): ContextualBindingBuilder
```

Create a contextual binding.

**Parameters:**
- `$context` (string): Context class name

**Returns:** ContextualBindingBuilder for fluent configuration

**Example:**
```php
$container->when(ApiController::class)
          ->needs(LoggerInterface::class)
          ->give('api-logger');

$container->when(WebController::class)
          ->needs(LoggerInterface::class)
          ->give('web-logger');
```

##### Utility Methods

###### isRegistered()

```php
public function isRegistered(string $id): bool
```

Check if a service is registered.

**Example:**
```php
if ($container->isRegistered('logger')) {
    $logger = $container->resolve('logger');
}
```

###### forget()

```php
public function forget(string $id): self
```

Remove a service from the container.

**Example:**
```php
$container->forget('old-service');
```

###### compile()

```php
public function compile(): self
```

Compile the container for production performance.

**Example:**
```php
// In production bootstrap
$container->compile();
```

###### autodiscover()

```php
public function autodiscover(array $directories): self
```

Auto-discover and register services from directories.

**Parameters:**
- `$directories` (array): Directories to scan

**Example:**
```php
$container->autodiscover([
    'src/Services',
    'src/Repositories',
    'src/Controllers'
]);
```

### ContextualBindingBuilder

Fluent interface for contextual bindings.

#### Methods

###### needs()

```php
public function needs(string $abstract): ContextualBindingNeedsBuilder
```

Specify what dependency is needed.

**Parameters:**
- `$abstract` (string): Interface or class name needed

**Returns:** ContextualBindingNeedsBuilder

#### ContextualBindingNeedsBuilder

###### give()

```php
public function give(mixed $implementation): void
```

Specify the implementation to provide.

**Parameters:**
- `$implementation` (mixed): Implementation to inject

###### giveTagged()

```php
public function giveTagged(string $tag): void
```

Provide the first service with the specified tag.

###### giveFactory()

```php
public function giveFactory(callable $factory): void
```

Provide a factory closure.

**Example:**
```php
$container->when(EmailService::class)
          ->needs(LoggerInterface::class)
          ->giveFactory(fn($c) => $c->resolve('email-logger'));
```

## Attributes

### Service

Mark a class as a service for auto-registration.

```php
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Service
```

#### Constructor

```php
public function __construct(
    public ?string $id = null,
    public bool $singleton = true,
    public array $tags = [],
    public int $priority = 0,
    public bool $lazy = false,
    public ?string $condition = null,
    public string $scope = 'singleton',
    public array $interfaces = []
)
```

**Parameters:**
- `$id`: Custom service ID (defaults to class name)
- `$singleton`: Register as singleton
- `$tags`: Tags for service discovery
- `$priority`: Registration priority
- `$lazy`: Register as lazy service
- `$condition`: Conditional registration
- `$scope`: Service scope ('singleton', 'transient', 'request', 'session')
- `$interfaces`: Specific interfaces to bind to

**Examples:**
```php
#[Service]
class BasicService {}

#[Service(id: 'cache.redis', singleton: true, lazy: true)]
class RedisCache implements CacheInterface {}

#[Service(
    tags: ['logger', 'file-handler'],
    condition: 'app.env === "production"'
)]
class ProductionLogger implements LoggerInterface {}
```

### Factory

Mark a method as a factory for creating services.

```php
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Factory
```

#### Constructor

```php
public function __construct(
    public string $creates,
    public bool $singleton = true,
    public array $tags = [],
    public int $priority = 0,
    public bool $lazy = false,
    public ?string $condition = null,
    public string $scope = 'singleton',
    public array $parameters = []
)
```

**Example:**
```php
class DatabaseFactory
{
    #[Factory(creates: DatabaseConnection::class, singleton: true)]
    public static function createConnection(Container $container): DatabaseConnection
    {
        $config = $container->resolve('config');
        return new DatabaseConnection(
            $config['database']['host'],
            $config['database']['port']
        );
    }
}
```

### Inject

Inject a specific service by ID or tag.

```php
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Inject
```

#### Constructor

```php
public function __construct(
    public ?string $id = null,
    public ?string $tag = null,
    public bool $optional = false,
    public int $priority = 0
)
```

**Examples:**
```php
class UserService
{
    public function __construct(
        #[Inject(id: 'logger.user')] LoggerInterface $logger,
        #[Inject(tag: 'cache')] CacheInterface $cache,
        #[Inject(id: 'mailer', optional: true)] ?MailerInterface $mailer = null
    ) {}
}
```

### Config

Inject configuration values.

```php
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Config
```

#### Constructor

```php
public function __construct(
    public string $key,
    public mixed $default = null,
    public ?string $env = null,
    public bool $required = false,
    public mixed $transform = null
)
```

**Parameters:**
- `$key`: Configuration key (dot notation supported)
- `$default`: Default value if not found
- `$env`: Environment variable to check first
- `$required`: Throw exception if missing
- `$transform`: Transform function

**Examples:**
```php
class DatabaseService
{
    public function __construct(
        #[Config('database.host', 'localhost')] string $host,
        #[Config('database.port', 3306)] int $port,
        #[Config('app.debug', false)] bool $debug,
        #[Config('cache.ttl', env: 'CACHE_TTL')] int $ttl
    ) {}
}
```

## Service Providers

Service providers organize service registrations.

### Creating a Service Provider

```php
<?php
use Framework\Container\ServiceProvider;

class DatabaseServiceProvider extends ServiceProvider
{
    protected int $priority = 100;
    protected array $requiredConfig = ['database.host', 'database.user'];

    public function shouldLoad(): bool
    {
        return $this->getConfig('database.enabled', false);
    }

    public function register(): void
    {
        $this->singleton('db.connection', function(Container $container) {
            return new PDO(
                $this->getConfig('database.dsn'),
                $this->getConfig('database.user'),
                $this->getConfig('database.password')
            );
        });

        $this->bind('user.repository', UserRepository::class);
        $this->tag('user.repository', 'repository');
    }

    public function boot(): void
    {
        // Called after all providers are registered
        $connection = $this->container->resolve('db.connection');
        $this->runMigrations($connection);
    }
}
```

### Service Provider Methods

#### register()

Register services in the container. Called during registration phase.

#### boot()

Boot services after all providers are registered. All services are available.

#### shouldLoad()

Determine if provider should be loaded based on conditions.

#### Helper Methods

```php
// Configuration
protected function getConfig(string $key, mixed $default = null): mixed
protected function hasConfig(string $key): bool
protected function getEnvConfig(string $key, string $envKey, mixed $default = null): mixed

// Service Registration
protected function bind(string $id, mixed $concrete = null, bool $singleton = false): void
protected function singleton(string $id, mixed $concrete = null): void
protected function instance(string $id, object $instance): void
protected function lazy(string $id, callable $factory, bool $singleton = true): void
protected function tag(string $serviceId, string $tag): void

// Advanced Registration
protected function registerService(string $id, mixed $concrete, array $options = []): void
protected function bindIf(string $condition, string $id, mixed $concrete = null): void
protected function when(string $context): ContextualBindingBuilder
```

## Examples

### Basic Service Registration

```php
<?php
use Framework\Container\Container;

$container = new Container();

// Bind interface to implementation
$container->bind(LoggerInterface::class, FileLogger::class);

// Register with factory
$container->bind('database', function(Container $c) {
    return new Database($c->resolve('config')['database']);
});

// Register singleton
$container->singleton('cache', RedisCache::class);

// Register instance
$config = new Config(['app' => ['debug' => true]]);
$container->instance('config', $config);
```

### Attribute-based Registration

```php
<?php
use Framework\Container\Attributes\{Service, Config, Inject};

#[Service(singleton: true, tags: ['logger'])]
class ApplicationLogger implements LoggerInterface
{
    public function __construct(
        #[Config('logging.level', 'info')] string $level,
        #[Config('logging.path')] string $path
    ) {}
}

#[Service]
class UserController
{
    public function __construct(
        #[Inject(tag: 'logger')] LoggerInterface $logger,
        UserRepository $repository
    ) {}
}

// Auto-discover and register
$container->autodiscover(['src/']);
```

### Contextual Bindings

```php
<?php
// Different loggers for different contexts
$container->when(ApiController::class)
          ->needs(LoggerInterface::class)
          ->give('api.logger');

$container->when(WebController::class)
          ->needs(LoggerInterface::class)
          ->give('web.logger');

$container->when(EmailService::class)
          ->needs(LoggerInterface::class)
          ->giveTagged('email-logger');
```

### Lazy Loading

```php
<?php
// Lazy service registration
$container->lazy('heavy.service', function(Container $c) {
    return new ExpensiveService(
        $c->resolve('database'),
        $c->resolve('cache')
    );
});

// Attribute-based lazy loading
#[Service(lazy: true)]
class ExpensiveService
{
    public function __construct(
        DatabaseInterface $db,
        CacheInterface $cache
    ) {}
}
```

### Service Providers

```php
<?php
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerServices([
            'logger' => [
                'concrete' => FileLogger::class,
                'singleton' => true,
                'tags' => ['logger']
            ],
            'cache' => [
                'concrete' => fn($c) => new RedisCache($c->resolve('config')['redis']),
                'singleton' => true
            ]
        ]);
    }

    public function boot(): void
    {
        $logger = $this->container->resolve('logger');
        $logger->info('Application booted');
    }
}

// Register provider
$container = new Container();
$provider = new AppServiceProvider($container);
$provider->register();
$provider->boot();
```

### Configuration Injection

```php
<?php
#[Service]
class MailService
{
    public function __construct(
        #[Config('mail.driver', 'smtp')] string $driver,
        #[Config('mail.host')] string $host,
        #[Config('mail.port', 587)] int $port,
        #[Config('mail.encryption', env: 'MAIL_ENCRYPTION')] ?string $encryption = null,
        #[Config('mail.username', required: true)] string $username,
        #[Config('mail.timeout', transform: fn($v) => (int)$v * 1000)] int $timeout
    ) {}
}
```

## Best Practices

### Security

1. **Always validate service IDs:**
```php
// ✅ Good
$container->bind('user.service', UserService::class);

// ❌ Bad - potential security issue
$container->bind('../../../etc/passwd', EvilService::class);
```

2. **Use allowed paths:**
```php
$container = new Container([], [
    '/app/src',
    '/app/config'  // Only allow these paths
]);
```

### Performance

1. **Compile in production:**
```php
// Production bootstrap
$container->compile();
```

2. **Use singletons for stateless services:**
```php
#[Service(singleton: true)]  // ✅ Good for stateless services
class CalculatorService {}

#[Service(singleton: false)] // ✅ Good for stateful services  
class UserSession {}
```

3. **Lazy load expensive services:**
```php
#[Service(lazy: true)]
class ExpensiveApiClient {}
```

### Code Organization

1. **Use service providers for modularity:**
```php
// Group related services
class DatabaseServiceProvider extends ServiceProvider {}
class CacheServiceProvider extends ServiceProvider {}
class LoggingServiceProvider extends ServiceProvider {}
```

2. **Use tags for service groups:**
```php
#[Service(tags: ['middleware', 'auth'])]
class AuthMiddleware {}

// Later: get all middleware
$middleware = $container->tagged('middleware');
```

3. **Use contextual bindings for variations:**
```php
$container->when(AdminController::class)
          ->needs(LoggerInterface::class)  
          ->give('admin.logger');
```

### Error Handling

1. **Use optional injection for optional dependencies:**
```php
public function __construct(
    LoggerInterface $logger,
    #[Inject(id: 'cache', optional: true)] ?CacheInterface $cache = null
) {}
```

2. **Handle missing services gracefully:**
```php
if ($container->isRegistered('optional.service')) {
    $service = $container->resolve('optional.service');
}
```

## Migration Guide

### From Symfony DI

```php
// Symfony
$container->register('mailer', Mailer::class)
         ->addArgument('%mailer.transport%');

// Framework Container
#[Service]
class Mailer
{
    public function __construct(
        #[Config('mailer.transport')] string $transport
    ) {}
}
```

### From Laravel Container

```php
// Laravel
$app->singleton('cache', function ($app) {
    return new RedisCache($app['config']['cache.redis']);
});

// Framework Container  
#[Service(singleton: true)]
class RedisCache
{
    public function __construct(
        #[Config('cache.redis')] array $config
    ) {}
}
```

### From PHP-DI

```php
// PHP-DI
$container->set('logger', \DI\create(FileLogger::class)
    ->constructor(\DI\get('config.logging.path')));

// Framework Container
#[Service]
class FileLogger  
{
    public function __construct(
        #[Config('config.logging.path')] string $path
    ) {}
}
```

## Error Reference

### ContainerException

Base exception for container operations.

**Methods:**
- `cannotResolve(string $service, string $reason = '')`
- `invalidService(string $service, string $reason)`
- `circularDependency(array $chain)`
- `securityViolation(string $service, string $reason)`

### ContainerNotFoundException

Thrown when a service is not found.

**Methods:**
- `serviceNotFound(string $service, array $availableServices = [])`
- `tagNotFound(string $tag, array $availableTags = [])`
- `getSuggestions(): array` - Get suggested similar services

**Example:**
```php
try {
    $service = $container->resolve('non-existent');
} catch (ContainerNotFoundException $e) {
    echo $e->getMessageWithSuggestions();
    // Output: Service 'non-existent' not found. Did you mean: existing-service?
}
```

---

*This documentation covers Framework Container v1.0. For updates and examples, visit the project repository.*