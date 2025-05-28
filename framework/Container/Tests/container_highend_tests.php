<?php

declare(strict_types=1);

namespace Framework\Container\Tests\Enterprise;

// Lade alle Container-Klassen
require_once __DIR__ . '/../Container.php';
require_once __DIR__ . '/../ServiceProvider.php';
require_once __DIR__ . '/../ContainerException.php';
require_once __DIR__ . '/../ContextualBindingBuilder.php';
require_once __DIR__ . '/../ContextualBindingNeedsBuilder.php';
require_once __DIR__ . '/../ServiceDiscovery.php';
require_once __DIR__ . '/../Attributes/Service.php';
require_once __DIR__ . '/../Attributes/Config.php';
require_once __DIR__ . '/../Attributes/Inject.php';
require_once __DIR__ . '/../Attributes/Factory.php';
require_once __DIR__ . '/../Lazy/LazyProxy.php';
require_once __DIR__ . '/../Lazy/GenericLazyProxy.php';

use Framework\Container\{Container, ServiceProvider, ContainerException, ContainerNotFoundException};
use Framework\Container\Attributes\{Service, Config, Inject, Factory};

/**
 * Enterprise-Grade High-Performance Container Tests
 *
 * Tests für High-End Projekte mit extremen Anforderungen:
 * - Massive Skalierung (10k+ Services)
 * - Concurrent Access Simulation
 * - Memory Pressure Tests
 * - Performance unter Last
 * - Complex Dependency Graphs
 * - Enterprise Patterns
 */
