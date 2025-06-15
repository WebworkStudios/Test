<?php

declare(strict_types=1);

namespace Framework\Container;

use Exception;
use Framework\Container\Psr\ContainerExceptionInterface;
use Throwable;

/**
 * Framework Container Exception mit PHP 8.4 Features
 *
 * Vereinfachte Exception-Klasse mit besserer Fehlerdiagnose
 * und optimierter Performance.
 */
class ContainerException extends Exception implements ContainerExceptionInterface
{
    // Property Hooks für computed properties
    public bool $hasContext {
        get => !empty($this->context);
    }

    public bool $hasServiceId {
        get => $this->serviceId !== null;
    }

    public string $shortMessage {
        get => $this->extractShortMessage();
    }

    public array $debugInfo {
        get => [
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'service_id' => $this->serviceId,
            'context_keys' => array_keys($this->context),
            'has_previous' => $this->getPrevious() !== null
        ];
    }

    private array $context = [];
    private ?string $serviceId = null;

    public function __construct(
        string     $message = '',
        int        $code = 0,
        ?Throwable $previous = null,
        array      $context = [],
        ?string    $serviceId = null
    )
    {
        parent::__construct($message, $code, $previous);
        $this->context = $this->sanitizeContext($context);
        $this->serviceId = $serviceId;
    }

    /**
     * Sanitize context data für sichere Ausgabe mit PHP 8.4 match
     */
    private function sanitizeContext(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            if (!is_string($key) || str_contains($key, '..') || strlen($key) > 100) {
                continue;
            }

            $sanitized[$key] = match (true) {
                is_scalar($value) => $value,
                is_array($value) => $this->sanitizeArray($value),
                is_object($value) => $value::class,
                default => gettype($value)
            };
        }

