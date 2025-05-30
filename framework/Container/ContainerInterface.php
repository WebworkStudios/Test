<?php


declare(strict_types=1);

namespace Framework\Container;

/**
 * Container interface for dependency injection
 */
interface ContainerInterface
{
    /**
     * Get service from container
     */
    public function get(string $id): mixed;

    /**
     * Check if container has service
     */
    public function has(string $id): bool;

    /**
     * Bind service to container
     */
    public function bind(string $id, mixed $concrete): void;

    /**
     * Bind singleton service to container
     */
    public function singleton(string $id, mixed $concrete): void;
}