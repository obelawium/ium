<?php

declare(strict_types=1);

namespace Obelaw\Ium\Providers;

use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;
use Obelaw\Ium\Console\Commands\IumMigrateCommand;
use Obelaw\Ium\Console\Commands\IumModulesCommand;
use Obelaw\Ium\Console\Commands\IumOutboxProcessCommand;
use Obelaw\Ium\Console\Commands\IumTenantsMigrateCommand;
use Obelaw\Ium\Contracts\ActorResolver;
use Obelaw\Ium\Contracts\Authorizer;
use Obelaw\Ium\Contracts\SettingsRepository;
use Obelaw\Ium\Contracts\TenantContext;
use Obelaw\Ium\Defaults\NullActorResolver;
use Obelaw\Ium\Defaults\NullAuthorizer;
use Obelaw\Ium\Defaults\NullSettingsRepository;
use Obelaw\Ium\Defaults\NullTenantContext;
use Obelaw\Ium\Engine\ObelawConfigManager;
use Obelaw\Ium\Facades\Ium;
use Obelaw\Ium\Modules\ModuleRegistry;
use Obelaw\Ium\ObelawiumManager;
use Obelaw\Ium\Outbox\Outbox;
use Obelaw\Ium\Support\Sequence;

/**
 * Registers core Obelawium services, environment contracts, building blocks,
 * module migration discovery, and Artisan commands.
 */
class ObelawiumServiceProvider extends ServiceProvider
{
    public const VERSION = '1.0.0';

    /**
     * Register container bindings.
     */
    public function register(): void
    {
        $this->app->singleton(ObelawConfigManager::class, static fn (): ObelawConfigManager => new ObelawConfigManager());

        $this->app->singleton(ModuleRegistry::class, static function (): ModuleRegistry {
            $registry = new ModuleRegistry();
            $coreMigrations = __DIR__ . '/../../database/migrations';

            if (is_dir($coreMigrations)) {
                $registry->addCoreMigrationPath($coreMigrations);
            }

            return $registry;
        });

        $this->app->singletonIf(TenantContext::class, static fn (): TenantContext => new NullTenantContext());
        $this->app->singletonIf(ActorResolver::class, static fn (): ActorResolver => new NullActorResolver());
        $this->app->singletonIf(Authorizer::class, static fn (): Authorizer => new NullAuthorizer());
        $this->app->singletonIf(SettingsRepository::class, static fn (): SettingsRepository => new NullSettingsRepository());

        $this->app->singleton(Sequence::class, static fn (): Sequence => new Sequence());
        $this->app->singleton(Outbox::class, static fn (): Outbox => new Outbox());

        $this->app->singleton(ObelawiumManager::class, static fn ($app): ObelawiumManager => new ObelawiumManager(
            $app->make(ObelawConfigManager::class),
            $app->make(ModuleRegistry::class),
        ));

        $this->app->alias(ObelawiumManager::class, 'ium');
        $this->app->alias(ObelawiumManager::class, Ium::class);
    }

    /**
     * Bootstrap package resources, commands, and automatic migration discovery.
     */
    public function boot(): void
    {
        $this->app->afterResolving('migrator', function ($migrator): void {
            /** @var ModuleRegistry $registry */
            $registry = $this->app->make(ModuleRegistry::class);

            /** @var TenantContext $tenantContext */
            $tenantContext = $this->app->make(TenantContext::class);

            foreach ($registry->migrationPaths(multiTenant: $tenantContext->isMultiTenant()) as $path) {
                $migrator->path($path);
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                IumModulesCommand::class,
                IumMigrateCommand::class,
                IumTenantsMigrateCommand::class,
                IumOutboxProcessCommand::class,
            ]);

            if (class_exists(AboutCommand::class)) {
                AboutCommand::add('Obelawium', static fn (): array => [
                    'Obelawium Core' => self::VERSION,
                ]);
            }
        }
    }
}
