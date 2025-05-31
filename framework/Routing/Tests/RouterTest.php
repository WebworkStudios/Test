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

    // Create MockContainerInterface before any other code
    if (!interface_exists('Framework\\Container\\ContainerInterface')) {
        interface MockContainerInterface
        {
            public function get(string $id): mixed;
            public function has(string $id): bool;
            public function bind(string $id, mixed $concrete): void;
            public function singleton(string $id, mixed $concrete): void;
        }
        // Create alias for the framework interface
        class_alias('MockContainerInterface', 'Framework\\Container\\ContainerInterface');
    }
}

namespace App\Actions {
    use Framework\Http\{Request, Response};

    final class TestAction
    {
        public function __invoke(Request $request, array $params): Response
        {
            return Response::text('Test Response: ' . json_encode($params));
        }
    }

    final class ApiAction
    {
        public function __invoke(Request $request, array $params): Response
        {
            return Response::json(['api' => true, 'params' => $params]);
        }
    }

    final class AdminAction
    {
        public function __invoke(Request $request, array $params): Response
        {
            return Response::json(['admin' => true, 'params' => $params]);
        }
    }
}

namespace {

    use Framework\Routing\{Router, RouteInfo, RouteCache};
    use Framework\Routing\Attributes\Route;
    use Framework\Routing\Exceptions\{RouteNotFoundException, MethodNotAllowedException};
    use Framework\Http\{Request, Response};

    /**
     * Comprehensive Router Test Suite for PHP 8.4
     */
    final class RouterTest
    {
        private int $passed = 0;
        private int $failed = 0;
        private array $failures = [];
        private Router $router;
        private MockContainer $container;
        private ?string $expectedExceptionClass = null;
        private ?string $tempCacheDir = null;

        public function __construct()
        {
            $this->container = new MockContainer();
            $this->setupRouter();
        }

        private function setupRouter(array $config = []): void
        {
            $defaultConfig = [
                'debug' => true,
                'strict_subdomain_mode' => false,
                'allowed_subdomains' => ['api', 'admin', 'www', 'test'],
                'base_domain' => 'localhost'
            ];

            $this->router = new Router(
                $this->container,
                null,
                null,
                array_merge($defaultConfig, $config)
            );
        }

        public function runAllTests(): void
        {
            // Clean output buffer before tests
            while (ob_get_level()) {
                ob_end_clean();
            }

            echo "Running Comprehensive Router Tests (PHP 8.4)...\n";
            echo str_repeat("=", 60) . "\n";
            flush();

            $methods = get_class_methods($this);
            $testMethods = array_filter($methods, fn($method) => str_starts_with($method, 'test'));

            // Sort tests by category for better output
            usort($testMethods, fn($a, $b) => $this->getTestCategory($a) <=> $this->getTestCategory($b));

            $currentCategory = '';
            foreach ($testMethods as $testMethod) {
                $category = $this->getTestCategory($testMethod);
                if ($category !== $currentCategory) {
                    echo "\n" . str_repeat("-", 40) . "\n";
                    echo "Category: {$category}\n";
                    echo str_repeat("-", 40) . "\n";
                    $currentCategory = $category;
                }

                try {
                    $this->resetForTest();
                    $this->$testMethod();

                    // Check if exception was expected but not thrown
                    if ($this->expectedExceptionClass !== null) {
                        throw new Exception("Expected exception {$this->expectedExceptionClass} was not thrown");
                    }

                    $this->passed++;
                    echo "✓ {$testMethod}\n";
                    flush();
                } catch (Exception|Error $e) {
                    // Check if this was an expected exception
                    if ($this->expectedExceptionClass !== null &&
                        ($e instanceof $this->expectedExceptionClass ||
                            get_class($e) === $this->expectedExceptionClass)) {
                        $this->passed++;
                        echo "✓ {$testMethod}\n";
                        flush();
                        continue;
                    }

                    $this->failed++;
                    $this->failures[] = [
                        'test' => $testMethod,
                        'error' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'category' => $category
                    ];
                    echo "✗ {$testMethod}: {$e->getMessage()}\n";
                    flush();
                }
            }

            $this->cleanup();
            $this->printSummary();
        }

