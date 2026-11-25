<?php

declare(strict_types=1);

namespace Obelaw\Ium\Defaults;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Obelaw\Ium\Contracts\SettingsRepository;
use Obelaw\Ium\Contracts\TenantContext;

/**
 * Zero-configuration default SettingsRepository backed by tenant-partitioned
 * in-memory state with fallback to Laravel's `config()` repository.
 */
final class NullSettingsRepository implements SettingsRepository
{
    /**
     * Partitioned in-memory overrides keyed by scope (`__central__` or tenant ID).
     *
     * @var array<string, array<string, mixed>>
     */
    private array $store = [];

    public function __construct(
        private readonly ?TenantContext $tenantContext = null,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $scope = $this->scopeKey();

        if (isset($this->store[$scope]) && array_key_exists($key, $this->store[$scope])) {
            return $this->store[$scope][$key];
        }

        $container = Container::getInstance();

        if ($container->bound('config')) {
            /** @var ConfigRepository $config */
            $config = $container->make('config');

            return $config->get($key, $default);
        }

        return $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->store[$this->scopeKey()][$key] = $value;
    }

    public function has(string $key): bool
    {
        $scope = $this->scopeKey();

        if (isset($this->store[$scope]) && array_key_exists($key, $this->store[$scope])) {
            return true;
        }

        $container = Container::getInstance();

        if ($container->bound('config')) {
            /** @var ConfigRepository $config */
            $config = $container->make('config');

            return $config->has($key);
        }

        return false;
    }

    public function forget(string $key): void
    {
        unset($this->store[$this->scopeKey()][$key]);
    }

    public function reset(): void
    {
        $this->store = [];
    }

    private function scopeKey(): string
    {
        $context = $this->tenantContext;

        if ($context === null) {
            $container = Container::getInstance();
            if ($container->bound(TenantContext::class)) {
                $context = $container->make(TenantContext::class);
            }
        }

        $tenant = $context?->current();

        return $tenant !== null ? 'tenant:' . $tenant->id : '__central__';
    }
}
