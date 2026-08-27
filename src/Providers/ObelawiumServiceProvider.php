<?php

namespace Obelaw\Ium\Providers;

use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;
use Obelaw\Ium\Facades\Ium;
use Obelaw\Ium\ObelawiumManager;

/**
 * Registers the Obelawium manager in the container and boots package
 * resources (migrations, about-command metadata).
 */
class ObelawiumServiceProvider extends ServiceProvider
{
    /**
     * Register container bindings.
     */
    public function register()
    {
        $this->app->singleton(ObelawiumManager::class, function ($app) {
            return ObelawiumManager::getInstance();
        });

        $this->app->singleton(Ium::class, function ($app) {
            return new ObelawiumManager();
        });
    }

    /**
     * Bootstrap package resources.
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

            AboutCommand::add('Obelawium', fn() => ['Obelawium Core' => '0.1.0']);
        }
    }
}