        private function getTestCategory(string $testMethod): string
        {
            return match (true) {
                str_contains($testMethod, 'Basic') ||
                str_contains($testMethod, 'Registration') ||
                str_contains($testMethod, 'Parameter') && !str_contains($testMethod, 'Validation') => 'A_Basic_Routing',

                str_contains($testMethod, 'Named') => 'B_Named_Routes',

                str_contains($testMethod, 'Subdomain') => 'C_Subdomain_Routing',

                str_contains($testMethod, 'Cache') => 'D_Route_Caching',

                str_contains($testMethod, 'Security') ||
                str_contains($testMethod, 'Validation') ||
                str_contains($testMethod, 'Protection') => 'E_Security',

                str_contains($testMethod, 'Error') ||
                str_contains($testMethod, 'NotFound') ||
                str_contains($testMethod, 'NotAllowed') ||
                str_contains($testMethod, 'Exception') => 'F_Error_Handling',

                str_contains($testMethod, 'Attribute') => 'G_Route_Attributes',

                str_contains($testMethod, 'Performance') ||
                str_contains($testMethod, 'Edge') ||
                str_contains($testMethod, 'Many') => 'H_Performance_EdgeCases',

                default => 'I_Miscellaneous'
            };
        }

        private function resetForTest(): void
        {
            $this->container = new MockContainer();
            $this->setupRouter();
            $this->expectedExceptionClass = null;

            // Reset $_SERVER for clean state
            $_SERVER = [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/',
                'HTTP_HOST' => 'localhost',
                'REMOTE_ADDR' => '127.0.0.1'
            ];
            $_GET = [];
            $_POST = [];
            $_COOKIE = [];
        }

        // === BASIC ROUTING TESTS ===

        public function testBasicRouteRegistration(): void
        {
            $this->router->addRoute('GET', '/users', 'App\\Actions\\TestAction');

            $routes = $this->router->getRoutes();
            $this->assertArrayHasKey('GET', $routes);
            $this->assertCount(1, $routes['GET']);

            $route = $routes['GET'][0];
            $this->assertEquals('GET', $route->method);
            $this->assertEquals('/users', $route->originalPath);
            $this->assertEquals('App\\Actions\\TestAction', $route->actionClass);
        }

        public function testBasicRouteWithParameters(): void
        {
            $this->container->set('App\\Actions\\TestAction', new \App\Actions\TestAction());
            $this->router->addRoute('GET', '/users/{id}', 'App\\Actions\\TestAction');

            $request = $this->createRequest('GET', '/users/123');
            $response = $this->router->dispatch($request);

            $this->assertInstanceOf(Response::class, $response);
            $this->assertEquals(200, $response->getStatus());
            $this->assertStringContainsString('123', $response->getBody());
        }

        public function testBasicRouteWithMultipleParameters(): void
        {
            $this->container->set('App\\Actions\\TestAction', new \App\Actions\TestAction());
            $this->router->addRoute('GET', '/users/{userId}/posts/{postId}', 'App\\Actions\\TestAction');

            $request = $this->createRequest('GET', '/users/123/posts/456');
            $response = $this->router->dispatch($request);

            $this->assertInstanceOf(Response::class, $response);
            $body = $response->getBody();
            $this->assertStringContainsString('123', $body);
            $this->assertStringContainsString('456', $body);
        }

        public function testBasicParameterExtraction(): void
        {
            $routeInfo = RouteInfo::fromPath('GET', '/users/{id}/posts/{slug}', 'App\\Actions\\TestAction');

            $this->assertTrue($routeInfo->matches('GET', '/users/123/posts/my-post'));

            $params = $routeInfo->extractParams('/users/123/posts/my-post');

            $this->assertArrayHasKey('id', $params);
            $this->assertArrayHasKey('slug', $params);
            $this->assertEquals('123', $params['id']);
            $this->assertEquals('my-post', $params['slug']);
        }

        // === NAMED ROUTES TESTS ===

        public function testNamedRouteRegistration(): void
        {
            $this->router->addRoute('GET', '/users/{id}', 'App\\Actions\\TestAction', [], 'user.show');

            $url = $this->router->url('user.show', ['id' => 123]);
            $this->assertEquals('/users/123', $url);
        }