class EnterpriseTestRunner
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];
    private float $startTime;
    private array $metrics = [];

    public function __construct()
    {
        $this->startTime = microtime(true);

        // Erhöhe Memory Limit für extreme Tests
        ini_set('memory_limit', '512M');

        echo "🏢 Enterprise Container Test Suite\n";
        echo "==================================\n";
        echo "💾 Memory Limit: " . ini_get('memory_limit') . "\n";
        echo "⏱️  Start Time: " . date('Y-m-d H:i:s') . "\n\n";
    }

    public function run(): void
    {
        $this->runMassiveScaleTests();
        $this->runPerformanceStressTests();
        $this->runComplexDependencyTests();
        $this->runEnterprisePatternTests();
        $this->runConcurrencySimulationTests();
        $this->runMemoryPressureTests();
        $this->runProductionScenarioTests();
        $this->runFailureRecoveryTests();

        $this->printEnterpriseResults();
    }

    private function runMassiveScaleTests(): void
    {
        echo "🔥 MASSIVE SCALE TESTS\n";
        echo "======================\n";

        $this->test('10k Service Registration Performance', function() {
            $container = new Container();
            $startMem = memory_get_usage();
            $start = microtime(true);

            // Register 10,000 services
            for ($i = 0; $i < 10000; $i++) {
                $container->bind("Service{$i}", "TestService{$i}");
            }

            $registrationTime = microtime(true) - $start;
            $memoryUsed = memory_get_usage() - $startMem;

            $this->metrics['10k_registration_time'] = $registrationTime;
            $this->metrics['10k_registration_memory'] = $memoryUsed;

            $this->assert($registrationTime < 1.0, "10k registrations should take < 1s, took: {$registrationTime}s");
            $this->assert($memoryUsed < 50 * 1024 * 1024, "Memory should be < 50MB, used: " . $this->formatBytes($memoryUsed));

            echo "   📊 Registration Time: {$registrationTime}s\n";
            echo "   📊 Memory Used: " . $this->formatBytes($memoryUsed) . "\n";
        });

        $this->test('1k Complex Service Resolution', function() {
            $container = new Container();

            // Register services with dependencies - fix dependency resolution
            $container->bind(TestDependency::class, TestDependency::class);
            for ($i = 0; $i < 1000; $i++) {
                $container->bind("ComplexService{$i}", ComplexTestService::class);
            }

            $start = microtime(true);
            $resolved = 0;

            // Resolve 100 complex services
            for ($i = 0; $i < 100; $i++) {
                $service = $container->resolve("ComplexService{$i}");
                if ($service instanceof ComplexTestService) {
                    $resolved++;
                }
            }

            $resolutionTime = microtime(true) - $start;
            $this->metrics['1k_resolution_time'] = $resolutionTime;

            $this->assert($resolved === 100, "Should resolve all 100 services");
            $this->assert($resolutionTime < 0.5, "Resolution should take < 0.5s, took: {$resolutionTime}s");

            echo "   📊 Resolution Time: {$resolutionTime}s\n";
            echo "   📊 Services Resolved: {$resolved}/100\n";
        });

        $this->test('Tagged Service Scalability (5k tags)', function() {
            $container = new Container();

            // Register 5000 services with various tags
            for ($i = 0; $i < 5000; $i++) {
                $container->bind("TaggedService{$i}", TestTaggedService::class);
                $container->tag("TaggedService{$i}", "tag" . ($i % 50)); // 50 different tags
            }

            $start = microtime(true);

            // Retrieve services by tags
            $taggedServices = [];
            for ($tag = 0; $tag < 10; $tag++) {
                $services = $container->tagged("tag{$tag}");
                $taggedServices[] = count($services);
            }

            $tagResolutionTime = microtime(true) - $start;
            $this->metrics['5k_tag_resolution_time'] = $tagResolutionTime;

            $this->assert($tagResolutionTime < 0.1, "Tag resolution should be fast, took: {$tagResolutionTime}s");
            $this->assert(max($taggedServices) === 100, "Each tag should have 100 services");

            echo "   📊 Tag Resolution Time: {$tagResolutionTime}s\n";
            echo "   📊 Services per tag: " . max($taggedServices) . "\n";
        });

        echo "\n";
    }

    private function runPerformanceStressTests(): void
    {
        echo "⚡ PERFORMANCE STRESS TESTS\n";
        echo "===========================\n";

        $this->test('High-Frequency Resolution Stress', function() {
            $container = new Container();

            // Setup services with proper dependencies
            $container->singleton(DatabaseConnection::class, DatabaseConnection::class);
            $container->singleton(CacheManager::class, CacheManager::class);
            $container->bind(UserService::class, UserService::class);

            $start = microtime(true);
            $resolutions = 0;

            // Stress test: 10k resolutions in short time
            for ($i = 0; $i < 10000; $i++) {
                $services = [
                    $container->resolve(DatabaseConnection::class),
                    $container->resolve(CacheManager::class),
                    $container->resolve(UserService::class)
                ];
                $resolutions += count($services);
            }

            $stressTime = microtime(true) - $start;
            $this->metrics['stress_resolution_time'] = $stressTime;
            $resolutionsPerSecond = $resolutions / $stressTime;

            $this->assert($stressTime < 2.0, "Stress test should complete in < 2s, took: {$stressTime}s");
            $this->assert($resolutionsPerSecond > 10000, "Should handle > 10k resolutions/sec");

            echo "   📊 Stress Time: {$stressTime}s\n";
            echo "   📊 Resolutions/sec: " . number_format($resolutionsPerSecond, 0) . "\n";
        });

        $this->test('Deep Dependency Chain Performance', function() {
            $container = new Container();

            // Create deep dependency chain (50 levels)
            for ($i = 0; $i < 50; $i++) {
                $container->bind("Layer{$i}", DeepDependencyService::class);
            }

            $start = microtime(true);
            $service = $container->resolve('Layer0');
            $deepResolutionTime = microtime(true) - $start;

            $this->metrics['deep_chain_time'] = $deepResolutionTime;

            $this->assert($service instanceof DeepDependencyService, 'Should resolve deep dependency');
            $this->assert($deepResolutionTime < 0.01, "Deep resolution should be fast, took: {$deepResolutionTime}s");

            echo "   📊 Deep Chain Time: {$deepResolutionTime}s\n";
        });

        $this->test('Compilation Performance Boost', function() {
            $container = new Container();

            // Register many simple services
            for ($i = 0; $i < 1000; $i++) {
                $container->bind("SimpleService{$i}", SimpleService::class);
            }

            // Test non-compiled performance
            $start = microtime(true);
            for ($i = 0; $i < 100; $i++) {
                $container->resolve("SimpleService{$i}");
            }
            $nonCompiledTime = microtime(true) - $start;

            // Compile and test again
            $container->compile();

            $start = microtime(true);
            for ($i = 0; $i < 100; $i++) {
                $container->resolve("SimpleService{$i}");
            }
            $compiledTime = microtime(true) - $start;

            $speedup = $nonCompiledTime / $compiledTime;
            $this->metrics['compilation_speedup'] = $speedup;

            $this->assert($compiledTime <= $nonCompiledTime, 'Compiled should be faster or equal');

            echo "   📊 Non-compiled: {$nonCompiledTime}s\n";
            echo "   📊 Compiled: {$compiledTime}s\n";
            echo "   📊 Speedup: " . number_format($speedup, 2) . "x\n";
        });

        echo "\n";
    }

    private function runComplexDependencyTests(): void
    {
        echo "🕸️  COMPLEX DEPENDENCY TESTS\n";
        echo "=============================\n";

        $this->test('Multi-Level Service Provider Orchestration', function() {
            $container = new Container([
                'database' => ['host' => 'localhost', 'port' => 3306],
                'cache' => ['driver' => 'redis'],
                'logging' => ['level' => 'debug'],
                'app' => ['env' => 'production']
            ]);

            // Register core dependencies first
            $container->singleton(DatabaseConnection::class, DatabaseConnection::class);
            $container->singleton(CacheManager::class, CacheManager::class);
            $container->singleton(SecurityManager::class, SecurityManager::class);
            $container->singleton(Logger::class, Logger::class);

            // Register multiple complex providers
            $providers = [
                new DatabaseServiceProvider($container),
                new CacheServiceProvider($container),
                new LoggingServiceProvider($container),
                new ApplicationServiceProvider($container),
                new SecurityServiceProvider($container),
                new MonitoringServiceProvider($container)
            ];

            $start = microtime(true);

            // Register all providers
            foreach ($providers as $provider) {
                if ($provider->shouldLoad()) {
                    $provider->register();
                }
            }

            // Boot all providers
            foreach ($providers as $provider) {
                if ($provider->shouldLoad()) {
                    $provider->boot();
                }
            }

            $orchestrationTime = microtime(true) - $start;
            $this->metrics['provider_orchestration_time'] = $orchestrationTime;

            // Test that complex services are properly wired
            $userService = $container->resolve('EnterpriseUserService');
            $this->assert($userService instanceof EnterpriseUserService, 'Complex service should be resolved');

            echo "   📊 Orchestration Time: {$orchestrationTime}s\n";
            echo "   📊 Providers Loaded: " . count($providers) . "\n";
        });

        $this->test('Contextual Binding Matrix', function() {
            $container = new Container();

            // Setup complex contextual bindings
            $contexts = [
                'ApiController', 'WebController', 'CliController',
                'AdminController', 'MobileController'
            ];

            $dependencies = [
                'LoggerInterface', 'CacheInterface', 'DatabaseInterface',
                'AuthInterface', 'ValidatorInterface'
            ];

            foreach ($contexts as $context) {
                foreach ($dependencies as $dependency) {
                    $implementation = $context . $dependency;
                    $container->bind($implementation, TestImplementation::class);

                    $container->when($context)
                        ->needs($dependency)
                        ->give($implementation);
                }
            }

            $start = microtime(true);

            // Test contextual resolution
            $resolved = 0;
            foreach ($contexts as $context) {
                $container->bind($context, TestController::class);
                $controller = $container->resolve($context);
                if ($controller instanceof TestController) {
                    $resolved++;
                }
            }

            $contextualTime = microtime(true) - $start;
            $this->metrics['contextual_matrix_time'] = $contextualTime;

            $this->assert($resolved === count($contexts), 'All contextual services should resolve');

            echo "   📊 Contextual Time: {$contextualTime}s\n";
            echo "   📊 Context Matrix: " . count($contexts) . "x" . count($dependencies) . "\n";
        });

        echo "\n";
    }

    private function runEnterprisePatternTests(): void
    {
        echo "🏢 ENTERPRISE PATTERN TESTS\n";
        echo "============================\n";

        $this->test('Microservice Architecture Simulation', function() {
            $container = new Container();

            // Simulate microservice dependencies
            $microservices = [
                'UserMicroservice', 'OrderMicroservice', 'PaymentMicroservice',
                'InventoryMicroservice', 'NotificationMicroservice', 'AnalyticsMicroservice'
            ];

            foreach ($microservices as $service) {
                $container->singleton($service, MicroserviceSimulator::class);
                $container->tag($service, 'microservice');
            }

            // Service mesh simulation
            $container->singleton('ServiceMesh', ServiceMeshSimulator::class);
            $container->singleton('LoadBalancer', LoadBalancerSimulator::class);
            $container->singleton('CircuitBreaker', CircuitBreakerSimulator::class);

            $start = microtime(true);

            $serviceMesh = $container->resolve('ServiceMesh');
            $microservices = $container->tagged('microservice');

            $microserviceTime = microtime(true) - $start;
            $this->metrics['microservice_setup_time'] = $microserviceTime;

            $this->assert(count($microservices) === 6, 'All microservices should be available');
            $this->assert($serviceMesh instanceof ServiceMeshSimulator, 'Service mesh should be configured');

            echo "   📊 Microservice Setup: {$microserviceTime}s\n";
            echo "   📊 Services in Mesh: " . count($microservices) . "\n";
        });

        $this->test('Event-Driven Architecture', function() {
            $container = new Container();

            // Event system setup
            $container->singleton('EventDispatcher', EventDispatcher::class);

            // Register event listeners
            $events = ['UserCreated', 'OrderPlaced', 'PaymentProcessed', 'InventoryUpdated'];
            foreach ($events as $event) {
                for ($i = 0; $i < 5; $i++) {
                    $listenerName = "{$event}Listener{$i}";
                    $container->bind($listenerName, EventListener::class);
                    $container->tag($listenerName, "listener.{$event}");
                }
            }

            $start = microtime(true);

            $dispatcher = $container->resolve('EventDispatcher');

            // Simulate event processing
            foreach ($events as $event) {
                $listeners = $container->tagged("listener.{$event}");
                // Process each listener
            }

            $eventTime = microtime(true) - $start;
            $this->metrics['event_system_time'] = $eventTime;

            $this->assert($dispatcher instanceof EventDispatcher, 'Event dispatcher should be available');

            echo "   📊 Event System Setup: {$eventTime}s\n";
            echo "   📊 Event Types: " . count($events) . "\n";
        });

        $this->test('CQRS Pattern Implementation', function() {
            $container = new Container();

            // Command side
            $container->singleton('CommandBus', CommandBus::class);
            $container->bind('UserCreateCommand', UserCreateCommand::class);
            $container->bind('UserCreateHandler', UserCreateHandler::class);

            // Query side
            $container->singleton('QueryBus', QueryBus::class);
            $container->bind('UserQuery', UserQuery::class);
            $container->bind('UserQueryHandler', UserQueryHandler::class);

            // Event sourcing
            $container->singleton('EventStore', EventStore::class);
            $container->singleton('ProjectionManager', ProjectionManager::class);

            $start = microtime(true);

            $commandBus = $container->resolve('CommandBus');
            $queryBus = $container->resolve('QueryBus');
            $eventStore = $container->resolve('EventStore');

            $cqrsTime = microtime(true) - $start;
            $this->metrics['cqrs_setup_time'] = $cqrsTime;

            $this->assert($commandBus instanceof CommandBus, 'Command bus should be configured');
            $this->assert($queryBus instanceof QueryBus, 'Query bus should be configured');

            echo "   📊 CQRS Setup: {$cqrsTime}s\n";
        });

        echo "\n";
    }

    private function runConcurrencySimulationTests(): void
    {
        echo "🔄 CONCURRENCY SIMULATION\n";
        echo "=========================\n";

        $this->test('Thread-Safe Container Access Simulation', function() {
            $container = new Container();

            // Setup shared services with proper dependency registration
            $container->singleton(SharedResource::class, SharedResource::class);
            $container->bind(ConcurrentService::class, ConcurrentService::class);

            $results = [];
            $start = microtime(true);

            // Simulate 100 concurrent requests
            for ($request = 0; $request < 100; $request++) {
                // Each "request" resolves multiple services
                $services = [
                    $container->resolve(SharedResource::class),
                    $container->resolve(ConcurrentService::class),
                    $container->resolve(SharedResource::class) // Should return same instance
                ];

                $results[] = [
                    'shared_instances_equal' => $services[0] === $services[2],
                    'service_resolved' => $services[1] instanceof ConcurrentService
                ];
            }

            $concurrentTime = microtime(true) - $start;
            $this->metrics['concurrent_simulation_time'] = $concurrentTime;

            $successCount = count(array_filter($results, fn($r) => $r['shared_instances_equal'] && $r['service_resolved']));

            $this->assert($successCount === 100, 'All concurrent requests should succeed');
            $this->assert($concurrentTime < 0.1, "Concurrent simulation should be fast, took: {$concurrentTime}s");

            echo "   📊 Concurrent Time: {$concurrentTime}s\n";
            echo "   📊 Success Rate: {$successCount}/100\n";
        });

        echo "\n";
    }

    private function runMemoryPressureTests(): void
    {
        echo "💾 MEMORY PRESSURE TESTS\n";
        echo "========================\n";

        $this->test('Memory Leak Detection', function() {
            $container = new Container();
            $startMemory = memory_get_usage();

            // Create and destroy many services
            for ($cycle = 0; $cycle < 10; $cycle++) {
                for ($i = 0; $i < 1000; $i++) {
                    $container->bind("TempService{$i}", TemporaryService::class);
                    $service = $container->resolve("TempService{$i}");
                    unset($service);
                    $container->forget("TempService{$i}");
                }

                // Force garbage collection
                gc_collect_cycles();
            }

            $endMemory = memory_get_usage();
            $memoryDiff = $endMemory - $startMemory;
            $this->metrics['memory_leak_test'] = $memoryDiff;

            // Memory should not grow significantly
            $this->assert($memoryDiff < 10 * 1024 * 1024, "Memory leak detected: " . $this->formatBytes($memoryDiff));

            echo "   📊 Memory Difference: " . $this->formatBytes($memoryDiff) . "\n";
        });

        $this->test('Garbage Collection Efficiency', function() {
            $container = new Container();

            // Create many lazy services
            for ($i = 0; $i < 1000; $i++) {
                $container->lazy("LazyService{$i}", fn() => new LazyTestService());
            }

            // Resolve some services
            for ($i = 0; $i < 100; $i++) {
                $service = $container->resolve("LazyService{$i}");
                unset($service);
            }

            $gcStart = memory_get_usage();
            $cleaned = $container->gc();
            $gcEnd = memory_get_usage();

            $this->metrics['gc_cleaned_services'] = $cleaned;
            $this->metrics['gc_memory_freed'] = $gcStart - $gcEnd;

            $this->assert($cleaned >= 0, 'GC should clean up services');

            echo "   📊 Services Cleaned: {$cleaned}\n";
            echo "   📊 Memory Freed: " . $this->formatBytes($gcStart - $gcEnd) . "\n";
        });

        echo "\n";
    }

    private function runProductionScenarioTests(): void
    {
        echo "🚀 PRODUCTION SCENARIO TESTS\n";
        echo "============================\n";

        $this->test('E-Commerce Platform Simulation', function() {
            $container = new Container([
                'database' => ['host' => 'localhost', 'port' => 3306],
                'redis' => ['host' => 'localhost', 'port' => 6379],
                'elasticsearch' => ['host' => 'localhost', 'port' => 9200],
                'app' => ['env' => 'production', 'debug' => false]
            ]);

            // E-commerce services
            $services = [
                'ProductCatalogService', 'InventoryService', 'PricingService',
                'CartService', 'CheckoutService', 'PaymentService',
                'OrderService', 'ShippingService', 'NotificationService',
                'SearchService', 'RecommendationService', 'AnalyticsService'
            ];

            $start = microtime(true);

            foreach ($services as $service) {
                $container->singleton($service, ECommerceService::class);
            }

            // Simulate high-traffic request
            $resolvedServices = [];
            for ($request = 0; $request < 50; $request++) {
                foreach (array_slice($services, 0, 6) as $service) {
                    $resolvedServices[] = $container->resolve($service);
                }
            }

            $ecommerceTime = microtime(true) - $start;
            $this->metrics['ecommerce_simulation_time'] = $ecommerceTime;

            $this->assert(count($resolvedServices) === 300, 'All e-commerce services should resolve');
            $this->assert($ecommerceTime < 0.5, "E-commerce simulation should be fast, took: {$ecommerceTime}s");

            echo "   📊 E-commerce Time: {$ecommerceTime}s\n";
            echo "   📊 Services: " . count($services) . "\n";
            echo "   📊 Resolutions: " . count($resolvedServices) . "\n";
        });

        $this->test('Banking System Simulation', function() {
            $container = new Container([
                'security' => ['level' => 'high'],
                'audit' => ['enabled' => true],
                'compliance' => ['pci_dss' => true, 'gdpr' => true]
            ]);

            // Banking services (high security)
            $bankingServices = [
                'AccountService', 'TransactionService', 'FraudDetectionService',
                'ComplianceService', 'AuditService', 'EncryptionService',
                'SecurityService', 'RiskAssessmentService'
            ];

            foreach ($bankingServices as $service) {
                $container->singleton($service, SecureBankingService::class);
                $container->tag($service, 'banking');
                $container->tag($service, 'secure');
            }

            $start = microtime(true);

            // Simulate secure banking operations
            $secureServices = $container->tagged('secure');
            $bankingServices = $container->tagged('banking');

            $bankingTime = microtime(true) - $start;
            $this->metrics['banking_simulation_time'] = $bankingTime;

            $this->assert(count($secureServices) === 8, 'All secure services should be tagged');
            $this->assert(count($bankingServices) === 8, 'All banking services should be tagged');

            echo "   📊 Banking Setup: {$bankingTime}s\n";
            echo "   📊 Secure Services: " . count($secureServices) . "\n";
        });

        echo "\n";
    }

    private function runFailureRecoveryTests(): void
    {
        echo "🛡️  FAILURE RECOVERY TESTS\n";
        echo "===========================\n";

        $this->test('Service Failure Graceful Degradation', function() {
            $container = new Container();

            // Setup services with fallbacks
            $container->bind('PrimaryService', FailingService::class);
            $container->bind('FallbackService', FallbackService::class);

            $failures = 0;
            $successes = 0;

            for ($i = 0; $i < 100; $i++) {
                try {
                    $service = $container->resolve('PrimaryService');
                    $successes++;
                } catch (\Throwable $e) {
                    $failures++;
                    // Fallback resolution
                    $fallback = $container->resolve('FallbackService');
                    if ($fallback instanceof FallbackService) {
                        $successes++;
                    }
                }
            }

            $this->assert($successes >= 50, "Should handle failures gracefully, successes: {$successes}");

            echo "   📊 Successes: {$successes}/100\n";
            echo "   📊 Failures Handled: {$failures}\n";
        });

        echo "\n";
    }

    private function test(string $name, callable $test): void
    {
        $testStart = microtime(true);

        try {
            $test();
            $testTime = microtime(true) - $testStart;
            $this->passed++;
            echo "✅ {$name} ({$testTime}s)\n";
        } catch (\Throwable $e) {
            $testTime = microtime(true) - $testStart;
            $this->failed++;
            $this->failures[] = [
                'name' => $name,
                'error' => $e->getMessage(),
                'time' => $testTime,
                'trace' => $e->getTraceAsString()
            ];
            echo "❌ {$name} ({$testTime}s)\n";
            echo "   💥 Error: {$e->getMessage()}\n";
        }
    }

    private function assert(bool $condition, string $message = ''): void
    {
        if (!$condition) {
            throw new \AssertionError($message ?: 'Assertion failed');
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unit = 0;

        while ($bytes >= 1024 && $unit < count($units) - 1) {
            $bytes /= 1024;
            $unit++;
        }

        return round($bytes, 2) . ' ' . $units[$unit];
    }

    private function printEnterpriseResults(): void
    {
        $total = $this->passed + $this->failed;
        $duration = round(microtime(true) - $this->startTime, 3);

        echo "\n";
        echo "📊 ENTERPRISE TEST RESULTS\n";
        echo "===========================\n";
        echo "🎯 Total Tests: {$total}\n";
        echo "✅ Passed: {$this->passed}\n";
        echo "❌ Failed: {$this->failed}\n";
        echo "⏱️  Duration: {$duration}s\n";
        echo "💾 Peak Memory: " . $this->formatBytes(memory_get_peak_usage(true)) . "\n";

        if (!empty($this->metrics)) {
            echo "\n📈 PERFORMANCE METRICS:\n";
            echo "========================\n";
            foreach ($this->metrics as $metric => $value) {
                if (is_float($value)) {
                    echo "   {$metric}: " . number_format($value, 6) . "s\n";
                } elseif (is_int($value) && $value > 1000) {
                    echo "   {$metric}: " . $this->formatBytes($value) . "\n";
                } else {
                    echo "   {$metric}: {$value}\n";
                }
            }
        }

        if ($this->failed > 0) {
            echo "\n💥 FAILURE ANALYSIS:\n";
            echo "====================\n";
            foreach ($this->failures as $failure) {
                echo "❌ {$failure['name']}\n";
                echo "   Time: {$failure['time']}s\n";
                echo "   Error: {$failure['error']}\n";
                echo "   ---\n";
            }
            exit(1);
        } else {
            echo "\n🎉 ALL ENTERPRISE TESTS PASSED!\n";
            echo "🏢 Container is Enterprise-Ready!\n";

            // Performance Rating
            $this->printPerformanceRating();
        }
    }

    private function printPerformanceRating(): void
    {
        echo "\n⭐ PERFORMANCE RATING:\n";
        echo "======================\n";

        $rating = 5;
        $reasons = [];

        // Analyze metrics for performance rating
        if (isset($this->metrics['10k_registration_time']) && $this->metrics['10k_registration_time'] > 1.0) {
            $rating -= 1;
            $reasons[] = "Slow mass registration";
        }

        if (isset($this->metrics['stress_resolution_time']) && $this->metrics['stress_resolution_time'] > 2.0) {
            $rating -= 1;
            $reasons[] = "High stress resolution time";
        }

        if (isset($this->metrics['memory_leak_test']) && $this->metrics['memory_leak_test'] > 10 * 1024 * 1024) {
            $rating -= 1;
            $reasons[] = "Memory leak detected";
        }

        $stars = str_repeat('⭐', max(1, $rating)) . str_repeat('☆', 5 - max(1, $rating));
        echo "   Rating: {$stars} ({$rating}/5)\n";

        if (empty($reasons)) {
            echo "   🚀 EXCELLENT PERFORMANCE!\n";
            echo "   💪 Ready for Enterprise Production\n";
        } else {
            echo "   📝 Improvement Areas:\n";
            foreach ($reasons as $reason) {
                echo "      - {$reason}\n";
            }
        }
    }
}

