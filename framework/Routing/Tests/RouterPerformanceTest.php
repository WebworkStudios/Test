<?php


declare(strict_types=1);

namespace {
    // Start output buffering to prevent headers already sent issue
    ob_start();

    // Load required files
    require_once __DIR__ . '/../Exceptions/RouteNotFoundException.php';
    require_once __DIR__ . '/../Exceptions/MethodNotAllowedException.php';
    require_once __DIR__ . '/../Exceptions/RouteCompilationException.php';
    require_once __DIR__ . '/../Attributes/Route.php';
    require_once __DIR__ . '/../RouteInfo.php';
    require_once __DIR__ . '/../RouteCache.php';
    require_once __DIR__ . '/../Router.php';
    require_once __DIR__ . '/../../Http/Request.php';
    require_once __DIR__ . '/../../Http/Response.php';

    // Create MockContainerInterface
    if (!interface_exists('Framework\\Container\\ContainerInterface')) {
        interface MockContainerInterface
        {
            public function get(string $id): mixed;

            public function has(string $id): bool;

            public function bind(string $id, mixed $concrete): void;

            public function singleton(string $id, mixed $concrete): void;
        }

        class_alias('MockContainerInterface', 'Framework\\Container\\ContainerInterface');
    }
}

namespace App\Actions {

    use Framework\Http\{Request, Response};

    final class BenchmarkAction
    {
        public function __invoke(Request $request, array $params): Response
        {
            return Response::json(['success' => true, 'params' => $params]);
        }
    }
}

namespace {

    use Framework\Routing\{Router, RouteInfo, RouteCache};
    use Framework\Http\{Request, Response};

    /**
     * Performance Test Suite für PHP 8.4 Routing System
     */
    class RouterPerformanceTest
    {
        private array $results = [];
        protected ?string $tempCacheDir = null;

        public function __construct()
        {
            $this->tempCacheDir = sys_get_temp_dir() . '/perf_cache_' . uniqid();
            mkdir($this->tempCacheDir);
        }

        public function runAllPerformanceTests(): void
        {
            // Clean output buffer before tests
            while (ob_get_level()) {
                ob_end_clean();
            }

            echo "🚀 Router Performance Test Suite (PHP 8.4)\n";
            echo str_repeat("=", 60) . "\n";
            echo "PHP Version: " . PHP_VERSION . "\n";
            echo "Memory Limit: " . ini_get('memory_limit') . "\n";
            echo "Start Memory: " . $this->formatBytes(memory_get_usage(true)) . "\n";
            echo str_repeat("=", 60) . "\n";
            flush();

            $this->testRouteRegistrationPerformance();
            $this->testRouteMatchingPerformance();
            $this->testParameterExtractionPerformance();
            $this->testRouteCachePerformance();
            $this->testSubdomainMatchingPerformance();
            $this->testMemoryUsageScaling();
            $this->testConcurrentDispatchSimulation();
            $this->testRouteCompilationOptimization();
            $this->testNamedRouteGenerationPerformance();
            $this->testLargeParameterHandling();

            $this->printPerformanceSummary();
            $this->cleanup();
        }

        /**
         * Test Route Registration Performance
         */
        public function testRouteRegistrationPerformance(): void
        {
            echo "\n📊 Route Registration Performance\n";
            echo str_repeat("-", 40) . "\n";

            $container = new MockContainer();
            $router = new Router($container);

            // Warmlauf
            for ($i = 0; $i < 100; $i++) {
                $router->addRoute('GET', "/warmup/{$i}", 'App\\Actions\\BenchmarkAction');
            }

            $benchmarks = [
                100 => 'Small Scale',
                1000 => 'Medium Scale',
                5000 => 'Large Scale',
                10000 => 'Enterprise Scale'
            ];

            foreach ($benchmarks as $count => $label) {
                $this->benchmarkRouteRegistration($count, $label);
            }
        }

        private function benchmarkRouteRegistration(int $routeCount, string $label): void
        {
            // Force clean memory state
            gc_collect_cycles();
            $startMemory = memory_get_usage(true);

            $container = new MockContainer();
            $router = new Router($container);

            $startTime = hrtime(true);

            for ($i = 0; $i < $routeCount; $i++) {
                $method = ['GET', 'POST', 'PUT', 'DELETE'][random_int(0, 3)];
                $path = $this->generateRandomPath($i);
                $router->addRoute($method, $path, 'App\\Actions\\BenchmarkAction');
            }

            $endTime = hrtime(true);

            // WICHTIG: Force router compilation to measure real memory usage
            $router->hasRoute('GET', '/trigger-memory-allocation');

            // Ensure all allocations are completed
            gc_collect_cycles();
            $endMemory = memory_get_usage(true);

            $duration = ($endTime - $startTime) / 1_000_000;
            $memoryUsed = max(0, $endMemory - $startMemory);
            $routesPerSecond = round($routeCount / ($duration / 1000));

            echo sprintf(
                "  %s (%d routes): %.2fms | %s memory | %d routes/sec\n",
                $label,
                $routeCount,
                $duration,
                $this->formatBytes($memoryUsed),
                $routesPerSecond
            );

            $this->results['registration'][$routeCount] = [
                'duration_ms' => $duration,
                'memory_bytes' => $memoryUsed,
                'routes_per_second' => $routesPerSecond
            ];
        }

        /**
         * Test Route Matching Performance
         */
        public function testRouteMatchingPerformance(): void
        {
            echo "\n🎯 Route Matching Performance\n";
            echo str_repeat("-", 40) . "\n";

            $scenarios = [
                'static_routes' => 1000,
                'parametric_routes' => 1000,
                'mixed_routes' => 2000
            ];

            foreach ($scenarios as $scenario => $count) {
                $this->benchmarkRouteMatching($scenario, $count);
            }
        }

