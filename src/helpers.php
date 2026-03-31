<?php

use Obelaw\Ium\ObelawiumManager;
use Obelaw\Ium\Contracts\IumConfigEnum;
use Illuminate\Container\Container;

if (!function_exists('ium')) {
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
    function ium_config(IumConfigEnum|string $enum, mixed $default = null): mixed
    {
        return ium()->config()->get($enum, $default);
    }
}

if (!function_exists('ium_set_config')) {
    function ium_set_config(IumConfigEnum|string $enum, mixed $value, ?bool $global = null): void
    {
        ium()->config()->set($enum, $value, $global);
    }
}