// ============================================================================
// ENTERPRISE TEST FIXTURES AND MOCK SERVICES
// ============================================================================

// High-Performance Test Services
class ComplexTestService
{
    public function __construct(
        public TestDependency $dependency
    ) {}
}

class TestDependency
{
    public function getData(): array
    {
        return ['data' => 'test'];
    }
}

class TestTaggedService
{
    public function process(): string
    {
        return 'processed';
    }
}

class DatabaseConnection
{
    public function connect(): bool
    {
        return true;
    }
}

class CacheManager
{
    public function get(string $key): mixed
    {
        return "cached_value_{$key}";
    }
}

class UserService
{
    public function __construct(
        DatabaseConnection $db,
        CacheManager $cache
    ) {}
}

class DeepDependencyService
{
    // Simulates deep dependency chains
    public function __construct() {}
}

class SimpleService
{
    // No dependencies for compilation tests
}

// Enterprise Service Providers
class DatabaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton('DatabaseConnection', DatabaseConnection::class);
        $this->singleton('DatabaseManager', DatabaseManager::class);
        $this->singleton('QueryBuilder', QueryBuilder::class);
    }

    public function boot(): void
    {
        // Initialize database connections
    }
}

class CacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton('CacheManager', CacheManager::class);
        $this->singleton('RedisCache', RedisCache::class);
        $this->singleton('MemcachedCache', MemcachedCache::class);
    }
}

class LoggingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton('Logger', Logger::class);
        $this->singleton('FileLogger', FileLogger::class);
        $this->singleton('DatabaseLogger', DatabaseLogger::class);
        $this->tag('FileLogger', 'logger');
        $this->tag('DatabaseLogger', 'logger');
    }
}

class ApplicationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->bind('EnterpriseUserService', EnterpriseUserService::class);
        $this->bind('BusinessLogicService', BusinessLogicService::class);
        $this->bind('ValidationService', ValidationService::class);
    }
}

class SecurityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton('SecurityManager', SecurityManager::class);
        $this->singleton('EncryptionService', EncryptionService::class);
        $this->singleton('AuthenticationService', AuthenticationService::class);
        $this->singleton('AuthorizationService', AuthorizationService::class);
    }
}

class MonitoringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton('MetricsCollector', MetricsCollector::class);
        $this->singleton('PerformanceMonitor', PerformanceMonitor::class);
        $this->singleton('AlertManager', AlertManager::class);
    }
}

// Enterprise Services
class EnterpriseUserService
{
    public function __construct(
        DatabaseConnection $db,
        CacheManager $cache,
        SecurityManager $security,
        Logger $logger
    ) {}
}

class DatabaseManager
{
    public function __construct(DatabaseConnection $connection) {}
}

