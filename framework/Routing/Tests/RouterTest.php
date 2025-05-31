<?php


declare(strict_types=1);

// Start output buffering to prevent headers already sent issue
ob_start();

// Load required files
require_once __DIR__ . '/../Exceptions/RouteNotFoundException.php';
require_once __DIR__ . '/../Exceptions/MethodNotAllowedException.php';
require_once __DIR__ . '/../Exceptions/RouteCompilationException.php';
require_once __DIR__ . '/../../Container/ContainerInterface.php'; // Container Interface laden
require_once __DIR__ . '/../Attributes/Route.php';
require_once __DIR__ . '/../RouteInfo.php';
require_once __DIR__ . '/../RouteCache.php';
require_once __DIR__ . '/../Router.php';
require_once __DIR__ . '/../../Http/Request.php';
require_once __DIR__ . '/../../Http/Response.php';

// Try to load ContainerInterface
$containerInterfaceLoaded = false;
if (file_exists(__DIR__ . '/../../Container/ContainerInterface.php')) {
    require_once __DIR__ . '/../../Container/ContainerInterface.php';
    $containerInterfaceLoaded = interface_exists('Framework\\Container\\ContainerInterface');
}

// Create simple ContainerInterface if framework interface not available
if (!$containerInterfaceLoaded) {
    eval('
    namespace Framework\\Container {
        interface ContainerInterface
        {
            public function get(string $id): mixed;
            public function has(string $id): bool;
            public function bind(string $id, mixed $concrete): void;
            public function singleton(string $id, mixed $concrete): void;
        }
    }
    ');
}

use Framework\Routing\{Router, RouteInfo, RouteCache};
use Framework\Routing\Attributes\Route;
use Framework\Routing\Exceptions\{RouteNotFoundException, MethodNotAllowedException};
use Framework\Http\{Request, Response};



/**
 * Router Test without Unit Test Framework
 */
final class RouterTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];
    private Router $router;
    private MockContainer $container;
    private ?string $expectedExceptionClass = null;

    public function __construct()
    {
        $this->container = new MockContainer();
        $this->router = new Router($this->container);
    }

    public function runAllTests(): void
    {
        // Clean output buffer before tests
        while (ob_get_level()) {
            ob_end_clean();
        }

        echo "Running Router Tests...\n";
        echo str_repeat("=", 50) . "\n";
        flush();

        $methods = get_class_methods($this);
        $testMethods = array_filter($methods, fn($method) => str_starts_with($method, 'test'));

        foreach ($testMethods as $testMethod) {
            try {
                // Reset for each test
                $this->container = new MockContainer();
                $this->router = new Router($this->container, null, null, [
                    'debug' => true,
                    'strict_subdomain_mode' => false
                ]);
                $this->expectedExceptionClass = null;

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
                    'line' => $e->getLine()
                ];
                echo "✗ {$testMethod}: {$e->getMessage()}\n";
                flush();
            }
        }

        $this->printSummary();
    }

    // === Test Methods ===

    public function testRouteRegistration(): void
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

    public function testRouteWithParameters(): void
    {
        $this->container->set('App\\Actions\\TestAction', new TestAction());
        $this->router->addRoute('GET', '/users/{id}', 'App\\Actions\\TestAction');

        $request = $this->createRequest('GET', '/users/123');
        $response = $this->router->dispatch($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatus());
    }

    public function testRouteWithMultipleParameters(): void
    {
        $this->container->set('App\\Actions\\TestAction', new TestAction());
        $this->router->addRoute('GET', '/users/{userId}/posts/{postId}', 'App\\Actions\\TestAction');

        $request = $this->createRequest('GET', '/users/123/posts/456');
        $response = $this->router->dispatch($request);

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testNamedRoute(): void
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

    public function testRouteNotFound(): void
    {
        $request = $this->createRequest('GET', '/nonexistent');

        $this->expectException(RouteNotFoundException::class);
        $this->router->dispatch($request);
    }

    public function testMethodNotAllowed(): void
    {
        $this->router->addRoute('GET', '/users', 'App\\Actions\\TestAction');

        $request = $this->createRequest('POST', '/users');

        $this->expectException(MethodNotAllowedException::class);
        $this->router->dispatch($request);
    }

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

    public function testParameterExtraction(): void
    {
        $routeInfo = RouteInfo::fromPath('GET', '/users/{id}/posts/{slug}', 'App\\Actions\\TestAction');

        // Test if route matches first
        $this->assertTrue($routeInfo->matches('GET', '/users/123/posts/my-post'));

        $params = $routeInfo->extractParams('/users/123/posts/my-post');

        $this->assertArrayHasKey('id', $params);
        $this->assertArrayHasKey('slug', $params);
        $this->assertEquals('123', $params['id']);
        $this->assertEquals('my-post', $params['slug']);
    }

    public function testParameterValidation(): void
    {
        $routeInfo = RouteInfo::fromPath('GET', '/users/{id}', 'App\\Actions\\TestAction');

        // Test if normal path works first
        $this->assertTrue($routeInfo->matches('GET', '/users/123'));

        // Test directory traversal protection - should throw exception
        try {
            $routeInfo->extractParams('/users/../admin');
            throw new Exception("Expected InvalidArgumentException was not thrown");
        } catch (\InvalidArgumentException $e) {
            // Expected exception was thrown
            $this->assertTrue(true);
        }
    }

    public function testNullByteProtection(): void
    {
        $routeInfo = RouteInfo::fromPath('GET', '/users/{id}', 'App\\Actions\\TestAction');

        $this->expectException(\InvalidArgumentException::class);
        $routeInfo->extractParams("/users/123\0admin");
    }

    public function testInvalidHttpMethod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->router->addRoute('INVALID', '/users', TestAction::class);
    }

    public function testInvalidActionClass(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->router->addRoute('GET', '/users', 'NonExistentClass');
    }

    public function testLongPath(): void
    {
        $longPath = '/' . str_repeat('a', 2049);

        $this->expectException(\InvalidArgumentException::class);
        $this->router->addRoute('GET', $longPath, 'App\\Actions\\TestAction');
    }

    public function testInvalidRouteName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->router->addRoute('GET', '/users', 'App\\Actions\\TestAction', [], 'invalid name!');
    }

    public function testDuplicateRouteName(): void
    {
        $this->router->addRoute('GET', '/users', 'App\\Actions\\TestAction', [], 'users');

        $this->expectException(\InvalidArgumentException::class);
        $this->router->addRoute('POST', '/users', 'App\\Actions\\TestAction', [], 'users');
    }

    public function testRouteMatching(): void
    {
        $routeInfo = RouteInfo::fromPath('GET', '/users/{id}', 'App\\Actions\\TestAction');

        // Debug: Check what the pattern looks like
        // echo "Pattern: " . $routeInfo->pattern . "\n";

        $this->assertTrue($routeInfo->matches('GET', '/users/123'));
        $this->assertFalse($routeInfo->matches('POST', '/users/123'));
        $this->assertFalse($routeInfo->matches('GET', '/posts/123'));
        $this->assertFalse($routeInfo->matches('GET', '/users')); // Missing parameter
    }

    public function testRouteAttribute(): void
    {
        // Test Route attribute creation
        $route = new Route('GET', '/users/{id}', [], 'user.show');

        $this->assertEquals('GET', $route->method);
        $this->assertEquals('/users/{id}', $route->path);
        $this->assertEquals('user.show', $route->name);
    }

    public function testInvalidRouteAttributePath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Route('GET', 'invalid-path'); // Must start with /
    }

    public function testRouteAttributeWithInvalidMethod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Route('INVALID', '/users');
    }

    public function testActionExecution(): void
    {
        $this->container->set('App\\Actions\\TestAction', new TestAction());
        $this->router->addRoute('GET', '/test', 'App\\Actions\\TestAction');

        $request = $this->createRequest('GET', '/test');
        $response = $this->router->dispatch($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals('Test Response', $response->getBody());
    }

    public function testSecurityValidation(): void
    {
        // Set up container first
        $this->container->set('App\\Actions\\TestAction', new TestAction());

        // Test various security validations
        $request = $this->createRequest('GET', '/test');

        // Should not throw exception for valid request
        $this->router->addRoute('GET', '/test', 'App\\Actions\\TestAction');
        $response = $this->router->dispatch($request);
        $this->assertInstanceOf(Response::class, $response);
    }

    // === Helper Methods ===

    private function createRequest(string $method, string $path): Request
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

    private function expectException(string $exceptionClass): void
    {
        $this->expectedExceptionClass = $exceptionClass;
    }

    // === Assertion Methods ===

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

    private function valueToString(mixed $value): string
    {
        return match(true) {
            is_null($value) => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => "'{$value}'",
            is_array($value) => 'array(' . count($value) . ' items)',
            is_object($value) => get_class($value) . ' object',
            default => (string) $value
        };
    }

    private function printSummary(): void
    {
        echo str_repeat("=", 50) . "\n";
        echo "Test Results:\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        echo "Total:  " . ($this->passed + $this->failed) . "\n";
        flush();

        if (!empty($this->failures)) {
            echo "\nFailures:\n";
            foreach ($this->failures as $failure) {
                echo "- {$failure['test']}: {$failure['error']}\n";
                echo "  at {$failure['file']}:{$failure['line']}\n";
            }
            flush();
        }

        echo ($this->failed === 0) ? "\n✅ All tests passed!\n" : "\n❌ Some tests failed!\n";
        flush();
    }
}

