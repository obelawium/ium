<?php

declare(strict_types=1);

namespace Obelaw\Ium\Defaults;

use Obelaw\Ium\Contracts\TenantContext;
use Obelaw\Ium\Contracts\TenantIdentity;

/**
 * Zero-configuration default TenantContext for single-tenant applications.
 */
final class NullTenantContext implements TenantContext
{
    private ?TenantIdentity $active = null;

    public function isMultiTenant(): bool
    {
        return false;
    }

    public function current(): ?TenantIdentity
    {
        return $this->active;
    }

    public function connectionName(): ?string
    {
        return $this->active?->connection;
    }

    public function all(): iterable
    {
        return [];
    }

    public function find(string $identifier): ?TenantIdentity
    {
        return null;
    }

    public function runForTenant(TenantIdentity $tenant, callable $callback): mixed
    {
        $previous = $this->active;
        $this->active = $tenant;

        try {
            return $callback($tenant);
        } finally {
            $this->active = $previous;
        }
    }

    public function enabledModulesFor(TenantIdentity $tenant): ?array
    {
        return $tenant->enabledModules;
    }
}