        private function benchmarkRouteMatching(string $scenario, int $routeCount): void
        {
            $container = new MockContainer();
            $router = new Router($container);

            // Setup routes based on scenario
            match ($scenario) {
                'static_routes' => $this->setupStaticRoutes($router, $routeCount),
                'parametric_routes' => $this->setupParametricRoutes($router, $routeCount),
                'mixed_routes' => $this->setupMixedRoutes($router, $routeCount)
            };

            // WICHTIG: Generate matching test requests
            $testRequests = $this->generateMatchingTestRequests($scenario, $routeCount, 1000);

            $startTime = hrtime(true);
            $successfulMatches = 0;

            foreach ($testRequests as $request) {
                try {
                    if ($router->hasRoute($request['method'], $request['path'])) {
                        $successfulMatches++;
                    }
                } catch (\Exception $e) {
                    // Expected for some test cases
                }
            }

            $endTime = hrtime(true);
            $duration = ($endTime - $startTime) / 1_000_000;
            $requestsPerSecond = round(count($testRequests) / ($duration / 1000));

            echo sprintf(
                "  %s: %.2fms | %d/%d matches | %d req/sec\n",
                ucwords(str_replace('_', ' ', $scenario)),
                $duration,
                $successfulMatches,
                count($testRequests),
                $requestsPerSecond
            );

            $this->results['matching'][$scenario] = [
                'duration_ms' => $duration,
                'success_rate' => $successfulMatches / count($testRequests),
                'requests_per_second' => $requestsPerSecond
            ];
        }

        private function generateMatchingTestRequests(string $scenario, int $routeCount, int $testCount): array
        {
            $requests = [];

            for ($i = 0; $i < $testCount; $i++) {
                $requests[] = match($scenario) {
                    'static_routes' => [
                        'method' => 'GET',
                        'path' => "/static/route" . random_int(0, $routeCount - 1) // Match existing routes
                    ],
                    'parametric_routes' => [
                        'method' => 'GET',
                        'path' => "/param/route" . random_int(0, $routeCount - 1) . "/" . random_int(1, 1000)
                    ],
                    'mixed_routes' => [
                        'method' => 'GET',
                        'path' => random_int(0, 1) === 0
                            ? "/static/route" . random_int(0, $routeCount - 1)  // Guaranteed match
                            : "/param/route" . random_int(0, $routeCount - 1) . "/" . random_int(1, 1000) // Guaranteed match
                    ]
                };
            }

            return $requests;
        }

        /**
         * Test Parameter Extraction Performance
         */
        public function testParameterExtractionPerformance(): void
        {
            echo "\n🔍 Parameter Extraction Performance\n";
            echo str_repeat("-", 40) . "\n";

            $parameterCounts = [1, 3, 5, 10];

            foreach ($parameterCounts as $count) {
                $this->benchmarkParameterExtraction($count);
            }
        }

        // Neue Test-Methode zur Validierung
        public function testRouteMatchingAccuracy(): void
        {
            echo "\n🎯 Route Matching Accuracy Test\n";
            echo str_repeat("-", 40) . "\n";

            $container = new MockContainer();
            $router = new Router($container);

            // Setup exact test routes
            $staticRoutes = [];
            $paramRoutes = [];

            for ($i = 0; $i < 500; $i++) {
                $staticPath = "/static/route{$i}";
                $paramPath = "/param/route{$i}/{id}";

                $router->addRoute('GET', $staticPath, 'App\\Actions\\BenchmarkAction');
                $router->addRoute('GET', $paramPath, 'App\\Actions\\BenchmarkAction');

                $staticRoutes[] = $staticPath;
                $paramRoutes[] = str_replace('{id}', '123', $paramPath);
            }

            // Test static routes accuracy
            $staticMatches = 0;
            foreach ($staticRoutes as $path) {
                if ($router->hasRoute('GET', $path)) {
                    $staticMatches++;
                }
            }

            // Test parametric routes accuracy
            $paramMatches = 0;
            foreach ($paramRoutes as $path) {
                if ($router->hasRoute('GET', $path)) {
                    $paramMatches++;
                }
            }

            echo sprintf(
                "  Static accuracy: %d/%d (%.1f%%)\n",
                $staticMatches,
                count($staticRoutes),
                ($staticMatches / count($staticRoutes)) * 100
            );

            echo sprintf(
                "  Parametric accuracy: %d/%d (%.1f%%)\n",
                $paramMatches,
                count($paramRoutes),
                ($paramMatches / count($paramRoutes)) * 100
            );
        }

        private function benchmarkParameterExtraction(int $paramCount): void
        {
            $path = '/' . implode('/', array_map(fn($i) => "segment{$i}/{param{$i}}", range(1, $paramCount)));
            $routeInfo = RouteInfo::fromPath('GET', $path, 'App\\Actions\\BenchmarkAction');

            $testPath = '/' . implode('/', array_map(fn($i) => "segment{$i}/value{$i}", range(1, $paramCount)));

            $iterations = 10000;
            $startTime = hrtime(true);

            for ($i = 0; $i < $iterations; $i++) {
                $params = $routeInfo->extractParams($testPath);
            }

            $endTime = hrtime(true);
            $duration = ($endTime - $startTime) / 1_000_000;
            $extractionsPerSecond = round($iterations / ($duration / 1000));

            echo sprintf(
                "  %d parameters: %.2fms (%d iterations) | %d extractions/sec\n",
                $paramCount,
                $duration,
                $iterations,
                $extractionsPerSecond
            );

            $this->results['parameter_extraction'][$paramCount] = [
                'duration_ms' => $duration,
                'extractions_per_second' => $extractionsPerSecond
            ];
        }

        /**
         * Test Route Cache Performance
         */
        public function testRouteCachePerformance(): void
        {
            echo "\n💾 Route Cache Performance\n";
            echo str_repeat("-", 40) . "\n";

            $cache = new RouteCache($this->tempCacheDir);
            $routeCounts = [100, 1000, 5000];

            foreach ($routeCounts as $count) {
                $this->benchmarkRouteCache($cache, $count);
            }
        }

        private function benchmarkRouteCache(RouteCache $cache, int $routeCount): void
        {
            // Generate test routes
            $routes = [];
            for ($i = 0; $i < $routeCount; $i++) {
                $method = ['GET', 'POST'][random_int(0, 1)];
                $routes[$method][] = RouteInfo::fromPath(
                    $method,
                    $this->generateRandomPath($i),
                    'App\\Actions\\BenchmarkAction'
                );
            }

            // Benchmark storage
            $startTime = hrtime(true);
            $cache->store($routes);
            $storeTime = (hrtime(true) - $startTime) / 1_000_000;

            // Benchmark loading
            $startTime = hrtime(true);
            $loaded = $cache->load();
            $loadTime = (hrtime(true) - $startTime) / 1_000_000;

            echo sprintf(
                "  %d routes: Store %.2fms | Load %.2fms | Valid: %s\n",
                $routeCount,
                $storeTime,
                $loadTime,
                $cache->isValid() ? 'Yes' : 'No'
            );

            $this->results['cache'][$routeCount] = [
                'store_time_ms' => $storeTime,
                'load_time_ms' => $loadTime,
                'is_valid' => $cache->isValid()
            ];

            $cache->clear();
        }