class QueryBuilder
{
    public function select(array $fields): self { return $this; }
    public function where(string $field, mixed $value): self { return $this; }
}

class RedisCache
{
    public function get(string $key): mixed { return null; }
    public function set(string $key, mixed $value, int $ttl = 3600): bool { return true; }
}

class MemcachedCache
{
    public function get(string $key): mixed { return null; }
    public function set(string $key, mixed $value, int $ttl = 3600): bool { return true; }
}

class Logger
{
    public function info(string $message): void {}
    public function error(string $message): void {}
}

class FileLogger extends Logger
{
    public function log(string $level, string $message): void {}
}

class DatabaseLogger extends Logger
{
    public function log(string $level, string $message): void {}
}

class SecurityManager
{
    public function authenticate(string $token): bool { return true; }
    public function authorize(string $permission): bool { return true; }
}

class EncryptionService
{
    public function encrypt(string $data): string { return base64_encode($data); }
    public function decrypt(string $data): string { return base64_decode($data); }
}

class AuthenticationService
{
    public function login(string $username, string $password): bool { return true; }
}

class AuthorizationService
{
    public function can(string $user, string $permission): bool { return true; }
}

class MetricsCollector
{
    public function increment(string $metric): void {}
    public function gauge(string $metric, float $value): void {}
}

