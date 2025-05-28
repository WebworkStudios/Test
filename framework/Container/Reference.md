# Framework Container - Quick Reference

## 🚀 Quick Start

```php
use Framework\Container\Container;
use Framework\Container\Attributes\{Service, Config, Inject};

// 1. Create container
$container = new Container($config, $allowedPaths);

// 2. Register services
$container->bind('logger', FileLogger::class);
$container->singleton('cache', RedisCache::class);

// 3. Resolve services  
$logger = $container->resolve('logger');

// 4. Auto-discovery
$container->autodiscover(['src/Services']);
```

## 📝 Attribute Cheat Sheet

### @Service
```php
#[Service]                                    // Basic service
#[Service(singleton: true)]                   // Singleton (default)
#[Service(lazy: true)]                        // Lazy loading
#[Service(tags: ['logger', 'file'])]          // Tagged service
#[Service(id: 'custom.name')]                 // Custom ID
#[Service(condition: 'app.env === "prod"')]   // Conditional
```

### @Config
```php
#[Config('database.host')]                    // Required config
#[Config('database.port', 3306)]              // With default
#[Config('debug', env: 'APP_DEBUG')]          // Environment variable
#[Config('timeout', required: true)]          // Required, no default
#[Config('size', transform: fn($v) => $v*2)]  // Transform value
```

### @Inject
```php
#[Inject(id: 'logger.file')]                  // Specific service ID
#[Inject(tag: 'cache')]                       // First tagged service
#[Inject(id: 'mailer', optional: true)]       // Optional injection
```

### @Factory
```php
#[Factory(creates: Connection::class)]
public static function createConnection(Container $c): Connection
{
    return new Connection($c->resolve('config')['database']);
}
```

## 🔧 Container Methods

| Method | Purpose | Example |
|--------|---------|---------|
| `bind($id, $concrete, $singleton)` | Register service | `$c->bind('logger', FileLogger::class)` |
| `singleton($id, $concrete)` | Register singleton | `$c->singleton('cache', RedisCache::class)` |
| `instance($id, $object)` | Register instance | `$c->instance('config', $configObj)` |
| `lazy($id, $factory)` | Lazy service | `$c->lazy('heavy', fn() => new Heavy())` |
| `resolve($id, $context)` | Get service | `$logger = $c->resolve('logger')` |
| `tag($id, $tag)` | Tag service | `$c->tag('file-logger', 'logger')` |
| `tagged($tag)` | Get tagged | `$loggers = $c->tagged('logger')` |
| `when($context)->needs($dep)->give($impl)` | Contextual | See below |
| `isRegistered($id)` | Check exists | `if ($c->isRegistered('cache'))` |
| `forget($id)` | Remove service | `$c->forget('old-service')` |
| `compile()` | Compile for prod | `$c->compile()` |
| `autodiscover($dirs)` | Auto-register | `$c->autodiscover(['src/'])` |

## 🎯 Contextual Bindings

```php
// Basic contextual binding
$container->when(ApiController::class)
          ->needs(LoggerInterface::class)
          ->give('api.logger');

// Multiple bindings
$container->when(EmailService::class)
          ->needsMany([
              LoggerInterface::class => 'email.logger',
              CacheInterface::class => 'email.cache'
          ]);

// Tagged services
$container->when(ReportService::class)
          ->needs(LoggerInterface::class)
          ->giveTagged('report-logger');

// Factory
$container->when(PaymentService::class)
          ->needs(GatewayInterface::class)
          ->giveFactory(fn($c) => new PayPalGateway($c->resolve('config')));

// Conditional
$container->when(DevController::class)
          ->needs(LoggerInterface::class)
          ->giveWhen(
              fn($c) => $c->resolve('config')['app']['debug'],
              'debug.logger'
          );
```

## 🏗️ Service Provider Template

```php
class MyServiceProvider extends ServiceProvider
{
    protected int $priority = 100;
    protected array $requiredConfig = ['my.config.key'];
    protected array $requiredServices = ['dependency.service'];
    
    public function shouldLoad(): bool
    {
        return $this->getConfig('my.enabled', true);
    }
    
    public function register(): void
    {
        // Register services
        $this->singleton('my.service', MyService::class);
        $this->bind('my.repository', MyRepository::class);
        
        // Bulk registration
        $this->registerServices([
            'service1' => ['concrete' => Service1::class, 'tags' => ['tag1']],
            'service2' => ['concrete' => fn($c) => new Service2(), 'lazy' => true]
        ]);
        
        // Conditional
        $this->bindIf('debug', 'debug.service', DebugService::class);
    }
    
    public function boot(): void
    {
        // Called after all providers registered
        $service = $this->container->resolve('my.service');
        $service->initialize();
    }
}
```