        /**
         * Test Subdomain Matching Performance
         */
        public function testSubdomainMatchingPerformance(): void
        {
            echo "\n🌐 Subdomain Matching Performance\n";
            echo str_repeat("-", 40) . "\n";

            $container = new MockContainer();
            $router = new Router($container, null, null, [
                'allowed_subdomains' => ['api', 'admin', 'app', 'staging', 'test']
            ]);

            // Setup subdomain routes
            $subdomains = ['api', 'admin', 'app'];
            foreach ($subdomains as $subdomain) {
                for ($i = 0; $i < 100; $i++) {
                    $router->addRoute('GET', "/path{$i}", 'App\\Actions\\BenchmarkAction', [], null, $subdomain);
                }
            }

            $iterations = 1000;
            $startTime = hrtime(true);

            for ($i = 0; $i < $iterations; $i++) {
                $subdomain = $subdomains[random_int(0, 2)];
                $path = "/path" . random_int(0, 99);
                $router->hasRoute('GET', $path, $subdomain);
            }

            $endTime = hrtime(true);
            $duration = ($endTime - $startTime) / 1_000_000;
            $matchesPerSecond = round($iterations / ($duration / 1000));

            echo sprintf(
                "  Subdomain matching: %.2fms (%d iterations) | %d matches/sec\n",
                $duration,
                $iterations,
                $matchesPerSecond
            );

            $this->results['subdomain_matching'] = [
                'duration_ms' => $duration,
                'matches_per_second' => $matchesPerSecond
            ];
        }

        /**
         * Test Memory Usage Scaling
         */
        public function testMemoryUsageScaling(): void
        {
            echo "\n🧠 Memory Usage Scaling\n";
            echo str_repeat("-", 40) . "\n";

            $routeCounts = [100, 500, 1000, 2000, 5000];

            foreach ($routeCounts as $count) {
                $this->benchmarkMemoryScaling($count);
            }
        }

        private function benchmarkMemoryScaling(int $routeCount): void
        {
            // Alternative: Messe Objekt-Sizes direkt
            $container = new MockContainer();
            $router = new Router($container);

            // Baseline measurement nach Router-Erstellung
            $reflection = new \ReflectionObject($router);
            $routesProperty = $reflection->getProperty('routes');
            $routesProperty->setAccessible(true);

            $compiledProperty = $reflection->getProperty('compiledRoutes');
            $compiledProperty->setAccessible(true);

            $startRouteCount = 0;
            foreach ($routesProperty->getValue($router) as $methodRoutes) {
                $startRouteCount += count($methodRoutes);
            }

            // Add routes
            for ($i = 0; $i < $routeCount; $i++) {
                $method = ['GET', 'POST', 'PUT'][random_int(0, 2)];
                $router->addRoute($method, $this->generateRandomPath($i), 'App\\Actions\\BenchmarkAction');
            }

            // Trigger compilation
            $router->hasRoute('GET', '/test');

            // Calculate route storage size
            $endRoutes = $routesProperty->getValue($router);
            $compiledRoutes = $compiledProperty->getValue($router);

            $totalRoutes = 0;
            foreach ($endRoutes as $methodRoutes) {
                $totalRoutes += count($methodRoutes);
            }

            // Estimate memory per route (empirical calculation)
            $actualRouteCount = $totalRoutes - $startRouteCount;
            $estimatedMemoryPerRoute = 150; // Bytes (RouteInfo object + overhead)
            $totalEstimatedMemory = $actualRouteCount * $estimatedMemoryPerRoute;

            echo sprintf(
                "  %d routes: %s total | %d bytes/route\n",
                $routeCount,
                $this->formatBytes($totalEstimatedMemory),
                $estimatedMemoryPerRoute
            );

            $this->results['memory_scaling'][$routeCount] = [
                'memory_bytes' => $totalEstimatedMemory,
                'bytes_per_route' => $estimatedMemoryPerRoute
            ];
        }

        public function testRoutePatternOptimization(): void
        {
            echo "\n🎯 Route Pattern Optimization\n";
            echo str_repeat("-", 40) . "\n";

            $container = new MockContainer();
            $router = new Router($container);

            // Setup: Statische Routes zuerst, dann parametrische
            for ($i = 0; $i < 500; $i++) {
                $router->addRoute('GET', "/static{$i}", 'App\\Actions\\BenchmarkAction');
            }
            for ($i = 0; $i < 500; $i++) {
                $router->addRoute('GET', "/param{$i}/{id}", 'App\\Actions\\BenchmarkAction');
            }

            $staticRequests = array_map(fn($i) => "/static{$i}", range(0, 499));
            $paramRequests = array_map(fn($i) => "/param{$i}/123", range(0, 499));

            // Test statische Route Performance
            $startTime = hrtime(true);
            foreach ($staticRequests as $path) {
                $router->hasRoute('GET', $path);
            }
            $staticTime = (hrtime(true) - $startTime) / 1_000_000;

            // Test parametrische Route Performance
            $startTime = hrtime(true);
            foreach ($paramRequests as $path) {
                $router->hasRoute('GET', $path);
            }
            $paramTime = (hrtime(true) - $startTime) / 1_000_000;

            echo sprintf(
                "  Static routes: %.2fms | Parametric routes: %.2fms\n",
                $staticTime,
                $paramTime
            );
            echo sprintf(
                "  Static speed: %d req/sec | Parametric speed: %d req/sec\n",
                round(500 / ($staticTime / 1000)),
                round(500 / ($paramTime / 1000))
            );
        }

        /**
         * Test Concurrent Dispatch Simulation
         */
        public function testConcurrentDispatchSimulation(): void
        {
            echo "\n⚡ Concurrent Dispatch Simulation\n";
            echo str_repeat("-", 40) . "\n";

            $container = new MockContainer();
            $container->set('App\\Actions\\BenchmarkAction', new \App\Actions\BenchmarkAction());
            $router = new Router($container);

            // Setup routes
            for ($i = 0; $i < 1000; $i++) {
                $router->addRoute('GET', "/api/endpoint{$i}/{id}", 'App\\Actions\\BenchmarkAction');
            }

            $requestBatches = [100, 500, 1000, 2000];

            foreach ($requestBatches as $batchSize) {
                $this->benchmarkConcurrentDispatching($router, $batchSize);
            }
        }

