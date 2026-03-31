<?php

namespace Obelaw\Ium\Engine;

class GlobalConfigManager
{
    protected static array $iumConfigs = [];

    public static function set(string $key, mixed $value): void
    {
        static::$iumConfigs[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::$iumConfigs[$key] ?? $default;
    }
}
