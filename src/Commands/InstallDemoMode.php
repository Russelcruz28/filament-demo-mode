<?php

namespace DemoMode\Commands;

use DemoMode\DemoModeServiceProvider;
use Illuminate\Console\Command;

class InstallDemoMode extends Command
{
    protected $signature = 'demo-mode:install {--force : Replace published configuration} {--migrate : Run the package migration}';

    protected $description = 'Publish Demo Mode configuration and optionally create its settings table';

    public function handle(): int
    {
        $path = config_path('demo-mode.php');
        $fresh = $this->option('force') || ! is_file($path);
        $published = $this->call('vendor:publish', [
            '--provider' => DemoModeServiceProvider::class,
            '--tag' => 'demo-mode-config', '--force' => (bool) $this->option('force'),
        ]);
        if ($published !== self::SUCCESS) {
            return $published;
        }
        // New installs keep the source database engine; the package default stays sqlite for existing installs.
        if ($fresh && is_file($path)) {
            file_put_contents($path, str_replace("env('DEMO_MODE_DRIVER', 'sqlite')", "env('DEMO_MODE_DRIVER', 'auto')", file_get_contents($path)));
        }
        if ($this->option('migrate')) {
            $result = $this->call('migrate', [
                '--path' => realpath(__DIR__.'/../../database/migrations'),
                '--realpath' => true, '--force' => (bool) $this->option('force'),
            ]);
            if ($result !== self::SUCCESS) {
                return $result;
            }
        }
        $this->info('Register DemoModePlugin::make() in your Filament panel provider.');
        $this->info('Run demo-mode:doctor to check the installation.');

        return self::SUCCESS;
    }
}
