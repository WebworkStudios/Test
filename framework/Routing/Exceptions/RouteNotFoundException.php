<?php


declare(strict_types=1);

namespace Framework\Routing\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Exception thrown when a route cannot be found
 */
final class RouteNotFoundException extends RuntimeException
{
    public function __construct(
        string     $message = 'Route not found',
        int        $code = 404,
        ?Throwable $previous = null
    )
    {
        parent::__construct($message, $code, $previous);
    }
}