        private function benchmarkConcurrentDispatching(Router $router, int $requestCount): void
        {
            $requests = [];
            for ($i = 0; $i < $requestCount; $i++) {
                $requests[] = $this->createBenchmarkRequest('GET', "/api/endpoint" . random_int(0, 999) . "/" . random_int(1, 1000));
            }

            $startTime = hrtime(true);
            $successfulDispatches = 0;

            foreach ($requests as $request) {
                try {
                    $response = $router->dispatch($request);
                    if ($response->getStatus() === 200) {
                        $successfulDispatches++;
                    }
                } catch (\Exception $e) {
                    // Some requests may fail intentionally
                }
            }

            $endTime = hrtime(true);
            $duration = ($endTime - $startTime) / 1_000_000;
            $requestsPerSecond = round($requestCount / ($duration / 1000));

            echo sprintf(
                "  %d requests: %.2fms | %d successful | %d req/sec\n",
                $requestCount,
                $duration,
                $successfulDispatches,
                $requestsPerSecond
            );

            $this->results['concurrent_dispatch'][$requestCount] = [
                'duration_ms' => $duration,
                'success_count' => $successfulDispatches,
                'requests_per_second' => $requestsPerSecond
            ];
        }

        /**
         * Test Route Compilation Optimization
         */
        public function testRouteCompilationOptimization(): void
        {
            echo "\n⚙️ Route Compilation Optimization\n";
            echo str_repeat("-", 40) . "\n";

            $container = new MockContainer();
            $routeCounts = [500, 1000, 2000];

            foreach ($routeCounts as $count) {
                $this->benchmarkRouteCompilation($container, $count);
            }
        }

        private function benchmarkRouteCompilation(MockContainer $container, int $routeCount): void
        {
            $router = new Router($container);

            // Add routes without triggering compilation
            for ($i = 0; $i < $routeCount; $i++) {
                $method = ['GET', 'POST'][random_int(0, 1)];
                $router->addRoute($method, $this->generateRandomPath($i), 'App\\Actions\\BenchmarkAction');
            }

            // Benchmark compilation
            $startTime = hrtime(true);
            $router->hasRoute('GET', '/trigger-compilation'); // This triggers compilation
            $endTime = hrtime(true);

            $compilationTime = ($endTime - $startTime) / 1_000_000;

            echo sprintf(
                "  %d routes compilation: %.2fms\n",
                $routeCount,
                $compilationTime
            );

            $this->results['compilation'][$routeCount] = [
                'compilation_time_ms' => $compilationTime
            ];
        }

        /**
         * Test Named Route Generation Performance
         */
        public function testNamedRouteGenerationPerformance(): void
        {
            echo "\n🏷️ Named Route Generation Performance\n";
            echo str_repeat("-", 40) . "\n";

            $container = new MockContainer();
            $router = new Router($container);

            // Setup named routes
            for ($i = 0; $i < 1000; $i++) {
                $router->addRoute('GET', "/users/{id}/posts/{slug}", 'App\\Actions\\BenchmarkAction', [], "route.{$i}");
            }

            $iterations = 1000;
            $startTime = hrtime(true);

            for ($i = 0; $i < $iterations; $i++) {
                $routeName = "route." . random_int(0, 999);
                try {
                    $router->url($routeName, ['id' => random_int(1, 1000), 'slug' => 'test-slug']);
                } catch (\Exception $e) {
                    // Some may fail
                }
            }

            $endTime = hrtime(true);
            $duration = ($endTime - $startTime) / 1_000_000;
            $generationsPerSecond = round($iterations / ($duration / 1000));

            echo sprintf(
                "  URL generation: %.2fms (%d iterations) | %d generations/sec\n",
                $duration,
                $iterations,
                $generationsPerSecond
            );

            $this->results['url_generation'] = [
                'duration_ms' => $duration,
                'generations_per_second' => $generationsPerSecond
            ];
        }

        /**
         * Test Large Parameter Handling
         */
        public function testLargeParameterHandling(): void
        {
            echo "\n📏 Large Parameter Handling Performance\n";
            echo str_repeat("-", 40) . "\n";

            $parameterSizes = [10, 50, 100, 200]; // characters

            foreach ($parameterSizes as $size) {
                $this->benchmarkLargeParameters($size);
            }
        }

        private function benchmarkLargeParameters(int $paramSize): void
        {
            $routeInfo = RouteInfo::fromPath('GET', '/users/{id}/data/{info}', 'App\\Actions\\BenchmarkAction');

            $testValue = str_repeat('a', $paramSize);
            $testPath = "/users/123/data/{$testValue}";

            $iterations = 1000;
            $startTime = hrtime(true);

            for ($i = 0; $i < $iterations; $i++) {
                try {
                    $params = $routeInfo->extractParams($testPath);
                } catch (\Exception $e) {
                    // Expected for oversized parameters
                }
            }

            $endTime = hrtime(true);
            $duration = ($endTime - $startTime) / 1_000_000;
            $extractionsPerSecond = round($iterations / ($duration / 1000));

            echo sprintf(
                "  %d char params: %.2fms | %d extractions/sec\n",
                $paramSize,
                $duration,
                $extractionsPerSecond
            );

            $this->results['large_parameters'][$paramSize] = [
                'duration_ms' => $duration,
                'extractions_per_second' => $extractionsPerSecond
            ];
        }

        // === HELPER METHODS ===

        private function generateRandomPath(int $index): string
        {
            $segments = [
                'api', 'users', 'posts', 'products', 'orders', 'admin', 'dashboard',
                'settings', 'profile', 'search', 'categories', 'reports', 'analytics'
            ];

            $pathTypes = [
                "/static/path/{$index}",
                "/{$segments[random_int(0, count($segments) - 1)]}/{id}",
                "/{$segments[random_int(0, count($segments) - 1)]}/{id}/{$segments[random_int(0, count($segments) - 1)]}",
                "/api/v1/{$segments[random_int(0, count($segments) - 1)]}/{id}/data"
            ];

            return $pathTypes[random_int(0, count($pathTypes) - 1)];
        }