        public function testNamedRouteWithMissingParameters(): void
        {
            $this->router->addRoute('GET', '/users/{id}', 'App\\Actions\\TestAction', [], 'user.show');

            $this->expectException(\InvalidArgumentException::class);
            $this->router->url('user.show'); // Missing id parameter
        }

        public function testNamedRouteNotFound(): void
        {
            $this->expectException(RouteNotFoundException::class);
            $this->router->url('nonexistent.route');
        }

        public function testNamedRouteDuplication(): void
        {
            $this->router->addRoute('GET', '/users', 'App\\Actions\\TestAction', [], 'users');

            $this->expectException(\InvalidArgumentException::class);
            $this->router->addRoute('POST', '/users', 'App\\Actions\\TestAction', [], 'users');
        }

        // === SUBDOMAIN ROUTING TESTS ===

        public function testSubdomainRouteRegistration(): void
        {
            $this->router->addRoute('GET', '/api/users', 'App\\Actions\\ApiAction', [], 'api.users', 'api');

            $routes = $this->router->getRoutes();
            $route = $routes['GET'][0];
            $this->assertEquals('api', $route->subdomain);
        }

        public function testSubdomainRouteMatching(): void
        {
            $this->container->set('App\\Actions\\ApiAction', new \App\Actions\ApiAction());
            $this->router->addRoute('GET', '/users', 'App\\Actions\\ApiAction', [], null, 'api');

            // Test mit korrekter Subdomain - Route sollte gefunden werden
            $request = $this->createRequest('GET', '/users', 'api.localhost');
            $response = $this->router->dispatch($request);
            $this->assertInstanceOf(Response::class, $response);
        }

        public function testSubdomainStrictMode(): void
        {
            $this->setupRouter(['strict_subdomain_mode' => true, 'allowed_subdomains' => ['api']]);
            $this->router->addRoute('GET', '/test', 'App\\Actions\\TestAction', [], null, 'unauthorized');

            $this->expectException(\InvalidArgumentException::class);

            // Try to access with unauthorized subdomain
            $request = $this->createRequest('GET', '/test', 'unauthorized.localhost');
            $this->router->dispatch($request);
        }

        public function testSubdomainRouteMismatch(): void
        {
            $this->router->addRoute('GET', '/users', 'App\\Actions\\ApiAction', [], null, 'api');

            // Test without subdomain should fail
            $request = $this->createRequest('GET', '/users', 'localhost');
            $this->expectException(RouteNotFoundException::class);
            $this->router->dispatch($request);
        }

        public function testSubdomainUrlGeneration(): void
        {
            $this->router->addRoute('GET', '/users/{id}', 'App\\Actions\\ApiAction', [], 'api.user.show', 'api');

            $url = $this->router->url('api.user.show', ['id' => 123], 'api');
            $this->assertEquals('//api.localhost/users/123', $url);
        }

        public function testSubdomainValidation(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->router->addRoute('GET', '/test', 'App\\Actions\\TestAction', [], null, 'invalid..subdomain');
        }

        // === ROUTE CACHING TESTS ===

        public function testRouteCacheCreation(): void
        {
            $this->tempCacheDir = sys_get_temp_dir() . '/test_cache_' . uniqid();
            mkdir($this->tempCacheDir);

            $cache = new RouteCache($this->tempCacheDir);
            $this->assertInstanceOf(RouteCache::class, $cache);
        }

        public function testRouteCacheStorage(): void
        {
            $this->tempCacheDir = sys_get_temp_dir() . '/test_cache_' . uniqid();
            mkdir($this->tempCacheDir);

            $cache = new RouteCache($this->tempCacheDir);
            $router = new Router($this->container, $cache);

            $router->addRoute('GET', '/users', 'App\\Actions\\TestAction');

            // Trigger compilation
            $this->assertTrue($router->hasRoute('GET', '/users'));

            // Cache should be valid after compilation
            $this->assertTrue($cache->isValid());
        }

