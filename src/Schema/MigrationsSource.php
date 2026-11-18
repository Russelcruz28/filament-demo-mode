<?php

namespace DemoMode\Schema;

use DemoMode\Contracts\SandboxDriver;
use DemoMode\Contracts\SchemaSource;
use DemoMode\Sandbox\Capability;
use DemoMode\Sandbox\SandboxRef;
use DemoMode\Sandbox\TablePlan;
use DemoMode\SandboxRuntime;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;

/**
 * Runs the host's migrations against the sandbox. The sandbox runtime is active, so migrations that
 * name the source connection (e.g. Telescope's getConnection()) are aliased to the sandbox.
 */
class MigrationsSource implements SchemaSource
{
    public function key(): string
    {
        return 'migrations';
    }

    public function supports(SandboxDriver $driver, Connection $source): Capability
    {
        return Capability::ok();
    }

    public function build(SandboxRef $ref, SandboxDriver $driver, Connection $source, TablePlan $plan): void
    {
        $this->migrate($ref, []);
    }

    protected function migrate(SandboxRef $ref, array $options): void
    {
        $runtime = app(SandboxRuntime::class);
        try {
            $runtime->enterRef($ref);
            if (Artisan::call('migrate', ['--database' => 'demo', '--force' => true, ...$options]) !== 0) {
                throw new \RuntimeException('Demo schema creation failed: '.trim(Artisan::output()));
            }
        } finally {
            $runtime->leave();
        }
    }
}