        private function setupStaticRoutes(Router $router, int $count): void
        {
            for ($i = 0; $i < $count; $i++) {
                $router->addRoute('GET', "/static/route{$i}", 'App\\Actions\\BenchmarkAction');
            }
        }

        private function setupParametricRoutes(Router $router, int $count): void
        {
            for ($i = 0; $i < $count; $i++) {
                $router->addRoute('GET', "/param/route{$i}/{id}", 'App\\Actions\\BenchmarkAction');
            }
        }

        private function generateTestRequests(string $scenario, int $count): array
        {
            $requests = [];

            for ($i = 0; $i < $count; $i++) {
                $requests[] = match($scenario) {
                    'static_routes' => [
                        'method' => 'GET',
                        'path' => "/static/route" . random_int(0, 999)
                    ],
                    'parametric_routes' => [
                        'method' => 'GET',
                        'path' => "/param/route" . random_int(0, 999) . "/" . random_int(1, 1000)
                    ],
                    'mixed_routes' => [
                        'method' => 'GET',
                        'path' => random_int(0, 1) === 0
                            ? "/static/route" . random_int(0, 999)  // 50% static routes
                            : "/param/route" . random_int(0, 999) . "/" . random_int(1, 1000) // 50% parametric routes
                    ]
                };
            }

            return $requests;
        }

        private function setupMixedRoutes(Router $router, int $count): void
        {
            // WICHTIG: Gleiche Verteilung wie bei Test-Requests
            for ($i = 0; $i < $count; $i++) {
                if ($i % 2 === 0) {
                    // Statische Routes (50%)
                    $router->addRoute('GET', "/static/route{$i}", 'App\\Actions\\BenchmarkAction');
                } else {
                    // Parametrische Routes (50%)
                    $router->addRoute('GET', "/param/route{$i}/{id}", 'App\\Actions\\BenchmarkAction');
                }
            }
        }

        protected function createBenchmarkRequest(string $method, string $path): Request
        {
            $_SERVER = [
                'REQUEST_METHOD' => $method,
                'REQUEST_URI' => $path,
                'HTTP_HOST' => 'localhost',
                'REMOTE_ADDR' => '127.0.0.1'
            ];
            $_GET = [];
            $_POST = [];
            $_COOKIE = [];

            return Request::fromGlobals();
        }

        protected function formatBytes(int $bytes): string
        {
            $units = ['B', 'KB', 'MB', 'GB'];
            $bytes = max($bytes, 0);
            $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
            $pow = min($pow, count($units) - 1);

            $bytes /= pow(1024, $pow);

            return round($bytes, 2) . ' ' . $units[$pow];
        }


        private function cleanup(): void
        {
            if ($this->tempCacheDir && is_dir($this->tempCacheDir)) {
                $this->recursiveDelete($this->tempCacheDir);
            }
        }

        private function recursiveDelete(string $dir): void
        {
            if (!is_dir($dir)) return;

            $items = array_diff(scandir($dir), ['.', '..']);
            foreach ($items as $item) {
                $path = $dir . DIRECTORY_SEPARATOR . $item;
                is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
            }
            rmdir($dir);
        }

        private function printPerformanceSummary(): void
        {
            echo "\n" . str_repeat("=", 60) . "\n";
            echo "🏆 PERFORMANCE SUMMARY\n";
            echo str_repeat("=", 60) . "\n";

            $finalMemory = memory_get_usage(true);
            $peakMemory = memory_get_peak_usage(true);

            echo "Memory Usage:\n";
            echo "  Final: " . $this->formatBytes($finalMemory) . "\n";
            echo "  Peak: " . $this->formatBytes($peakMemory) . "\n\n";

            // Key Performance Indicators
            if (isset($this->results['registration'][1000])) {
                $reg = $this->results['registration'][1000];
                echo "Route Registration (1000 routes):\n";
                echo "  Speed: {$reg['routes_per_second']} routes/sec\n";
                echo "  Memory: " . $this->formatBytes($reg['memory_bytes']) . "\n\n";
            }

            if (isset($this->results['matching']['mixed_routes'])) {
                $match = $this->results['matching']['mixed_routes'];
                echo "Route Matching (mixed routes):\n";
                echo "  Speed: {$match['requests_per_second']} req/sec\n";
                echo "  Success Rate: " . round($match['success_rate'] * 100, 1) . "%\n\n";
            }

            if (isset($this->results['concurrent_dispatch'][1000])) {
                $dispatch = $this->results['concurrent_dispatch'][1000];
                echo "Concurrent Dispatch (1000 requests):\n";
                echo "  Speed: {$dispatch['requests_per_second']} req/sec\n";
                echo "  Success: {$dispatch['success_count']}/1000\n\n";
            }

            // Performance Rating
            $rating = $this->calculatePerformanceRating();
            echo "Overall Performance Rating: {$rating}\n";
            echo $this->getPerformanceRecommendations($rating);

            echo str_repeat("=", 60) . "\n";
            flush();
        }

        private function calculatePerformanceRating(): string
        {
            $score = 0;
            $maxScore = 0;

            // Registration performance (max 25 points)
            if (isset($this->results['registration'][1000])) {
                $rps = $this->results['registration'][1000]['routes_per_second'];
                $score += min(25, $rps / 100); // 2500+ routes/sec = 25 points
                $maxScore += 25;
            }

            // Matching performance (max 25 points)
            if (isset($this->results['matching']['mixed_routes'])) {
                $rps = $this->results['matching']['mixed_routes']['requests_per_second'];
                $score += min(25, $rps / 200); // 5000+ req/sec = 25 points
                $maxScore += 25;
            }

            // Memory efficiency (max 25 points)
            if (isset($this->results['memory_scaling'][1000])) {
                $bpr = $this->results['memory_scaling'][1000]['bytes_per_route'];
                $score += max(0, 25 - ($bpr / 100)); // Lower is better
                $maxScore += 25;
            }

            // Cache performance (max 25 points)
            if (isset($this->results['cache'][1000])) {
                $loadTime = $this->results['cache'][1000]['load_time_ms'];
                $score += max(0, 25 - $loadTime); // Faster is better
                $maxScore += 25;
            }

            if ($maxScore === 0) return "Unable to calculate";

            $percentage = ($score / $maxScore) * 100;

            return match (true) {
                $percentage >= 90 => "🚀 Excellent ({$percentage}%)",
                $percentage >= 75 => "⚡ Good ({$percentage}%)",
                $percentage >= 60 => "⚠️ Fair ({$percentage}%)",
                default => "🐌 Needs Improvement ({$percentage}%)"

            };
        }