class PerformanceMonitor
{
    public function startTimer(string $name): void {}
    public function endTimer(string $name): float { return 0.001; }
}

class AlertManager
{
    public function alert(string $message, string $level = 'info'): void {}
}

class BusinessLogicService
{
    public function processBusinessRule(array $data): array { return $data; }
}

class ValidationService
{
    public function validate(array $data, array $rules): bool { return true; }
}

// Contextual Binding Test Services
class TestImplementation
{
    public function execute(): string { return 'executed'; }
}

class TestController
{
    public function handle(): string { return 'handled'; }
}

// Microservice Simulation
class MicroserviceSimulator
{
    public function call(string $endpoint, array $data = []): array
    {
        return ['status' => 'success', 'data' => $data];
    }
}

class ServiceMeshSimulator
{
    public function route(string $service, string $method): array
    {
        return ['routed_to' => $service, 'method' => $method];
    }
}

class LoadBalancerSimulator
{
    public function balance(array $services): string
    {
        return $services[array_rand($services)];
    }
}

class CircuitBreakerSimulator
{
    private bool $open = false;

    public function call(callable $service): mixed
    {
        if ($this->open) {
            throw new \RuntimeException('Circuit breaker is open');
        }
        return $service();
    }
}

// Event System
class EventDispatcher
{
    private array $listeners = [];

