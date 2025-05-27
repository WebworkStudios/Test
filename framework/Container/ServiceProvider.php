<?php

declare(strict_types=1);

namespace Framework\Container;

/**
 * Abstract Service Provider for organizing service registrations
 * 
 * Service providers offer a clean way to group related service
 * registrations and bootstrap logic, complementing attribute-based
 * auto-discovery for complex setup scenarios.
 * 
 * @example
 * class DatabaseServiceProvider extends ServiceProvider
 * {
 *     public function register(): void
 *     {
 *         $this->container->singleton('db.connection', fn() => new PDO(...));
 *     }
 * 
 *     public function boot(): void
 *     {
 *         // Run migrations, seed data, etc.
 *     }
 * }
 */
abstract readonly class ServiceProvider
{
    public function __construct(
        protected Container $container
    ) {}

    /**
     * Register services in the container
     * 
     * This method is called during the registration phase,
     * before all services are available for resolution.
     */
    abstract public function register(): void;

    /**
     * Boot services after all providers are registered
     * 
     * This method is called after all service providers
     * have been registered, making all services available.
     */
    public function boot(): void
    {
        // Default implementation - override in subclasses if needed
    }

    /**
     * Determine if provider should be deferred
     * 
     * Deferred providers are only registered when their
     * services are actually requested.
     */
    public function isDeferred(): bool
    {
        return false;
    }

    /**
     * Get services provided by this provider
     * 
     * Used for deferred loading to determine which
     * services trigger provider registration.
     * 
     * @return array<string>
     */
    public function provides(): array
    {
        return [];
    }
}