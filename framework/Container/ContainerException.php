<?php

declare(strict_types=1);

namespace Framework\Container;

use Framework\Container\Psr\ContainerExceptionInterface;
use Framework\Container\Psr\NotFoundExceptionInterface;

/**
 * Framework Container Exception mit PHP 8.4 Features
 *
 * Vereinfachte Exception-Klasse mit besserer Fehlerdiagnose
 * und optimierter Performance.
 */
class ContainerException extends \Exception implements ContainerExceptionInterface
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
        string      $message = '',
        int         $code = 0,
        ?\Throwable $previous = null,
        array       $context = [],
        ?string     $serviceId = null
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

/**
 * Exception thrown when requested service is not found
 *
 * Vereinfachte NotFound-Exception mit Service-Vorschlägen
 */
class ContainerNotFoundException extends ContainerException implements NotFoundExceptionInterface
{
    // Property Hooks für computed properties
    public bool $hasSuggestions {
        get => !empty($this->suggestions);
    }

    public int $suggestionCount {
        get => count($this->suggestions);
    }

    private array $suggestions = [];

    /**
     * Create exception for missing services mit Vorschlägen
     */
    public static function serviceNotFound(string $service, array $availableServices = []): self
    {
        $safeService = self::sanitizeServiceName($service);

        $exception = new self(
            "Service '{$safeService}' not found in container",
            2001,
            null,
            ['available_count' => count($availableServices)],
            $safeService
        );

        $exception->suggestions = $exception->findSimilarServices($safeService, $availableServices);

        return $exception;
    }

    /**
     * Findet ähnliche Services basierend auf String-Ähnlichkeit
     */
    private function findSimilarServices(string $needle, array $haystack): array
    {
        if (empty($haystack) || strlen($needle) < 3) {
            return [];
        }

        $suggestions = [];
        $needle = strtolower($needle);

        foreach (array_slice($haystack, 0, 100) as $service) { // Limit processing
            if (!is_string($service) || strlen($service) > 255) {
                continue;
            }

            $service = strtolower($service);
            $similarity = $this->calculateSimilarity($needle, $service);

            if ($similarity > 0.4) {
                $suggestions[] = [
                    'service' => $service,
                    'similarity' => $similarity
                ];
            }
        }

        usort($suggestions, fn($a, $b) => $b['similarity'] <=> $a['similarity']);

        return array_slice(
            array_column($suggestions, 'service'),
            0,
            5
        );
    }

    /**
     * Berechnet String-Ähnlichkeit mit verschiedenen Algorithmen
     */
    private function calculateSimilarity(string $needle, string $service): float
    {
        return match (true) {
            strlen($service) < 50 && strlen($needle) < 50 => $this->levenshteinSimilarity($needle, $service),
            str_contains($service, $needle) => 0.6,
            default => $this->similarTextSimilarity($needle, $service)
        };
    }

    private function levenshteinSimilarity(string $needle, string $service): float
    {
        $distance = levenshtein($needle, $service);
        $maxLen = max(strlen($needle), strlen($service));
        return $maxLen > 0 ? 1 - ($distance / $maxLen) : 0;
    }

    private function similarTextSimilarity(string $needle, string $service): float
    {
        $similarity = 0;
        similar_text($needle, $service, $similarity);
        return $similarity / 100;
    }

    /**
     * Create exception for missing tagged services
     */
    public static function tagNotFound(string $tag, array $availableTags = []): self
    {
        $safeTag = preg_replace('/[^\w.]/', '', $tag);

        $exception = new self(
            "No services found with tag '{$safeTag}'",
            2002,
            null,
            ['available_tags' => array_slice($availableTags, 0, 10)]
        );

        $exception->suggestions = $exception->findSimilarServices($safeTag, $availableTags);

        return $exception;
    }

    public function getSuggestions(): array
    {
        return $this->suggestions;
    }

    public function __toString(): string
    {
        $result = $this->getMessageWithSuggestions();
        $result .= "\n\nStack trace:\n" . $this->getTraceAsString();

        if ($this->hasContext) {
            $result .= "\n\nContext: " . json_encode($this->getContext(), JSON_PRETTY_PRINT);
        }

        return $result;
    }

    /**
     * Erweiterte Fehlermeldung mit Vorschlägen
     */
    public function getMessageWithSuggestions(): string
    {
        $message = $this->getMessage();

        if ($this->hasSuggestions) {
            $message .= "\n\nDid you mean one of these?";
            foreach ($this->suggestions as $suggestion) {
                $message .= "\n  - {$suggestion}";
            }
        }

        return $message;
    }
}