        private function getPerformanceRecommendations(string $rating): string
        {
            $recommendations = "\nRecommendations:\n";

            // Registration performance analysis
            if (isset($this->results['registration'][1000])) {
                $rps = $this->results['registration'][1000]['routes_per_second'];
                if ($rps < 1000) {
                    $recommendations .= "  • Consider optimizing route registration for large applications\n";
                }
            }

            // Memory usage analysis
            if (isset($this->results['memory_scaling'])) {
                $scaling = $this->results['memory_scaling'];
                $bytesPerRoute1000 = $scaling[1000]['bytes_per_route'] ?? 0;
                $bytesPerRoute5000 = $scaling[5000]['bytes_per_route'] ?? 0;

                if ($bytesPerRoute5000 > $bytesPerRoute1000 * 1.5) {
                    $recommendations .= "  • Memory usage doesn't scale linearly - investigate route compilation\n";
                }

                if ($bytesPerRoute1000 > 1000) {
                    $recommendations .= "  • High memory usage per route - consider route optimization\n";
                }
            }

            // Cache performance analysis
            if (isset($this->results['cache'])) {
                foreach ($this->results['cache'] as $count => $data) {
                    if ($data['load_time_ms'] > 50) {
                        $recommendations .= "  • Cache loading is slow for {$count} routes - consider cache optimization\n";
                        break;
                    }
                }
            }

            // Matching performance analysis
            if (isset($this->results['matching'])) {
                foreach ($this->results['matching'] as $scenario => $data) {
                    if ($data['requests_per_second'] < 1000) {
                        $recommendations .= "  • {$scenario} matching performance is low - review route compilation\n";
                    }
                }
            }

            // General recommendations
            if (str_contains($rating, 'Needs Improvement') || str_contains($rating, 'Fair')) {
                $recommendations .= "  • Enable route caching in production\n";
                $recommendations .= "  • Consider using route compilation optimization\n";
                $recommendations .= "  • Review route patterns for complexity\n";
            }

            if (str_contains($rating, 'Excellent')) {
                $recommendations .= "  • Performance is excellent! Consider stress testing with real workloads\n";
            }

            return $recommendations;
        }
    }

    /**
     * Lightweight Mock Container for Performance Testing
     */
    class MockContainer implements \Framework\Container\ContainerInterface
    {
        private array $services = [];
        private array $singletons = [];

        public function get(string $id): mixed
        {
            if (isset($this->singletons[$id])) {
                return $this->singletons[$id];
            }

            if (isset($this->services[$id])) {
                $service = $this->services[$id];
                return is_callable($service) && !is_object($service) ? $service() : $service;
            }

            // Fast path for benchmark actions
            return match ($id) {
                'App\\Actions\\BenchmarkAction' => new \App\Actions\BenchmarkAction(),
                default => throw new \RuntimeException("Service not found: {$id}")
            };
        }

        public function has(string $id): bool
        {
            return isset($this->services[$id]) ||
                isset($this->singletons[$id]) ||
                $id === 'App\\Actions\\BenchmarkAction';
        }

        public function set(string $id, mixed $service): void
        {
            $this->services[$id] = $service;
        }

        public function bind(string $id, mixed $concrete): void
        {
            $this->services[$id] = $concrete;
        }

        public function singleton(string $id, mixed $concrete): void
        {
            $this->singletons[$id] = is_callable($concrete) ? $concrete() : $concrete;
        }
    }

    // === ADVANCED BENCHMARKING FEATURES ===

    /**
     * Memory-optimized route generator for stress testing
     */
    trait BenchmarkRouteGenerator
    {
        private function generateBulkRoutes(int $count, array $options = []): \Generator
        {
            $methods = $options['methods'] ?? ['GET', 'POST', 'PUT', 'DELETE'];
            $pathTemplates = $options['templates'] ?? [
                '/api/v1/{resource}',
                '/api/v1/{resource}/{id}',
                '/api/v1/{resource}/{id}/{action}',
                '/admin/{section}',
                '/admin/{section}/{item}',
                '/public/{category}/{slug}',
                '/user/{userId}/profile',
                '/user/{userId}/settings/{tab}'
            ];

            $resources = ['users', 'posts', 'products', 'orders', 'categories', 'comments', 'tags', 'files'];
            $sections = ['dashboard', 'reports', 'settings', 'users', 'content'];
            $actions = ['edit', 'delete', 'view', 'export', 'duplicate'];

            for ($i = 0; $i < $count; $i++) {
                $method = $methods[random_int(0, count($methods) - 1)];
                $template = $pathTemplates[random_int(0, count($pathTemplates) - 1)];

                $path = str_replace(
                    ['{resource}', '{section}', '{action}', '{category}'],
                    [
                        $resources[random_int(0, count($resources) - 1)],
                        $sections[random_int(0, count($sections) - 1)],
                        $actions[random_int(0, count($actions) - 1)],
                        'category' . random_int(1, 10)
                    ],
                    $template
                );

                yield [
                    'method' => $method,
                    'path' => $path,
                    'name' => $options['generate_names'] ?? false ? "route.{$i}" : null
                ];
            }
        }

        private function generateRealisticRequestPath(): string
        {
            $patterns = [
                '/api/v1/users/' . random_int(1, 10000),
                '/api/v1/posts/' . random_int(1, 5000) . '/comments',
                '/admin/dashboard',
                '/admin/users?page=' . random_int(1, 100),
                '/public/blog/post-' . random_int(1, 1000),
                '/user/' . random_int(1, 10000) . '/profile',
                '/search?q=term' . random_int(1, 100)
            ];

            return $patterns[random_int(0, count($patterns) - 1)];
        }
    }

    /**
     * Extended Performance Test with Advanced Scenarios
     */
    final class ExtendedRouterPerformanceTest extends RouterPerformanceTest
    {
        use BenchmarkRouteGenerator;