        public function testRouteCacheLoading(): void
        {
            $this->tempCacheDir = sys_get_temp_dir() . '/test_cache_' . uniqid();
            mkdir($this->tempCacheDir);

            $cache = new RouteCache($this->tempCacheDir);

            // Store some test routes
            $testRoutes = [
                'GET' => [
                    RouteInfo::fromPath('GET', '/test', 'App\\Actions\\TestAction')
                ]
            ];

            $cache->store($testRoutes);
            $this->assertTrue($cache->isValid());

            $loaded = $cache->load();
            $this->assertIsArray($loaded);
            $this->assertArrayHasKey('GET', $loaded);
        }

        public function testRouteCacheInvalidation(): void
        {
            $this->tempCacheDir = sys_get_temp_dir() . '/test_cache_' . uniqid();
            mkdir($this->tempCacheDir);

            $cache = new RouteCache($this->tempCacheDir);

            $testRoutes = ['GET' => []];
            $cache->store($testRoutes);
            $this->assertTrue($cache->isValid());

            $cache->clear();
            $this->assertFalse($cache->isValid());
        }

        // === SECURITY TESTS ===


        public function testSecurityDirectoryTraversalProtection(): void
        {
            $routeInfo = RouteInfo::fromPath('GET', '/files/{path}', 'App\\Actions\\TestAction');

            // Test mit einem Pfad der tatsächlich als Parameter erkannt wird
            $this->expectException(\InvalidArgumentException::class);
            $routeInfo->extractParams('/files/..%2Fetc%2Fpasswd'); // URL-encoded ..
        }

        public function testSecurityNullByteProtection(): void
        {
            $routeInfo = RouteInfo::fromPath('GET', '/users/{id}', 'App\\Actions\\TestAction');

            $this->expectException(\InvalidArgumentException::class);
            $routeInfo->extractParams("/users/123\0admin");
        }

        public function testSecurityOversizedParameterProtection(): void
        {
            $routeInfo = RouteInfo::fromPath('GET', '/users/{id}', 'App\\Actions\\TestAction');

            $this->expectException(\InvalidArgumentException::class);
            $routeInfo->extractParams('/users/' . str_repeat('a', 256));
        }

        public function testSecurityParameterValidationById(): void
        {
            $routeInfo = RouteInfo::fromPath('GET', '/users/{id}', 'App\\Actions\\TestAction');

            // Valid ID should work
            $params = $routeInfo->extractParams('/users/123');
            $this->assertEquals('123', $params['id']);

            // Invalid ID should fail
            $this->expectException(\InvalidArgumentException::class);
            $routeInfo->extractParams('/users/invalid-id');
        }

        public function testSecurityParameterValidationBySlug(): void
        {
            $routeInfo = RouteInfo::fromPath('GET', '/posts/{slug}', 'App\\Actions\\TestAction');

            // Valid slug should work
            $params = $routeInfo->extractParams('/posts/my-post-title');
            $this->assertEquals('my-post-title', $params['slug']);

            // Invalid slug should fail
            $this->expectException(\InvalidArgumentException::class);
            $routeInfo->extractParams('/posts/invalid<script>');
        }

        public function testSecurityActionClassValidation(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->router->addRoute('GET', '/test', 'SomeClass\\ReflectionClass');
        }

        public function testSecurityInvalidNamespaceProtection(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->router->addRoute('GET', '/test', 'System\\FileReader');
        }

        // === ERROR HANDLING TESTS ===

        public function testErrorRouteNotFoundException(): void
        {
            $request = $this->createRequest('GET', '/nonexistent');

            $this->expectException(RouteNotFoundException::class);
            $this->router->dispatch($request);
        }

        public function testErrorMethodNotAllowed(): void
        {
            $this->router->addRoute('GET', '/users', 'App\\Actions\\TestAction');

            $request = $this->createRequest('POST', '/users');

            $this->expectException(MethodNotAllowedException::class);
            $this->router->dispatch($request);
        }

        public function testErrorMethodNotAllowedWithAllowedMethods(): void
        {
            $this->router->addRoute('GET', '/users', 'App\\Actions\\TestAction');
            $this->router->addRoute('POST', '/users', 'App\\Actions\\TestAction');

            $request = $this->createRequest('DELETE', '/users');

            try {
                $this->router->dispatch($request);
                $this->fail('Expected MethodNotAllowedException');
            } catch (MethodNotAllowedException $e) {
                $allowedMethods = $e->getAllowedMethods();
                $this->assertContains('GET', $allowedMethods);
                $this->assertContains('POST', $allowedMethods);
            }
        }

