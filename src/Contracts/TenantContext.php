<?php

declare(strict_types=1);

namespace Obelaw\Ium\Contracts;

/**
 * Environment contract abstracting multi-tenancy and tenant database connection resolution.
 *
 * Domain modules depend solely on this contract and never reference `obelaw/ium-tenancy`.
 */
interface TenantContext
{
    /**
     * Whether the host application is operating in multi-tenant mode.
     */
    public function isMultiTenant(): bool;

    /**
     * Return the currently active tenant identity, or null when in central/single-tenant scope.
     */
    public function current(): ?TenantIdentity;

    /**
     * Return the database connection name for the active tenant context, or null for default.
     */
    public function connectionName(): ?string;

    /**
     * Enumerate all provisioned tenants (used by core CLI commands like `ium:tenants:migrate`).
     *
     * @return iterable<TenantIdentity>
     */
    public function all(): iterable;

    /**
     * Find a tenant by its ID or key.
     */
    public function find(string $identifier): ?TenantIdentity;

    /**
     * Execute a callback within the specified tenant context, restoring the prior context afterwards.
     *
     * @template TResult
     *
     * @param callable(TenantIdentity): TResult $callback
     *
     * @return TResult
     */
    public function runForTenant(TenantIdentity $tenant, callable $callback): mixed;

    /**
     * Return the enabled module slugs for a tenant, or null when all tenant modules are enabled.
     *
     * @return list<string>|null
     */
    public function enabledModulesFor(TenantIdentity $tenant): ?array;
}
