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
        $published = $this->call('vendor:publish', [
            '--provider' => DemoModeServiceProvider::class,
            '--tag' => 'demo-mode-config', '--force' => (bool) $this->option('force'),
        ]);
        if ($published !== self::SUCCESS) {
            return $published;
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
