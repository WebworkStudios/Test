<?php


declare(strict_types=1);

namespace Framework\Routing;

/**
 * Performance metrics tracking for Router with PHP 8.4 optimizations
 */
final class PerformanceMetrics
{
    // PHP 8.4 Property Hooks for better API
    public int $routeRegistrations {
        get => $this->routeRegistrations;
        set => $this->routeRegistrations = $value;
    }

    public int $successfulDispatches {
        get => $this->successfulDispatches;
        set => $this->successfulDispatches = $value;
    }

    public int $failedDispatches {
        get => $this->failedDispatches;
        set => $this->failedDispatches = $value;
    }

    public float $averageDispatchTime {
        get => $this->totalDispatches > 0
            ? $this->totalDispatchTime / $this->totalDispatches
            : 0.0;
    }

    public float $successRate {
        get => $this->totalDispatches > 0
            ? ($this->successfulDispatches / $this->totalDispatches) * 100
            : 0.0;
    }
    private float $totalDispatchTime = 0.0;
    private int $totalDispatches = 0;

    private array $recentTimes = [];
    private int $maxRecentTimes = 100;

    public function incrementRouteRegistrations(): void
    {
        $this->routeRegistrations++;
    }

    public function recordSuccessfulDispatch(float $timeMs): void
    {
        $this->successfulDispatches++;
        $this->totalDispatches++;
        $this->totalDispatchTime += $timeMs;
        $this->addRecentTime($timeMs);
    }

    public function recordFailedDispatch(float $timeMs): void
    {
        $this->failedDispatches++;
        $this->totalDispatches++;
        $this->totalDispatchTime += $timeMs;
        $this->addRecentTime($timeMs);
    }

    private function addRecentTime(float $timeMs): void
    {
        if (count($this->recentTimes) >= $this->maxRecentTimes) {
            array_shift($this->recentTimes);
        }
        $this->recentTimes[] = $timeMs;
    }

    public function getRecentAverageTime(): float
    {
        if (empty($this->recentTimes)) {
            return 0.0;
        }

        return array_sum($this->recentTimes) / count($this->recentTimes);
    }

    public function getStats(): array
    {
        return [
            'route_registrations' => $this->routeRegistrations,
            'successful_dispatches' => $this->successfulDispatches,
            'failed_dispatches' => $this->failedDispatches,
            'total_dispatches' => $this->totalDispatches,
            'average_dispatch_time_ms' => $this->averageDispatchTime,
            'recent_average_time_ms' => $this->getRecentAverageTime(),
            'success_rate_percent' => $this->successRate,
        ];
    }

    public function reset(): void
    {
        $this->routeRegistrations = 0;
        $this->successfulDispatches = 0;
        $this->failedDispatches = 0;
        $this->totalDispatchTime = 0.0;
        $this->totalDispatches = 0;
        $this->recentTimes = [];
    }
}