## 🔒 Security Checklist

- ✅ Use `allowedPaths` in constructor
- ✅ Validate service IDs (no `..`, `/`, invalid chars)
- ✅ Use attribute-based registration over dynamic
- ✅ Avoid injecting `Container` directly (service locator anti-pattern)
- ✅ Compile container in production

## ⚡ Performance Tips

1. **Compile in production:**
```php
$container->compile(); // Generates optimized bindings
```

2. **Use singletons for stateless services:**
```php
#[Service(singleton: true)]  // ✅ Stateless
class Calculator {}

#[Service(singleton: false)] // ✅ Stateful
class UserSession {}
```

3. **Lazy load expensive services:**
```php
#[Service(lazy: true)]
class HeavyApiClient {}
```

4. **Cache reflection:**
```php
// Automatically cached in WeakMap
$container->resolve('service'); // Fast on subsequent calls
```

## 🚨 Common Mistakes

### ❌ Service Locator Anti-pattern
```php
// DON'T inject the container
class BadService
{
    public function __construct(Container $container) {
        $this->logger = $container->resolve('logger'); // ❌
    }
}

// DO inject specific dependencies  
class GoodService
{
    public function __construct(LoggerInterface $logger) { // ✅
        $this->logger = $logger;
    }
}
```

### ❌ Circular Dependencies
```php
// Will throw CircularDependencyException
class A { public function __construct(B $b) {} }
class B { public function __construct(A $a) {} }
```

### ❌ Missing Required Config
```php
#[Config('required.key', required: true)] // Will throw if missing
```

## 🔍 Debugging

### Get Container Stats
```php
$stats = $container->getStats();
/*
[
    'total_services' => 50,
    'resolved_instances' => 12,
    'lazy_services' => 5,
    'compiled' => true,
    'memory_usage' => 1024000
]
*/
```

### Error Handling
```php
try {
    $service = $container->resolve('non-existent');
} catch (ContainerNotFoundException $e) {
    // Get suggestions for similar services
    $suggestions = $e->getSuggestions();
    echo $e->getMessageWithSuggestions();
}
```

## 📦 Real-World Examples

### Database Service
```php
#[Service(singleton: true)]
class DatabaseService
{
    public function __construct(
        #[Config('database.host')] string $host,
        #[Config('database.port', 3306)] int $port,
        #[Config('database.name')] string $database,
        #[Config('database.user')] string $user,
        #[Config('database.password', env: 'DB_PASSWORD')] string $password
    ) {
        $this->pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$database}",
            $user, $password
        );
    }
}
```

### Logger with Context
```php
#[Service(tags: ['logger'])]
class ContextualLogger implements LoggerInterface
{
    public function __construct(
        #[Config('logging.level', 'info')] string $level,
        #[Config('logging.file')] string $file,
        #[Inject(id: 'app.context', optional: true)] ?array $context = null
    ) {}
}

// Contextual binding
$container->when(ApiController::class)
          ->needs(LoggerInterface::class)
          ->giveWhen(
              fn() => true,
              fn($c) => new ContextualLogger('debug', 'api.log', ['api' => true])
          );
```

### Cache Factory
```php
class CacheFactory
{
    #[Factory(creates: CacheInterface::class, singleton: true)]
    public static function createCache(Container $container): CacheInterface
    {
        $config = $container->resolve('config')['cache'];
        
        return match ($config['driver']) {
            'redis' => new RedisCache($config['redis']),
            'memcached' => new MemcachedCache($config['memcached']),
            'file' => new FileCache($config['file']['path']),
            default => new ArrayCache()
        };
    }
    
    #[Factory(creates: 'cache.tagged', tags: ['cache'])]
    public static function createTaggedCache(Container $container): TaggedCache
    {
        return new TaggedCache($container->resolve(CacheInterface::class));
    }
}
```

### HTTP Client with Retry
```php
#[Service(lazy: true, tags: ['http', 'api'])]
class HttpClient
{
    public function __construct(
        #[Config('http.timeout', 30)] int $timeout,
        #[Config('http.retries', 3)] int $retries,
        #[Config('http.base_url')] string $baseUrl,
        #[Inject(tag: 'logger')] LoggerInterface $logger
    ) {
        $this->client = new GuzzleHttp\Client([
            'timeout' => $timeout,
            'base_uri' => $baseUrl
        ]);
        $this->retries = $retries;
        $this->logger = $logger;
    }
}
```