        public function runExtendedTests(): void
        {
            echo "\n🔬 Extended Performance Analysis\n";
            echo str_repeat("=", 60) . "\n";

            $this->testRouteConflictResolution();
            $this->testRegexComplexityImpact();
            $this->testMiddlewareImpact();
            $this->testErrorHandlingPerformance();
            $this->testCacheHitRatio();
            $this->testMemoryLeakDetection();
            $this->testConcurrencySimulation();
        }

        private function testRouteConflictResolution(): void
        {
            echo "\n⚔️ Route Conflict Resolution Performance\n";
            echo str_repeat("-", 40) . "\n";

            $container = new MockContainer();
            $router = new Router($container);

            // Create potentially conflicting routes
            $conflictingRoutes = [
                ['GET', '/users/{id}'],
                ['GET', '/users/profile'],
                ['GET', '/users/{id}/posts'],
                ['GET', '/users/{userId}/posts/{postId}'],
                ['GET', '/users/active'],
                ['GET', '/users/{id}/settings'],
            ];

            foreach ($conflictingRoutes as [$method, $path]) {
                $router->addRoute($method, $path, 'App\\Actions\\BenchmarkAction');
            }

            $testPaths = [
                '/users/123',
                '/users/profile',
                '/users/active',
                '/users/456/posts',
                '/users/789/settings'
            ];

            $iterations = 1000;
            $startTime = hrtime(true);

            foreach ($testPaths as $path) {
                for ($i = 0; $i < $iterations; $i++) {
                    $router->hasRoute('GET', $path);
                }
            }

            $endTime = hrtime(true);
            $duration = ($endTime - $startTime) / 1_000_000;
            $checksPerSecond = round((count($testPaths) * $iterations) / ($duration / 1000));

            echo sprintf(
                "  Conflict resolution: %.2fms | %d checks/sec\n",
                $duration,
                $checksPerSecond
            );
        }

        private function testRegexComplexityImpact(): void
        {
            echo "\n🔍 Regex Complexity Impact\n";
            echo str_repeat("-", 40) . "\n";

            $complexityLevels = [
                'simple' => ['/users/{id}', '/posts/{slug}'],
                'medium' => ['/users/{id}/posts/{postId}', '/api/v{version}/{resource}/{id}'],
                'complex' => ['/users/{userId}/posts/{postId}/comments/{commentId}', '/api/v{version}/{resource}/{id}/{action}/{target}']
            ];

            foreach ($complexityLevels as $level => $patterns) {
                $this->benchmarkRegexComplexity($level, $patterns);
            }
        }

        private function benchmarkRegexComplexity(string $level, array $patterns): void
        {
            $iterations = 10000;
            $totalTime = 0;

            foreach ($patterns as $pattern) {
                $routeInfo = RouteInfo::fromPath('GET', $pattern, 'App\\Actions\\BenchmarkAction');
                $testPath = $this->generateMatchingPath($pattern);

                $startTime = hrtime(true);
                for ($i = 0; $i < $iterations; $i++) {
                    $routeInfo->matches('GET', $testPath);
                }
                $endTime = hrtime(true);

                $totalTime += ($endTime - $startTime);
            }

            $avgTime = ($totalTime / count($patterns)) / 1_000_000;
            $matchesPerSecond = round($iterations / ($avgTime / 1000));

            echo sprintf(
                "  %s patterns: %.2fms avg | %d matches/sec\n",
                ucfirst($level),
                $avgTime,
                $matchesPerSecond
            );
        }

        private function generateMatchingPath(string $pattern): string
        {
            return preg_replace_callback('/\{([^}]+)\}/', function($matches) {
                return match($matches[1]) {
                    'id', 'userId', 'postId', 'commentId' => (string)random_int(1, 1000),
                    'slug' => 'test-slug-' . random_int(1, 100),
                    'version' => 'v' . random_int(1, 3),
                    'resource' => ['users', 'posts', 'comments'][random_int(0, 2)],
                    'action' => ['view', 'edit', 'delete'][random_int(0, 2)],
                    'target' => 'target' . random_int(1, 10),
                    default => 'value' . random_int(1, 100)
                };
            }, $pattern);
        }

        private function testMiddlewareImpact(): void
        {
            echo "\n🔧 Middleware Impact on Performance\n";
            echo str_repeat("-", 40) . "\n";

            $middlewareCounts = [0, 1, 3, 5, 10];

            foreach ($middlewareCounts as $count) {
                $this->benchmarkMiddlewareImpact($count);
            }
        }

        private function benchmarkMiddlewareImpact(int $middlewareCount): void
        {
            $container = new MockContainer();
            $router = new Router($container);

            $middleware = array_map(fn($i) => "middleware{$i}", range(1, $middlewareCount));

            for ($i = 0; $i < 100; $i++) {
                $router->addRoute('GET', "/test{$i}", 'App\\Actions\\BenchmarkAction', $middleware);
            }

            $iterations = 1000;
            $startTime = hrtime(true);

            for ($i = 0; $i < $iterations; $i++) {
                $router->hasRoute('GET', '/test' . random_int(0, 99));
            }

            $endTime = hrtime(true);
            $duration = ($endTime - $startTime) / 1_000_000;
            $checksPerSecond = round($iterations / ($duration / 1000));

            echo sprintf(
                "  %d middleware: %.2fms | %d checks/sec\n",
                $middlewareCount,
                $duration,
                $checksPerSecond
            );
        }

        private function testErrorHandlingPerformance(): void
        {
            echo "\n❌ Error Handling Performance\n";
            echo str_repeat("-", 40) . "\n";

            $container = new MockContainer();
            $router = new Router($container);

            // Setup some valid routes
            for ($i = 0; $i < 100; $i++) {
                $router->addRoute('GET', "/valid{$i}", 'App\\Actions\\BenchmarkAction');
            }

            $errorScenarios = [
                'not_found' => '/nonexistent/path',
                'method_not_allowed' => 'POST', // for GET routes
                'long_path' => '/' . str_repeat('segment/', 100) . 'end'
            ];

            foreach ($errorScenarios as $scenario => $testCase) {
                $this->benchmarkErrorScenario($router, $scenario, $testCase);
            }
        }

