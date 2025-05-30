<?php


declare(strict_types=1);

namespace Framework\Routing\Exceptions;

/**
 * Exception thrown when route compilation fails
 */
final class RouteCompilationException extends \RuntimeException
{
    public function __construct(
        string      $route,
        string      $reason,
        ?\Throwable $previous = null
    )
    {
        $message = "Route compilation failed for '{$route}': {$reason}";
        parent::__construct($message, 0, $previous);
    }
}