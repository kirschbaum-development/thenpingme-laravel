<?php

namespace Thenpingme;

use Illuminate\Support\ServiceProvider;
use Thenpingme\Console\Commands\ThenpingmeSetupCommand;

class ThenpingmeServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/thenpingme.php' => config_path('thenpingme.php'),
            ], 'config');

            // Registering package commands.
            $this->commands([
                ThenpingmeSetupCommand::class,
            ]);
        }
    }

    /**
     * Register the application services.
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/thenpingme.php', 'thenpingme');
        $this->mergeConfigFrom(__DIR__.'/../config/webhook-server.php', 'webhook-server');

        // Register the main class to use with the facade
        $this->app->singleton('thenpingme', function () {
            return new Thenpingme;
        });
    }
}
