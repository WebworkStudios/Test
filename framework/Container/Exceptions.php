<?php

declare(strict_types=1);

namespace Framework\Container;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Base container exception implementing PSR-11 interface
 * 
 * Thrown when container operations fail due to configuration
 * issues, resolution problems, or other internal errors.
 */
class ContainerException extends \Exception implements ContainerExceptionInterface
{
    /**
     * Create exception for resolution failures
     */
    public static function cannotResolve(string $service, string $reason = ''): self
    {
        $message = "Cannot resolve service '{$service}'";
        if ($reason !== '') {
            $message .= ": {$reason}";
        }
        
        return new self($message);
    }

    /**
     * Create exception for invalid service definitions
     */
    public static function invalidService(string $service, string $reason): self
    {
        return new self("Invalid service definition for '{$service}': {$reason}");
    }

    /**
     * Create exception for circular dependencies
     */
    public static function circularDependency(array $chain): self
    {
        $chainStr = implode(' -> ', $chain);
        return new self("Circular dependency detected: {$chainStr}");
    }
}

/**
 * Exception thrown when requested service is not found
 * 
 * Implements PSR-11 NotFoundExceptionInterface for standard
 * container compatibility.
 */
class ContainerNotFoundException extends ContainerException implements NotFoundExceptionInterface
{
    /**
     * Create exception for missing services
     */
    public static function serviceNotFound(string $service): self
    {
        return new self("Service '{$service}' not found in container");
    }

    /**
     * Create exception for missing tagged services
     */
    public static function tagNotFound(string $tag): self
    {
        return new self("No services found with tag '{$tag}'");
    }
}