        return $sanitized;
    }

    /**
     * Sanitize array values recursively (with depth limit)
     */
    private function sanitizeArray(array $array, int $depth = 0): array
    {
        if ($depth > 3) { // Prevent deep recursion
            return ['...' => 'max_depth_reached'];
        }

        $sanitized = [];
        $count = 0;

        foreach ($array as $key => $value) {
            if ($count >= 10) { // Limit array size in context
                $sanitized['...'] = 'truncated';
                break;
            }

            if (is_string($key) && strlen($key) <= 50) {
                $sanitized[$key] = match (true) {
                    is_scalar($value) => $value,
                    is_array($value) => $this->sanitizeArray($value, $depth + 1),
                    is_object($value) => $value::class,
                    default => gettype($value)
                };
            }

            $count++;
        }

        return $sanitized;
    }

    /**
     * Create exception for resolution failures
     */
    public static function cannotResolve(string $service, string $reason = '', array $context = []): self
    {
        $safeService = self::sanitizeServiceName($service);

        $message = "Cannot resolve service '{$safeService}'";
        if ($reason !== '') {
            $message .= ": {$reason}";
        }

        return new self($message, 1001, null, $context, $safeService);
    }

    /**
     * Sanitize service name for safe output
     */
    protected static function sanitizeServiceName(string $service): string
    {
        $sanitized = preg_replace('/[^\w\\\\.]/s', '', $service);
        return strlen($sanitized) > 100 ? substr($sanitized, 0, 97) . '...' : $sanitized;
    }

    /**
     * Create exception for invalid service definitions
     */
    public static function invalidService(string $service, string $reason, array $context = []): self
    {
        $safeService = self::sanitizeServiceName($service);

        return new self(
            "Invalid service definition for '{$safeService}': {$reason}",
            1002,
            null,
            $context,
            $safeService
        );
    }

    /**
     * Create exception for circular dependencies
     */
    public static function circularDependency(array $chain, array $context = []): self
    {
        $safeChain = array_map(
            fn($item) => self::sanitizeServiceName((string)$item),
            array_slice($chain, 0, 20) // Limit chain length
        );

        $chainStr = implode(' -> ', $safeChain);
        if (count($chain) > 20) {
            $chainStr .= ' -> ...';
        }

        return new self(
            "Circular dependency detected: {$chainStr}",
            1003,
            null,
            array_merge($context, ['chain' => $safeChain])
        );
    }

    /**
     * Create exception for security violations
     */
    public static function securityViolation(string $service, string $reason, array $context = []): self
    {
        $safeService = self::sanitizeServiceName($service);

        return new self(
            "Security violation for service '{$safeService}': {$reason}",
            1004,
            null,
            $context,
            $safeService
        );
    }

    /**
     * Create exception for configuration errors
     */
    public static function configurationError(string $key, string $reason, array $context = []): self
    {
        $safeKey = self::sanitizeConfigKey($key);

        return new self(
            "Configuration error for key '{$safeKey}': {$reason}",
            1005,
            null,
            array_merge($context, ['config_key' => $safeKey])
        );
    }

    /**
     * Sanitize config key for safe output
     */
    private static function sanitizeConfigKey(string $key): string
    {
        $sanitized = preg_replace('/[^\w.]/', '', $key);
        return strlen($sanitized) > 100 ? substr($sanitized, 0, 97) . '...' : $sanitized;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function getServiceId(): ?string
    {
        return $this->serviceId;
    }

    /**
     * Check if exception is caused by missing dependency
     */
    public function isMissingDependency(): bool
    {
        return $this->getCode() === 1001 ||
            str_contains($this->getMessage(), 'not found') ||
            str_contains($this->getMessage(), 'Cannot resolve');
    }

    /**
     * Check if exception is security-related
     */
    public function isSecurityViolation(): bool
    {
        return $this->getCode() === 1004 ||
            str_contains($this->getMessage(), 'Security violation');
    }

    /**
     * Check if exception is configuration-related
     */
    public function isConfigurationError(): bool
    {
        return $this->getCode() === 1005 ||
            str_contains($this->getMessage(), 'Configuration error');
    }

    /**
     * Get exception category for logging/monitoring
     */
    public function getCategory(): string
    {
        return match ($this->getCode()) {
            1001 => 'resolution_failure',
            1002 => 'invalid_service',
            1003 => 'circular_dependency',
            1004 => 'security_violation',
            1005 => 'configuration_error',
            default => 'unknown_error'
        };
    }

    /**
     * Get user-friendly error message
     */
    public function getUserMessage(): string
    {
        return match ($this->getCode()) {
            1001 => "Service dependency could not be resolved. Please check your service configuration.",
            1002 => "Invalid service configuration detected. Please review your service definitions.",
            1003 => "Circular dependency detected in your services. Please restructure your dependencies.",
            1004 => "Security violation detected. Please check your service implementations.",
            1005 => "Configuration error detected. Please verify your application configuration.",
            default => "An unexpected container error occurred."
        };
    }

    /**
     * Create exception with more context
     */
    public function withContext(array $additionalContext): self
    {
        return new self(
            $this->getMessage(),
            $this->getCode(),
            $this->getPrevious(),
            array_merge($this->context, $additionalContext),
            $this->serviceId
        );
    }

    /**
     * Create exception with different service ID
     */
    public function withServiceId(string $serviceId): self
    {
        return new self(
            $this->getMessage(),
            $this->getCode(),
            $this->getPrevious(),
            $this->context,
            $serviceId
        );
    }

    /**
     * Magic method for debugging
     */
    public function __debugInfo(): array
    {
        return $this->debugInfo;
    }

    public function __toString(): string
    {
        $result = parent::__toString();

        if ($this->hasContext) {
            $result .= "\nContext: " . json_encode($this->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        if ($this->hasServiceId) {
            $result .= "\nService ID: {$this->serviceId}";
        }

        return $result;
    }

    /**
     * Extract short message for logging
     */
    private function extractShortMessage(): string
    {
        $message = $this->getMessage();
        $firstLine = strtok($message, "\n");
        return strlen($firstLine) > 100 ? substr($firstLine, 0, 97) . '...' : $firstLine;
    }
}
