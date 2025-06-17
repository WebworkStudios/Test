<?php
declare(strict_types=1);

namespace Framework;

use Framework\Container\Container;
use Framework\Http\Request;
use Throwable;

/**
 * Main Application class - Entry point for the framework
 */
final class Application
{
    // Property Hooks for runtime info
    public bool $isRunning {
        get => $this->running;
    }

    public string $environment {
        get => $this->config['app']['env'] ?? 'production';
    }

    public bool $debugMode {
        get => $this->config['app']['debug'] ?? false;
    }

    private bool $running = false;
    private ?Kernel $kernel = null;

    public function __construct(
        private readonly Container $container,
        private readonly array     $config = []
    )
    {
        $this->initializeErrorHandling();
        $this->container->instance(self::class, $this);
    }

    /**
     * Initialize error handling based on environment
     */
    private function initializeErrorHandling(): void
    {
        if ($this->debugMode) {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
        } else {
            error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
            ini_set('display_errors', '0');
            ini_set('log_errors', '1');
        }

        // Set custom error handler
        set_error_handler($this->createErrorHandler(...));
        set_exception_handler($this->createExceptionHandler(...));
    }

    /**
     * Create application with default configuration
     */
    public static function create(array $config = []): self
    {
        $container = new Container($config);
        return new self($container, $config);
    }

    /**
     * Register service provider
     */
    public function register(object $provider): self
    {
        $this->getKernel()->register($provider);
        return $this;
    }

    /**
     * Get or create kernel instance
     */
    private function getKernel(): Kernel
    {
        return $this->kernel ??= new Kernel($this->container, $this->config);
    }

    /**
     * Boot the application
     */
    public function boot(): self
    {
        $this->getKernel()->boot();
        return $this;
    }

    /**
     * Run the application
     */
    public function run(): void
    {
        $this->running = true;

        try {
            // ✅ Better error reporting
            error_log("=== Application::run() START ===");

            // Create request from globals
            $request = Request::fromGlobals();
            error_log("✅ Request created: " . $request->method . " " . $request->path);

            // Handle request through kernel
            $response = $this->getKernel()->handle($request);
            error_log("✅ Response created with status: " . $response->getStatus());

            // Send response
            $response->send();

        } catch (Throwable $e) {
            error_log("❌ Fatal application error: " . $e->getMessage());
            error_log("   File: " . $e->getFile() . ":" . $e->getLine());
            error_log("   Trace: " . $e->getTraceAsString());

            $this->handleFatalError($e);
        } finally {
            $this->terminate();
        }
    }

    /**
     * Handle fatal application errors
     */
    private function handleFatalError(Throwable $e): void
    {
        error_log("Fatal application error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }

        if ($this->debugMode) {
            echo "<h1>Fatal Error</h1>";
            echo "<p><strong>Message:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
            echo "<p><strong>File:</strong> " . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "</p>";
            echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
        } else {
            echo "<head><title>Server Error</title></head><body><h1>500 - Internal Server Error</h1></body></html>";
        }
    }

    /**
     * Terminate the application
     */
    private function terminate(): void
    {
        if ($this->kernel) {
            $this->kernel->terminate();
        }
        $this->running = false;
    }

    /**
     * Get container instance
     */
    public function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * Check if application is in debug mode
     */
    public function isDebug(): bool
    {
        return $this->debugMode;
    }

    /**
     * Get application configuration
     */
    public function getConfig(?string $key = null): mixed
    {
        if ($key === null) {
            return $this->config;
        }

        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Custom error handler
     */
    private function createErrorHandler(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        $errorType = match ($severity) {
            E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR => 'ERROR',
            E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING => 'WARNING',
            E_NOTICE, E_USER_NOTICE => 'NOTICE',
            default => 'UNKNOWN'
        };

        error_log("[{$errorType}] {$message} in {$file}:{$line}");

        if ($this->debugMode) {
            echo "<br><b>{$errorType}:</b> {$message} in <b>{$file}</b> on line <b>{$line}</b><br>";
        }

        return true;
    }

    /**
     * Custom exception handler
     */
    private function createExceptionHandler(Throwable $exception): void
    {
        error_log("Uncaught exception: " . $exception->getMessage() . " in " . $exception->getFile() . ":" . $exception->getLine());

        if ($this->debugMode) {
            echo "<h1>Uncaught Exception</h1>";
            echo "<p><strong>Message:</strong> " . htmlspecialchars($exception->getMessage()) . "</p>";
            echo "<p><strong>File:</strong> " . htmlspecialchars($exception->getFile()) . ":" . $exception->getLine() . "</p>";
            echo "<pre>" . htmlspecialchars($exception->getTraceAsString()) . "</pre>";
        } else {
            http_response_code(500);
            echo "Internal Server Error";
        }
    }
}