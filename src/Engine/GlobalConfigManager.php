<?php

declare(strict_types=1);

namespace Obelaw\Ium\Engine;

use Illuminate\Container\Container;

/**
 * Container-scoped global configuration store for static contexts (e.g. model booting).
 *
 * Bound to the active Laravel Container so that Laravel Octane request lifecycles
 * and isolated test cases never leak configuration across boundaries.
 */
class GlobalConfigManager
{
    private const CONTAINER_KEY = 'obelawium.global_config_store';

    /**
     * Fallback storage used only when no container is initialized.
     *
     * @var array<string, mixed>
     */
    protected static array $fallbackConfigs = [];

    /**
     * Set a global configuration value.
     */
    public static function set(string $key, mixed $value): void
    {
        $store = self::resolveStore();

        if ($store !== null) {
            $store->items[$key] = $value;

            return;
        }

        static::$fallbackConfigs[$key] = $value;
    }

    /**
     * Get a global configuration value.
     *
     * @param mixed $default Fallback when the key is not set.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $store = self::resolveStore();

        if ($store !== null) {
            return $store->items[$key] ?? $default;
        }

        return static::$fallbackConfigs[$key] ?? $default;
    }

    /**
     * Check whether a key exists in the global store.
     */
    public static function has(string $key): bool
    {
        $store = self::resolveStore();

        if ($store !== null) {
            return array_key_exists($key, $store->items);
        }

        return array_key_exists($key, static::$fallbackConfigs);
    }

    /**
     * Remove a key from the global store.
     */
    public static function forget(string $key): void
    {
        $store = self::resolveStore();

        if ($store !== null) {
            unset($store->items[$key]);

            return;
        }

        unset(static::$fallbackConfigs[$key]);
    }

    /**
     * Return all stored global configuration values.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $store = self::resolveStore();

        return $store !== null ? $store->items : static::$fallbackConfigs;
    }

    /**
     * Clear all stored global configuration values.
     */
    public static function reset(): void
    {
        $store = self::resolveStore();

        if ($store !== null) {
            $store->items = [];
        }

        static::$fallbackConfigs = [];
    }

    /**
     * Resolve the container-scoped storage object when a container is active.
     */
    private static function resolveStore(): ?\stdClass
    {
        $container = Container::getInstance();

        if (! $container->bound(self::CONTAINER_KEY)) {
            $store = new \stdClass();
            $store->items = [];
            $container->instance(self::CONTAINER_KEY, $store);
        }

        /** @var \stdClass $resolved */
        $resolved = $container->make(self::CONTAINER_KEY);

        return $resolved;
    }
}
