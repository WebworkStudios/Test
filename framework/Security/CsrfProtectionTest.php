<?php

declare(strict_types=1);

// Start output buffering to prevent headers already sent issue
ob_start();

// Load required files
require_once __DIR__ . '/../Http/SessionInterface.php';
require_once __DIR__ . '/../Http/Session.php';
require_once __DIR__ . '/../Http/Request.php';
require_once __DIR__ . '/CsrfProtectionInterface.php';
require_once __DIR__ . '/CsrfProtection.php';

use Framework\Http\Session;
use Framework\Http\Request;
use Framework\Security\CsrfProtection;

/**
 * CSRF Protection Test without Unit Test Framework
 */
final class CsrfProtectionTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];
    private \Framework\Http\SessionInterface $session;
    private CsrfProtection $csrf;

    public function __construct()
    {
        // Clean any existing session data
        $_SESSION = [];

        // Create mock session that doesn't require actual PHP session
        $this->session = new MockSession();
        $this->csrf = new CsrfProtection($this->session);
    }

    public function runAllTests(): void
    {
        // Clean output buffer before tests
        while (ob_get_level()) {
            ob_end_clean();
        }

        echo "Running CSRF Protection Tests...\n";
        echo str_repeat("=", 50) . "\n";
        flush();

        $methods = get_class_methods($this);
        $testMethods = array_filter($methods, fn($method) => str_starts_with($method, 'test'));

        foreach ($testMethods as $testMethod) {
            try {
                // Reset session data for each test
                $this->session = new MockSession();
                $this->csrf = new CsrfProtection($this->session);

                $this->$testMethod();
                $this->passed++;
                echo "✓ {$testMethod}\n";
                flush();
            } catch (Exception|Error $e) {
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

    public function testTokenGeneration(): void
    {
        $token = $this->csrf->generateToken();

        $this->assertNotEmpty($token);
        $this->assertEquals(64, strlen($token)); // 32 bytes = 64 hex chars
        $this->assertTrue(ctype_xdigit($token));
    }

    public function testTokenValidation(): void
    {
        $token = $this->csrf->generateToken();

        $this->assertTrue($this->csrf->validateToken($token));
        $this->assertFalse($this->csrf->validateToken('invalid_token'));
        $this->assertFalse($this->csrf->validateToken(''));
    }

    public function testActionSpecificTokens(): void
    {
        $loginToken = $this->csrf->generateToken('login');
        $deleteToken = $this->csrf->generateToken('delete');

        $this->assertNotEquals($loginToken, $deleteToken);

        $this->assertTrue($this->csrf->validateToken($loginToken, 'login'));
        $this->assertTrue($this->csrf->validateToken($deleteToken, 'delete'));

        $this->assertFalse($this->csrf->validateToken($loginToken, 'delete'));
        $this->assertFalse($this->csrf->validateToken($deleteToken, 'login'));
    }

    public function testOneTimeTokenConsumption(): void
    {
        $token = $this->csrf->generateToken();

        // First validation should pass
        $this->assertTrue($this->csrf->validateToken($token, 'default', true));

        // Consume the token
        $this->csrf->consumeToken();

        // Second validation should fail (token already used)
        $this->assertFalse($this->csrf->validateToken($token, 'default', true));
    }

    public function testValidateAndConsume(): void
    {
        $token = $this->csrf->generateToken();

        // Should validate and consume in one operation
        $this->assertTrue($this->csrf->validateAndConsume($token));

        // Token should now be marked as used
        $this->assertFalse($this->csrf->validateToken($token, 'default', true));
    }

    public function testReusableTokens(): void
    {
        $token = $this->csrf->generateToken();

        // Validate multiple times with oneTime = false
        $this->assertTrue($this->csrf->validateToken($token, 'default', false));
        $this->assertTrue($this->csrf->validateToken($token, 'default', false));
        $this->assertTrue($this->csrf->validateToken($token, 'default', false));
    }

    public function testTokenRetrieval(): void
    {
        $token = $this->csrf->generateToken('test');

        $retrieved = $this->csrf->getToken('test');
        $this->assertEquals($token, $retrieved);

        $nonExistent = $this->csrf->getToken('nonexistent');
        $this->assertNull($nonExistent);
    }

    public function testTokenMethod(): void
    {
        // First call should generate token
        $token1 = $this->csrf->token('test');
        $this->assertNotEmpty($token1);

        // Second call should return same token (not expired)
        $token2 = $this->csrf->token('test');
        $this->assertEquals($token1, $token2);
    }

    public function testHtmlFieldGeneration(): void
    {
        $field = $this->csrf->field();

        $this->assertStringContainsString('<input type="hidden"', $field);
        $this->assertStringContainsString('name="_token"', $field);
        $this->assertStringContainsString('value="', $field);

        // Custom name
        $customField = $this->csrf->field('login', 'csrf_token');
        $this->assertStringContainsString('name="csrf_token"', $customField);
    }

    public function testMetaTagGeneration(): void
    {
        $metaTag = $this->csrf->metaTag();

        $this->assertStringContainsString('<meta name="csrf-token"', $metaTag);
        $this->assertStringContainsString('content="', $metaTag);
    }

    public function testRequestValidation(): void
    {
        // Test 1: Token in body - create request manually with proper body
        $token1 = $this->csrf->generateToken();

        // Simulate proper POST request with form data
        $_POST = ['_token' => $token1];
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/test',
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded'
        ];

        $request = Request::fromGlobals();

        $result = $this->csrf->validateFromRequest($request, 'default', false);
        $this->assertTrue($result, "Body token validation failed");

        // Clean up
        $_POST = [];
        $_SERVER = [];

        // Test 2: Request without token
        $_POST = [];
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/test'
        ];

        $requestNoToken = Request::fromGlobals();
        $this->assertFalse($this->csrf->validateFromRequest($requestNoToken, 'default', false));

        // Test 3: Token in header
        $token2 = $this->csrf->generateToken('header');
        $_POST = [];
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/test',
            'HTTP_X_CSRF_TOKEN' => $token2
        ];

        $requestHeader = Request::fromGlobals();
        $headerResult = $this->csrf->validateFromRequest($requestHeader, 'header', false);
        $this->assertTrue($headerResult, "Header token validation failed");

        // Clean up
        $_POST = [];
        $_SERVER = [];
    }

    public function testTokenInvalidation(): void
    {
        $token = $this->csrf->generateToken('test');

        $this->assertTrue($this->csrf->validateToken($token, 'test'));

        $this->csrf->invalidateToken('test');

        $this->assertFalse($this->csrf->validateToken($token, 'test'));
    }

    public function testClearAllTokens(): void
    {
        $this->csrf->generateToken('token1');
        $this->csrf->generateToken('token2');
        $this->csrf->generateToken('token3');

        $tokens = $this->csrf->getStoredTokens();
        $this->assertCount(3, $tokens);

        $this->csrf->clearTokens();

        $tokens = $this->csrf->getStoredTokens();
        $this->assertEmpty($tokens);
    }

    public function testTokenExpiration(): void
    {
        // Create token and manually set old timestamp
        $token = $this->csrf->generateToken('test');
        $tokens = $this->csrf->getStoredTokens();

        // Manually age the token
        $tokens['test']['created_at'] = time() - 7200; // 2 hours ago
        $this->session->set('_csrf_tokens', $tokens);

        // Clean expired tokens
        $this->csrf->cleanExpiredTokens(3600); // 1 hour max age

        // Token should be removed
        $this->assertNull($this->csrf->getToken('test'));
    }

    public function testMaxTokenLimit(): void
    {
        // Generate more than MAX_TOKENS (10)
        for ($i = 0; $i < 15; $i++) {
            $this->csrf->generateToken("token_{$i}");
        }

        $tokens = $this->csrf->getStoredTokens();
        $this->assertLessThanOrEqual(10, count($tokens));
    }

    public function testPropertyHooks(): void
    {
        // Test defaultToken property hook
        $defaultToken = $this->csrf->defaultToken;
        $this->assertNotEmpty($defaultToken);
        $this->assertEquals(64, strlen($defaultToken));

        // Test allTokens property hook
        $this->csrf->generateToken('test1');
        $this->csrf->generateToken('test2');

        $allTokens = $this->csrf->allTokens;
        $this->assertIsArray($allTokens);
        $this->assertGreaterThanOrEqual(2, count($allTokens));

        // Test hasTokens property hook
        $this->assertTrue($this->csrf->hasTokens);

        $this->csrf->clearTokens();
        $this->assertFalse($this->csrf->hasTokens);
    }

    public function testTimingSafeComparison(): void
    {
        $token = $this->csrf->generateToken();

        // Valid token should pass
        $this->assertTrue($this->csrf->validateToken($token));

        // Similar but wrong token should fail
        $wrongToken = substr($token, 0, -1) . 'x';
        $this->assertFalse($this->csrf->validateToken($wrongToken));

        // Empty token should fail
        $this->assertFalse($this->csrf->validateToken(''));
    }

    public function testHtmlEscaping(): void
    {
        $field = $this->csrf->field();

        // Check for proper HTML escaping
        $this->assertStringNotContainsString('<script', $field);
        $this->assertStringNotContainsString('javascript:', $field);

        $metaTag = $this->csrf->metaTag();
        $this->assertStringNotContainsString('<script', $metaTag);
        $this->assertStringNotContainsString('javascript:', $metaTag);
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

    private function assertNotEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected === $actual) {
            throw new Exception($message ?: 'Values should not be equal');
        }
    }

    private function assertNull(mixed $value, string $message = ''): void
    {
        if ($value !== null) {
            throw new Exception($message ?: 'Expected null');
        }
    }

    private function assertNotNull(mixed $value, string $message = ''): void
    {
        if ($value === null) {
            throw new Exception($message ?: 'Expected non-null value');
        }
    }

    private function assertEmpty(mixed $value, string $message = ''): void
    {
        if (!empty($value)) {
            throw new Exception($message ?: 'Expected empty value');
        }
    }

    private function assertNotEmpty(mixed $value, string $message = ''): void
    {
        if (empty($value)) {
            throw new Exception($message ?: 'Expected non-empty value');
        }
    }

    private function assertCount(int $expected, array $array, string $message = ''): void
    {
        $actual = count($array);
        if ($actual !== $expected) {
            throw new Exception($message ?: "Expected {$expected} items, got {$actual}");
        }
    }

    private function assertLessThanOrEqual(int $expected, int $actual, string $message = ''): void
    {
        if ($actual > $expected) {
            throw new Exception($message ?: "Expected {$actual} <= {$expected}");
        }
    }

    private function assertGreaterThanOrEqual(int $expected, int $actual, string $message = ''): void
    {
        if ($actual < $expected) {
            throw new Exception($message ?: "Expected {$actual} >= {$expected}");
        }
    }

    private function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new Exception($message ?: "String '{$haystack}' does not contain '{$needle}'");
        }
    }

    private function assertStringNotContainsString(string $needle, string $haystack, string $message = ''): void
    {
        if (str_contains($haystack, $needle)) {
            throw new Exception($message ?: "String '{$haystack}' should not contain '{$needle}'");
        }
    }

    private function assertIsArray(mixed $value, string $message = ''): void
    {
        if (!is_array($value)) {
            throw new Exception($message ?: 'Expected array');
        }
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
 * Mock Session for testing without PHP session dependency
 */
class MockSession implements \Framework\Http\SessionInterface
{
    private array $data = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }
}

// === Usage ===

if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    // Ensure clean start
    while (ob_get_level()) {
        ob_end_clean();
    }

    $testRunner = new CsrfProtectionTest();
    $testRunner->runAllTests();
}