<?php

declare(strict_types=1);

namespace Obelaw\Ium\Engine;

use Illuminate\Container\Container;
use Obelaw\Ium\Contracts\IumConfigEnum;

/**
 * Container-managed configuration store for the Obelawium engine.
 *
 * Keys may be plain strings or any backed enum implementing IumConfigEnum.
 * Values flagged as global are mirrored into GlobalConfigManager so they can
 * be read from static contexts without leaking across Octane requests or tests.
 */
final class ObelawConfigManager
{
    /**
     * Stored configuration values.
     *
     * @var array<string, mixed>
     */
    private array $configs = [];

    public function __construct() {}

    /**
     * Resolve the active container-bound configuration manager.
     *
     * Retained for backwards compatibility; delegates to the Laravel Container
     * rather than holding a process-global static singleton.
     */
    public static function getInstance(): self
    {
        $container = Container::getInstance();

        if (! $container->bound(self::class)) {
            $container->singleton(self::class, static fn (): self => new self());
        }

        return $container->make(self::class);
    }

    /**
     * Get a configuration value.
     *
     * @param IumConfigEnum|string $config Config key or backed enum.
     * @param mixed $default Fallback when the key is not set.
     */
    public function get(IumConfigEnum|string $config, mixed $default = null): mixed
    {
        $key = $config instanceof IumConfigEnum ? $config->value : $config;

        return $this->configs[$key] ?? $default;
    }

    /**
     * Set a configuration value.
     *
     * @param IumConfigEnum|string $config Config key or backed enum.
     * @param mixed $value Value to store.
     * @param bool|null $global When true, also store in GlobalConfigManager.
     */
    public function set(IumConfigEnum|string $config, mixed $value, ?bool $global = null): self
    {
        $key = $config instanceof IumConfigEnum ? $config->value : $config;

        $this->configs[$key] = $value;

        if ($global) {
            GlobalConfigManager::set($key, $value);
        }

        return $this;
    }

    /**
     * Set a configuration value only when the key is not already set.
     *
     * @param IumConfigEnum|string $config Config key or backed enum.
     * @param mixed $value Default value to store.
     */
    public function defaults(IumConfigEnum|string $config, mixed $value): self
    {
        $key = $config instanceof IumConfigEnum ? $config->value : $config;

        if (! array_key_exists($key, $this->configs)) {
            $this->configs[$key] = $value;
        }

        return $this;
    }

    /**
     * Merge a set of configuration values into the store.
     *
     * @param array<mixed> $configs Keyed configuration values; nested arrays are merged recursively.
     */
    public function merge(array $configs): self
    {
        foreach ($configs as $key => $value) {
            if ($key instanceof IumConfigEnum || is_string($key)) {
                $this->set($key, $value);
            } elseif (is_array($value)) {
                $this->merge($value);
            }
        }

        return $this;
    }

    /**
     * Get all stored configuration values.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->configs;
    }

    /**
     * Clear all stored configuration values and reset mirrored global values.
     */
    public function reset(): self
    {
        $this->configs = [];
        GlobalConfigManager::reset();

        return $this;
    }
}
