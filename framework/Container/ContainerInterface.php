<?php

declare(strict_types=1);

namespace Framework\Container;

use Framework\Container\Psr\ContainerInterface as PsrContainerInterface;

/**
 * Extended container interface for dependency injection configuration
 *
 * Extends PSR-11 compatible interface with write methods for container setup.
 */
interface ContainerInterface extends PsrContainerInterface
{
    /**
     * Bind service to container (fluent interface)
     */
    public function bind(string $id, mixed $concrete = null, bool $singleton = false): static;

    /**
     * Bind singleton service to container (fluent interface)
     */
    public function singleton(string $id, mixed $concrete = null): static;

    /**
     * Register existing instance (fluent interface)
     */
    public function instance(string $id, object $instance): static;

    /**
     * Check if service is registered
     */
    public function isRegistered(string $id): bool;

    /**
     * Tag service for discovery (fluent interface)
     */
    public function tag(string $id, string $tag): static;

    /**
     * Register lazy service (fluent interface)
     */
    public function lazy(string $id, callable $factory, bool $singleton = true): static;
}