<?php

namespace Obelaw\Ium\Engine;

/**
 * Static, globally reachable configuration store.
 *
 * Holds values that must be readable from static contexts where the
 * singleton ObelawConfigManager is not available (e.g. model construction).
 */
class GlobalConfigManager
{
    /**
     * Stored global configuration values.
     *
     * @var array<string, mixed>
     */
    protected static array $iumConfigs = [];

    /**
     * Set a global configuration value.
     */
    public static function set(string $key, mixed $value): void
    {
        static::$iumConfigs[$key] = $value;
    }

    /**
     * Get a global configuration value.
     *
     * @param mixed $default Fallback when the key is not set.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return static::$iumConfigs[$key] ?? $default;
    }
}
