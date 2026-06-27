<?php

namespace Cuonggt\Bosun;

use Cuonggt\Bosun\Console\DeployCommand;
use Cuonggt\Bosun\Console\SetupCommand;
use Illuminate\Support\ServiceProvider;

class BosunServiceProvider extends ServiceProvider
{
    /**
     * Register package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bosun.php', 'bosun');
    }

    /**
     * Bootstrap package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/bosun.php' => config_path('bosun.php'),
            ], 'bosun-config');

            $this->commands([
                SetupCommand::class,
                DeployCommand::class,
            ]);
        }
    }
}
