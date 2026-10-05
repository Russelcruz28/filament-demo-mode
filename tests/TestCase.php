<?php

namespace DemoMode\Tests;

use DemoMode\DemoModeServiceProvider;
use Filament\FilamentServiceProvider;
use Illuminate\Support\Facades\File;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, FilamentServiceProvider::class, DemoModeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('session.driver', 'array');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('demo-mode.root', sys_get_temp_dir().'/filament-demo-test-'.bin2hex(random_bytes(8)));
        $app['config']->set('demo-mode.models', [Fixtures\Widget::class]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->app['migrator']->path(__DIR__.'/Fixtures/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(config('demo-mode.root'));
        parent::tearDown();
    }
}