        public function testErrorInvalidHttpMethod(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->router->addRoute('INVALID', '/users', 'App\\Actions\\TestAction');
        }

        public function testErrorInvalidActionClass(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->router->addRoute('GET', '/users', 'App\\Actions\\NonExistentClass');
        }

        public function testErrorLongPath(): void
        {
            $longPath = '/' . str_repeat('a', 2049);

            $this->expectException(\InvalidArgumentException::class);
            $this->router->addRoute('GET', $longPath, 'App\\Actions\\TestAction');
        }

        public function testErrorInvalidRouteName(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->router->addRoute('GET', '/users', 'App\\Actions\\TestAction', [], 'invalid name!');
        }

        // === ROUTE ATTRIBUTES TESTS ===

        public function testAttributeRouteCreation(): void
        {
            $route = new Route('GET', '/users/{id}', [], 'user.show');

            $this->assertEquals('GET', $route->method);
            $this->assertEquals('/users/{id}', $route->path);
            $this->assertEquals('user.show', $route->name);
        }

        public function testAttributeInvalidPath(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            new Route('GET', 'invalid-path'); // Must start with /
        }

        public function testAttributeInvalidMethod(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            new Route('INVALID', '/users');
        }

        public function testAttributeWithSubdomain(): void
        {
            $route = new Route('GET', '/api/users', [], 'api.users', 'api');

            $this->assertEquals('api', $route->subdomain);
        }

        public function testAttributeInvalidSubdomain(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            new Route('GET', '/test', [], null, 'invalid..subdomain');
        }

        // === PERFORMANCE & EDGE CASES ===

        public function testPerformanceManyRoutes(): void
        {
            $startTime = microtime(true);

            // Add many routes
            for ($i = 0; $i < 100; $i++) {
                $this->router->addRoute('GET', "/route{$i}", 'App\\Actions\\TestAction');
            }

            $this->assertTrue($this->router->hasRoute('GET', '/route50'));

            $endTime = microtime(true);
            $duration = $endTime - $startTime;

            // Should complete within reasonable time (1 second)
            $this->assertLessThan(1.0, $duration, 'Route registration took too long');
        }

        public function testEdgeCaseSpecialCharactersInParameters(): void
        {
            $this->container->set('App\\Actions\\TestAction', new \App\Actions\TestAction());
            $this->router->addRoute('GET', '/search/{query}', 'App\\Actions\\TestAction');

            $request = $this->createRequest('GET', '/search/hello%20world');
            $response = $this->router->dispatch($request);
            $this->assertInstanceOf(Response::class, $response);
        }

        public function testEdgeCaseEmptyParameters(): void
        {
            $routeInfo = RouteInfo::fromPath('GET', '/test/{param}', 'App\\Actions\\TestAction');

            // Empty parameter should not match
            $this->assertFalse($routeInfo->matches('GET', '/test/'));
        }

        public function testEdgeCaseRouteMatching(): void
        {
            $routeInfo = RouteInfo::fromPath('GET', '/users/{id}', 'App\\Actions\\TestAction');

            $this->assertTrue($routeInfo->matches('GET', '/users/123'));
            $this->assertFalse($routeInfo->matches('POST', '/users/123'));
            $this->assertFalse($routeInfo->matches('GET', '/posts/123'));
            $this->assertFalse($routeInfo->matches('GET', '/users')); // Missing parameter
        }

        public function testEdgeCaseUrlGeneration(): void
        {
            $this->router->addRoute('GET', '/complex/{param1}/test/{param2}', 'App\\Actions\\TestAction', [], 'complex');

            $url = $this->router->url('complex', ['param1' => 'value1', 'param2' => 'value2']);
            $this->assertEquals('/complex/value1/test/value2', $url);
        }

        // === MISCELLANEOUS TESTS ===

        public function testHasRoute(): void
        {
            $this->router->addRoute('GET', '/users', 'App\\Actions\\TestAction');

            $this->assertTrue($this->router->hasRoute('GET', '/users'));
            $this->assertFalse($this->router->hasRoute('POST', '/users'));
            $this->assertFalse($this->router->hasRoute('GET', '/posts'));
        }