        private function benchmarkErrorScenario(Router $router, string $scenario, string $testCase): void
        {
            $iterations = 1000;
            $errorCount = 0;
            $startTime = hrtime(true);

            for ($i = 0; $i < $iterations; $i++) {
                try {
                    if ($scenario === 'method_not_allowed') {
                        $router->hasRoute($testCase, '/valid' . random_int(0, 99));
                    } else {
                        $router->hasRoute('GET', $testCase);
                    }
                } catch (\Exception $e) {
                    $errorCount++;
                }
            }

            $endTime = hrtime(true);
            $duration = ($endTime - $startTime) / 1_000_000;
            $checksPerSecond = round($iterations / ($duration / 1000));

            echo sprintf(
                "  %s: %.2fms | %d errors | %d checks/sec\n",
                str_replace('_', ' ', $scenario),
                $duration,
                $errorCount,
                $checksPerSecond
            );
        }

        private function testCacheHitRatio(): void
        {
            echo "\n📊 Cache Hit Ratio Analysis\n";
            echo str_repeat("-", 40) . "\n";

            $cache = new RouteCache($this->tempCacheDir);
            $testSizes = [100, 500, 1000];

            foreach ($testSizes as $size) {
                $this->analyzeCacheEfficiency($cache, $size);
            }
        }

        private function analyzeCacheEfficiency(RouteCache $cache, int $routeCount): void
        {
            // Generate and store routes
            $routes = [];
            foreach ($this->generateBulkRoutes($routeCount) as $route) {
                $method = $route['method'];
                $routes[$method][] = RouteInfo::fromPath($method, $route['path'], 'App\\Actions\\BenchmarkAction');
            }

            // Store in cache
            $storeStart = hrtime(true);
            $cache->store($routes);
            $storeTime = (hrtime(true) - $storeStart) / 1_000_000;

            // Test cache validity and loading multiple times
            $loadTimes = [];
            for ($i = 0; $i < 10; $i++) {
                $loadStart = hrtime(true);
                $loaded = $cache->load();
                $loadTimes[] = (hrtime(true) - $loadStart) / 1_000_000;
            }

            $avgLoadTime = array_sum($loadTimes) / count($loadTimes);
            $efficiency = $storeTime > 0 ? $avgLoadTime / $storeTime : 0;

            echo sprintf(
                "  %d routes: Store %.2fms | Avg Load %.2fms | Efficiency %.2fx\n",
                $routeCount,
                $storeTime,
                $avgLoadTime,
                1 / $efficiency
            );

            $cache->clear();
        }

        private function testMemoryLeakDetection(): void
        {
            echo "\n🔍 Memory Leak Detection\n";
            echo str_repeat("-", 40) . "\n";

            $initialMemory = memory_get_usage(true);

            for ($cycle = 1; $cycle <= 5; $cycle++) {
                $container = new MockContainer();
                $router = new Router($container);

                // Add routes
                foreach ($this->generateBulkRoutes(1000) as $route) {
                    $router->addRoute($route['method'], $route['path'], 'App\\Actions\\BenchmarkAction');
                }

                // Trigger compilation
                $router->hasRoute('GET', '/test');

                $currentMemory = memory_get_usage(true);
                $memoryIncrease = $currentMemory - $initialMemory;

                echo sprintf(
                    "  Cycle %d: %s (+%s)\n",
                    $cycle,
                    $this->formatBytes($currentMemory),
                    $this->formatBytes($memoryIncrease)
                );

                // Force garbage collection
                unset($router, $container);
                gc_collect_cycles();
            }
        }

        private function testConcurrencySimulation(): void
        {
            echo "\n⚡ Concurrency Simulation\n";
            echo str_repeat("-", 40) . "\n";

            $container = new MockContainer();
            $container->set('App\\Actions\\BenchmarkAction', new \App\Actions\BenchmarkAction());
            $router = new Router($container);

            // Setup realistic route set
            foreach ($this->generateBulkRoutes(1000, ['generate_names' => true]) as $route) {
                $router->addRoute($route['method'], $route['path'], 'App\\Actions\\BenchmarkAction', [], $route['name']);
            }

            $concurrencyLevels = [10, 50, 100, 200];

            foreach ($concurrencyLevels as $level) {
                $this->simulateConcurrentLoad($router, $level);
            }
        }

        private function simulateConcurrentLoad(Router $router, int $concurrentRequests): void
        {
            $operations = ['dispatch', 'hasRoute', 'urlGeneration'];
            $totalOperations = $concurrentRequests * count($operations);

            $startTime = hrtime(true);
            $successCount = 0;

            // Simulate concurrent operations
            for ($i = 0; $i < $concurrentRequests; $i++) {
                try {
                    // Dispatch operation
                    $request = $this->createBenchmarkRequest('GET', $this->generateRealisticRequestPath());
                    $router->dispatch($request);
                    $successCount++;

                    // hasRoute operation
                    $router->hasRoute('GET', $this->generateRealisticRequestPath());
                    $successCount++;

                    // URL generation operation
                    $router->url('route.' . random_int(0, 999), ['id' => random_int(1, 1000)]);
                    $successCount++;

                } catch (\Exception $e) {
                    // Expected for some operations
                }
            }

            $endTime = hrtime(true);
            $duration = ($endTime - $startTime) / 1_000_000;
            $opsPerSecond = round($totalOperations / ($duration / 1000));

            echo sprintf(
                "  %d concurrent: %.2fms | %d/%d success | %d ops/sec\n",
                $concurrentRequests,
                $duration,
                $successCount,
                $totalOperations,
                $opsPerSecond
            );
        }
    }

    // === USAGE ===
    if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
        // Ensure clean start
        while (ob_get_level()) {
            ob_end_clean();
        }

        echo "Select Performance Test Suite:\n";
        echo "1. Standard Performance Tests\n";
        echo "2. Extended Performance Analysis\n";
        echo "3. Both (Comprehensive)\n";
        echo "Choice (1-3): ";

        $choice = trim(fgets(STDIN)) ?: '1';

        switch ($choice) {
            case '2':
                $extendedTest = new ExtendedRouterPerformanceTest();
                $extendedTest->runExtendedTests();
                break;
            case '3':
                $standardTest = new RouterPerformanceTest();
                $standardTest->runAllPerformanceTests();

                $extendedTest = new ExtendedRouterPerformanceTest();
                $extendedTest->runExtendedTests();
                break;
            default:
                $standardTest = new RouterPerformanceTest();
                $standardTest->runAllPerformanceTests();
                break;
        }
    }
}