### Event Dispatcher
```php
#[Service(singleton: true)]
class EventDispatcher
{
    private array $listeners = [];
    
    public function __construct(
        #[Inject(tag: 'event.listener')] array $listeners = []
    ) {
        foreach ($listeners as $listener) {
            $this->addListener($listener);
        }
    }
}

// Event listeners
#[Service(tags: ['event.listener'])]
class UserRegisteredListener
{
    public function handle(UserRegistered $event): void
    {
        // Send welcome email
    }
}
```

## 🔄 Lifecycle Management

### Scopes
```php
#[Service(scope: 'singleton')]    // Lives for entire application
class DatabaseConnection {}

#[Service(scope: 'transient')]    // New instance every time
class EmailMessage {}

#[Service(scope: 'request')]      // Lives for HTTP request
class RequestContext {}

#[Service(scope: 'session')]      // Lives for user session
class UserPreferences {}
```

### Cleanup
```php
class MyServiceProvider extends ServiceProvider
{
    public function cleanup(): void
    {
        // Called when provider is destroyed
        $connection = $this->container->resolve('database');
        $connection->close();
    }
}

// Container cleanup
$container->gc(); // Returns number of cleaned up services
```

## 🧪 Testing Integration

### Mock Services in Tests
```php
class UserServiceTest extends TestCase
{
    public function testUserCreation()
    {
        $container = new Container();
        
        // Mock dependencies
        $mockLogger = $this->createMock(LoggerInterface::class);
        $mockRepo = $this->createMock(UserRepositoryInterface::class);
        
        $container->instance('logger', $mockLogger);
        $container->instance('user.repository', $mockRepo);
        
        // Register service under test
        $container->bind('user.service', UserService::class);
        
        $userService = $container->resolve('user.service');
        
        // Test...
        $mockRepo->expects($this->once())
                 ->method('save')
                 ->with($this->isInstanceOf(User::class));
                 
        $userService->createUser('test@example.com');
    }
}
```

### Test Container
```php
class TestContainer extends Container
{
    public function __construct()
    {
        parent::__construct([
            'app' => ['env' => 'testing'],
            'database' => ['driver' => 'sqlite', 'path' => ':memory:']
        ]);
        
        // Register test-specific services
        $this->singleton('test.database', fn() => new SQLiteDatabase(':memory:'));
    }
}
```

## 🔧 Advanced Patterns

### Decorator Pattern
```php
// Base service
#[Service(id: 'cache.base')]
class RedisCache implements CacheInterface {}

// Decorator
#[Service(id: 'cache.logging')]
class LoggingCacheDecorator implements CacheInterface
{
    public function __construct(
        #[Inject(id: 'cache.base')] CacheInterface $cache,
        #[Inject(tag: 'logger')] LoggerInterface $logger
    ) {
        $this->cache = $cache;
        $this->logger = $logger;
    }
    
    public function get($key)
    {
        $this->logger->debug("Cache get: {$key}");
        return $this->cache->get($key);
    }
}

// Bind decorator as main cache
$container->bind(CacheInterface::class, 'cache.logging');
```

### Factory Method Pattern
```php
interface PaymentGatewayFactory
{
    public function create(string $type): PaymentGatewayInterface;
}

#[Service]
class PaymentGatewayFactory implements PaymentGatewayFactory
{
    public function __construct(
        private Container $container
    ) {}
    
    public function create(string $type): PaymentGatewayInterface
    {
        return match ($type) {
            'paypal' => $this->container->resolve('gateway.paypal'),
            'stripe' => $this->container->resolve('gateway.stripe'),
            'square' => $this->container->resolve('gateway.square'),
            default => throw new InvalidArgumentException("Unknown gateway: {$type}")
        };
    }
}
```

### Repository Pattern with Multiple Drivers
```php
interface UserRepositoryInterface
{
    public function find(int $id): ?User;
    public function save(User $user): void;
}

#[Service(id: 'user.repository.database')]
class DatabaseUserRepository implements UserRepositoryInterface
{
    public function __construct(
        #[Inject(id: 'database')] DatabaseInterface $db
    ) {}
}

#[Service(id: 'user.repository.memory')]  
class MemoryUserRepository implements UserRepositoryInterface
{
    private array $users = [];
}

// Contextual binding based on environment
$container->when(UserController::class)
          ->needs(UserRepositoryInterface::class)
          ->giveWhen(
              fn($c) => $c->resolve('config')['app']['env'] === 'testing',
              'user.repository.memory'
          );

$container->when(UserController::class)
          ->needs(UserRepositoryInterface::class)
          ->giveWhen(
              fn($c) => $c->resolve('config')['app']['env'] !== 'testing',
              'user.repository.database'
          );
```

