<?php

declare(strict_types=1);

namespace Obelaw\Ium\Facades;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Facade;
use Obelaw\Ium\Contracts\Actor;
use Obelaw\Ium\Contracts\ActorResolver;
use Obelaw\Ium\Contracts\Authorizer;
use Obelaw\Ium\Contracts\SettingsRepository;
use Obelaw\Ium\Contracts\TenantContext;
use Obelaw\Ium\Modules\Module;
use Obelaw\Ium\Modules\ModuleRegistry;
use Obelaw\Ium\ObelawiumManager;
use Obelaw\Ium\Outbox\Outbox;
use Obelaw\Ium\Support\Sequence;

/**
 * @method static \Obelaw\Ium\Engine\ObelawConfigManager config()
 * @method static ModuleRegistry modules()
 * @method static TenantContext tenantContext()
 * @method static ActorResolver actorResolver()
 * @method static Actor actor()
 * @method static Authorizer authorizer()
 * @method static SettingsRepository settings()
 * @method static Sequence sequence()
 * @method static Outbox outbox()
 * @method static ?string connectionName()
 * @method static ConnectionInterface connection()
 * @method static mixed transaction(callable $callback, int $attempts = 1)
 * @method static ObelawiumManager __invoke(array $configs = [])
 * @method static ObelawiumManager registerModule(Module $module)
 * @method static ObelawiumManager ignoreMigrations(string ...$modules)
 * @method static ObelawiumManager useMigrationPath(string $module, string|array $paths)
 * @method static ObelawiumManager registerDomain(string $name, class-string<\Obelaw\Ium\Abstracts\Domain> $domain)
 * @method static class-string<\Obelaw\Ium\Abstracts\Domain>|null getDomain(string $name)
 * @method static bool hasDomain(string $name)
 * @method static array<string, class-string<\Obelaw\Ium\Abstracts\Domain>> getDomains()
 * @method static mixed __call(string $name, array $arguments)
 * @method static ObelawiumManager reset()
 *
 * @see \Obelaw\Ium\ObelawiumManager
 */
class Ium extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ObelawiumManager::class;
    }
}
