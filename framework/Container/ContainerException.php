<?php

declare(strict_types=1);

namespace Framework\Container;

/**
 * Framework Container Exception mit PHP 8.4 Features
 *
 * Keine PSR-11 Abhängigkeit, optimiert für unser Framework.
 * Fokus auf Performance und bessere Fehlerdiagnose.
 */
class ContainerException extends \Exception
{
    private array $context = [];
    private ?string $serviceId = null;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        array $context = [],
        ?string $serviceId = null
    ) {
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
            if (!is_string($key) || str_contains($key, '..')) {
                continue;
            }

            $sanitized[$key] = match (true) {
                is_scalar($value) => $value,
                is_array($value) => $this->sanitizeContext($value),
                is_object($value) => $value::class,
                default => gettype($value)
            };
        }

        return $sanitized;
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
     * Create exception for resolution failures
     */
    public static function cannotResolve(string $service, string $reason = '', array $context = []): self
    {
        $safeService = preg_replace('/[^\w\\\\\.]/', '', $service);

        $message = "Cannot resolve service '{$safeService}'";
        if ($reason !== '') {
            $message .= ": {$reason}";
        }

        return new self($message, 1001, null, $context, $safeService);
    }

    /**
     * Create exception for invalid service definitions
     */
    public static function invalidService(string $service, string $reason, array $context = []): self
    {
        $safeService = preg_replace('/[^\w\\\\\.]/', '', $service);

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
            fn($item) => preg_replace('/[^\w\\\\\.]/', '', (string)$item),
            $chain
        );

        $chainStr = implode(' -> ', $safeChain);

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
        $safeService = preg_replace('/[^\w\\\\\.]/', '', $service);

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
        $safeKey = preg_replace('/[^\w\.]/', '', $key);

        return new self(
            "Configuration error for key '{$safeKey}': {$reason}",
            1005,
            null,
            array_merge($context, ['config_key' => $safeKey])
        );
    }

    /**
     * Für strukturiertes Logging
     */
    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'service_id' => $this->serviceId,
            'context' => $this->context,
            'trace' => $this->getTraceAsString()
        ];
    }

    public function __toString(): string
    {
        $result = parent::__toString();

        if (!empty($this->context)) {
            $result .= "\nContext: " . json_encode($this->context, JSON_PRETTY_PRINT);
        }

        if ($this->serviceId !== null) {
            $result .= "\nService ID: {$this->serviceId}";
        }

        return $result;
    }
}

/**
 * Exception thrown when requested service is not found
 *
 * Erweiterte NotFound-Exception mit Service-Vorschlägen
 */
class ContainerNotFoundException extends ContainerException
{
    private array $suggestions = [];

    /**
     * Create exception for missing services mit Vorschlägen
     */
    public static function serviceNotFound(string $service, array $availableServices = []): self
    {
        $safeService = preg_replace('/[^\w\\\\\.]/', '', $service);

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
     * Create exception for missing tagged services
     */
    public static function tagNotFound(string $tag, array $availableTags = []): self
    {
        $safeTag = preg_replace('/[^\w\.]/', '', $tag);

        $exception = new self(
            "No services found with tag '{$safeTag}'",
            2002,
            null,
            ['available_tags' => array_slice($availableTags, 0, 10)]
        );

        $exception->suggestions = $exception->findSimilarServices($safeTag, $availableTags);

        return $exception;
    }

    /**
     * Findet ähnliche Services basierend auf String-Ähnlichkeit mit PHP 8.4 match
     */
    private function findSimilarServices(string $needle, array $haystack): array
    {
        if (empty($haystack) || strlen($needle) < 3) {
            return [];
        }

        $suggestions = [];
        $needle = strtolower($needle);

        foreach ($haystack as $service) {
            if (!is_string($service)) {
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
        return 1 - ($distance / $maxLen);
    }

    private function similarTextSimilarity(string $needle, string $service): float
    {
        $similarity = 0;
        similar_text($needle, $service, $similarity);
        return $similarity / 100;
    }

    public function getSuggestions(): array
    {
        return $this->suggestions;
    }

    /**
     * Erweiterte Fehlermeldung mit Vorschlägen
     */
    public function getMessageWithSuggestions(): string
    {
        $message = $this->getMessage();

        if (!empty($this->suggestions)) {
            $message .= "\n\nDid you mean one of these?";
            foreach ($this->suggestions as $suggestion) {
                $message .= "\n  - {$suggestion}";
            }
        }

        return $message;
    }

    public function __toString(): string
    {
        $result = $this->getMessageWithSuggestions();
        $result .= "\n\nStack trace:\n" . $this->getTraceAsString();

        if (!empty($this->getContext())) {
            $result .= "\n\nContext: " . json_encode($this->getContext(), JSON_PRETTY_PRINT);
        }

        return $result;
    }
}