        public function testRouteMiddleware(): void
        {
            $this->router->addRoute('GET', '/admin', 'App\\Actions\\TestAction', ['auth', 'admin']);

            $routes = $this->router->getRoutes();
            $route = $routes['GET'][0];

            $this->assertEquals(['auth', 'admin'], $route->middleware);
        }

        public function testActionExecution(): void
        {
            $this->container->set('App\\Actions\\TestAction', new \App\Actions\TestAction());
            $this->router->addRoute('GET', '/test', 'App\\Actions\\TestAction');

            $request = $this->createRequest('GET', '/test');
            $response = $this->router->dispatch($request);

            $this->assertInstanceOf(Response::class, $response);
            $this->assertStringContainsString('Test Response', $response->getBody());
        }

        public function testRouteCompilation(): void
        {
            $this->router->addRoute('GET', '/users/{id}', 'App\\Actions\\TestAction');
            $this->router->addRoute('GET', '/static', 'App\\Actions\\TestAction');

            // Trigger compilation by checking routes
            $this->assertTrue($this->router->hasRoute('GET', '/users/123'));
            $this->assertTrue($this->router->hasRoute('GET', '/static'));
        }

        // === HELPER METHODS ===

        private function createRequest(string $method, string $path, string $host = 'localhost'): Request
        {
            $_SERVER = [
                'REQUEST_METHOD' => $method,
                'REQUEST_URI' => $path,
                'HTTP_HOST' => $host,
                'REMOTE_ADDR' => '127.0.0.1'
            ];
            $_GET = [];
            $_POST = [];
            $_COOKIE = [];

            return Request::fromGlobals();
        }

        private function cleanup(): void
        {
            if ($this->tempCacheDir && is_dir($this->tempCacheDir)) {
                $this->recursiveDelete($this->tempCacheDir);
            }
        }

        private function recursiveDelete(string $dir): void
        {
            if (!is_dir($dir)) {
                return;
            }

            $items = array_diff(scandir($dir), ['.', '..']);
            foreach ($items as $item) {
                $path = $dir . DIRECTORY_SEPARATOR . $item;
                if (is_dir($path)) {
                    $this->recursiveDelete($path);
                } else {
                    unlink($path);
                }
            }
            rmdir($dir);
        }


        private function expectException(string $exceptionClass): void
        {
            $this->expectedExceptionClass = $exceptionClass;
        }

        // === ASSERTION METHODS ===

        private function assertTrue(bool $condition, string $message = ''): void
        {
            if (!$condition) {
                throw new Exception($message ?: 'Expected true, got false');
            }
        }

        private function assertFalse(bool $condition, string $message = ''): void
        {
            if ($condition) {
                throw new Exception($message ?: 'Expected false, got true');
            }
        }

        private function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
        {
            if ($expected !== $actual) {
                $expectedStr = $this->valueToString($expected);
                $actualStr = $this->valueToString($actual);
                throw new Exception($message ?: "Expected {$expectedStr}, got {$actualStr}");
            }
        }

        private function assertInstanceOf(string $expected, mixed $actual, string $message = ''): void
        {
            if (!($actual instanceof $expected)) {
                $actualType = is_object($actual) ? get_class($actual) : gettype($actual);
                throw new Exception($message ?: "Expected instance of {$expected}, got {$actualType}");
            }
        }

        private function assertArrayHasKey(string|int $key, array $array, string $message = ''): void
        {
            if (!array_key_exists($key, $array)) {
                throw new Exception($message ?: "Array does not contain key '{$key}'");
            }
        }

        private function assertCount(int $expected, array $array, string $message = ''): void
        {
            $actual = count($array);
            if ($actual !== $expected) {
                throw new Exception($message ?: "Expected {$expected} items, got {$actual}");
            }
        }

        private function assertContains(mixed $needle, array $haystack, string $message = ''): void
        {
            if (!in_array($needle, $haystack, true)) {
                throw new Exception($message ?: "Array does not contain expected value");
            }
        }

