<?php

namespace Obelaw\Ium\Providers;

use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;
use Obelaw\Ium\ObelawiumManager;

class ObelawiumServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(ObelawiumManager::class, function ($app) {
            return ObelawiumManager::getInstance();
        });
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

            AboutCommand::add('Obelawium', fn() => ['Obelawium Core' => '0.1.0']);
        }
    }
}