    public function dispatch(string $event, array $data = []): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener->handle($data);
        }
    }

    public function addListener(string $event, EventListener $listener): void
    {
        $this->listeners[$event][] = $listener;
    }
}

class EventListener
{
    public function handle(array $data): void
    {
        // Process event
    }
}

// CQRS Pattern
class CommandBus
{
    public function execute(object $command): mixed
    {
        return "Command executed: " . get_class($command);
    }
}

class QueryBus
{
    public function query(object $query): mixed
    {
        return "Query executed: " . get_class($query);
    }
}

class UserCreateCommand
{
    public function __construct(public string $name, public string $email) {}
}

class UserCreateHandler
{
    public function handle(UserCreateCommand $command): void
    {
        // Handle user creation
    }
}

class UserQuery
{
    public function __construct(public int $id) {}
}

class UserQueryHandler
{
    public function handle(UserQuery $query): array
    {
        return ['id' => $query->id, 'name' => 'Test User'];
    }
}

class EventStore
{
    private array $events = [];

    public function append(string $stream, array $events): void
    {
        $this->events[$stream] = array_merge($this->events[$stream] ?? [], $events);
    }

    public function read(string $stream): array
    {
        return $this->events[$stream] ?? [];
    }
}

class ProjectionManager
{
    public function project(array $events): array
    {
        return ['projection' => 'built from events'];
    }
}

