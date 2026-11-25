<?php

declare(strict_types=1);

namespace Obelaw\Ium\Testing;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Cache;
use Obelaw\Ium\Contracts\TenantContext;
use Obelaw\Ium\Contracts\TenantIdentity;

/**
 * In-memory multi-tenant context used by `ModuleTestCase` to verify strict
 * data, sequence, settings, and cache isolation across two independent tenants.
 */
final class InMemoryTenantContext implements TenantContext
{
    private ?TenantIdentity $active = null;

    /**
     * @param list<TenantIdentity> $tenants
     */
    public function __construct(
        private readonly array $tenants,
    ) {}

    public function isMultiTenant(): bool
    {
        return true;
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
        return $this->tenants;
    }

    public function find(string $identifier): ?TenantIdentity
    {
        foreach ($this->tenants as $tenant) {
            if ($tenant->id === $identifier || $tenant->key === $identifier) {
                return $tenant;
            }
        }

        return null;
    }

    public function runForTenant(TenantIdentity $tenant, callable $callback): mixed
    {
        $previous = $this->active;
        $storeName = 'ium_tenant_cache_' . $tenant->id;
        $previousStore = $this->swapCacheStore($storeName);
        $this->active = $tenant;

        try {
            return $callback($tenant);
        } finally {
            $this->active = $previous;
            $this->swapCacheStore(
                $previous !== null
                    ? 'ium_tenant_cache_' . $previous->id
                    : $previousStore
            );
        }
    }

    public function enabledModulesFor(TenantIdentity $tenant): ?array
    {
        return $tenant->enabledModules;
    }

    private function swapCacheStore(string $storeName): string
    {
        $container = Container::getInstance();

        if (! $container->bound('config')) {
            return 'array';
        }

        $config = $container->make('config');
        $previous = (string) $config->get('cache.default', 'array');

        if (! $config->has("cache.stores.{$storeName}")) {
            $config->set("cache.stores.{$storeName}", [
                'driver' => 'array',
                'serialize' => false,
            ]);
        }

        $config->set('cache.default', $storeName);

        if ($container->bound('cache')) {
            Cache::setDefaultDriver($storeName);
        }

        return $previous;
    }
}
