<?php

namespace Obelaw\Ium\Engine;

use Obelaw\Ium\Contracts\IumConfigEnum;
use Obelaw\Ium\Engine\GlobalConfigManager;

/**
 * Process-local configuration store for the Ium engine.
 *
 * Keys may be plain strings or any backed enum implementing IumConfigEnum.
 * Values flagged as global are mirrored into the GlobalConfigManager so
 * they can be read from static contexts (e.g. model booting).
 */
final class ObelawConfigManager
{
    /**
     * Singleton instance.
     */
    private static ?self $instance = null;

    /**
     * Stored configuration values.
     *
     * @var array<string, mixed>
     */
    private array $configs = [];

    private function __construct() {}

    /**
     * Get the singleton instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
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
     * @param bool|null $global When true, also store in the global manager.
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

        if (!isset($this->configs[$key])) {
            $this->configs[$key] = $value;
        }

        return $this;
    }

    /**
     * Merge a set of configuration values into the store.
     *
     * @param array<mixed> $configs Keyed configuration values; nested arrays
     *                              are merged recursively.
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
     * Clear all stored configuration values.
     */
    public function reset(): self
    {
        $this->configs = [];

        return $this;
    }
}