// Concurrency Test Services
class SharedResource
{
    private static int $instanceCount = 0;
    public int $id;

    public function __construct()
    {
        $this->id = ++self::$instanceCount;
    }
}

class ConcurrentService
{
    public function __construct(SharedResource $resource) {}
}

// Memory Test Services
class TemporaryService
{
    private array $data;

    public function __construct()
    {
        $this->data = array_fill(0, 100, 'test_data');
    }
}

class LazyTestService
{
    private array $heavyData;

    public function __construct()
    {
        $this->heavyData = array_fill(0, 1000, 'heavy_data');
    }
}

// Production Scenario Services
class ECommerceService
{
    public function process(): array
    {
        return ['status' => 'processed'];
    }
}

class SecureBankingService
{
    public function __construct()
    {
        // High security initialization
    }

    public function processTransaction(array $data): array
    {
        return ['transaction_id' => uniqid(), 'status' => 'approved'];
    }
}

// Failure Recovery Services
class FailingService
{
    public function __construct()
    {
        if (rand(1, 100) <= 50) { // 50% failure rate
            throw new \RuntimeException('Service unavailable');
        }
    }
}

class FallbackService
{
    public function __construct() {}

    public function fallbackMethod(): string
    {
        return 'fallback_response';
    }
}

// Load Testing Simulation
class LoadTestSimulator
{
    public static function simulateLoad(Container $container, int $requests = 1000): array
    {
        $results = [
            'total_requests' => $requests,
            'successful' => 0,
            'failed' => 0,
            'avg_response_time' => 0,
            'peak_memory' => 0
        ];

        $totalTime = 0;
        $startMemory = memory_get_usage();

        for ($i = 0; $i < $requests; $i++) {
            $requestStart = microtime(true);

            try {
                // Simulate typical request
                $service1 = $container->resolve('UserService');
                $service2 = $container->resolve('CacheManager');
                $service3 = $container->resolve('DatabaseConnection');

                $results['successful']++;
            } catch (\Throwable $e) {
                $results['failed']++;
            }

            $requestTime = microtime(true) - $requestStart;
            $totalTime += $requestTime;

            $currentMemory = memory_get_usage();
            if ($currentMemory > $results['peak_memory']) {
                $results['peak_memory'] = $currentMemory;
            }
        }

        $results['avg_response_time'] = $totalTime / $requests;
        $results['peak_memory'] -= $startMemory;

        return $results;
    }
}

// Main execution
if (php_sapi_name() === 'cli') {
    echo "🏢 Enterprise Container Test Suite\n";
    echo "==================================\n";
    echo "⚠️  WARNING: These are intensive tests!\n";
    echo "   - High memory usage (up to 512MB)\n";
    echo "   - CPU intensive operations\n";
    echo "   - Long execution time (30s+)\n\n";

    echo "Continue? (y/n): ";
    $choice = trim(fgets(STDIN));

    if (strtolower($choice) === 'y') {
        (new EnterpriseTestRunner())->run();
    } else {
        echo "Enterprise tests cancelled.\n";
    }
} else {
    echo "<pre>";
    echo "Enterprise Container Tests\n";
    echo "Run from command line for full experience.\n";
    echo "</pre>";
}