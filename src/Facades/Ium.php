<?php

namespace Obelaw\Ium\Facades;

use Illuminate\Support\Facades\Facade;
use Obelaw\Ium\ObelawiumManager;

/**
 * @method static \Obelaw\Ium\Engine\ObelawConfigManager config()
 * @method static \Obelaw\Ium\ObelawiumManager __invoke(array $configs = [])
 * @method static \Obelaw\Ium\ObelawiumManager registerDomain(string $name, object $module)
 * @method static ?object getDomain(string $name)
 * @method static bool hasDomain(string $name)
 * @method static array getDomains()
 * @method static mixed __call(string $name, array $arguments)
 * @method static \Obelaw\Ium\ObelawiumManager reset()
 */
class Ium extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ObelawiumManager::class;
    }
}
