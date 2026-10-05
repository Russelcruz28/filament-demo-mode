<?php

namespace DemoMode\Commands;

use DemoMode\Contracts\ApplicationAdapter;
use DemoMode\Contracts\RoleSwitcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class DoctorDemoMode extends Command
{
    protected $signature = 'demo-mode:doctor';

    protected $description = 'Check Demo Mode dependencies, adapter, schema, and storage';

    public function handle(): int
    {
        $checks = [['SQLite PDO', extension_loaded('pdo_sqlite')]];
        $adapter = config('demo-mode.adapter');
        $checks[] = ['Application adapter', is_string($adapter) && is_a($adapter, ApplicationAdapter::class, true)];
        $checks[] = ['Standalone role switcher', is_string($adapter) && is_a($adapter, RoleSwitcher::class, true)];
        $root = config('demo-mode.root');
        $parent = $root;
        while (! File::exists($parent) && dirname($parent) !== $parent) {
            $parent = dirname($parent);
        }
        $checks[] = ['Sandbox storage writable', File::isDirectory($parent) && is_writable($parent)];
        try {
            $checks[] = ['Settings migration', DB::connection()->getSchemaBuilder()->hasTable('demo_settings')];
            $this->line('Database driver: '.DB::connection()->getDriverName());
        } catch (\Throwable $exception) {
            $checks[] = ['Database reachable', false];
            $this->error($exception->getMessage());
        }
        foreach ($checks as [$name, $passed]) {
            $this->line(($passed ? 'PASS' : 'FAIL').' '.$name);
        }
        $this->warn('Host migrations must run on SQLite. Review external SDKs, raw SQL, tenant/role rules, and direct filesystem calls before using in production.');

        return collect($checks)->every(fn ($check) => $check[1]) ? self::SUCCESS : self::FAILURE;
    }
}
