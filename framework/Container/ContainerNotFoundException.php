<?php


declare(strict_types=1);

namespace Framework\Container;

use Framework\Container\Psr\NotFoundExceptionInterface;

/**
 * Exception thrown when requested service is not found
 */
final class ContainerNotFoundException extends ContainerException implements NotFoundExceptionInterface
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