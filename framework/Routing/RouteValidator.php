<?php


declare(strict_types=1);

namespace Framework\Routing;

final class RouteValidator
{
    public static function validateMethod(string $method): void
    {
        $allowed = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS'];

        if (!in_array(strtoupper($method), $allowed, true)) {
            throw new \InvalidArgumentException("Invalid HTTP method: {$method}");
        }
    }

    public static function validatePath(string $path): void
    {
        if (!str_starts_with($path, '/')) {
            throw new \InvalidArgumentException('Path must start with /');
        }

        if (strlen($path) > 2048) {
            throw new \InvalidArgumentException('Path too long');
        }
    }

    public static function validateActionClass(string $actionClass): void
    {
        if (!class_exists($actionClass)) {
            throw new \InvalidArgumentException("Action class {$actionClass} does not exist");
        }

        $reflection = new \ReflectionClass($actionClass);
        if (!$reflection->hasMethod('__invoke')) {
            throw new \InvalidArgumentException("Action class {$actionClass} must be invokable");
        }
    }
}