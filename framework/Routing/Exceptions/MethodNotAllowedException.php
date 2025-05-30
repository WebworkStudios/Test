<?php


declare(strict_types=1);

namespace Framework\Routing\Exceptions;

/**
 * Exception thrown when HTTP method is not allowed for a route
 */
final class MethodNotAllowedException extends \RuntimeException
{
    public function __construct(
        string                 $message = 'Method not allowed',
        private readonly array $allowedMethods = [],
        int                    $code = 405,
        ?\Throwable            $previous = null
    )
    {
        parent::__construct($message, $code, $previous);
    }

    public function getAllowedMethods(): array
    {
        return $this->allowedMethods;
    }
}