        private function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
        {
            if (!str_contains($haystack, $needle)) {
                throw new Exception($message ?: "String '{$haystack}' does not contain '{$needle}'");
            }
        }

        private function assertIsArray(mixed $value, string $message = ''): void
        {
            if (!is_array($value)) {
                throw new Exception($message ?: 'Expected array');
            }
        }

        private function assertLessThan(float $expected, float $actual, string $message = ''): void
        {
            if ($actual >= $expected) {
                throw new Exception($message ?: "Expected {$actual} to be less than {$expected}");
            }
        }

        private function fail(string $message): void
        {
            throw new Exception($message);
        }

        private function valueToString(mixed $value): string
        {
            return match (true) {
                is_null($value) => 'null',
                is_bool($value) => $value ? 'true' : 'false',
                is_string($value) => "'{$value}'",
                is_array($value) => 'array(' . count($value) . ' items)',
                is_object($value) => get_class($value) . ' object',
                default => (string)$value
            };
        }

        private function printSummary(): void
        {
            echo "\n" . str_repeat("=", 60) . "\n";
            echo "COMPREHENSIVE TEST RESULTS\n";
            echo str_repeat("=", 60) . "\n";
            echo "Passed: {$this->passed}\n";
            echo "Failed: {$this->failed}\n";
            echo "Total:  " . ($this->passed + $this->failed) . "\n";

            if ($this->passed > 0) {
                $successRate = round(($this->passed / ($this->passed + $this->failed)) * 100, 1);
                echo "Success Rate: {$successRate}%\n";
            }

            flush();

            if (!empty($this->failures)) {
                echo "\n" . str_repeat("-", 60) . "\n";
                echo "FAILURE DETAILS\n";
                echo str_repeat("-", 60) . "\n";

                $failuresByCategory = [];
                foreach ($this->failures as $failure) {
                    $category = $failure['category'];
                    $failuresByCategory[$category][] = $failure;
                }

                foreach ($failuresByCategory as $category => $failures) {
                    echo "\n{$category}:\n";
                    foreach ($failures as $failure) {
                        echo "- {$failure['test']}: {$failure['error']}\n";
                        echo "  at {$failure['file']}:{$failure['line']}\n";
                    }
                }
                flush();
            }

            echo "\n" . str_repeat("=", 60) . "\n";
            if ($this->failed === 0) {
                echo "🎉 ALL TESTS PASSED! Router is production ready.\n";
            } else {
                echo "❌ SOME TESTS FAILED! Please fix issues before production.\n";
            }
            echo str_repeat("=", 60) . "\n";
            flush();
        }
    }

    /**
     * Enhanced Mock Container for comprehensive testing
     */
    class MockContainer implements \Framework\Container\ContainerInterface
    {
        private array $services = [];
        private array $singletons = [];

        public function get(string $id): mixed
        {
            // Check singletons first
            if (isset($this->singletons[$id])) {
                return $this->singletons[$id];
            }

            if (isset($this->services[$id])) {
                $service = $this->services[$id];

                // If it's a callable, invoke it
                if (is_callable($service)) {
                    return $service();
                }

                return $service;
            }

            // Auto-create test services
            return match ($id) {
                'App\\Actions\\TestAction' => new \App\Actions\TestAction(),
                'App\\Actions\\ApiAction' => new \App\Actions\ApiAction(),
                'App\\Actions\\AdminAction' => new \App\Actions\AdminAction(),
                default => throw new \RuntimeException("Service not found: {$id}")
            };
        }

        public function has(string $id): bool
        {
            return isset($this->services[$id]) ||
                isset($this->singletons[$id]) ||
                in_array($id, [
                    'App\\Actions\\TestAction',
                    'App\\Actions\\ApiAction',
                    'App\\Actions\\AdminAction'
                ], true);
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
            if (is_callable($concrete)) {
                $this->singletons[$id] = $concrete();
            } else {
                $this->singletons[$id] = $concrete;
            }
        }

        public function flush(): void
        {
            $this->services = [];
            $this->singletons = [];
        }
    }

    // === USAGE ===
    if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
        // Ensure clean start
        while (ob_get_level()) {
            ob_end_clean();
        }

        $testRunner = new RouterTest();
        $testRunner->runAllTests();
    }
}