/**
 * Mock Container for testing
 */
class MockContainer implements \Framework\Container\ContainerInterface
{
    private array $services = [];

    public function get(string $id): mixed
    {
        if (isset($this->services[$id])) {
            return $this->services[$id];
        }

        // Auto-create test services
        if ($id === TestAction::class) {
            return new TestAction();
        }

        throw new \RuntimeException("Service not found: {$id}");
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]) || $id === TestAction::class;
    }

    public function set(string $id, mixed $service): void
    {
        $this->services[$id] = $service;
    }

    public function bind(string $id, mixed $concrete): void
    {
        // TODO: Implement bind() method.
    }

    public function singleton(string $id, mixed $concrete): void
    {
        // TODO: Implement singleton() method.
    }
}

/**
 * Test Action Class in allowed namespace
 */
final class TestAction
{
    public function __invoke(Request $request, array $params): Response
    {
        return Response::text('Test Response');
    }
}

// Create fake namespace class for testing
if (!class_exists('App\\Actions\\TestAction')) {
    class_alias('TestAction', 'App\\Actions\\TestAction');
}

// Create ContainerInterface if it doesn't exist
if (!interface_exists('Framework\Container\ContainerInterface')) {
    namespace Framework\Container {
        interface ContainerInterface
        {
            public function get(string $id): mixed;
            public function has(string $id): bool;
        }
    }
}

// === Usage ===

if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    // Ensure clean start
    while (ob_get_level()) {
        ob_end_clean();
    }

    $testRunner = new RouterTest();
    $testRunner->runAllTests();
}