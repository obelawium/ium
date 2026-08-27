<?php

use Obelaw\Ium\ObelawiumManager;
use Obelaw\Ium\Contracts\IumConfigEnum;
use Illuminate\Container\Container;

if (!function_exists('ium')) {
    /**
     * Resolve the Obelawium manager from the container, optionally merging config.
     *
     * @param array<string, mixed> $configs Configuration values to merge.
     */
    function ium(array $configs = []): ObelawiumManager
    {
        $manager = Container::getInstance()->make(ObelawiumManager::class);

        if (!empty($configs)) {
            $manager($configs);
        }

        return $manager;
    }
}

if (!function_exists('ium_config')) {
    /**
     * Get an Ium configuration value.
     *
     * @param IumConfigEnum|string $enum Config key or backed enum.
     * @param mixed $default Fallback when the key is not set.
     */
    function ium_config(IumConfigEnum|string $enum, mixed $default = null): mixed
    {
        return ium()->config()->get($enum, $default);
    }
}

if (!function_exists('ium_set_config')) {
    /**
     * Set an Ium configuration value.
     *
     * @param IumConfigEnum|string $enum Config key or backed enum.
     * @param mixed $value Value to store.
     * @param bool|null $global When true, also store in the global manager.
     */
    function ium_set_config(IumConfigEnum|string $enum, mixed $value, ?bool $global = null): void
    {
        ium()->config()->set($enum, $value, $global);
    }
}
