<?php

declare(strict_types=1);

namespace Toshkq93\LaravelLayers\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Toshkq93\LaravelLayers\LaravelLayersServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelDataServiceProvider::class,
            LaravelLayersServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // A Laravel app does this in LoadConfiguration; Testbench does not,
        // and the container ignores #[Bind] without it. Laravel 12+ only
        // (Laravel 11 has neither the method nor #[Bind]).
        if (method_exists($app, 'resolveEnvironmentUsing')) {
            $app->resolveEnvironmentUsing($app->environment(...));
        }

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
