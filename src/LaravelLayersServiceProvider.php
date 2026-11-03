<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers;

use Illuminate\Support\ServiceProvider;
use Toshkq93\LaravelLayers\Console\MakeLayersCommand;
use Toshkq93\LaravelLayers\Schema\ModelInspector;

class LaravelLayersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/layers.php', 'layers');

        $this->app->bind(ModelInspector::class, fn ($app) => new ModelInspector(
            readOnlyColumns: $app['config']->get('layers.read_only_columns', ['deleted_at', 'remember_token']),
        ));
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            MakeLayersCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../config/layers.php' => config_path('layers.php'),
        ], 'layers-config');

        $this->publishes([
            __DIR__.'/../stubs' => base_path('stubs/layers'),
        ], 'layers-stubs');
    }
}