## 📊 Monitoring & Profiling

### Service Resolution Timing
```php
class ProfiledContainer extends Container
{
    private array $resolutionTimes = [];
    
    public function resolve(string $id, ?string $context = null): mixed
    {
        $start = microtime(true);
        $result = parent::resolve($id, $context);
        $end = microtime(true);
        
        $this->resolutionTimes[$id] = ($end - $start) * 1000; // ms
        
        return $result;
    }
    
    public function getSlowServices(float $thresholdMs = 10.0): array
    {
        return array_filter(
            $this->resolutionTimes,
            fn($time) => $time > $thresholdMs
        );
    }
}
```

### Memory Usage Tracking
```php
$stats = $container->getStats();

if ($stats['memory_usage'] > 50 * 1024 * 1024) { // 50MB
    $container->gc(); // Cleanup unused services
    
    // Log memory usage
    $logger->warning('High container memory usage', [
        'memory' => $stats['memory_usage'],
        'services' => $stats['total_services'],
        'instances' => $stats['resolved_instances']
    ]);
}
```

## 🚀 Production Optimizations

### Container Compilation
```php
// Build script (build.php)
$container = new Container($config);

// Register all services
$container->autodiscover(['src/']);

// Add service providers
foreach ($providers as $provider) {
    $provider->register();
}

// Compile for production
$container->compile();

// Optional: Generate static container
$dumper = new ContainerDumper($container);
file_put_contents('cache/container.php', $dumper->dump());
```

### Preloading (PHP 7.4+)
```php
// preload.php
opcache_compile_file('src/Container/Container.php');
opcache_compile_file('src/Container/ServiceDiscovery.php');
opcache_compile_file('cache/container.php');

// Preload frequently used services
$container = require 'cache/container.php';
$container->resolve('logger');
$container->resolve('database');
$container->resolve('cache');
```

### Configuration Caching
```php
// Cache configuration resolution
$container = new Container();

if (file_exists('cache/config.php')) {
    $config = require 'cache/config.php';
    $container->setConfig($config);
} else {
    $config = $container->buildConfig(['config/']);
    file_put_contents('cache/config.php', '<?php return ' . var_export($config, true) . ';');
}
```

## 🐛 Troubleshooting

### Common Issues

**1. Circular Dependencies**
```php
// Problem: A depends on B, B depends on A
class A { public function __construct(B $b) {} }
class B { public function __construct(A $a) {} }

// Solution: Break cycle with interface or factory
interface AInterface {}
class A implements AInterface { public function __construct(B $b) {} }
class B { public function __construct(AInterface $a) {} }
```

**2. Missing Configuration**
```php
// Problem: Config key not found
#[Config('missing.key')] string $value

// Solution: Provide default or use optional
#[Config('missing.key', 'default')] string $value
// or
#[Config('missing.key', required: false)] ?string $value = null
```

**3. Service Not Found**
```php
try {
    $service = $container->resolve('typo-service');
} catch (ContainerNotFoundException $e) {
    // Shows: "Did you mean: type-service?"
    echo $e->getMessageWithSuggestions();
}
```

**4. Performance Issues**
```php
// Enable profiling
$container->enableProfiling();

// Check slow services
$slowServices = $container->getSlowServices(10.0); // >10ms

// Optimize by:
// 1. Making services lazy
// 2. Using singletons
// 3. Compilation
// 4. Reducing dependencies
```

## 📚 Additional Resources

### Useful Patterns
- **Registry Pattern**: Use tagged services for collections
- **Strategy Pattern**: Use contextual bindings for different implementations
- **Observer Pattern**: Use event dispatcher with tagged listeners
- **Chain of Responsibility**: Use tagged services with priority

### Integration Examples
- **PSR-11 Compatibility**: Implement `ContainerInterface`
- **Symfony Bridge**: Create Symfony bundle
- **Laravel Integration**: Service provider adapter
- **Doctrine Integration**: Entity manager factory

### Performance Benchmarks
```bash
# Run benchmarks
php bin/benchmark --iterations=10000

# Results:
# Simple resolution: 0.001ms
# Complex resolution: 0.005ms  
# Lazy resolution: 0.002ms
# Compiled resolution: 0.0005ms
```

---

*For complete examples and advanced usage, see the full API documentation.*